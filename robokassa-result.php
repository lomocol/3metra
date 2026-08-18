<?php
/**
 * robokassa-result.php — Result URL для уведомлений Robokassa
 * (https://docs.robokassa.ru/pay-interface/).
 *
 * Принимает POST (или GET). Проверяет SignatureValue по Паролю #2,
 * восстанавливает сделку по InvId и сверяет сумму с серверным списком
 * цен. После успешной проверки:
 *   - добавляет к сделке amoCRM примечание «Оплата получена»;
 *   - проставляет бюджет сделки (и статус «Оплачено», если задан в конфиге);
 *   - шлёт уведомление администратору и офлайн-цель в Яндекс.Метрику;
 *   - запоминает счёт как обработанный (файл-маркер) — повторное
 *     уведомление отвечает OK без повторной обработки.
 *
 * Ответы: OK{InvId} — принято (или уже обработано), FAIL — ошибка
 * (Robokassa будет повторять уведомление).
 */

declare(strict_types=1);

ini_set('display_errors', '0');

const CB_LOG_FILE = __DIR__ . '/robokassa.log';
const CB_DATA_DIR = __DIR__ . '/payment-data';
const CB_EVENTS = array(
    'aug21' => 'пятница, 21 августа — ЗОЖ-вечер, группа 22–35 лет',
);

require_once __DIR__ . '/notification-sender.php';

function cbLog($message)
{
    @file_put_contents(
        CB_LOG_FILE,
        '[' . date('Y-m-d H:i:s') . '] result: ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function cbRespond($answer)
{
    header('Content-Type: text/plain; charset=utf-8');
    echo $answer;
    exit;
}

function cbParam($name)
{
    if (isset($_POST[$name])) {
        return (string) $_POST[$name];
    }
    if (isset($_GET[$name])) {
        return (string) $_GET[$name];
    }
    return '';
}

function cbAmoRequest($url, $token, $method, array $payload)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ));
    curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array($status, $error);
}

function cbSendMetrikaPayment(array $config, $clientId, $invId, $amount, $currency)
{
    $counterId = isset($config['metrika_counter_id'])
        ? (int) $config['metrika_counter_id']
        : 0;
    $target = isset($config['metrika_payment_goal'])
        ? trim((string) $config['metrika_payment_goal'])
        : '';
    $token = isset($config['metrika_oauth_token'])
        ? trim((string) $config['metrika_oauth_token'])
        : '';

    if (
        $counterId <= 0
        || !preg_match('/^[A-Za-z0-9_-]+$/', $target)
        || $token === ''
        || strpos($token, 'ВСТАВИТЬ') !== false
        || !preg_match('/^\d{5,32}$/', $clientId)
    ) {
        cbLog('Метрика оплаты не настроена или отсутствует ClientID для счёта ' . $invId);
        return false;
    }

    $csvPath = @tempnam(CB_DATA_DIR, 'metrika-');
    if ($csvPath === false) {
        cbLog('Не удалось создать временный файл Метрики для счёта ' . $invId);
        return false;
    }

    $fp = @fopen($csvPath, 'wb');
    if ($fp === false) {
        @unlink($csvPath);
        cbLog('Не удалось открыть временный файл Метрики для счёта ' . $invId);
        return false;
    }
    fputcsv($fp, array('ClientId', 'PurchaseId', 'Target', 'DateTime', 'Price', 'Currency'));
    fputcsv(
        $fp,
        array($clientId, $invId, $target, time() - 5, $amount, $currency)
    );
    fclose($fp);

    $ch = curl_init(
        'https://api-metrika.yandex.net/management/v1/counter/'
        . $counterId . '/offline_conversions/upload'
    );
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => array(
            'file' => new CURLFile($csvPath, 'text/csv', 'payment-conversion.csv'),
        ),
        CURLOPT_HTTPHEADER => array('Authorization: OAuth ' . $token),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ));
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($csvPath);

    $response = is_string($body) ? json_decode($body, true) : null;
    $uploadId = is_array($response) && isset($response['uploading']['id'])
        ? (int) $response['uploading']['id']
        : 0;
    if ($error !== '' || $status < 200 || $status >= 300 || $uploadId <= 0) {
        cbLog(
            'Цель оплаты не передана в Метрику для счёта ' . $invId
            . ' (HTTP ' . $status . ($error !== '' ? ', ' . $error : '') . ')'
        );
        return false;
    }

    cbLog('Цель оплаты передана в Метрику: счёт ' . $invId . ', загрузка ' . $uploadId);
    return true;
}

function cbSendPaymentNotification(
    array $orderData,
    $event,
    $gender,
    $amount,
    $currency,
    $dealUrl,
    $testMode
) {
    $title = $testMode ? 'Оплата получена (тест)' : 'Оплата получена';
    return sendSiteNotification(
        buildDealNotification(
            $title,
            array(
                'name' => isset($orderData['name']) ? (string) $orderData['name'] : '',
                'phone' => isset($orderData['phone']) ? (string) $orderData['phone'] : '',
                'event' => isset(CB_EVENTS[$event]) ? CB_EVENTS[$event] : $event,
                'service' => $gender === 'f' ? 'Женский билет' : 'Мужской билет',
                'amount' => $amount,
                'currency' => $currency,
                'deal_url' => $dealUrl,
            )
        )
    );
}

function cbHandlePaymentNotification(
    $marker,
    array $orderData,
    $event,
    $gender,
    $amount,
    $currency,
    $dealUrl,
    $testMode,
    $invId
) {
    if (file_exists($marker) || $dealUrl === '') {
        return;
    }
    $result = cbSendPaymentNotification(
        $orderData,
        $event,
        $gender,
        $amount,
        $currency,
        $dealUrl,
        $testMode
    );
    if (empty($result['enabled'])) {
        return;
    }
    if (!empty($result['success'])) {
        @file_put_contents(
            $marker,
            json_encode(array('sent_at' => date('c')), JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
        return;
    }
    cbLog(
        'Уведомление об оплате ' . $invId . ' не отправлено: '
        . notificationFailureSummary($result)
    );
}

/* ------------------------------------------------------------------
   Конфигурация
   ------------------------------------------------------------------ */

$config = @include __DIR__ . '/robokassa-config.php';
if (
    !is_array($config)
    || empty($config['merchant_login']) || empty($config['password2'])
    || strpos((string) $config['merchant_login'], 'ВСТАВИТЬ') !== false
    || strpos((string) $config['password2'], 'ВСТАВИТЬ') !== false
    || empty($config['prices']) || !is_array($config['prices'])
) {
    cbLog('robokassa-config.php отсутствует или не заполнен');
    cbRespond('FAIL');
}

/* ------------------------------------------------------------------
   Параметры уведомления и подпись
   ------------------------------------------------------------------ */

$outSum    = cbParam('OutSum');
$invIdRaw  = cbParam('InvId');
$signature = cbParam('SignatureValue');

if ($outSum === '' || $invIdRaw === '' || $signature === '' || !ctype_digit($invIdRaw)) {
    cbLog('Неполное уведомление: InvId=' . $invIdRaw);
    cbRespond('FAIL');
}
$invId = $invIdRaw;

/* Дополнительные параметры входят в подпись в алфавитном порядке
   и приходят обратно ровно теми, что отправил pay.php */
$shpParams = array();
foreach (array_merge($_GET, $_POST) as $name => $value) {
    if (is_string($name) && strncasecmp($name, 'shp_', 4) === 0 && !is_array($value)) {
        $shpParams[$name] = (string) $value;
    }
}
ksort($shpParams, SORT_STRING);
$shpSignaturePart = '';
foreach ($shpParams as $name => $value) {
    $shpSignaturePart .= ':' . $name . '=' . $value;
}

/* SignatureValue = MD5(OutSum:InvId:Пароль#2[:shp_...]) */
$expectedSignature = md5(
    $outSum . ':' . $invId . ':' . (string) $config['password2'] . $shpSignaturePart
);
if (!hash_equals($expectedSignature, strtolower($signature))) {
    cbLog('Неверная подпись для счёта ' . $invId);
    cbRespond('FAIL');
}

/* ------------------------------------------------------------------
   Сверка счёта с сохранённым заказом и серверными ценами
   ------------------------------------------------------------------ */

$leadId = (int) $invId;
$event  = isset($shpParams['shp_event']) ? $shpParams['shp_event'] : '';
$gender = isset($shpParams['shp_gender']) ? $shpParams['shp_gender'] : '';

if ($leadId <= 0 || !isset(CB_EVENTS[$event]) || !isset($config['prices'][$gender])) {
    cbLog('Неизвестная услуга в счёте ' . $invId . ': event=' . $event . ' gender=' . $gender);
    cbRespond('FAIL');
}

$expectedAmount = number_format((float) $config['prices'][$gender], 2, '.', '');
$currency = isset($config['currency']) ? (string) $config['currency'] : 'RUB';
if (number_format((float) $outSum, 2, '.', '') !== $expectedAmount) {
    cbLog(
        'Сумма не совпала для счёта ' . $invId
        . ': получено ' . $outSum . ', ожидалось ' . $expectedAmount
    );
    cbRespond('FAIL');
}
$amount = $expectedAmount;

/* ------------------------------------------------------------------
   Идемпотентность: повторное уведомление не обрабатываем повторно
   ------------------------------------------------------------------ */

if (!is_dir(CB_DATA_DIR)) {
    @mkdir(CB_DATA_DIR, 0755);
    /* Каталог закрыт от чтения через веб — и здесь, и в корневом .htaccess */
    @file_put_contents(
        CB_DATA_DIR . '/.htaccess',
        "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
    );
}

$marker = CB_DATA_DIR . '/paid-' . $invId . '.json';
$metrikaMarker = CB_DATA_DIR . '/metrika-' . $invId . '.json';
$notificationMarker = CB_DATA_DIR . '/notification-' . $invId . '.json';
$orderFile = CB_DATA_DIR . '/order-' . $invId . '.json';
$orderData = array();
if (is_file($orderFile)) {
    $decodedOrder = json_decode((string) @file_get_contents($orderFile), true);
    if (is_array($decodedOrder)) {
        $orderData = $decodedOrder;
    }
}

/* Счёт должен быть выставлен этим сайтом: файл заказа создаёт pay.php */
if ($orderData === array()) {
    cbLog('Нет сохранённого заказа для счёта ' . $invId . ' — уведомление отклонено');
    cbRespond('FAIL');
}

$testMode = !empty($config['test_mode']);
$metrikaClientId = isset($orderData['client_id'])
    && preg_match('/^\d{5,32}$/', (string) $orderData['client_id'])
    ? (string) $orderData['client_id']
    : '';
$amoConfig = @include __DIR__ . '/amo-config.php';
$dealUrl = is_array($amoConfig) && !empty($amoConfig['domain'])
    ? 'https://' . $amoConfig['domain'] . '/leads/detail/' . $leadId
    : '';

if (file_exists($marker)) {
    if (
        !$testMode
        && !file_exists($metrikaMarker)
        && cbSendMetrikaPayment($config, $metrikaClientId, $invId, $amount, $currency)
    ) {
        @file_put_contents($metrikaMarker, json_encode(array('sent_at' => date('c'))), LOCK_EX);
    }
    cbHandlePaymentNotification(
        $notificationMarker,
        $orderData,
        $event,
        $gender,
        $amount,
        $currency,
        $dealUrl,
        $testMode,
        $invId
    );
    cbRespond('OK' . $invId);
}

/* ------------------------------------------------------------------
   Отметка об оплате в amoCRM
   ------------------------------------------------------------------ */

if (!is_array($amoConfig) || empty($amoConfig['domain']) || empty($amoConfig['token'])) {
    cbLog('amo-config.php недоступен — оплата ' . $invId . ' не записана, ждём повтора');
    cbRespond('FAIL');
}
$baseUrl = 'https://' . $amoConfig['domain'];

$noteText = 'ОПЛАТА ПОЛУЧЕНА (Robokassa)' . ($testMode ? ' — ТЕСТОВЫЙ РЕЖИМ' : '')
    . "\nНомер счёта: " . $invId
    . "\nСумма: " . $amount . ' ' . $currency
    . "\nВечер: " . $event . ', билет: ' . ($gender === 'f' ? 'женский' : 'мужской')
    . "\nВремя: " . date('d.m.Y H:i:s');

list($noteStatus, $noteError) = cbAmoRequest(
    $baseUrl . '/api/v4/leads/' . $leadId . '/notes',
    $amoConfig['token'],
    'POST',
    array(array('note_type' => 'common', 'params' => array('text' => $noteText)))
);

if ($noteError !== '' || $noteStatus < 200 || $noteStatus >= 300) {
    cbLog(
        'Примечание об оплате не добавлено к сделке ' . $leadId
        . ' (HTTP ' . $noteStatus . ($noteError !== '' ? ', ' . $noteError : '') . ') — ждём повтора'
    );
    cbRespond('FAIL');
}

/* Оплата зафиксирована в сделке — с этого момента уведомление принято.
   Маркер пишем сразу, чтобы повтор не продублировал примечание */
@file_put_contents(
    $marker,
    json_encode(
        array(
            'inv_id' => $invId,
            'lead_id' => $leadId,
            'amount' => $amount,
            'currency' => $currency,
            'test_mode' => $testMode ? '1' : '0',
            'metrika_client_id' => $metrikaClientId,
            'processed_at' => date('c'),
        ),
        JSON_UNESCAPED_UNICODE
    ),
    LOCK_EX
);

/* Бюджет сделки и, если настроен, статус «Оплачено» — не критично:
   при ошибке фиксируем в лог, но уведомление уже принято */
$leadPatch = array('price' => (int) round((float) $amount));
if (!empty($config['paid_status_id'])) {
    $leadPatch['status_id'] = (int) $config['paid_status_id'];
    if (!empty($config['paid_pipeline_id'])) {
        $leadPatch['pipeline_id'] = (int) $config['paid_pipeline_id'];
    }
}
list($patchStatus, $patchError) = cbAmoRequest(
    $baseUrl . '/api/v4/leads/' . $leadId,
    $amoConfig['token'],
    'PATCH',
    $leadPatch
);
if ($patchError !== '' || $patchStatus < 200 || $patchStatus >= 300) {
    cbLog(
        'Бюджет/статус сделки ' . $leadId . ' не обновлён (HTTP ' . $patchStatus
        . ($patchError !== '' ? ', ' . $patchError : '') . ')'
    );
}

/* Ошибка мессенджера не влияет на подтверждение оплаты. Маркер исключает
   дубли, а повторный callback сможет повторить неудачную отправку. */
cbHandlePaymentNotification(
    $notificationMarker,
    $orderData,
    $event,
    $gender,
    $amount,
    $currency,
    $dealUrl,
    $testMode,
    $invId
);

/* Аналитика не влияет на приём платежа. При повторном callback попытка
   отправки повторится, пока рядом со счётом нет отдельного маркера. */
if (
    !$testMode
    && !file_exists($metrikaMarker)
    && cbSendMetrikaPayment($config, $metrikaClientId, $invId, $amount, $currency)
) {
    @file_put_contents($metrikaMarker, json_encode(array('sent_at' => date('c'))), LOCK_EX);
}

cbLog('Оплата принята: счёт ' . $invId . ', сделка ' . $leadId . ', ' . $amount . ' ' . $currency);
cbRespond('OK' . $invId);
