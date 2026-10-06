<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'AEA Service Operations Hub' }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <style>
        .ls-wrap { min-height: 100vh; display: flex; flex-direction: column; background: #e31e24; }
        .ls-hero {
            position: relative; display: flex; flex-direction: column; justify-content: space-between;
            min-height: 260px; padding: 1.5rem; color: #fff; overflow: hidden;
            background: #18181b url('{{ asset('images/login-hero.jpg') }}') center 30% / cover no-repeat;
        }
        .ls-hero::after {
            content: ''; position: absolute; inset: 0; pointer-events: none;
            background: linear-gradient(180deg, rgba(24, 24, 27, 0.35) 0%, rgba(24, 24, 27, 0) 35%, rgba(24, 24, 27, 0.15) 55%, rgba(24, 24, 27, 0.88) 100%);
        }
        .ls-hero > * { position: relative; z-index: 1; }
        .ls-badge { display: inline-flex; align-items: center; }
        .ls-badge img { height: 3.4rem; width: auto; display: block; filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.35)); }
        .ls-badge span { font-weight: 800; letter-spacing: 0.08em; color: #fff; font-size: 1.6rem; }
        .ls-tag { text-wrap: balance; font-size: 1.6rem; line-height: 1.1; font-weight: 800; letter-spacing: -0.02em; max-width: 20rem; }
        .ls-sub { margin-top: 0.6rem; font-size: 0.85rem; letter-spacing: 0.16em; text-transform: uppercase; color: rgba(255, 255, 255, 0.82); }
        .ls-form {
            position: relative; flex: 1; display: flex; align-items: center; justify-content: center; padding: 2.25rem 1.25rem; color: #fff;
            background: #e31e24 url('{{ asset('images/aea_pattern.png') }}') center / 520px repeat;
            background-blend-mode: soft-light;
        }
        .ls-form::before { content: ''; position: absolute; inset: 0; background: linear-gradient(160deg, rgba(227, 30, 36, 0.92), rgba(168, 21, 26, 0.96)); pointer-events: none; }
        .ls-form > * { position: relative; z-index: 1; }
        .ls-inner { width: 100%; max-width: 24rem; }
        @media (min-width: 1024px) {
            .ls-wrap { flex-direction: row; }
            .ls-hero { flex: 0 0 52%; padding: 2.5rem 3rem; min-height: 100vh; background-position: center 25%; }
            .ls-tag { font-size: 2.4rem; max-width: 26rem; } .ls-badge img { height: 4rem; }
            .ls-form { flex: 1; padding: 3rem; }
        }
    </style>
</head>
<body class="antialiased" style="margin: 0; background: #e31e24">
    <div class="ls-wrap">
        <div class="ls-hero">
            <div>
                <span class="ls-badge">
                    @if (file_exists(public_path('images/aea_logo.svg')))
                        <img src="{{ asset('images/aea_logo.svg') }}" alt="AEA Limited">
                    @else
                        <span>AEA</span>
                    @endif
                </span>
            </div>
            <div>
                <h2 class="ls-tag">Weigh ahead of the rest</h2>
                <p class="ls-sub">Service Operations Hub</p>
            </div>
        </div>

        <div class="ls-form">
            <div class="ls-inner">
                {{ $slot }}
            </div>
        </div>
    </div>

    @livewireScripts
</body>
</html>
