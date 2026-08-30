<?php
/**
 * This file is part of FacturasNube plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\FacturasNube\Lib\Nube;

use Exception;

/**
 * Error al comunicarse con un servicio de almacenamiento en la nube.
 *
 * Guarda el código HTTP de la respuesta porque no todos los fallos son iguales:
 * un 404 al comprobar un archivo significa que ya no está, mientras que un 500
 * o un timeout solo significan que hay que reintentarlo más tarde.
 */
class CloudException extends Exception
{
    /** @var int Código HTTP de la respuesta, 0 si el fallo no viene de una respuesta. */
    protected $statusCode;

    public function __construct(string $message, int $statusCode = 0)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
