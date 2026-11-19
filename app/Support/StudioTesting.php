<?php

namespace App\Support;

class StudioTesting
{
    public static function enabled(): bool
    {
        return app()->environment('local') && (bool) config('media.studio_testing', true);
    }

    public static function policy(array $policy): array
    {
        if (self::enabled()) {
            $policy['max_duration'] = 86400;
        }

        return $policy;
    }
}
