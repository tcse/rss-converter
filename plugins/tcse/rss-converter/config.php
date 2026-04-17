<?php
// /plugins/tcse/rss-converter/config.php

return [
    // Настройки прокси Cloudflare Worker
    'proxy' => [
        'enabled' => true,
        'url' => 'https://ru.site-name.workers.dev',
        'token' => '1q2w3e4r5t6y7u8i9o0psdxbaja4gdbh44sjcjhsbfr'
    ],
    
    // Кеширование
    'cache' => [
        'enabled' => true,
        'dir' => __DIR__ . '/cache',
        'ttl' => 3600, // 1 час
    ],
    
    // Настройки YouTube
    'youtube' => [
        'user_agent' => 'Mozilla/5.0 (compatible; RSS-Converter/1.0; +https://site-name.ru)'
    ]
];