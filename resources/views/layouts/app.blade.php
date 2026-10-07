<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'AEA Service Operations Hub' }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="{{ asset('js/location-picker.js') }}"></script>
    <style>
        /* rf: service report form */
        .rf { max-width: 42rem; margin: 0 auto; }
        .rf .input { font-size: 16px; line-height: 1.4; padding: 0.75rem 0.875rem; border-radius: 0.75rem; background-color: #fff; }
        .rf input.input, .rf select.input { min-height: 48px; }
        .rf .input:disabled { background-color: #f4f4f5; color: #52525b; }
        .rf .label { font-size: 0.8125rem; font-weight: 600; color: #3f3f46; margin-bottom: 0.375rem; }
        .rf-opt { font-weight: 500; color: #a1a1aa; margin-left: 0.25rem; }
        .rf-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
        .rf-grid2 { display: grid; gap: 0.75rem; grid-template-columns: 1fr 1fr; }

        .rf-hero { position: relative; overflow: hidden; background: #18181b; color: #fff; border-radius: 1.25rem; padding: 1.25rem 1.125rem 1.125rem; }
        .rf-hero::before { content: ''; position: absolute; left: 0; right: 0; top: 0; height: 4px; background: #f5a623; }
        .rf-hero-label { font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.07em; color: #a1a1aa; }
        .rf-hero-no { font-size: 2rem; font-weight: 800; line-height: 1.1; color: #f5a623; margin-top: 0.125rem; }
        .rf-hero-grid { display: grid; gap: 0.875rem 1rem; grid-template-columns: 1fr 1fr; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #3f3f46; }
        .rf-hero-val { font-size: 0.9375rem; font-weight: 700; margin-top: 0.125rem; word-break: break-word; }
        .rf-status { flex-shrink: 0; background: rgba(245, 166, 35, 0.18); color: #f5a623; border-radius: 999px; padding: 0.3125rem 0.75rem; font-size: 0.75rem; font-weight: 700; }

        .rf-sec { background: #fff; border: 1px solid rgba(228, 228, 231, 0.9); border-radius: 1.25rem; box-shadow: var(--shadow-card); overflow: hidden; }
        .rf-sec-head { display: flex; align-items: center; gap: 0.75rem; padding: 0.875rem 1.125rem; background: #fafafa; border-bottom: 1px solid #f0f0f2; }
        .rf-badge { display: flex; align-items: center; justify-content: center; flex-shrink: 0; width: 2.125rem; height: 2.125rem; border-radius: 0.75rem; background: #fef2f2; color: #a8151a; font-size: 0.875rem; font-weight: 800; }
        .rf-sec-title { font-size: 0.9375rem; font-weight: 700; color: #18181b; line-height: 1.2; }
        .rf-sec-sub { font-size: 0.75rem; color: #71717a; margin-top: 0.125rem; }
        .rf-body { display: flex; flex-direction: column; gap: 1rem; padding: 1.125rem; }

        .rf-chips { display: grid; grid-template-columns: 1fr 1fr; gap: 0.625rem; }
        .rf-chip { display: flex; align-items: center; gap: 0.625rem; min-height: 56px; padding: 0.75rem 0.875rem; border: 1.5px solid #e4e4e7; border-radius: 0.875rem; background: #fff; font-size: 0.9375rem; font-weight: 600; line-height: 1.2; color: #3f3f46; cursor: pointer; }
        .rf-chip input { flex-shrink: 0; width: 1.25rem; height: 1.25rem; accent-color: #e31e24; }
        .rf-chip-on { border-color: #e31e24; background: #fef2f2; color: #a8151a; }

        .rf-part { border: 1px solid #e4e4e7; border-radius: 1rem; background: #fafafa; padding: 0.875rem; }
        .rf-part-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: #71717a; }
        .rf-add { min-height: 48px; border: 1.5px dashed #d4d4d8; border-radius: 0.875rem; background: #fff; font-size: 0.9375rem; font-weight: 700; color: #a8151a; cursor: pointer; }
        .rf-add:hover { background: #fef2f2; border-color: #e31e24; }
        .rf-remove { font-size: 0.8125rem; font-weight: 700; color: #71717a; padding: 0.375rem 0.625rem; border-radius: 0.5rem; cursor: pointer; text-transform: none; letter-spacing: 0; }
        .rf-remove:hover { color: #a71d2a; background: #fbe9eb; }

        .rf-drop { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.25rem; min-height: 124px; padding: 1.25rem 1rem; text-align: center; border: 2px dashed #d4d4d8; border-radius: 1rem; background: #fafafa; cursor: pointer; }
        .rf-drop:hover { background: #fef2f2; border-color: #e31e24; }
        .rf-drop-title { font-size: 0.9375rem; font-weight: 700; color: #18181b; }
        .rf-drop-sub { font-size: 0.8125rem; color: #71717a; }
        .rf-file { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.75rem 0.875rem; border: 1px solid #aef5a3; border-radius: 0.875rem; background: #eefdec; min-width: 0; }
        .rf-note { padding: 0.875rem 1rem; border-radius: 0.875rem; background: #f4f4f5; font-size: 0.875rem; color: #52525b; }

        .rf-sign { position: relative; overflow: hidden; border: 2px dashed #d4d4d8; border-radius: 1rem; background: #fff; }
        .rf-sign-line { position: absolute; left: 1.25rem; right: 1.25rem; bottom: 2.25rem; height: 1px; background: #d4d4d8; z-index: 0; }
        .rf-sign-hint { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; text-align: center; padding: 0 1rem; font-size: 0.9375rem; color: #a1a1aa; pointer-events: none; z-index: 2; }
        .rf-sign-sub { font-size: 0.75rem; color: #a1a1aa; }
        .rf-signed { font-size: 0.8125rem; font-weight: 700; color: #189913; }

        .rf-err { margin-top: 0.375rem; font-size: 0.8125rem; font-weight: 600; color: #a71d2a; }

        .rf-bar { position: sticky; bottom: 0; z-index: 20; margin: 1rem -1rem 0; padding: 0.75rem 1rem calc(0.75rem + env(safe-area-inset-bottom, 0px)); background: rgba(255, 255, 255, 0.96); backdrop-filter: blur(8px); border-top: 1px solid #e4e4e7; }
        .rf-bar-hint { margin-bottom: 0.5rem; text-align: center; font-size: 0.75rem; color: #71717a; }
        .rf-bar-row { display: flex; gap: 0.625rem; }
        .rf-bar .btn-outline, .rf-bar .btn-primary { min-height: 50px; font-size: 0.9375rem; font-weight: 700; }
        @media (min-width: 768px) {
            .rf-bar { margin: 1rem 0 0; border: 1px solid #e4e4e7; border-radius: 1rem; }
        }
        @media (max-width: 380px) {
            .rf-chips { grid-template-columns: 1fr; }
        }
    </style>
    @livewireStyles
</head>
<body class="min-h-screen bg-[#f7f7f8] antialiased">

    <div class="md:flex md:min-h-screen">
        {{-- Desktop sidebar --}}
        <x-sidebar />

        <div class="flex min-h-screen flex-1 flex-col">
            {{-- Mobile menu --}}
            <x-mobile-header />

            {{-- Desktop topbar --}}
            <header class="hidden h-16 shrink-0 items-center justify-between px-8 md:flex" style="background: #2f2f2f url('{{ asset('images/aea_pattern.png') }}') repeat-x left center / auto 100%; border-bottom: 3px solid var(--color-primary-500, #e31e24)">
                <div class="ops-hub-title flex items-center gap-3" style="min-width: 0"><span style="font-size: 0.78rem; font-weight: 600; letter-spacing: 0.2em; text-transform: uppercase; white-space: nowrap; color: rgba(255, 255, 255, 0.92); text-shadow: 0 1px 3px rgba(0, 0, 0, 0.6)">Service Operations Hub</span></div>
                <div class="flex items-center gap-4">
                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full" style="background: rgba(255, 255, 255, 0.16); color: #fff">
                        <x-icon name="bell" class="h-4.5 w-4.5" />
                    </button>
                </div>
            </header>

            <main class="flex-1 px-4 py-6 pb-8 md:px-8 md:py-8 md:pb-8">
                @if (auth()->user()->hasRole('Customer') && ! auth()->user()->hasVerifiedEmail())
                    <div class="mb-4 flex items-center justify-between rounded-[var(--radius-md)] border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800">
                        <span>Please verify your email address ({{ auth()->user()->email }}).</span>
                        <a href="/email/verify" wire:navigate class="font-semibold underline hover:no-underline">Verify now</a>
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>

    @livewireScripts
</body>
</html>
