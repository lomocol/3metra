<?php
/**
 * pay.php — переход на страницу оплаты Robokassa сразу после отправки
 * формы (сделка в amoCRM уже создана form-handler.php).
 *
 * Принимает GET-параметры от script.js:
 *   lead   — ID сделки в amoCRM, он же InvId счёта в Robokassa
 *   event  — код вечера (aug15, aug21, …) — из серверного списка
 *   gender — код услуги: m (мужской билет) или f (женский)
 *
 * Сумме из браузера не доверяет: цена берётся из robokassa-config.php.
 * Считает SignatureValue по формуле Robokassa и отправляет гостя на
 * https://auth.robokassa.ru/Merchant/Index.aspx
 * (https://docs.robokassa.ru/script-parameters/).
 *
 * Перед редиректом добавляет к сделке примечание с номером счёта — так
 * номер заказа и сумма фиксируются в amoCRM без новых полей.
 */

declare(strict_types=1);

ini_set('display_errors', '0');

const PAY_LOG_FILE = __DIR__ . '/robokassa.log';
const PAY_DATA_DIR = __DIR__ . '/payment-data';
/* Каталог прошлой платёжной интеграции: заявки, отправленные до деплоя,
   лежат ещё там — читаем их оттуда, чтобы не потерять ClientID Метрики */
const PAY_LEGACY_DATA_DIR = __DIR__ . '/payanyway-data';

/* Список вечеров — должен совпадать с EVENTS в script.js и form-handler.php */
const PAY_EVENTS = array(
    'aug15' => 'суббота, 15 августа — старшая группа',
    'aug21' => 'пятница, 21 августа — основная группа',
    'aug22' => 'суббота, 22 августа — основная группа, свидание по ценностям: ЗОЖ',
);

const PAY_ROBOKASSA_URL = 'https://auth.robokassa.ru/Merchant/Index.aspx';

/* Robokassa ограничивает Description 100 символами, а name в чеке — 128 */
const PAY_DESCRIPTION_LIMIT = 100;
const PAY_RECEIPT_NAME_LIMIT = 128;

/* InvId в Robokassa — целое число в пределах unsigned int32 */
const PAY_MAX_INV_ID = 2147483647;

function payLog($message)
{
    @file_put_contents(
        PAY_LOG_FILE,
        '[' . date('Y-m-d H:i:s') . '] pay.php: ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

/* Человеческая страница ошибки — гость не должен увидеть белый экран */
function payFailPage($title, $text)
{
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES) . ' — 3 метра</title>'
        . '<style>body{margin:0;display:grid;place-items:center;min-height:100vh;'
        . 'background:#faf7f2;color:#261c18;font:16px/1.6 "Golos Text",system-ui,sans-serif;padding:24px}'
        . '.card{max-width:440px;text-align:center;background:#fff;border:1px solid rgba(46,30,24,.12);'
        . 'border-radius:22px;padding:40px 32px}'
        . 'h1{font-size:22px;margin:0 0 12px}p{margin:0 0 22px;color:#5d5148}'
        . 'a{display:inline-block;background:#a01f3c;color:#fff;text-decoration:none;'
        . 'padding:13px 26px;border-radius:999px;font-weight:600}</style></head><body>'
        . '<div class="card"><h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1>'
        . '<p>' . htmlspecialchars($text, ENT_QUOTES) . '</p>'
        . '<a href="/">Вернуться на сайт</a></div></body></html>';
    exit;
}

/* ------------------------------------------------------------------
   Конфигурация и входные данные
   ------------------------------------------------------------------ */

$config = @include __DIR__ . '/robokassa-config.php';
if (
    !is_array($config)
    || empty($config['merchant_login']) || empty($config['password1'])
    || strpos((string) $config['merchant_login'], 'ВСТАВИТЬ') !== false
    || strpos((string) $config['password1'], 'ВСТАВИТЬ') !== false
    || empty($config['prices']) || !is_array($config['prices'])
) {
    payLog('robokassa-config.php отсутствует или не заполнен');
    payFailPage(
        'Оплата временно недоступна',
        'Мы уже знаем о проблеме. Ваша заявка сохранена — администратор свяжется с вами и поможет с оплатой.'
    );
}

$leadId = isset($_GET['lead']) ? (int) $_GET['lead'] : 0;
$event  = isset($_GET['event']) ? (string) $_GET['event'] : '';
$gender = isset($_GET['gender']) ? (string) $_GET['gender'] : '';

if (
    $leadId <= 0 || $leadId > PAY_MAX_INV_ID
    || !isset(PAY_EVENTS[$event]) || !isset($config['prices'][$gender])
) {
    payLog('Некорректные параметры: lead=' . $leadId . ' event=' . $event . ' gender=' . $gender);
    payFailPage(
        'Не удалось открыть оплату',
        'Ссылка на оплату устарела или неверна. Вернитесь на сайт и заполните форму ещё раз.'
    );
}

/* ------------------------------------------------------------------
   Параметры платежа
   ------------------------------------------------------------------ */

$merchantLogin = (string) $config['merchant_login'];
$currency      = isset($config['currency']) ? (string) $config['currency'] : 'RUB';
$isTest        = !empty($config['test_mode']) ? '1' : '0';
$amount        = number_format((float) $config['prices'][$gender], 2, '.', '');

/* InvId — номер счёта в Robokassa. Это ID сделки: он уникален, поэтому
   повторный заход на оплату той же заявки не создаёт второй счёт */
$invId = (string) $leadId;

$description = 'Участие в вечере знакомств «3 метра»: ' . PAY_EVENTS[$event];
if (mb_strlen($description, 'UTF-8') > PAY_DESCRIPTION_LIMIT) {
    $description = mb_substr($description, 0, PAY_DESCRIPTION_LIMIT - 1, 'UTF-8') . '…';
}

/* ClientID Метрики сохраняем на сервере рядом со счётом. Он не входит
   в платёжные параметры и не может быть подменён во время оплаты. */
if (!is_dir(PAY_DATA_DIR)) {
    @mkdir(PAY_DATA_DIR, 0755);
    @file_put_contents(
        PAY_DATA_DIR . '/.htaccess',
        "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
    );
}
$metrikaClientId = '';
$leadName = '';
$leadPhone = '';
$leadAnalyticsFile = PAY_DATA_DIR . '/lead-' . $leadId . '.json';
if (!is_file($leadAnalyticsFile)) {
    $leadAnalyticsFile = PAY_LEGACY_DATA_DIR . '/lead-' . $leadId . '.json';
}
if (is_file($leadAnalyticsFile)) {
    $leadAnalytics = json_decode((string) @file_get_contents($leadAnalyticsFile), true);
    if (
        is_array($leadAnalytics)
        && isset($leadAnalytics['client_id'])
        && preg_match('/^\d{5,32}$/', (string) $leadAnalytics['client_id'])
    ) {
        $metrikaClientId = (string) $leadAnalytics['client_id'];
    }
    if (is_array($leadAnalytics)) {
        $leadName = isset($leadAnalytics['name']) ? (string) $leadAnalytics['name'] : '';
        $leadPhone = isset($leadAnalytics['phone']) ? (string) $leadAnalytics['phone'] : '';
    }
}
$orderFile = PAY_DATA_DIR . '/order-' . $invId . '.json';
$orderSaved = @file_put_contents(
    $orderFile,
    json_encode(
        array(
            'inv_id' => $invId,
            'lead_id' => $leadId,
            'client_id' => $metrikaClientId,
            'name' => $leadName,
            'phone' => $leadPhone,
            'event' => $event,
            'gender' => $gender,
            'amount' => $amount,
            'currency' => $currency,
            'created_at' => date('c'),
        ),
        JSON_UNESCAPED_UNICODE
    ),
    LOCK_EX
);
if ($orderSaved === false) {
    payLog('Не удалось сохранить аналитику счёта ' . $invId);
}

/* ------------------------------------------------------------------
   Чек «Робочеки СМЗ» и подпись
   ------------------------------------------------------------------ */

/* Дополнительные параметры Robokassa возвращает на Result URL без
   изменений. В подписи они идут после пароля в алфавитном порядке */
$shpParams = array(
    'shp_event'  => $event,
    'shp_gender' => $gender,
);
ksort($shpParams, SORT_STRING);
$shpSignaturePart = '';
foreach ($shpParams as $name => $value) {
    $shpSignaturePart .= ':' . $name . '=' . $value;
}

/* Чек плательщика НПД: tax = none, без sno — Robokassa передаёт чек
   в сервис ФНС «Мой налог» (https://docs.robokassa.ru/fiscalization/) */
$receiptEncoded = '';
if (!empty($config['send_receipt'])) {
    $itemName = 'Участие в мероприятии «3 метра»: ' . PAY_EVENTS[$event];
    if (mb_strlen($itemName, 'UTF-8') > PAY_RECEIPT_NAME_LIMIT) {
        $itemName = mb_substr($itemName, 0, PAY_RECEIPT_NAME_LIMIT, 'UTF-8');
    }
    $receiptJson = json_encode(
        array(
            'items' => array(
                array(
                    'name' => $itemName,
                    'quantity' => 1,
                    'sum' => (float) $amount,
                    'payment_method' => 'full_payment',
                    'payment_object' => 'service',
                    'tax' => 'none',
                ),
            ),
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    /* Receipt участвует в подписи ровно в том виде, в котором уходит
       в запросе, — url-encoded */
    $receiptEncoded = rawurlencode((string) $receiptJson);
}

/* SignatureValue = MD5(MerchantLogin:OutSum:InvId[:Receipt]:Пароль#1[:shp_...]) */
$signatureSource = $merchantLogin . ':' . $amount . ':' . $invId
    . ($receiptEncoded !== '' ? ':' . $receiptEncoded : '')
    . ':' . (string) $config['password1']
    . $shpSignaturePart;
$signature = md5($signatureSource);

/* ------------------------------------------------------------------
   Фиксируем выставленный счёт в сделке amoCRM (не блокирует оплату)
   ------------------------------------------------------------------ */

$amoConfig = @include __DIR__ . '/amo-config.php';
if (is_array($amoConfig) && !empty($amoConfig['domain']) && !empty($amoConfig['token'])) {
    $noteText = 'Гость перешёл к оплате Robokassa' . ($isTest === '1' ? ' (ТЕСТОВЫЙ РЕЖИМ)' : '')
        . "\nНомер счёта: " . $invId
        . "\nСумма: " . $amount . ' ' . $currency
        . "\nУслуга: " . $description;
    $ch = curl_init('https://' . $amoConfig['domain'] . '/api/v4/leads/' . $leadId . '/notes');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(
            array(array('note_type' => 'common', 'params' => array('text' => $noteText))),
            JSON_UNESCAPED_UNICODE
        ),
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . $amoConfig['token'],
            'Content-Type: application/json',
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
    ));
    curl_exec($ch);
    $amoStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($amoStatus < 200 || $amoStatus >= 300) {
        payLog('Примечание о счёте не добавлено к сделке ' . $leadId . ' (HTTP ' . $amoStatus . ')');
    }
} else {
    payLog('amo-config.php недоступен — счёт ' . $invId . ' не зафиксирован в сделке');
}

/* ------------------------------------------------------------------
   Редирект на платёжную страницу Robokassa
   ------------------------------------------------------------------ */

/* Собираем строку запроса вручную: Receipt уже url-encoded и повторное
   кодирование сломало бы подпись */
$query = 'MerchantLogin=' . rawurlencode($merchantLogin)
    . '&OutSum=' . rawurlencode($amount)
    . '&InvId=' . rawurlencode($invId)
    . '&Description=' . rawurlencode($description)
    . '&SignatureValue=' . $signature
    . '&Culture=ru'
    . '&Encoding=utf-8';
/* IsTest передаётся только для тестовых платежей: в боевом режиме
   параметра быть не должно */
if ($isTest === '1') {
    $query .= '&IsTest=1';
}
if ($receiptEncoded !== '') {
    $query .= '&Receipt=' . $receiptEncoded;
}
foreach ($shpParams as $name => $value) {
    $query .= '&' . rawurlencode($name) . '=' . rawurlencode($value);
}

payLog('Переход к оплате: счёт ' . $invId . ', ' . $amount . ' ' . $currency
    . ($isTest === '1' ? ' (тест)' : ''));

header('Cache-Control: no-store');
header('Location: ' . PAY_ROBOKASSA_URL . '?' . $query, true, 302);
exit;
