<?php

declare(strict_types=1);

namespace App\Models;

final class Role
{
    public const CONTRATACION_PUBLICA = 'contratacion_publica';
    public const BIENES_ACTIVOS_FIJOS = 'bienes_activos_fijos';
    public const CONTADOR = 'contador';
    public const DIRECTOR = 'director';
    public const OPERADOR = 'operador';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::CONTRATACION_PUBLICA => 'Contratación Pública',
            self::BIENES_ACTIVOS_FIJOS => 'Bienes y Activos Fijos',
            self::CONTADOR => 'Contador',
            self::DIRECTOR => 'Director',
            self::OPERADOR => 'Operador',
        ];
    }
}
