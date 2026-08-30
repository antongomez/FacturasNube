<?php
/**
 * This file is part of FacturasNube plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\FacturasNube\Worker;

use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Template\WorkerClass;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Plugins\FacturasNube\Lib\Nube\Config;
use FacturaScripts\Plugins\FacturasNube\Lib\Sincronizador;

/**
 * Reacciona a los eventos de guardado y borrado de facturas de cliente
 * marcando el documento como pendiente de sincronizar.
 *
 * Aquí no se sube nada: la subida la hace el cron, para que un fallo de red
 * no se pierda (la cola de trabajos marca el evento como hecho aunque el
 * worker lance una excepción) y para agrupar varias ediciones seguidas.
 */
class FacturaClienteNubeWorker extends WorkerClass
{
    public function run(WorkEvent $event): bool
    {
        if (false === Config::enabled()) {
            return $this->done();
        }

        if (str_ends_with($event->name, '.Delete')) {
            Sincronizador::enqueueDelete($event->value);
            return $this->done();
        }

        $factura = new FacturaCliente();
        if ($factura->load($event->value)) {
            Sincronizador::enqueue($factura);
        }

        return $this->done();
    }
}
