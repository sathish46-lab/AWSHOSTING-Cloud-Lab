<?php
/**
 * Error Page Service
 * Called by Traefik errors middleware when backend returns 502/503/504
 * Query param: ?domain=myapp.tomweb.shop
 * Returns: user's custom error page or platform default
 */
require_once __DIR__ . '/../../../src/load.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Error-Page: true');

$domain = $_SERVER['HTTP_HOST'] ?? '';
$backendStatus = $_GET['backend_status'] ?? '502';

if (empty($domain)) {
    serveDefaultPage($backendStatus);
    exit;
}

try {
    $db = DatabaseConnection::getDefaultDatabase();
    
    // Find the lab that owns this domain
    $inst = $db->machine_labs->findOne([
        '$or' => [
            ['code_domain' => $domain],
            ['gui_domain' => $domain],
            ['domains' => $domain],
        ]
    ]);
    
    if ($inst && !empty($inst['custom_error_page'])) {
        // Serve user's custom error page
        echo $inst['custom_error_page'];
    } else {
        serveDefaultPage($backendStatus);
    }
} catch (Exception $e) {
    serveDefaultPage($backendStatus);
}

function serveDefaultPage($statusCode = '502') {
    $statusText = match($statusCode) {
        '502' => 'Bad Gateway',
        '503' => 'Service Unavailable',
        '504' => 'Gateway Timeout',
        default => 'Service Unavailable'
    };
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= $statusCode ?> <?= $statusText ?> - Tom Labs</title>
        <style>
            *{margin:0;padding:0;box-sizing:border-box}
            body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:linear-gradient(135deg,#0a0a0a 0%,#1a1a2e 50%,#0a0a0a 100%);color:#e0e0e0;min-height:100vh;display:flex;align-items:center;justify-content:center}
            .c{text-align:center;max-width:480px;padding:2rem}
            .sc{font-size:6rem;font-weight:800;background:linear-gradient(135deg,#ff6b35,#f7c948);-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1;margin-bottom:1rem}
            .t{font-size:1.5rem;font-weight:600;margin-bottom:.75rem;color:#fff}
            .m{font-size:.95rem;color:#888;line-height:1.6;margin-bottom:2rem}
            .sb{display:inline-flex;align-items:center;gap:.5rem;padding:.5rem 1rem;border-radius:9999px;font-size:.8rem;font-weight:500;background:rgba(255,107,53,.1);border:1px solid rgba(255,107,53,.2);color:#ff6b35;margin-bottom:1.5rem}
            .sd{width:8px;height:8px;border-radius:50%;background:#ff6b35;animation:p 2s infinite}
            @keyframes p{0%,100%{opacity:1}50%{opacity:.4}}
            .b{font-size:.8rem;color:#555;margin-top:2rem}
            .b a{color:#ff6b35;text-decoration:none}
        </style>
    </head>
    <body>
        <div class="c">
            <div class="sc"><?= $statusCode ?></div>
            <h1 class="t"><?= $statusText ?></h1>
            <div class="sb"><span class="sd"></span> Your lab is currently starting or offline</div>
            <p class="m">The service you're trying to reach is not responding. This usually means the lab instance is being deployed, restarted, or has been stopped.</p>
            <p class="m">Try refreshing in a few moments. If the problem persists, check your lab status from the dashboard.</p>
            <div class="b">Powered by <a href="https://tomlabs.in">Tom Labs</a></div>
        </div>
    </body>
    </html>
    <?php
}
