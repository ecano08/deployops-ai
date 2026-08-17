<?php

namespace App\Support;

class DemoSeeding
{
    public static function isEnabled(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        if (config('demo.seed_demo_data')) {
            return true;
        }

        return app()->environment(['local', 'demo']);
    }
}
