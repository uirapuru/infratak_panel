<?php

declare(strict_types=1);

namespace App\Service\Security;

final class OtsAdminPasswordGenerator
{
    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!#$%&*+-=?_';

    public function generate(int $length = 32): string
    {
        if ($length < 16) {
            throw new \InvalidArgumentException('Password length must be at least 16 characters.');
        }

        $max = strlen(self::ALPHABET) - 1;
        $result = '';

        for ($i = 0; $i < $length; ++$i) {
            $result .= self::ALPHABET[random_int(0, $max)];
        }

        return $result;
    }
}
