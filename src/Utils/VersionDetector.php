<?php

declare(strict_types=1);

namespace CAH\Utils;

final class VersionDetector
{
    public static function looksLikeVersion(string $value): bool
    {
        $v = trim($value);
        if ($v === '' || strlen($v) > 20) {
            return false;
        }

        return (bool) (
            preg_match('/^v\s*\d+\.?\d*[a-z]*$/i', $v) ||
            preg_match('/^\d+\.\d+[a-z]*$/', $v) ||
            preg_match('/^[A-Z]{2,4}\s*v\s*\d+/i', $v) ||
            preg_match('/^(beta|alpha|ks|kickstarter|bbv\d+|bbbv?\d*)$/i', $v) ||
            preg_match('/^[A-Z]{2,6}v\d+$/i', $v)
        );
    }
}
