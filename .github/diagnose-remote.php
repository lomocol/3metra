<?php
/* Временная диагностика уведомлений. Запускается по SSH из GitHub Actions,
   секретов не печатает: только канал, длины/префиксы значений и строки
   лога об ошибках отправки (в них нет персональных данных). */

$path = isset($argv[1]) ? $argv[1] : '';
if ($path === '' || !is_dir($path)) {
    echo "site path not found\n";
    exit(1);
}
chdir($path);

echo "=== notification-config.php\n";
$config = @include $path . '/notification-config.php';
if (!is_array($config)) {
    echo "config: missing or not an array\n";
} else {
    echo 'channel: ', isset($config['channel']) ? $config['channel'] : '(none)', "\n";
    foreach (array('telegram', 'max') as $provider) {
        if (!isset($config[$provider]) || !is_array($config[$provider])) {
            echo $provider, ": section missing\n";
            continue;
        }
        foreach ($config[$provider] as $key => $value) {
            $value = (string) $value;
            echo $provider, '.', $key, ': len=', strlen($value),
                ' prefix=', substr($value, 0, 4), "...\n";
        }
    }
}

foreach (array('https://api.telegram.org/', 'https://botapi.max.ru/') as $url) {
    echo "=== connectivity ", $url, "\n";
    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
    ));
    curl_exec($ch);
    echo 'http=', (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        ' err=', curl_error($ch),
        ' time=', round(microtime(true) - $start, 2), "s\n";
    curl_close($ch);
}

echo "=== form-handler.log: строки об ошибках уведомлений (последние 20)\n";
$lines = @file($path . '/form-handler.log');
if (!is_array($lines)) {
    echo "log missing or unreadable\n";
} else {
    $matches = array();
    foreach ($lines as $line) {
        if (strpos($line, 'не отправлено') !== false) {
            $matches[] = $line;
        }
    }
    if ($matches === array()) {
        echo "(строк об ошибках уведомлений нет; всего строк в логе: ", count($lines), ")\n";
    } else {
        foreach (array_slice($matches, -20) as $line) {
            echo $line;
        }
    }
}
