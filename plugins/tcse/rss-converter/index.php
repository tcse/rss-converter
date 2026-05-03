<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSS Converter — YouTube → RSS/JSON через Cloudflare Worker</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            background: #f5f7fb;
            color: #1a1a2e;
            line-height: 1.6;
            padding: 40px 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card {
            background: white;
            border-radius: 20px;
            padding: 28px;
            margin-bottom: 28px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
            transition: box-shadow 0.2s;
        }
        .card:hover {
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        }
        .input-group {
            margin-bottom: 28px;
            border-bottom: 1px solid #edf2f7;
            padding-bottom: 24px;
        }
        .input-group:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .input-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            font-size: 1.1rem;
            color: #1e293b;
            margin-bottom: 12px;
        }
        .input-group label span {
            background: #e2e8f0;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
            font-family: monospace;
            color: #334155;
        }
        .input-wrapper {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }
        .input-wrapper input {
            flex: 2;
            min-width: 200px;
            padding: 12px 16px;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            font-size: 0.95rem;
            font-family: 'Monaco', 'Menlo', monospace;
            transition: 0.2s;
            background: #fafcff;
        }
        .input-wrapper input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }
        .button-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f1f5f9;
            color: #1e293b;
        }
        .btn-rss {
            background: #f97316;
            color: white;
            box-shadow: 0 2px 6px rgba(249,115,22,0.2);
        }
        .btn-rss:hover {
            background: #ea580c;
            transform: translateY(-1px);
        }
        .btn-json {
            background: #3b82f6;
            color: white;
            box-shadow: 0 2px 6px rgba(59,130,246,0.2);
        }
        .btn-json:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }
        .btn:hover {
            transform: translateY(-1px);
        }
        .helper {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 8px;
            margin-left: 4px;
        }
        .badge {
            background: #e2e8f0;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #475569;
        }
        footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            color: #64748b;
            font-size: 0.8rem;
        }
        .example-hint {
            background: #fef9c3;
            border-left: 4px solid #eab308;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 0.8rem;
            margin-bottom: 20px;
        }
        @media (max-width: 640px) {
            .card {
                padding: 20px;
            }
            .input-wrapper {
                flex-direction: column;
                align-items: stretch;
            }
            .button-group {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>
        📡 RSS Converter
        <span class="badge">YouTube → RSS/JSON</span>
    </h1>
    <p style="color: #475569; margin-bottom: 24px;">Введите данные канала, плейлиста или пользователя — и получите живую RSS или JSON ленту через Cloudflare Worker (обход блокировок).</p>

    <div class="example-hint">
        💡 <strong>Где взять ID?</strong> 
        <strong>Channel ID:</strong> со страницы канала (UC...)<br>
        <strong>Playlist ID:</strong> из URL после <code>list=</code> (PL...)<br>
        <strong>Username:</strong> @имя (без @)
    </div>

    <!-- Форма для Channel ID -->
    <div class="card">
        <div class="input-group">
            <label>📺 <strong>Channel ID</strong> <span>YouTube канал</span></label>
            <div class="input-wrapper">
                <input type="text" id="channel_id" placeholder="Например: UCFaU3eGiIdpdNSCuReqoFSA" value="UCFaU3eGiIdpdNSCuReqoFSA">
                <div class="button-group">
                    <button class="btn btn-rss" onclick="generateAndOpen('channel', 'rss')">📡 RSS лента</button>
                    <button class="btn btn-json" onclick="generateAndOpen('channel', 'json')">🔗 JSON лента</button>
                </div>
            </div>
            <div class="helper">RSS/JSON откроются в новой вкладке. Лента включает все видео канала.</div>
        </div>

        <!-- Playlist ID -->
        <div class="input-group">
            <label>🎵 <strong>Playlist ID</strong> <span>YouTube плейлист</span></label>
            <div class="input-wrapper">
                <input type="text" id="playlist_id" placeholder="Например: PLUfT3oHVGZjolj76pX-cq9UpXY5uMu_yz" value="PLUfT3oHVGZjolj76pX-cq9UpXY5uMu_yz">
                <div class="button-group">
                    <button class="btn btn-rss" onclick="generateAndOpen('playlist', 'rss')">📡 RSS лента</button>
                    <button class="btn btn-json" onclick="generateAndOpen('playlist', 'json')">🔗 JSON лента</button>
                </div>
            </div>
            <div class="helper">Для публичных плейлистов. ID берётся из URL: <code>&list=PLxxxxxxxxxxxx</code></div>
        </div>

        <!-- YouTube Username -->
        <div class="input-group">
            <label>👤 <strong>YouTube Username</strong> <span>без @</span></label>
            <div class="input-wrapper">
                <input type="text" id="username" placeholder="Например: VitalyChuyakov" value="VitalyChuyakov">
                <div class="button-group">
                    <button class="btn btn-rss" onclick="generateAndOpen('user', 'rss')">📡 RSS лента</button>
                    <button class="btn btn-json" onclick="generateAndOpen('user', 'json')">🔗 JSON лента</button>
                </div>
            </div>
            <div class="helper">Используется имя канала (то что после youtube.com/@). Скрипт сам найдёт Channel ID.</div>
        </div>
    </div>

    <footer>
        📡 RSS Converter &nbsp;|&nbsp; <a href="https://tcse-cms.com" target="_blank">TCSE-CMS</a> &nbsp;|&nbsp; Работает через Cloudflare Worker + PHP прокси<br>
        Версия 2.0 | YouTube → RSS/JSON | Открытие в новом окне
    </footer>
</div>

<script>
// Базовый URL до вашего youtube.php (автоматически определяется)
const baseUrl = (() => {
    // Получаем текущий путь к папке плагина
    const currentPath = window.location.pathname;
    // Убираем index.php из конца, если есть
    const base = currentPath.replace(/\/index\.php$/, '').replace(/\/$/, '');
    return window.location.origin + base + '/youtube.php';
})();

/**
 * Генерирует ссылку и открывает в новом окне
 * @param {string} type  'channel', 'playlist', 'user'
 * @param {string} format 'rss' или 'json'
 */
function generateAndOpen(type, format) {
    let paramName = '';
    let paramValue = '';

    // Определяем, какое поле читать и какой параметр передавать
    if (type === 'channel') {
        paramName = 'id';
        paramValue = document.getElementById('channel_id').value.trim();
        action = 'channel';
    } else if (type === 'playlist') {
        paramName = 'id';
        paramValue = document.getElementById('playlist_id').value.trim();
        action = 'playlist';
    } else if (type === 'user') {
        paramName = 'name';
        paramValue = document.getElementById('username').value.trim();
        action = 'user';
    }

    if (!paramValue) {
        alert('❌ Пожалуйста, введите значение для ' + 
              (type === 'channel' ? 'Channel ID' : (type === 'playlist' ? 'Playlist ID' : 'Username')));
        return;
    }

    // Экранируем спецсимволы для URL
    const encodedValue = encodeURIComponent(paramValue);
    
    // Формируем правильную ссылку согласно вашему youtube.php
    // Пример: youtube.php?action=channel&id=UC...&format=rss
    const url = `${baseUrl}?action=${action}&${paramName}=${encodedValue}&format=${format}`;
    
    // Открываем в новой вкладке
    window.open(url, '_blank');
}
</script>
</body>
</html>
