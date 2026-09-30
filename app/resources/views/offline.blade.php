<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b57d0">
    <title>Offline — {{ config('app.name', 'Simple ERP') }}</title>
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; background: #f9f9ff; color: #191c20;
               display: grid; place-items: center; min-height: 100dvh; }
        .card { background: #f0f3fa; border-radius: 1rem; padding: 2rem; max-width: 24rem;
                margin: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,.15); text-align: center; }
        .badge { display: inline-block; background: #f9dedc; color: #410e0b; border-radius: 999px;
                 padding: .25rem .75rem; font-size: .75rem; font-weight: 600; }
        button { margin-top: 1.25rem; min-height: 44px; padding: 0 1.5rem; border: 0; border-radius: 999px;
                 background: #0b57d0; color: #fff; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <span class="badge">OFFLINE</span>
        <h1 style="font-size: 1.25rem; margin: .75rem 0 .5rem;">You're offline</h1>
        <p style="color: #44474e; line-height: 1.5;">
            This screen needs the network. Data you saved on this device will
            synchronize automatically once you're back online.
        </p>
        <button onclick="location.reload()">Try again</button>
    </div>
</body>
</html>
