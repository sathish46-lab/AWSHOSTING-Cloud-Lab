<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Unavailable - Tom Labs</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0a0a0a 0%, #1a1a2e 50%, #0a0a0a 100%);
            color: #e0e0e0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            text-align: center;
            max-width: 480px;
            padding: 2rem;
        }
        .status-code {
            font-size: 6rem;
            font-weight: 800;
            background: linear-gradient(135deg, #ff6b35, #f7c948);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1;
            margin-bottom: 1rem;
        }
        .title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            color: #fff;
        }
        .message {
            font-size: 0.95rem;
            color: #888;
            line-height: 1.6;
            margin-bottom: 2rem;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 500;
            background: rgba(255, 107, 53, 0.1);
            border: 1px solid rgba(255, 107, 53, 0.2);
            color: #ff6b35;
            margin-bottom: 1.5rem;
        }
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #ff6b35;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }
        .brand {
            font-size: 0.8rem;
            color: #555;
            margin-top: 2rem;
        }
        .brand a { color: #ff6b35; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="status-code" id="statusCode">502</div>
        <h1 class="title">Service Temporarily Unavailable</h1>
        <div class="status-badge">
            <span class="status-dot"></span>
            <span id="statusText">Your lab is currently starting or offline</span>
        </div>
        <p class="message">
            The service you're trying to reach is not responding. This usually means the lab instance is being deployed, restarted, or has been stopped.
        </p>
        <p class="message">
            Try refreshing in a few moments. If the problem persists, check your lab status from the dashboard.
        </p>
        <div class="brand">
            Powered by <a href="https://tomlabs.in">Tom Labs</a>
        </div>
    </div>
    <script>
        var hash = window.location.hostname.split('.')[0];
        var code = parseInt(document.getElementById('statusCode').textContent);
        if (hash && hash.length === 32) {
            var link = document.createElement('a');
            link.href = '/labs/dashboard/' + hash;
            link.textContent = 'Go to Lab Dashboard';
            link.style.cssText = 'display:inline-block;padding:0.75rem 1.5rem;background:linear-gradient(135deg,#ff6b35,#f7c948);color:#000;text-decoration:none;border-radius:8px;font-weight:600;font-size:0.9rem;margin-top:0.5rem;';
            document.querySelector('.message').insertAdjacentElement('afterend', link);
        }
    </script>
</body>
</html>
