<?php

declare(strict_types=1);

namespace App\Services;

final class NextcloudStorage
{
    /** @param array<string, string> $env */
    public function __construct(private readonly array $env)
    {
    }

    public function isConfigured(): bool
    {
        return ($this->env['NEXTCLOUD_BASE_URL'] ?? '') !== ''
            && ($this->env['NEXTCLOUD_USERNAME'] ?? '') !== ''
            && ($this->env['NEXTCLOUD_APP_PASSWORD'] ?? '') !== '';
    }
}
