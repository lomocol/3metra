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
const PAYMENT_DATA_DIR = __DIR__ . '/payment-data';
/* ID воронки и этапа, найденные по названиям из amo-config.php */
const AMO_CACHE_FILE = __DIR__ . '/amo-cache.json';

/* Список вечеров — должен совпадать с EVENTS в script.js и PAY_EVENTS
   в pay.php */
const EVENTS = array(
    'aug21' => 'пятница, 21 августа — ЗОЖ-вечер, группа 22–35 лет',
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

function amoGet($url, $token)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $token),
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

/* Первый рабочий этап воронки: без «Неразобранного» (type=1) и без системных
   «Успешно реализовано» (142) и «Закрыто и не реализовано» (143) */
function firstWorkingStatus(array $pipeline)
{
    $statuses = isset($pipeline['_embedded']['statuses'])
        ? $pipeline['_embedded']['statuses']
        : array();
    usort($statuses, function ($a, $b) {
        $sa = isset($a['sort']) ? (int) $a['sort'] : 0;
        $sb = isset($b['sort']) ? (int) $b['sort'] : 0;
        return $sa - $sb;
    });
    foreach ($statuses as $status) {
        $id = isset($status['id']) ? (int) $status['id'] : 0;
        if ($id === 142 || $id === 143) {
            continue;
        }
        if (isset($status['type']) && (int) $status['type'] === 1) {
            continue;
        }
        return $id;
    }
    return null;
}

/**
 * ID воронки и этапа по их названиям из конфига. Результат кешируется в
 * amo-cache.json; ключ кеша включает домен и оба названия, поэтому при
 * переименовании воронки или смене аккаунта кеш обновляется сам.
 *
 * Воронку не нашли — возвращаем null: сделка уйдёт в основную воронку
 * аккаунта. Терять заявку из-за переименования нельзя.
 *
 * @return array [int|null pipelineId, int|null statusId]
 */
function resolvePipeline($baseUrl, $token, $pipelineName, $statusName)
{
    if ($pipelineName === '') {
        return array(null, null);
    }

    $cacheKey = $baseUrl . '|' . $pipelineName . '|' . $statusName;
    $cache = json_decode((string) @file_get_contents(AMO_CACHE_FILE), true);
    if (is_array($cache) && isset($cache['key']) && $cache['key'] === $cacheKey && !empty($cache['pipeline_id'])) {
        return array(
            (int) $cache['pipeline_id'],
            empty($cache['status_id']) ? null : (int) $cache['status_id'],
        );
    }

    list($status, $body, $curlError) = amoGet($baseUrl . '/api/v4/leads/pipelines', $token);
    if ($curlError !== '' || $status < 200 || $status >= 300) {
        logError('Список воронок не получен: ' . amoErrorSummary($status, $body, $curlError));
        return array(null, null);
    }

    $json = json_decode($body, true);
    $pipelines = isset($json['_embedded']['pipelines']) && is_array($json['_embedded']['pipelines'])
        ? $json['_embedded']['pipelines']
        : array();

    foreach ($pipelines as $pipeline) {
        if (!isset($pipeline['name']) || $pipeline['name'] !== $pipelineName) {
            continue;
        }
        $pipelineId = (int) $pipeline['id'];
        $statusId = null;
        if ($statusName !== '' && isset($pipeline['_embedded']['statuses'])) {
            foreach ($pipeline['_embedded']['statuses'] as $stage) {
                if (isset($stage['name']) && $stage['name'] === $statusName) {
                    $statusId = (int) $stage['id'];
                    break;
                }
            }
        }
        if ($statusId === null) {
            $statusId = firstWorkingStatus($pipeline);
            if ($statusName !== '') {
                logError('Этап «' . $statusName . '» не найден в воронке «' . $pipelineName
                    . '» — заявка встанет на первый этап воронки');
            }
        }
        @file_put_contents(
            AMO_CACHE_FILE,
            json_encode(
                array('key' => $cacheKey, 'pipeline_id' => $pipelineId, 'status_id' => $statusId),
                JSON_UNESCAPED_UNICODE
            ),
            LOCK_EX
        );
        return array($pipelineId, $statusId);
    }

    logError('Воронка «' . $pipelineName . '» не найдена — заявка уйдёт в основную воронку');
    return array(null, null);
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
   amoCRM: контакт + сделка одним запросом. Воронка и этап — по названиям
   из amo-config.php (ID ищутся и кешируются в amo-cache.json)
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

$baseUrl = 'https://' . $config['domain'];

/* Воронка своя у каждого сайта — аккаунт amoCRM может быть общим */
list($pipelineId, $statusId) = resolvePipeline(
    $baseUrl,
    $config['token'],
    isset($config['pipeline']) ? (string) $config['pipeline'] : '',
    isset($config['status']) ? (string) $config['status'] : ''
);

$lead = array(
    'name' => 'Заявка на вечер — ' . $name,
    '_embedded' => array(
        'contacts' => array($contactEmbed),
        'tags' => array(array('name' => 'Сайт 3metra-rostov.ru')),
    ),
);
if ($pipelineId !== null) {
    $lead['pipeline_id'] = $pipelineId;
}
if ($statusId !== null) {
    /* без status_id сделка встаёт на первый этап указанной воронки */
    $lead['status_id'] = $statusId;
}

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
    $amoError = amoErrorSummary($status, $body, $curlError);
    logError('Создание сделки не удалось: ' . $amoError);

    /* amoCRM недоступна (например, HTTP 402 — не оплачена подписка
       аккаунта). Чтобы не терять заявку, всё равно отправляем её в
       мессенджеры с пометкой «занести вручную». Если уведомление ушло —
       показываем гостю успех (leadCreated:false, чтобы цель Метрики об
       оплате не сработала), администратор свяжется вручную. */
    $fallback = sendSiteNotification(
        buildDealNotification(
            'НОВАЯ ЗАЯВКА — занести в amoCRM вручную (CRM недоступна)',
            array(
                'name' => $name,
                'phone' => $phone !== '' ? $phone : $contact,
                'event' => EVENTS[$event],
                'service' => SERVICES[$gender],
                'amount' => null,
                'currency' => 'RUB',
                'deal_url' => 'сделка не создана (' . $amoError . ')',
            )
        )
    );
    if (!empty($fallback['enabled']) && empty($fallback['success'])) {
        logError(
            'Резервное уведомление о заявке не отправлено: '
            . notificationFailureSummary($fallback)
        );
    }

    if (!empty($fallback['enabled']) && !empty($fallback['success'])) {
        respond(200, true, null, false);
    }
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
   по leadId и прикрепит к конкретному счёту Robokassa. */
if (!is_dir(PAYMENT_DATA_DIR)) {
    @mkdir(PAYMENT_DATA_DIR, 0755);
    @file_put_contents(
        PAYMENT_DATA_DIR . '/.htaccess',
        "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
    );
}
$paymentConfig = @include __DIR__ . '/robokassa-config.php';
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
