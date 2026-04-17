// Universal HTTP Proxy Worker for ru.site-mane.workers.dev
// Проксирует запросы к любым URL, переданным через параметр 'url'

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const path = url.pathname;
    const method = request.method;

    // Токен для авторизации (из переменных окружения)
    const PROXY_TOKEN = env.PROXY_TOKEN;
    
    // Проверяем авторизацию
    const authToken = url.searchParams.get('token') || 
                      request.headers.get('X-Auth-Token');
    
    if (authToken !== PROXY_TOKEN) {
      return new Response('Unauthorized', { status: 401 });
    }

    // === НОВЫЙ РЕЖИМ: универсальное прокси для любых URL ===
    // Использование: ?url=https://example.com&token=xxx
    const targetUrl = url.searchParams.get('url');
    
    if (targetUrl) {
      try {
        // Декодируем URL
        const decodedUrl = decodeURIComponent(targetUrl);
        
        // Проверяем, что URL безопасен (только http/https)
        if (!decodedUrl.startsWith('http://') && !decodedUrl.startsWith('https://')) {
          return new Response('Bad Request: Invalid URL protocol', { status: 400 });
        }
        
        // Запрещаем доступ к внутренним ресурсам Cloudflare
        const blockedDomains = ['cloudflare.com', 'workers.dev', '.local'];
        for (const domain of blockedDomains) {
          if (decodedUrl.includes(domain)) {
            return new Response('Forbidden: Access to this domain is not allowed', { status: 403 });
          }
        }
        
        // Выполняем запрос к целевой странице
        const response = await fetch(decodedUrl, {
          headers: {
            'User-Agent': 'Mozilla/5.0 (compatible; RSS-Converter/1.0; +https://chuyakov.ru)',
            'Accept': 'application/xml, application/rss+xml, application/json, text/html, */*'
          }
        });
        
        // Получаем содержимое ответа
        const responseBody = await response.arrayBuffer();
        
        // Определяем Content-Type для ответа
        let contentType = response.headers.get('Content-Type') || 'application/octet-stream';
        
        // Для RSS и XML устанавливаем правильный тип
        if (decodedUrl.includes('.xml') || decodedUrl.includes('feeds/videos.xml')) {
          contentType = 'application/rss+xml; charset=utf-8';
        }
        
        // Возвращаем ответ с CORS-заголовками
        return new Response(responseBody, {
          status: response.status,
          headers: {
            'Content-Type': contentType,
            'Access-Control-Allow-Origin': '*',
            'X-Proxied-By': 'Cloudflare-Worker',
            'Cache-Control': 'public, max-age=3600'
          }
        });
        
      } catch (error) {
        return new Response(JSON.stringify({
          error: 'Proxy error',
          message: error.message
        }), {
          status: 500,
          headers: {
            'Content-Type': 'application/json',
            'Access-Control-Allow-Origin': '*'
          }
        });
      }
    }
    
    // === РЕЖИМ TELEGRAM (для обратной совместимости) ===
    let telegramUrl;
    
    if (path.startsWith('/bot')) {
      telegramUrl = `https://api.telegram.org${path}`;
    } 
    else if (path.startsWith('/file/bot')) {
      telegramUrl = `https://api.telegram.org${path}`;
    }
    else {
      // Если нет ни url, ни пути Telegram — возвращаем инструкцию
      return new Response(JSON.stringify({
        message: 'Universal HTTP Proxy is running',
        usage: 'Use ?url=https://example.com&token=YOUR_TOKEN to proxy any URL',
        telegram: 'Use /bot or /file/bot paths for Telegram API'
      }), {
        status: 200,
        headers: {
          'Content-Type': 'application/json',
          'Access-Control-Allow-Origin': '*'
        }
      });
    }

    // Обработка Telegram API (как было раньше)
    const searchParams = url.searchParams;
    searchParams.delete('token');
    const queryString = searchParams.toString();
    const fullUrl = telegramUrl + (queryString ? `?${queryString}` : '');

    const headers = new Headers(request.headers);
    headers.delete('host');
    headers.delete('x-auth-token');

    const fetchOptions = {
      method: method,
      headers: headers,
    };

    if (method !== 'GET' && method !== 'HEAD') {
      fetchOptions.body = request.body;
    }

    try {
      const response = await fetch(fullUrl, fetchOptions);
      
      const responseHeaders = new Headers(response.headers);
      responseHeaders.set('Access-Control-Allow-Origin', '*');
      responseHeaders.set('X-Proxied-By', 'Cloudflare-Worker');
      
      return new Response(response.body, {
        status: response.status,
        statusText: response.statusText,
        headers: responseHeaders
      });
      
    } catch (error) {
      return new Response(JSON.stringify({
        ok: false,
        error_code: 500,
        description: 'Proxy error: ' + error.message
      }), {
        status: 500,
        headers: {
          'Content-Type': 'application/json',
          'Access-Control-Allow-Origin': '*'
        }
      });
    }
  }
};