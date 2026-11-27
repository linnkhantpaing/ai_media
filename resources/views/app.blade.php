<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0B0D14">
        <meta name="description" content="{{ request()->attributes->get('platform_settings')['tagline'] ?? 'AI Video Recap Studio' }}">
        @php($favicon = request()->attributes->get('platform_settings')['favicon'] ?? asset('favicon.svg'))
        <link rel="icon" href="{{ $favicon }}">
        {{-- Home-screen shortcut icon (iPhone / Android): the favicon saved in General Settings when it is a PNG. --}}
        @if (str_ends_with(strtolower($favicon), '.png'))
            <link rel="apple-touch-icon" href="{{ $favicon }}">
            <link rel="icon" type="image/png" sizes="192x192" href="{{ $favicon }}">
        @endif
        <meta name="apple-mobile-web-app-title" content="{{ request()->attributes->get('platform_settings')['name'] ?? config('app.name') }}">
        <title inertia>{{ config('app.name', 'AI Media') }}</title>
        <script nonce="{{ Vite::cspNonce() }}">
            (() => {
                document.documentElement.dataset.appName = {{ \Illuminate\Support\Js::from(config('app.name')) }};
                let theme = 'dark';
                try { if (localStorage.getItem('ai-media.theme') === 'light') theme = 'light'; } catch {}
                document.documentElement.dataset.theme = theme;
                document.documentElement.style.colorScheme = theme;
                document.querySelector('meta[name="theme-color"]').content = theme === 'dark' ? '#0B0D14' : '#F6F7FB';
            })();
        </script>
        @viteReactRefresh
        @vite('resources/js/app.tsx')
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
