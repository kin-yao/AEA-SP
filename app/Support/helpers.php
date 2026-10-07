<?php

use App\Support\Settings;

if (! function_exists('setting')) {
    /** One system setting, from the ICT settings page (or its built-in default). */
    function setting(string $key): mixed
    {
        return Settings::get($key);
    }
}

if (! function_exists('currency')) {
    /** The default currency code, for example KES. */
    function currency(): string
    {
        return Settings::currency();
    }
}
