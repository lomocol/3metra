<?php
/**
 * form-handler.php — принимает заявку с формы сайта («Заявка на вечер»)
 * и создаёт в amoCRM (API v4) контакт + сделку одним запросом
 * (/api/v4/leads/complex), затем добавляет к сделке текстовое примечание
 * со всеми данными заявки, страницей, referrer и UTM-метками.
 *
 * Домен и долгосрочный токен — в amo-config.php рядом с этим файлом
 * (в git не попадает, образец — amo-config.example.php).
 *
 * Ответы: JSON. 200 — успех, 400 — плохой запрос, 405 — не POST,
 * 422 — ошибка валидации, 500 — не заполнен конфиг, 502 — ошибка amoCRM.
 */

declare(strict_types=1);

ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const MAX_FIELD_LENGTH = 500;
const LOG_FILE = __DIR__ . '/form-handler.log';
const PAYMENT_DATA_DIR = __DIR__ . '/payanyway-data';

/* Список вечеров — должен совпадать с EVENTS в script.js и PAY_EVENTS
   в pay.php */
const EVENTS = array(
    'jul24' => 'пятница, 24 июля — основная группа',
    'jul25' => 'суббота, 25 июля — старшая группа',
    'jul31' => 'пятница, 31 июля — основная группа',
    'aug1'  => 'суббота, 1 августа — старшая группа',
);

const GENDERS = array('m' => 'Мужчина', 'f' => 'Женщина');
const SERVICES = array('m' => 'Мужской билет', 'f' => 'Женский билет');
const METHODS = array('phone' => 'Телефон', 'telegram' => 'Telegram', 'max' => 'MAX');
const UTM_KEYS = array('utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term');

require_once __DIR__ . '/notification-sender.php';

function respond($status, $success, $message = null, $leadCreated = null, $leadId = null)
{
    http_response_code($status);
    $body = array('success' => $success);
    if ($leadCreated !== null) {
        $body['leadCreated'] = $leadCreated;
    }
    if ($leadId !== null) {
        /* ID сделки нужен script.js, чтобы передать его в pay.php
           и связать оплату с заявкой */
        $body['leadId'] = $leadId;
    }
    if ($message !== null) {
        $body['message'] = $message;
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/* Технический лог: только служебная информация — без токена и без
   персональных данных из заявки */
function logError($message)
{
    @file_put_contents(
        LOG_FILE,
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function cleanString($value)
{
    if (!is_string($value) && !is_numeric($value)) {
        return '';
    }
    $value = trim((string) $value);
    $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
    return mb_substr($value, 0, MAX_FIELD_LENGTH);
}

/* +7XXXXXXXXXX для российских номеров, иначе просто цифры с плюсом */
function normalizePhone($raw)
{
    $digits = (string) preg_replace('/\D/', '', $raw);
    if (strlen($digits) === 11 && ($digits[0] === '8' || $digits[0] === '7')) {
        return '+7' . substr($digits, 1);
    }
    if (strlen($digits) === 10) {
        return '+7' . $digits;
    }
    return $digits === '' ? '' : '+' . $digits;
}

function amoRequest($url, $token, array $payload)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ));
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array($status, is_string($body) ? $body : '', $error);
}

/* Краткое описание ошибки amoCRM для лога — заголовок и подсказка из
   ответа API, но не тело целиком (оно может содержать данные заявки) */
function amoErrorSummary($status, $body, $curlError)
{
    if ($curlError !== '') {
        return 'cURL: ' . $curlError;
    }
    $parts = array('HTTP ' . $status);
    $json = json_decode($body, true);
    if (is_array($json)) {
        foreach (array('title', 'detail', 'hint') as $key) {
            if (isset($json[$key]) && is_string($json[$key]) && $json[$key] !== '') {
                $parts[] = $json[$key];
            }
        }
    }
    return implode(' — ', $parts);
}

/* ------------------------------------------------------------------
   Запрос
   ------------------------------------------------------------------ */

$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
if ($requestMethod !== 'POST') {
    respond(405, false, 'Метод не поддерживается');
}

$config = @include __DIR__ . '/amo-config.php';
if (
    !is_array($config)
    || empty($config['domain']) || empty($config['token'])
    || strpos($config['domain'], 'ВСТАВИТЬ') !== false
    || strpos($config['token'], 'ВСТАВИТЬ') !== false
) {
    logError('amo-config.php отсутствует или не заполнен');
    respond(500, false, 'Не удалось отправить заявку');
}

/* Принимаем JSON (основной путь — script.js) и обычный FormData */
$contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
if (stripos($contentType, 'application/json') !== false) {
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        respond(400, false, 'Проверьте заполненные данные');
    }
} else {
    $input = $_POST;
}
if (!is_array($input) || $input === array()) {
    respond(400, false, 'Проверьте заполненные данные');
}

/* Honeypot: скрытое поле «website» люди не заполняют. Боту отвечаем
   успехом (leadCreated: false — цель Метрики не сработает), но заявку
   не создаём */
if (cleanString(isset($input['website']) ? $input['website'] : '') !== '') {
    respond(200, true, null, false);
}

/* ------------------------------------------------------------------
   Валидация
   ------------------------------------------------------------------ */

$name = cleanString(isset($input['name']) ? $input['name'] : '');
$age = (int) cleanString(isset($input['age']) ? $input['age'] : '');
$event = cleanString(isset($input['event']) ? $input['event'] : '');
$gender = cleanString(isset($input['gender']) ? $input['gender'] : '');
$method = cleanString(isset($input['method']) ? $input['method'] : '');
$contact = cleanString(isset($input['contact']) ? $input['contact'] : '');
$email = cleanString(isset($input['email']) ? $input['email'] : '');
$metrikaClientIdRaw = cleanString(
    isset($input['metrikaClientId']) ? $input['metrikaClientId'] : ''
);
$metrikaClientId = preg_match('/^\d{5,32}$/', $metrikaClientIdRaw)
    ? $metrikaClientIdRaw
    : '';
$consent = !empty($input['consent']);

$valid = mb_strlen($name) >= 2
    && $age >= 18 && $age <= 99
    && isset(EVENTS[$event])
    && isset(GENDERS[$gender])
    && isset(METHODS[$method])
    && $consent;

$contactDigits = (string) preg_replace('/\D/', '', $contact);
if ($method === 'phone') {
    $valid = $valid && strlen($contactDigits) >= 10;
} else {
    $valid = $valid && mb_strlen($contact) >= 2;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $valid = false;
}

if (!$valid) {
    respond(422, false, 'Проверьте заполненные данные');
}

/* Телефон: контакт при способе «Телефон» или любой контакт, похожий на
   номер (например, телефон в поле MAX) */
$phone = '';
if ($method === 'phone' || strlen($contactDigits) >= 10) {
    $phone = normalizePhone($contact);
}

$page = cleanString(isset($input['page']) ? $input['page'] : '');
$referrer = cleanString(isset($input['referrer']) ? $input['referrer'] : '');
$utm = array();
foreach (UTM_KEYS as $key) {
    $utm[$key] = cleanString(isset($input[$key]) ? $input[$key] : '');
}

/* ------------------------------------------------------------------
   amoCRM: контакт + сделка одним запросом (основная воронка, первый
   статус — ID воронки и статуса не указываем намеренно)
   ------------------------------------------------------------------ */

$contactEmbed = array('first_name' => $name);
$contactFields = array();
if ($phone !== '') {
    $contactFields[] = array(
        'field_code' => 'PHONE',
        'values' => array(array('value' => $phone, 'enum_code' => 'WORK')),
    );
}
if ($email !== '') {
    $contactFields[] = array(
        'field_code' => 'EMAIL',
        'values' => array(array('value' => $email, 'enum_code' => 'WORK')),
    );
}
if ($contactFields !== array()) {
    $contactEmbed['custom_fields_values'] = $contactFields;
}

$lead = array(
    'name' => 'Заявка с сайта',
    '_embedded' => array(
        'contacts' => array($contactEmbed),
        'tags' => array(array('name' => 'Сайт')),
    ),
);

$baseUrl = 'https://' . $config['domain'];
list($status, $body, $curlError) = amoRequest(
    $baseUrl . '/api/v4/leads/complex',
    $config['token'],
    array($lead)
);

$leadId = 0;
if ($curlError === '' && $status >= 200 && $status < 300) {
    $json = json_decode($body, true);
    if (is_array($json) && isset($json[0]['id'])) {
        $leadId = (int) $json[0]['id'];
    }
}

if ($leadId === 0) {
    logError('Создание сделки не удалось: ' . amoErrorSummary($status, $body, $curlError));
    respond(502, false, 'Не удалось отправить заявку');
}

/* ------------------------------------------------------------------
   Примечание к сделке со всеми данными заявки
   ------------------------------------------------------------------ */

$lines = array(
    'Новая заявка с сайта',
    '',
    'Вечер: ' . EVENTS[$event],
    'Имя: ' . $name,
    'Возраст: ' . $age,
    'Пол: ' . GENDERS[$gender],
    'Способ связи: ' . METHODS[$method],
    'Контакт: ' . $contact,
);
if ($phone !== '' && $phone !== $contact) {
    $lines[] = 'Телефон (нормализованный): ' . $phone;
}
if ($email !== '') {
    $lines[] = 'Email: ' . $email;
}
$lines[] = '';
$lines[] = 'Страница: ' . ($page !== '' ? $page : '—');
$lines[] = 'Referrer: ' . ($referrer !== '' ? $referrer : '—');
if ($metrikaClientId !== '') {
    $lines[] = 'ClientID Метрики: ' . $metrikaClientId;
}
foreach (UTM_KEYS as $key) {
    $label = 'UTM ' . substr($key, 4);
    $lines[] = $label . ': ' . ($utm[$key] !== '' ? $utm[$key] : '—');
}
$lines[] = '';
$lines[] = 'Получено: ' . date('d.m.Y H:i:s');

list($noteStatus, $noteBody, $noteCurlError) = amoRequest(
    $baseUrl . '/api/v4/leads/' . $leadId . '/notes',
    $config['token'],
    array(
        array(
            'note_type' => 'common',
            'params' => array('text' => implode("\n", $lines)),
        ),
    )
);

if ($noteCurlError !== '' || $noteStatus < 200 || $noteStatus >= 300) {
    /* Сделка уже создана — заявку не проваливаем, только фиксируем в логе */
    logError(
        'Примечание к сделке ' . $leadId . ' не добавлено: '
        . amoErrorSummary($noteStatus, $noteBody, $noteCurlError)
    );
}

/* Сохраняем ClientID и данные заявки на сервере. pay.php прочитает их
   по leadId и прикрепит к конкретному счёту PayAnyWay. */
if (!is_dir(PAYMENT_DATA_DIR)) {
    @mkdir(PAYMENT_DATA_DIR, 0755);
    @file_put_contents(
        PAYMENT_DATA_DIR . '/.htaccess',
        "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
    );
}
$paymentConfig = @include __DIR__ . '/payanyway-config.php';
$leadAmount = is_array($paymentConfig) && isset($paymentConfig['prices'][$gender])
    ? number_format((float) $paymentConfig['prices'][$gender], 2, '.', '')
    : null;
$leadCurrency = is_array($paymentConfig) && !empty($paymentConfig['currency'])
    ? (string) $paymentConfig['currency']
    : 'RUB';
$leadDataSaved = @file_put_contents(
    PAYMENT_DATA_DIR . '/lead-' . $leadId . '.json',
    json_encode(
        array(
            'lead_id' => $leadId,
            'client_id' => $metrikaClientId,
            'name' => $name,
            'phone' => $phone !== '' ? $phone : $contact,
            'event' => $event,
            'gender' => $gender,
            'amount' => $leadAmount,
            'currency' => $leadCurrency,
            'created_at' => date('c'),
        ),
        JSON_UNESCAPED_UNICODE
    ),
    LOCK_EX
);
if ($leadDataSaved === false) {
    logError('Данные заявки не сохранены для сделки ' . $leadId);
}

$notification = sendSiteNotification(
    buildDealNotification(
        'Новая заявка',
        array(
            'name' => $name,
            'phone' => $phone !== '' ? $phone : $contact,
            'event' => EVENTS[$event],
            'service' => SERVICES[$gender],
            'amount' => $leadAmount,
            'currency' => $leadCurrency,
            'deal_url' => $baseUrl . '/leads/detail/' . $leadId,
        )
    )
);
if (!empty($notification['enabled']) && empty($notification['success'])) {
    logError(
        'Уведомление о сделке ' . $leadId . ' не отправлено: '
        . notificationFailureSummary($notification)
    );
}

respond(200, true, null, true, $leadId);
