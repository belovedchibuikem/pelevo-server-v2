<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Open in Pelevo</title>
    <meta name="robots" content="noindex">
    <style>
        :root { color-scheme: dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: system-ui, -apple-system, Segoe UI, sans-serif;
            background: radial-gradient(circle at top left, #5a1020, #050505 55%);
            color: #f5f5f5;
            padding: 24px;
        }
        .card {
            width: min(420px, 100%);
            background: rgba(20, 20, 20, .88);
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 18px;
            padding: 28px 24px;
            text-align: center;
            box-shadow: 0 24px 60px rgba(0,0,0,.45);
        }
        h1 { margin: 0 0 8px; font-size: 1.4rem; }
        p { margin: 0 0 20px; color: #b7b7b7; line-height: 1.45; }
        a.button {
            display: block;
            text-decoration: none;
            background: #1db954;
            color: #fff;
            font-weight: 700;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 10px;
        }
        a.secondary {
            display: block;
            color: #9ad4ff;
            text-decoration: none;
            font-size: .95rem;
            margin-top: 8px;
        }
    </style>
</head>
<body>
@php
    /** @var array{title:?string,https_url:string,app_scheme_url:string,web_path:string} $resolved */
    $title = $resolved['title'] ?? 'this Pelevo share';
    $intentUrl = 'intent://'.ltrim($webPath, '/').'#Intent;scheme=https;package='.$packageId.';S.browser_fallback_url='.rawurlencode($httpsUrl).';end';
@endphp
<div class="card">
    <h1>Open in Pelevo</h1>
    <p>Continue to {{ $title }} in the Pelevo app.</p>
    <a class="button" id="open-app" href="{{ $appSchemeUrl }}">Open episode in app</a>
    <a class="button" href="{{ $intentUrl }}" style="background:#2d6cdf">Open with App Link</a>
    <a class="secondary" href="/">Go to Pelevo home</a>
</div>
<script>
(function () {
    var scheme = @json($appSchemeUrl);
    var https = @json($httpsUrl);
    var started = Date.now();
    // Try the custom scheme first; if the app is not installed, fall through.
    window.location.href = scheme;
    setTimeout(function () {
        if (Date.now() - started < 1600) {
            // Keep the user on this page with App Link / store options instead of looping.
            document.getElementById('open-app')?.focus();
        }
    }, 1200);
})();
</script>
</body>
</html>
