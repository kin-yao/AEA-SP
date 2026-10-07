<?php

namespace App\Support;

/**
 * One place that knows where the AEA logo lives, so the sign-in page, the
 * sidebar, sign-up, error pages, emails and every PDF all show the same file.
 * To change the logo everywhere, replace public/images/aea_logo.svg.
 */
class Brand
{
    public const LOGO = 'images/aea_logo.svg';

    public static function path(): ?string
    {
        $p = public_path(self::LOGO);

        return is_file($p) ? $p : null;
    }

    public static function has(): bool
    {
        return self::path() !== null;
    }

    public static function url(): string
    {
        $p = self::path();

        return asset(self::LOGO).($p ? '?v='.filemtime($p) : '');
    }

    /** Inline copy for PDFs, which cannot fetch web addresses. */
    public static function dataUri(): ?string
    {
        $p = self::path();
        if (! $p) {
            return null;
        }

        return 'data:image/svg+xml;base64,'.base64_encode((string) file_get_contents($p));
    }
}
