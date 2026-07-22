<?php
/**
 * payanyway-callback.php — Pay URL для уведомлений PayAnyWay
 * (https://docs.moneta.ru/assistant/v1/pay-notification/index.html).
 *
 * Принимает GET или POST. Проверяет MNT_SIGNATURE, номер счёта
 * (MNT_TRANSACTION_ID, сформирован pay.php), ID сделки и сумму по
 * серверному списку цен. После успешной проверки:
 *   - добавляет к сделке amoCRM примечание «Оплата получена»;
 *   - проставляет бюджет сделки (и статус «Оплачено», если задан в конфиге);
 *   - запоминает счёт как обработанный (файл-маркер) — повторное
 *     уведомление отвечает SUCCESS без повторной обработки.
 *
 * Ответы: SUCCESS — принято (или уже обработано), FAIL — ошибка
 * (PayAnyWay будет повторять уведомление в течение суток).
 */

declare(strict_types=1);

ini_set('display_errors', '0');

const CB_LOG_FILE = __DIR__ . '/payanyway.log';
const CB_DATA_DIR = __DIR__ . '/payanyway-data';
const CB_EVENTS = array(
    'jul24' => 'пятница, 24 июля — основная группа',
    'jul25' => 'суббота, 25 июля — старшая группа',
    'jul31' => 'пятница, 31 июля — основная группа',
    'aug1'  => 'суббота, 1 августа — старшая группа',
);

require_once __DIR__ . '/notification-sender.php';

function cbLog($message)
{
    @file_put_contents(
        CB_LOG_FILE,
        '[' . date('Y-m-d H:i:s') . '] callback: ' . $message . PHP_EOL,
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

function cbSendMetrikaPayment(array $config, $clientId, $transactionId, $amount, $currency)
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
        cbLog('Метрика оплаты не настроена или отсутствует ClientID для счёта ' . $transactionId);
        return false;
    }

    $csvPath = @tempnam(CB_DATA_DIR, 'metrika-');
    if ($csvPath === false) {
        cbLog('Не удалось создать временный файл Метрики для счёта ' . $transactionId);
        return false;
    }

    $fp = @fopen($csvPath, 'wb');
    if ($fp === false) {
        @unlink($csvPath);
        cbLog('Не удалось открыть временный файл Метрики для счёта ' . $transactionId);
        return false;
    }
    fputcsv($fp, array('ClientId', 'PurchaseId', 'Target', 'DateTime', 'Price', 'Currency'));
    fputcsv(
        $fp,
        array($clientId, $transactionId, $target, time() - 5, $amount, $currency)
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
            'Цель оплаты не передана в Метрику для счёта ' . $transactionId
            . ' (HTTP ' . $status . ($error !== '' ? ', ' . $error : '') . ')'
        );
        return false;
    }

    cbLog('Цель оплаты передана в Метрику: счёт ' . $transactionId . ', загрузка ' . $uploadId);
    return true;
}

function cbSendPaymentNotification(
    array $orderData,
    $leadId,
    $event,
    $gender,
    $amount,
    $currency,
    $dealUrl,
    $testMode
) {
    $title = $testMode === '1' ? 'Оплата получена (тест)' : 'Оплата получена';
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
    $leadId,
    $event,
    $gender,
    $amount,
    $currency,
    $dealUrl,
    $testMode,
    $transactionId
) {
    if (file_exists($marker) || $dealUrl === '') {
        return;
    }
    $result = cbSendPaymentNotification(
        $orderData,
        $leadId,
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
        'Уведомление об оплате ' . $transactionId . ' не отправлено: '
        . notificationFailureSummary($result)
    );
}

/* ------------------------------------------------------------------
   Конфигурация
   ------------------------------------------------------------------ */

$config = @include __DIR__ . '/payanyway-config.php';
if (
    !is_array($config)
    || empty($config['mnt_id']) || empty($config['integrity_code'])
    || strpos((string) $config['mnt_id'], 'ВСТАВИТЬ') !== false
    || strpos((string) $config['integrity_code'], 'ВСТАВИТЬ') !== false
    || empty($config['prices']) || !is_array($config['prices'])
) {
    cbLog('payanyway-config.php отсутствует или не заполнен');
    cbRespond('FAIL');
}

/* ------------------------------------------------------------------
   Параметры уведомления и подпись
   ------------------------------------------------------------------ */

$mntId         = cbParam('MNT_ID');
$transactionId = cbParam('MNT_TRANSACTION_ID');
$operationId   = cbParam('MNT_OPERATION_ID');
$amount        = cbParam('MNT_AMOUNT');
$currency      = cbParam('MNT_CURRENCY_CODE');
$subscriberId  = cbParam('MNT_SUBSCRIBER_ID');
$testMode      = cbParam('MNT_TEST_MODE');
$signature     = cbParam('MNT_SIGNATURE');

if ($mntId === '' || $transactionId === '' || $amount === '' || $signature === '') {
    cbLog('Неполное уведомление: tid=' . $transactionId);
    cbRespond('FAIL');
}

if ($mntId !== (string) $config['mnt_id']) {
    cbLog('Чужой MNT_ID: ' . $mntId);
    cbRespond('FAIL');
}

/* MNT_SIGNATURE = MD5(MNT_ID + MNT_TRANSACTION_ID + MNT_OPERATION_ID +
   MNT_AMOUNT + MNT_CURRENCY_CODE + MNT_SUBSCRIBER_ID + MNT_TEST_MODE +
   КодПроверкиЦелостности) */
$expectedSignature = md5(
    $mntId . $transactionId . $operationId . $amount . $currency . $subscriberId
    . $testMode . (string) $config['integrity_code']
);
if (!hash_equals($expectedSignature, strtolower($signature))) {
    cbLog('Неверная подпись для счёта ' . $transactionId);
    cbRespond('FAIL');
}

/* Тестовое уведомление в боевом режиме оплатой не считается */
if ($testMode === '1' && empty($config['test_mode'])) {
    cbLog('Отклонено тестовое уведомление в боевом режиме: ' . $transactionId);
    cbRespond('FAIL');
}

/* ------------------------------------------------------------------
   Разбор номера счёта и проверка суммы по серверным ценам
   ------------------------------------------------------------------ */

/* Формат из pay.php: 3M-{leadId}-{event}-{gender}-{ymdHis}-{rand} */
if (!preg_match('/^3M-(\d+)-([a-z0-9]+)-([a-z])-\d{12}-[a-f0-9]{8}$/', $transactionId, $m)) {
    cbLog('Нераспознанный номер счёта: ' . $transactionId);
    cbRespond('FAIL');
}
$leadId = (int) $m[1];
$event  = $m[2];
$gender = $m[3];

if ($leadId <= 0 || !isset($config['prices'][$gender])) {
    cbLog('Неизвестная услуга в счёте ' . $transactionId);
    cbRespond('FAIL');
}

if ($subscriberId !== '' && $subscriberId !== (string) $leadId) {
    cbLog('MNT_SUBSCRIBER_ID не совпадает с ID сделки: ' . $transactionId);
    cbRespond('FAIL');
}

$expectedAmount = number_format((float) $config['prices'][$gender], 2, '.', '');
$expectedCurrency = isset($config['currency']) ? (string) $config['currency'] : 'RUB';
if (number_format((float) $amount, 2, '.', '') !== $expectedAmount || $currency !== $expectedCurrency) {
    cbLog(
        'Сумма не совпала для счёта ' . $transactionId
        . ': получено ' . $amount . ' ' . $currency
        . ', ожидалось ' . $expectedAmount . ' ' . $expectedCurrency
    );
    cbRespond('FAIL');
}

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

$safeTransactionId = preg_replace('/[^A-Za-z0-9_\-]/', '', $transactionId);
$marker = CB_DATA_DIR . '/paid-' . $safeTransactionId . '.json';
$metrikaMarker = CB_DATA_DIR . '/metrika-' . $safeTransactionId . '.json';
$notificationMarker = CB_DATA_DIR . '/notification-' . $safeTransactionId . '.json';
$orderFile = CB_DATA_DIR . '/order-' . $safeTransactionId . '.json';
$orderData = array();
if (is_file($orderFile)) {
    $decodedOrder = json_decode((string) @file_get_contents($orderFile), true);
    if (is_array($decodedOrder)) {
        $orderData = $decodedOrder;
    }
}
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
        $testMode !== '1'
        && !file_exists($metrikaMarker)
        && cbSendMetrikaPayment(
            $config,
            $metrikaClientId,
            $transactionId,
            $amount,
            $currency
        )
    ) {
        @file_put_contents($metrikaMarker, json_encode(array('sent_at' => date('c'))), LOCK_EX);
    }
    cbHandlePaymentNotification(
        $notificationMarker,
        $orderData,
        $leadId,
        $event,
        $gender,
        $amount,
        $currency,
        $dealUrl,
        $testMode,
        $transactionId
    );
    cbRespond('SUCCESS');
}

/* ------------------------------------------------------------------
   Отметка об оплате в amoCRM
   ------------------------------------------------------------------ */

if (!is_array($amoConfig) || empty($amoConfig['domain']) || empty($amoConfig['token'])) {
    cbLog('amo-config.php недоступен — оплата ' . $transactionId . ' не записана, ждём повтора');
    cbRespond('FAIL');
}
$baseUrl = 'https://' . $amoConfig['domain'];

$noteText = 'ОПЛАТА ПОЛУЧЕНА (PayAnyWay)' . ($testMode === '1' ? ' — ТЕСТОВЫЙ РЕЖИМ' : '')
    . "\nНомер счёта: " . $transactionId
    . "\nОперация MONETA: " . ($operationId !== '' ? $operationId : '—')
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
            'transaction_id' => $transactionId,
            'operation_id' => $operationId,
            'lead_id' => $leadId,
            'amount' => $amount,
            'currency' => $currency,
            'test_mode' => $testMode,
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
    $leadId,
    $event,
    $gender,
    $amount,
    $currency,
    $dealUrl,
    $testMode,
    $transactionId
);

/* Аналитика не влияет на приём платежа. При повторном callback попытка
   отправки повторится, пока рядом со счётом нет отдельного маркера. */
if (
    $testMode !== '1'
    && !file_exists($metrikaMarker)
    && cbSendMetrikaPayment(
        $config,
        $metrikaClientId,
        $transactionId,
        $amount,
        $currency
    )
) {
    @file_put_contents($metrikaMarker, json_encode(array('sent_at' => date('c'))), LOCK_EX);
}

cbLog('Оплата принята: ' . $transactionId . ', сделка ' . $leadId . ', ' . $amount . ' ' . $currency);
cbRespond('SUCCESS');
