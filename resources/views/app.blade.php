<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0B0D14">
        <meta name="description" content="{{ request()->attributes->get('platform_settings')['tagline'] ?? 'AI Video Recap Studio' }}">
        <link rel="icon" href="{{ request()->attributes->get('platform_settings')['favicon'] ?? asset('favicon.svg') }}">
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
