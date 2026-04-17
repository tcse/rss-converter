<?php
/**
 * YouTube RSS/JSON Converter
 * 
 * Использование:
 *   ?action=playlist&id=PLUfT3oHVGZjolj76pX-cq9UpXY5uMu_yz&format=rss
 *   ?action=channel&id=UCFaU3eGiIdpdNSCuReqoFSA&format=json
 *   ?action=user&name=VitalyChuyakov&format=rss
 * 
 * Форматы: rss (по умолчанию), json
 */

// Загружаем конфигурацию
$config = require __DIR__ . '/config.php';

// Получаем параметры запроса
$action = $_GET['action'] ?? '';
$format = strtolower($_GET['format'] ?? 'rss');

// Проверяем формат вывода
if (!in_array($format, ['rss', 'json'])) {
    $format = 'rss';
}

// Определяем параметры в зависимости от действия
$youtubeUrl = '';
$cacheKey = '';

switch ($action) {
    case 'playlist':
        $playlistId = $_GET['id'] ?? '';
        if (empty($playlistId)) {
            die('Error: playlist id is required');
        }
        // Очищаем ID от лишних символов
        $playlistId = preg_replace('/[^a-zA-Z0-9_-]/', '', $playlistId);
        $youtubeUrl = "https://www.youtube.com/feeds/videos.xml?playlist_id=" . $playlistId;
        $cacheKey = "youtube_playlist_{$playlistId}_{$format}";
        break;
        
    case 'channel':
        $channelId = $_GET['id'] ?? '';
        if (empty($channelId)) {
            die('Error: channel id is required');
        }
        $youtubeUrl = "https://www.youtube.com/feeds/videos.xml?channel_id=" . urlencode($channelId);
        $cacheKey = "youtube_channel_{$channelId}_{$format}";
        break;
        
    case 'user':
        $username = $_GET['name'] ?? '';
        if (empty($username)) {
            die('Error: username is required');
        }
        // Сначала получаем channel_id по username (через прокси)
        $channelId = getChannelIdByUsername($username, $config);
        if (!$channelId) {
            die('Error: user not found');
        }
        $youtubeUrl = "https://www.youtube.com/feeds/videos.xml?channel_id=" . urlencode($channelId);
        $cacheKey = "youtube_user_{$username}_{$format}";
        break;
        
    default:
        die('Error: unknown action. Use: playlist, channel, user');
}

// Проверяем кеш
$cacheFile = $config['cache']['dir'] . '/' . md5($cacheKey) . '.cache';
$cacheTTL = $config['cache']['ttl'];

if ($config['cache']['enabled'] && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
    $output = file_get_contents($cacheFile);
    setHeaders($format);
    echo $output;
    exit;
}

// Получаем данные через прокси
$xmlContent = fetchViaProxy($youtubeUrl, $config);
if (!$xmlContent) {
    die('Error: failed to fetch data from YouTube');
}

// Парсим XML и конвертируем в нужный формат
$items = parseYouTubeXML($xmlContent);

if ($format === 'json') {
    $output = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    $output = generateRSS($items, $action, $_GET, $config);
}

// Сохраняем в кеш
if ($config['cache']['enabled']) {
    if (!is_dir($config['cache']['dir'])) {
        mkdir($config['cache']['dir'], 0755, true);
    }
    file_put_contents($cacheFile, $output);
}

// Отдаем результат
setHeaders($format);
echo $output;

/**
 * Отправляет HTTP-заголовки в зависимости от формата
 */
function setHeaders($format) {
    if ($format === 'json') {
        header('Content-Type: application/json; charset=UTF-8');
    } else {
        header('Content-Type: application/rss+xml; charset=UTF-8');
    }
    header('Cache-Control: public, max-age=3600');
}

/**
 * Получает channel_id по username через прокси
 */
function getChannelIdByUsername($username, $config) {
    $url = "https://www.youtube.com/@{$username}";
    $html = fetchViaProxy($url, $config);
    
    if (!$html) {
        return false;
    }
    
    // Ищем channel_id в HTML
    if (preg_match('/"channelId":"([^"]+)"/', $html, $matches)) {
        return $matches[1];
    }
    
    if (preg_match('/https:\/\/www\.youtube\.com\/channel\/([^"\?]+)/', $html, $matches)) {
        return $matches[1];
    }
    
    return false;
}

/**
 * Выполняет запрос через Cloudflare Worker прокси
 */
function fetchViaProxy($url, $config) {
    $proxyConfig = $config['proxy'];
    
    if ($proxyConfig['enabled']) {
        // Используем новый Worker ru.chuyakov.workers.dev
        $proxyUrl = 'https://ru.chuyakov.workers.dev';
        $proxyToken = $proxyConfig['token'];
        
        // Формируем URL для универсального прокси
        $requestUrl = $proxyUrl . '/?url=' . urlencode($url) . 
                      '&token=' . urlencode($proxyToken);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $requestUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $config['youtube']['user_agent']);
        
        // Для отладки
        if ($config['debug'] ?? false) {
            error_log("RSS-Converter: Requesting " . $requestUrl);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            error_log("RSS-Converter: Proxy error HTTP $httpCode, URL: $requestUrl");
            error_log("RSS-Converter: Response: " . substr($response, 0, 500));
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        return $response;
        
    } else {
        // Прямой запрос (резервный вариант)
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $config['youtube']['user_agent']);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return $response;
    }
}

/**
 * Парсит XML от YouTube и возвращает массив элементов
 */
function parseYouTubeXML($xmlContent) {
    $items = [];
    
    $xml = simplexml_load_string($xmlContent);
    if (!$xml) {
        return $items;
    }
    
    // Регистрируем пространства имен
    $namespaces = $xml->getNamespaces(true);
    $mediaNs = $namespaces['media'] ?? 'http://search.yahoo.com/mrss/';
    $ytNs = $namespaces['yt'] ?? 'http://www.youtube.com/xml/schemas/2015';
    
    foreach ($xml->entry as $entry) {
        // Получаем media:group
        $mediaGroup = $entry->children($mediaNs)->group;
        
        // Получаем videoId
        $videoId = (string)$entry->children($ytNs)->videoId;
        
        // Формируем ссылку на видео
        $link = 'https://www.youtube.com/watch?v=' . $videoId;
        
        // Получаем превью (из media:thumbnail)
        $thumbnail = '';
        if ($mediaGroup && $mediaGroup->thumbnail) {
            $thumbnail = (string)$mediaGroup->thumbnail->attributes()->url;
        }
        
        // Если нет через media:thumbnail, используем стандартный URL YouTube
        if (empty($thumbnail) && $videoId) {
            $thumbnail = "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
        }
        
        // Получаем описание
        $description = '';
        if ($mediaGroup && $mediaGroup->description) {
            $description = (string)$mediaGroup->description;
        }
        
        // Получаем статистику
        $views = 0;
        if ($mediaGroup && $mediaGroup->community && $mediaGroup->community->statistics) {
            $views = (int)$mediaGroup->community->statistics->attributes()->views;
        }
        
        $items[] = [
            'id' => $videoId,
            'title' => (string)$entry->title,
            'link' => $link,
            'description' => $description,
            'content' => $description,
            'pubDate' => date('D, d M Y H:i:s O', strtotime((string)$entry->published)),
            'author' => (string)$entry->author->name,
            'thumbnail' => $thumbnail,
            'views' => $views,
            'video_id' => $videoId
        ];
    }
    
    return $items;
}

/**
 * Генерирует RSS-ленту из массива элементов
 */
function generateRSS($items, $action, $params, $config) {
    $siteUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    $currentUrl = $siteUrl . $_SERVER['REQUEST_URI'];
    
    $channelTitle = 'YouTube Feed';
    if ($action === 'playlist' && isset($params['id'])) {
        $channelTitle = 'YouTube Playlist: ' . $params['id'];
    } elseif ($action === 'channel' && isset($params['id'])) {
        $channelTitle = 'YouTube Channel: ' . $params['id'];
    } elseif ($action === 'user' && isset($params['name'])) {
        $channelTitle = 'YouTube User: ' . $params['name'];
    }
    
    $rss = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $rss .= '<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
    $rss .= '  <channel>' . "\n";
    $rss .= '    <title>' . htmlspecialchars($channelTitle) . '</title>' . "\n";
    $rss .= '    <link>' . htmlspecialchars($siteUrl) . '</link>' . "\n";
    $rss .= '    <description>YouTube videos feed from RSS Converter</description>' . "\n";
    $rss .= '    <language>ru-ru</language>' . "\n";
    $rss .= '    <atom:link href="' . htmlspecialchars($currentUrl) . '" rel="self" type="application/rss+xml" />' . "\n";
    
    foreach ($items as $item) {
        // Формируем описание с кликабельной картинкой (как в RSS-Bridge)
        $descriptionHtml = '';
        if (!empty($item['thumbnail'])) {
            $descriptionHtml .= '<a href="' . htmlspecialchars($item['link']) . '">';
            $descriptionHtml .= '<img src="' . htmlspecialchars($item['thumbnail']) . '" alt="' . htmlspecialchars($item['title']) . '" />';
            $descriptionHtml .= '</a><br />';
        }
        if (!empty($item['description'])) {
            $descriptionHtml .= '<p>' . nl2br(htmlspecialchars($item['description'])) . '</p>';
        }
        
        $rss .= '    <item>' . "\n";
        $rss .= '      <title>' . htmlspecialchars($item['title']) . '</title>' . "\n";
        $rss .= '      <link>' . htmlspecialchars($item['link']) . '</link>' . "\n";
        $rss .= '      <guid isPermaLink="false">' . htmlspecialchars($item['link']) . '</guid>' . "\n";
        $rss .= '      <pubDate>' . $item['pubDate'] . '</pubDate>' . "\n";
        $rss .= '      <description><![CDATA[' . $descriptionHtml . ']]></description>' . "\n";
        $rss .= '      <author>' . htmlspecialchars($item['author']) . '</author>' . "\n";
        
        // Оставляем media:thumbnail для совместимости
        if (!empty($item['thumbnail'])) {
            $rss .= '      <media:thumbnail url="' . htmlspecialchars($item['thumbnail']) . '" />' . "\n";
        }
        
        $rss .= '    </item>' . "\n";
    }
    
    $rss .= '  </channel>' . "\n";
    $rss .= '</rss>';
    
    return $rss;
}
?>