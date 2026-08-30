<?php
/**
 * This file is part of FacturasNube plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\FacturasNube\Controller;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FacturasNube\Lib\Nube\CloudException;
use FacturaScripts\Plugins\FacturasNube\Lib\Nube\GoogleDrive;

/**
 * URL de retorno del consentimiento de Google (/oauth2/facturas-nube/google).
 *
 * Está en una ruta propia, sin parámetros de consulta, porque es el valor
 * exacto que hay que dar de alta como "URI de redirección autorizado" en
 * Google Cloud Console.
 */
class FacturasNubeGoogleCallback extends Controller
{
    const STATE_CACHE_KEY = 'facturasnube-oauth-state-google';
    const STATE_MAX_AGE = 600;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['title'] = 'facturasnube-google-callback';
        $data['icon'] = 'fa-brands fa-google-drive';
        $data['showonmenu'] = false;
        return $data;
    }

    /** Genera y guarda un state nuevo para iniciar el flujo de autorización. */
    public static function newState(string $nick): string
    {
        $state = bin2hex(random_bytes(16));
        Cache::set(self::STATE_CACHE_KEY, [
            'state' => $state,
            'nick' => $nick,
            'time' => time(),
        ]);

        return $state;
    }

    public function run(): void
    {
        parent::run();

        $target = Tools::siteUrl() . '/FacturasNube';

        // el usuario ha denegado el permiso, o Google ha devuelto un error
        $error = $this->request()->query('error', '');
        if (!empty($error)) {
            $this->response()->redirect($target . '?fsnube=denied');
            return;
        }

        $code = (string)$this->request()->query('code', '');
        $state = (string)$this->request()->query('state', '');
        if (empty($code) || false === $this->checkState($state)) {
            $this->response()->redirect($target . '?fsnube=state-error');
            return;
        }

        try {
            GoogleDrive::exchangeCode($code);
        } catch (CloudException $ex) {
            Tools::log('facturasnube')->error($ex->getMessage());
            $this->response()->redirect($target . '?fsnube=auth-error');
            return;
        }

        // la cuenta ha cambiado: los ids de carpeta cacheados ya no valen
        (new GoogleDrive())->forgetFolderCache();

        $this->response()->redirect($target . '?fsnube=connected');
    }

    /** Comprueba que el state coincide con el que generamos y que no ha caducado. */
    protected function checkState(string $state): bool
    {
        $stored = Cache::get(self::STATE_CACHE_KEY);
        Cache::delete(self::STATE_CACHE_KEY);

        if (empty($state) || false === is_array($stored) || empty($stored['state'])) {
            return false;
        }

        if (time() - (int)($stored['time'] ?? 0) > self::STATE_MAX_AGE) {
            return false;
        }

        return hash_equals((string)$stored['state'], $state);
    }
}
