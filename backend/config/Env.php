<?php

declare(strict_types=1);

namespace App\Config;

final class Env
{
    /** @return array<string, string> */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('No se encontró el archivo de configuración .env.');
        }

        $variables = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $variables[trim($key)] = trim($value);
        }

        return $variables;
    }
}
