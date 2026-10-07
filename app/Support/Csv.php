<?php

namespace App\Support;

/** CSV rows that are safe to open in Excel: text starting with = + - @ would otherwise run as a formula. */
class Csv
{
    public static function safe(array $row): array
    {
        return array_map(function ($cell) {
            if (is_string($cell) && $cell !== '' && preg_match('/^[=+\-@\t\r]/', $cell) && ! is_numeric($cell)) {
                return "'".$cell;
            }

            return $cell;
        }, $row);
    }

    public static function put($out, array $row, ...$rest)
    {
        return fputcsv($out, self::safe($row), ...$rest);
    }
}
