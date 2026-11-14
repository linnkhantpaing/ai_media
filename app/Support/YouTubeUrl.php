<?php

namespace App\Support;

final class YouTubeUrl
{
    public static function normalize(string $input): ?string
    {
        $parts = parse_url(trim($input));
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $id = null;
        if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
            if (preg_match('#^/([a-zA-Z0-9_-]{11})/?$#D', $path, $match)) {
                $id = $match[1];
            }
        } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'], true)) {
            if ($path === '/watch' || $path === '/watch/') {
                parse_str($parts['query'] ?? '', $query);
                $id = is_string($query['v'] ?? null) ? $query['v'] : null;
            } elseif (preg_match('#^/(?:shorts|embed|live)/([a-zA-Z0-9_-]{11})/?$#D', $path, $match)) {
                $id = $match[1];
            }
        }
        if (! is_string($id) || ! preg_match('/^[a-zA-Z0-9_-]{11}$/D', $id)) {
            return null;
        }

        return 'https://www.youtube.com/watch?v='.$id;
    }
}
