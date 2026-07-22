<?php
/**
 * Общий best-effort отправщик служебных уведомлений в Telegram и MAX.
 * Возвращает результат каждого канала, но никогда не завершает запрос.
 */

declare(strict_types=1);

function notificationPostJson($url, array $headers, array $payload)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
    ));
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return array($status, is_string($body) ? $body : '', $error);
}

function notificationTelegram(array $config, $message)
{
    $token = isset($config['bot_token']) ? trim((string) $config['bot_token']) : '';
    $chatId = isset($config['chat_id']) ? trim((string) $config['chat_id']) : '';
    if (
        $token === '' || $chatId === ''
        || strpos($token, 'ВСТАВИТЬ') !== false
        || strpos($chatId, 'ВСТАВИТЬ') !== false
    ) {
        return array(false, 'не заполнены bot_token или chat_id');
    }

    list($status, $body, $error) = notificationPostJson(
        'https://api.telegram.org/bot' . $token . '/sendMessage',
        array('Content-Type: application/json'),
        array('chat_id' => $chatId, 'text' => $message)
    );
    $response = json_decode($body, true);
    if (
        $error !== '' || $status < 200 || $status >= 300
        || !is_array($response) || empty($response['ok'])
    ) {
        return array(false, $error !== '' ? $error : 'HTTP ' . $status);
    }

    return array(true, '');
}

function notificationMax(array $config, $message)
{
    $token = isset($config['access_token']) ? trim((string) $config['access_token']) : '';
    $chatId = isset($config['chat_id']) ? trim((string) $config['chat_id']) : '';
    $apiBase = isset($config['api_base'])
        ? rtrim(trim((string) $config['api_base']), '/')
        : 'https://platform-api2.max.ru';
    if (
        $token === '' || $chatId === ''
        || strpos($token, 'ВСТАВИТЬ') !== false
        || strpos($chatId, 'ВСТАВИТЬ') !== false
    ) {
        return array(false, 'не заполнены access_token или chat_id');
    }
    if ($apiBase === '' || stripos($apiBase, 'https://') !== 0) {
        return array(false, 'некорректный api_base');
    }

    list($status, $body, $error) = notificationPostJson(
        $apiBase . '/messages?chat_id=' . rawurlencode($chatId),
        array(
            'Authorization: ' . $token,
            'Content-Type: application/json',
        ),
        array('text' => $message)
    );
    $response = json_decode($body, true);
    if (
        $error !== '' || $status < 200 || $status >= 300
        || !is_array($response) || empty($response['message'])
    ) {
        return array(false, $error !== '' ? $error : 'HTTP ' . $status);
    }

    return array(true, '');
}

function sendSiteNotification($message)
{
    try {
        $config = @include __DIR__ . '/notification-config.php';
    } catch (Throwable $error) {
        return array(
            'enabled' => true,
            'success' => false,
            'results' => array('config' => array(false, 'ошибка PHP-конфига')),
        );
    }
    if (!is_array($config)) {
        return array('enabled' => false, 'success' => true, 'results' => array());
    }

    $channel = isset($config['channel']) ? strtolower(trim((string) $config['channel'])) : 'off';
    if ($channel === '' || $channel === 'off') {
        return array('enabled' => false, 'success' => true, 'results' => array());
    }

    $providers = array();
    if ($channel === 'telegram' || $channel === 'both') {
        $providers[] = 'telegram';
    }
    if ($channel === 'max' || $channel === 'both') {
        $providers[] = 'max';
    }
    if ($providers === array()) {
        return array(
            'enabled' => true,
            'success' => false,
            'results' => array('config' => array(false, 'неизвестный канал')),
        );
    }

    $results = array();
    $success = true;
    foreach ($providers as $provider) {
        $providerConfig = isset($config[$provider]) && is_array($config[$provider])
            ? $config[$provider]
            : array();
        $result = $provider === 'telegram'
            ? notificationTelegram($providerConfig, $message)
            : notificationMax($providerConfig, $message);
        $results[$provider] = $result;
        if (empty($result[0])) {
            $success = false;
        }
    }

    return array('enabled' => true, 'success' => $success, 'results' => $results);
}

function notificationFailureSummary(array $result)
{
    $errors = array();
    if (isset($result['results']) && is_array($result['results'])) {
        foreach ($result['results'] as $provider => $providerResult) {
            if (is_array($providerResult) && empty($providerResult[0])) {
                $errors[] = $provider . ': ' . (isset($providerResult[1]) ? $providerResult[1] : 'ошибка');
            }
        }
    }
    return implode('; ', $errors);
}

function notificationAmount($amount, $currency)
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    $number = (float) $amount;
    $decimals = abs($number - round($number)) < 0.00001 ? 0 : 2;
    $formatted = number_format($number, $decimals, ',', ' ');
    return $formatted . ((string) $currency === 'RUB' ? ' ₽' : ' ' . (string) $currency);
}

function buildDealNotification($title, array $data)
{
    return implode("\n", array(
        (string) $title,
        '',
        'Имя: ' . (isset($data['name']) && $data['name'] !== '' ? $data['name'] : '—'),
        'Телефон: ' . (isset($data['phone']) && $data['phone'] !== '' ? $data['phone'] : '—'),
        'Дата мероприятия: ' . (isset($data['event']) && $data['event'] !== '' ? $data['event'] : '—'),
        'Услуга: ' . (isset($data['service']) && $data['service'] !== '' ? $data['service'] : '—'),
        'Сумма: ' . notificationAmount(
            array_key_exists('amount', $data) ? $data['amount'] : null,
            isset($data['currency']) ? $data['currency'] : 'RUB'
        ),
        'Сделка amoCRM: ' . (isset($data['deal_url']) ? $data['deal_url'] : '—'),
    ));
}
