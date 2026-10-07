<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Secretlab Key-Value Store — API</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui.css">
    <style>
        body { margin: 0; background: #fafafa; }
        .topbar { display: none; }
        .demo-key {
            font: 14px/1.5 system-ui, sans-serif;
            background: #1b1b1f; color: #e7e7ea;
            padding: 14px 20px;
        }
        .demo-key code {
            background: #2f2f36; padding: 2px 6px; border-radius: 3px;
            font-family: ui-monospace, monospace;
        }
    </style>
</head>
<body>
<div class="demo-key">
    Public demo key for the <strong>Authorize</strong> button:
    <code>{{ \Database\Seeders\DemoApiClientSeeder::DEMO_KEY }}</code>
    &mdash; optional. Without it you are rate-limited by IP address.
</div>

<div id="swagger-ui"></div>

<script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-bundle.js" crossorigin></script>
<script>
    window.ui = SwaggerUIBundle({
        url: '/openapi.yaml',
        dom_id: '#swagger-ui',
        deepLinking: true,
        displayRequestDuration: true,
        tryItOutEnabled: true,
        defaultModelsExpandDepth: 1,
        presets: [SwaggerUIBundle.presets.apis],
    });
</script>
</body>
</html>
