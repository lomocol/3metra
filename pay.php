<?php
/**
 * pay.php — шаг оплаты после создания заявки в amoCRM.
 *
 * Принимает GET-параметры от script.js:
 *   lead   — ID сделки в amoCRM (создана form-handler.php)
 *   event  — код вечера (jul24, jul25, …) — из серверного списка
 *   gender — код услуги: m (мужской билет) или f (женский)
 *
 * Сумме из браузера не доверяет: цена берётся из payanyway-config.php.
 * Формирует уникальный MNT_TRANSACTION_ID, считает MNT_SIGNATURE по
 * формуле MONETA.Assistant и отдаёт HTML-страницу с формой, которая
 * автоматически отправляется POST на https://www.payanyway.ru/assistant.htm
 * (https://docs.moneta.ru/assistant/v1/payment-request/).
 *
 * Перед редиректом добавляет к сделке примечание с номером счёта — так
 * номер заказа и сумма фиксируются в amoCRM без новых полей.
 */

declare(strict_types=1);

ini_set('display_errors', '0');

const PAY_LOG_FILE = __DIR__ . '/payanyway.log';
const PAY_DATA_DIR = __DIR__ . '/payanyway-data';

/* Список вечеров — должен совпадать с EVENTS в script.js и form-handler.php */
const PAY_EVENTS = array(
    'jul24' => 'пятница, 24 июля — основная группа',
    'jul25' => 'суббота, 25 июля — старшая группа',
    'jul31' => 'пятница, 31 июля — основная группа',
    'aug1'  => 'суббота, 1 августа — старшая группа',
);

const PAY_ASSISTANT_URL = 'https://www.payanyway.ru/assistant.htm';

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

$config = @include __DIR__ . '/payanyway-config.php';
if (
    !is_array($config)
    || empty($config['mnt_id']) || empty($config['integrity_code'])
    || strpos((string) $config['mnt_id'], 'ВСТАВИТЬ') !== false
    || strpos((string) $config['integrity_code'], 'ВСТАВИТЬ') !== false
    || empty($config['prices']) || !is_array($config['prices'])
) {
    payLog('payanyway-config.php отсутствует или не заполнен');
    payFailPage(
        'Оплата временно недоступна',
        'Мы уже знаем о проблеме. Ваша заявка сохранена — мы свяжемся с вами и пришлём ссылку на оплату.'
    );
}

$leadId = isset($_GET['lead']) ? (int) $_GET['lead'] : 0;
$event  = isset($_GET['event']) ? (string) $_GET['event'] : '';
$gender = isset($_GET['gender']) ? (string) $_GET['gender'] : '';

if ($leadId <= 0 || !isset(PAY_EVENTS[$event]) || !isset($config['prices'][$gender])) {
    payLog('Некорректные параметры: lead=' . $leadId . ' event=' . $event . ' gender=' . $gender);
    payFailPage(
        'Не удалось открыть оплату',
        'Ссылка на оплату устарела или неверна. Вернитесь на сайт и отправьте заявку ещё раз.'
    );
}

/* ------------------------------------------------------------------
   Параметры платежа
   ------------------------------------------------------------------ */

$mntId        = (string) $config['mnt_id'];
$currency     = isset($config['currency']) ? (string) $config['currency'] : 'RUB';
$testMode     = !empty($config['test_mode']) ? '1' : '0';
$amount       = number_format((float) $config['prices'][$gender], 2, '.', '');
/* MNT_SUBSCRIBER_ID не отправляем (в подписи — пустая строка): ID сделки
   уже зашит в MNT_TRANSACTION_ID, а лишний необязательный параметр —
   лишний шанс на расхождение подписи на стороне PayAnyWay */
$subscriberId = '';

/* Уникальный номер счёта. Внутри — ID сделки, вечер и код услуги:
   callback восстановит их из номера и проверит сумму без базы данных */
try {
    $rand = bin2hex(random_bytes(4));
} catch (Exception $e) {
    $rand = substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
}
$transactionId = '3M-' . $leadId . '-' . $event . '-' . $gender . '-' . date('ymdHis') . '-' . $rand;

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
$orderFile = PAY_DATA_DIR . '/order-' . $transactionId . '.json';
$orderSaved = @file_put_contents(
    $orderFile,
    json_encode(
        array(
            'transaction_id' => $transactionId,
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
    payLog('Не удалось сохранить аналитику счёта ' . $transactionId);
}

/* MNT_SIGNATURE = MD5(MNT_ID + MNT_TRANSACTION_ID + MNT_AMOUNT +
   MNT_CURRENCY_CODE + MNT_SUBSCRIBER_ID + MNT_TEST_MODE + КодПроверки) */
$signature = md5(
    $mntId . $transactionId . $amount . $currency . $subscriberId . $testMode
    . (string) $config['integrity_code']
);

/* Success/Fail URL — страницы этого же сайта */
$baseUrl = isset($config['base_url']) ? rtrim((string) $config['base_url'], '/') : '';
if ($baseUrl === '') {
    $host = isset($_SERVER['HTTP_HOST'])
        ? (string) preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : '';
    if ($host === '') {
        payLog('Не удалось определить домен для Success/Fail URL');
        payFailPage('Оплата временно недоступна', 'Попробуйте ещё раз чуть позже.');
    }
    $baseUrl = 'https://' . $host;
}

$description = 'Билет на вечер знакомств «3 метра»: ' . PAY_EVENTS[$event]
    . ($gender === 'f' ? ' (женский)' : ' (мужской)');

/* ------------------------------------------------------------------
   Фиксируем выставленный счёт в сделке amoCRM (не блокирует оплату)
   ------------------------------------------------------------------ */

$amoConfig = @include __DIR__ . '/amo-config.php';
if (is_array($amoConfig) && !empty($amoConfig['domain']) && !empty($amoConfig['token'])) {
    $noteText = 'Выставлен счёт PayAnyWay' . ($testMode === '1' ? ' (ТЕСТОВЫЙ РЕЖИМ)' : '')
        . "\nНомер счёта: " . $transactionId
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
    payLog('amo-config.php недоступен — счёт ' . $transactionId . ' не зафиксирован в сделке');
}

/* ------------------------------------------------------------------
   Автоотправляемая форма на платёжную страницу
   ------------------------------------------------------------------ */

$fields = array(
    'MNT_ID'             => $mntId,
    'MNT_TRANSACTION_ID' => $transactionId,
    'MNT_AMOUNT'         => $amount,
    'MNT_CURRENCY_CODE'  => $currency,
    'MNT_TEST_MODE'      => $testMode,
    'MNT_DESCRIPTION'    => $description,
    'MNT_SUCCESS_URL'    => $baseUrl . '/payment-success.html',
    'MNT_FAIL_URL'       => $baseUrl . '/payment-fail.html',
    'MNT_SIGNATURE'      => $signature,
    'moneta.locale'      => 'ru',
);

$inputs = '';
foreach ($fields as $name => $value) {
    $inputs .= '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES)
        . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES) . '">' . "\n";
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Переход к оплате — 3 метра</title>
  <style>
    body { margin: 0; display: grid; place-items: center; min-height: 100vh;
           background: #faf7f2; color: #261c18;
           font: 16px/1.6 "Golos Text", system-ui, sans-serif; padding: 24px; }
    .card { max-width: 440px; text-align: center; background: #fff;
            border: 1px solid rgba(46, 30, 24, 0.12); border-radius: 22px;
            padding: 40px 32px; }
    h1 { font-size: 22px; margin: 0 0 10px; }
    p { margin: 0 0 8px; color: #5d5148; }
    .sum { font-weight: 700; color: #261c18; }
    .spinner { width: 28px; height: 28px; margin: 18px auto 0; border-radius: 50%;
               border: 3px solid rgba(160, 31, 60, 0.2); border-top-color: #a01f3c;
               animation: spin 0.9s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
    button { margin-top: 18px; background: #a01f3c; color: #fff; border: 0;
             padding: 13px 26px; border-radius: 999px; font: 600 16px "Golos Text",
             system-ui, sans-serif; cursor: pointer; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Переходим к оплате…</h1>
    <p><?php echo htmlspecialchars($description, ENT_QUOTES); ?></p>
    <p class="sum"><?php echo htmlspecialchars($amount, ENT_QUOTES); ?> ₽<?php
        echo $testMode === '1' ? ' · тестовый режим' : ''; ?></p>
    <form id="paw" method="post" action="<?php echo htmlspecialchars(PAY_ASSISTANT_URL, ENT_QUOTES); ?>">
      <?php echo $inputs; ?>
      <noscript><button type="submit">Перейти к оплате</button></noscript>
    </form>
    <div class="spinner" aria-hidden="true"></div>
  </div>
  <script>document.getElementById('paw').submit();</script>
</body>
</html>
