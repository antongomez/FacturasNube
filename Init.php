<?php
/**
 * This file is part of FacturasNube plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\FacturasNube;

use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\WorkQueue;

class Init extends InitClass
{
    public function init(): void
    {
        // marcamos la factura como pendiente de subir al guardarla o eliminarla.
        // Basta con escuchar .Save porque saveInsert y saveUpdate siempre se
        // invocan desde save(), que emite ese evento.
        WorkQueue::addWorker('FacturaClienteNubeWorker', 'Model.FacturaCliente.Save');
        WorkQueue::addWorker('FacturaClienteNubeWorker', 'Model.FacturaCliente.Delete');

        // URL de retorno del consentimiento de Google. Se registra como ruta propia
        // para poder darla de alta en Google Cloud Console sin parámetros de consulta.
        Kernel::addRoute('/oauth2/facturas-nube/google', 'FacturasNubeGoogleCallback', -1, 'facturas-nube-google');
    }

    public function update(): void
    {
    }

    public function uninstall(): void
    {
    }
}
