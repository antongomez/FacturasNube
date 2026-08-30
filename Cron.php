<?php
/**
 * This file is part of FacturasNube plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\FacturasNube;

use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FacturasNube\Lib\Nube\Config;
use FacturaScripts\Plugins\FacturasNube\Lib\Sincronizador;

/**
 * Sube a la nube las facturas marcadas como pendientes.
 * Requiere que el cron de FacturaScripts esté configurado en el servidor.
 */
class Cron extends CronClass
{
    public function run(): void
    {
        if (false === Config::enabled()) {
            return;
        }

        $this->job('sincronizar-facturas-nube')
            ->every('1 minute')
            ->run(function () {
                $result = Sincronizador::syncPending();
                if ($result['total'] > 0) {
                    Tools::log('facturasnube')->notice('facturasnube-cron-result', [
                        '%ok%' => $result['ok'],
                        '%error%' => $result['error'],
                    ]);
                }
            });
    }
}
