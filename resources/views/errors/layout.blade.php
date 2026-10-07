<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }} | AEA Service Operations Hub</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem;
               background: #f4f4f5; color: #18181b; font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        .box { width: 100%; max-width: 26rem; background: #fff; border-radius: 1.25rem; overflow: hidden; text-align: center;
               box-shadow: 0 10px 30px rgba(0,0,0,.08); border: 1px solid #e4e4e7; }
        .bar { height: 5px; background: #e31e24; }
        .in { padding: 2rem 1.5rem 1.75rem; }
        .code { font-size: 3.25rem; font-weight: 800; line-height: 1; color: #e31e24; }
        h1 { margin: .75rem 0 .5rem; font-size: 1.25rem; }
        p { margin: 0 0 1.25rem; color: #52525b; font-size: .9375rem; line-height: 1.5; }
        .row { display: flex; gap: .5rem; justify-content: center; flex-wrap: wrap; }
        a, button { font: inherit; font-weight: 700; font-size: .875rem; text-decoration: none; cursor: pointer; border-radius: .75rem; padding: .7rem 1.1rem; min-height: 44px; }
        .p { background: #e31e24; color: #fff; border: 0; }
        .s { background: #fff; color: #3f3f46; border: 1px solid #d4d4d8; }
    </style>
</head>
<body>
    <div class="box">
        <div class="bar"></div>
        <div class="in">
            <div class="code">{{ $code }}</div>
            <h1>{{ $heading }}</h1>
            <p>{{ $text }}</p>
            <div class="row">
                @if (! empty($reload))
                    <button class="p" type="button" onclick="location.reload()">Reload page</button>
                @else
                    <a class="p" href="{{ auth()->check() ? url('/dashboard') : url('/login') }}">{{ auth()->check() ? 'Go to dashboard' : 'Go to sign in' }}</a>
                @endif
                <button class="s" type="button" onclick="history.length > 1 ? history.back() : (location.href = '/')">Go back</button>
            </div>
        </div>
    </div>
</body>
</html>
