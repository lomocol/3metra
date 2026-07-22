<?php
/* Образец конфигурации уведомлений.
   Скопируйте в notification-config.php рядом с form-handler.php.
   Реальный конфиг не попадает в git и закрыт от чтения через веб. */
return [
    /* off | telegram | max | both */
    'channel' => 'off',

    'telegram' => [
        'bot_token' => 'ВСТАВИТЬ_ТОКЕН_TELEGRAM_БОТА',
        'chat_id' => 'ВСТАВИТЬ_CHAT_ID_TELEGRAM',
    ],

    'max' => [
        'access_token' => 'ВСТАВИТЬ_ТОКЕН_MAX_БОТА',
        'chat_id' => 'ВСТАВИТЬ_CHAT_ID_MAX',
        'api_base' => 'https://platform-api2.max.ru',
    ],
];
