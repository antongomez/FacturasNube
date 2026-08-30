<?php
/**
 * This file is part of FacturasNube plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\FacturasNube\Controller;

use FacturaScripts\Core\Lib\ExtendedController\PanelController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Plugins\FacturasNube\Lib\Nube\CloudException;
use FacturaScripts\Plugins\FacturasNube\Lib\Nube\Config;
use FacturaScripts\Plugins\FacturasNube\Lib\Nube\GoogleDrive;
use FacturaScripts\Plugins\FacturasNube\Lib\Sincronizador;
use FacturaScripts\Plugins\FacturasNube\Model\ArchivoNube;
use FacturaScripts\Plugins\FacturasNube\Model\CuentaNube;

/**
 * Pantalla de administración del plugin: conexión con Google Drive,
 * configuración de carpetas y nombres, y registro de sincronizaciones.
 */
class FacturasNube extends PanelController
{
    /**
     * Facturas que sube cada petición del botón "sincronizar ahora".
     * Es deliberadamente pequeño: el navegador encadena tandas hasta terminar,
     * y así ninguna petición se acerca al límite de tiempo del servidor.
     */
    const AJAX_BATCH = 5;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'facturasnube';
        $data['icon'] = 'fa-solid fa-cloud-arrow-up';
        return $data;
    }

    /** Cuenta de Google conectada, para la plantilla. */
    public function googleDrive(): GoogleDrive
    {
        return new GoogleDrive();
    }

    /** Resumen de filas por estado, para las tarjetas de la plantilla. */
    public function stats(): array
    {
        return [
            'pending' => ArchivoNube::count([Where::eq('estado', ArchivoNube::ESTADO_PENDING)]),
            'synced' => ArchivoNube::count([Where::eq('estado', ArchivoNube::ESTADO_SYNCED)]),
            'error' => ArchivoNube::count([Where::eq('estado', ArchivoNube::ESTADO_ERROR)]),
            'sweep' => Sincronizador::pendingSweepCount(),
        ];
    }

    /** Valor de una opción de configuración, para la plantilla. */
    public function setting(string $key, $default = null)
    {
        return Tools::settings(Config::GROUP, $key, $default);
    }

    public function redirectUri(): string
    {
        return Config::redirectUri();
    }

    /** Permiso configurado, para marcar la opción elegida en el desplegable. */
    public function scope(): string
    {
        return Config::scope();
    }

    /** True si el permiso elegido no es compatible con la carpeta indicada. */
    public function scopeConflict(): bool
    {
        return Config::scopeConflictsWithFolder();
    }

    protected function createViews(): void
    {
        $this->setTabsPosition('top');
        $this->showCallbackMessage();
        $this->warnAboutScope();

        $this->createViewsConfig();
        $this->createViewsFiles();
    }

    /**
     * Muestra el resultado del flujo de autorización. El callback de Google no puede
     * dejar el mensaje en la sesión (es de un solo request), así que vuelve aquí
     * con un parámetro en la url.
     */
    protected function showCallbackMessage(): void
    {
        switch ($this->request->query('fsnube', '')) {
            case 'auth-error':
                Tools::log()->error('facturasnube-auth-error');
                break;

            case 'connected':
                Tools::log()->notice('facturasnube-connected-ok');
                break;

            case 'denied':
                Tools::log()->warning('facturasnube-auth-denied');
                break;

            case 'state-error':
                Tools::log()->error('facturasnube-state-error');
                break;
        }
    }

    protected function createViewsConfig(string $viewName = 'FacturasNubeConfig'): void
    {
        // Assets/JS/FacturasNube.js lo carga solo el core, porque su nombre coincide
        // con el del controlador. Registrarlo aquí además lo duplicaría, y con él
        // el manejador del formulario de sincronización.
        $this->addHtmlView($viewName, 'Tab/FacturasNubeConfig', 'ArchivoNube', 'configuration', 'fa-solid fa-gears');
    }

    protected function createViewsFiles(string $viewName = 'ListArchivoNube'): void
    {
        $this->addListView($viewName, 'ArchivoNube', 'files', 'fa-solid fa-cloud-arrow-up')
            ->addOrderBy(['fecha_sync'], 'date', 2)
            ->addOrderBy(['codigo'], 'code')
            ->addOrderBy(['id'], 'id')
            ->addSearchFields(['codigo', 'nombre', 'error']);

        $this->views[$viewName]->addFilterSelectWhere('estado', [
            ['label' => Tools::trans('all'), 'where' => []],
            ['label' => Tools::trans('facturasnube-pending'), 'where' => [Where::eq('estado', ArchivoNube::ESTADO_PENDING)]],
            ['label' => Tools::trans('facturasnube-synced'), 'where' => [Where::eq('estado', ArchivoNube::ESTADO_SYNCED)]],
            ['label' => Tools::trans('facturasnube-error'), 'where' => [Where::eq('estado', ArchivoNube::ESTADO_ERROR)]],
        ]);

        $this->setSettings($viewName, 'btnNew', false);
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'connect-google':
                return $this->connectGoogleAction();

            case 'disconnect-google':
                return $this->disconnectGoogleAction();

            case 'enqueue-all':
                return $this->enqueueAllAction();

            case 'retry-errors':
                return $this->retryErrorsAction();

            case 'save-config':
                return $this->saveConfigAction();

            case 'sync-batch':
                return $this->syncBatchAction();

            case 'sync-now':
                return $this->syncNowAction();
        }

        return parent::execPreviousAction($action);
    }

    protected function loadData($viewName, $view): void
    {
        switch ($viewName) {
            case 'ListArchivoNube':
                $view->loadData();
                break;
        }
    }

    /** Envía al usuario a la pantalla de consentimiento de Google. */
    protected function connectGoogleAction(): bool
    {
        if (false === $this->permissions->allowUpdate || false === $this->validateFormToken()) {
            Tools::log()->warning('not-allowed-modify');
            return true;
        }

        if (Config::clientId() === '' || Config::clientSecret() === '') {
            Tools::log()->warning('facturasnube-missing-credentials');
            return true;
        }

        $state = FacturasNubeGoogleCallback::newState($this->user->nick);
        $this->redirect(GoogleDrive::authUrl($state));
        return false;
    }

    /** Revoca el acceso y borra los tokens guardados. */
    protected function disconnectGoogleAction(): bool
    {
        if (false === $this->permissions->allowUpdate || false === $this->validateFormToken()) {
            Tools::log()->warning('not-allowed-modify');
            return true;
        }

        $drive = new GoogleDrive();
        if ($drive->disconnect()) {
            Sincronizador::clearProviders();
            Tools::log()->notice('facturasnube-disconnected');
            return true;
        }

        Tools::log()->error('record-save-error');
        return true;
    }

    /**
     * Marca como pendientes todas las facturas emitidas desde una fecha,
     * para subir de golpe el histórico tras instalar el plugin.
     */
    protected function enqueueAllAction(): bool
    {
        if (false === $this->permissions->allowUpdate || false === $this->validateFormToken()) {
            Tools::log()->warning('not-allowed-modify');
            return true;
        }

        if (false === Config::enabled()) {
            Tools::log()->warning('facturasnube-disabled');
            return true;
        }

        $desde = $this->request->input('desde', '');
        $where = empty($desde) ? [] : [Where::gte('fecha', $desde)];
        $force = (bool)$this->request->input('forzar', false);

        $count = 0;
        foreach (FacturaCliente::all($where, ['idfactura' => 'ASC'], 0, 0) as $factura) {
            if (Sincronizador::enqueue($factura, GoogleDrive::SERVICE, $force)) {
                $count++;
            }
        }

        // si se han pedido todas, el repaso del histórico ya no tiene nada que mirar
        if (empty($desde)) {
            Config::setSweepCursor((int)FacturaCliente::table()->max('idfactura'));
        }

        Tools::log()->notice('facturasnube-enqueued', ['%count%' => $count]);
        return true;
    }

    /** Devuelve a la cola las filas que habían agotado los reintentos. */
    protected function retryErrorsAction(): bool
    {
        if (false === $this->permissions->allowUpdate || false === $this->validateFormToken()) {
            Tools::log()->warning('not-allowed-modify');
            return true;
        }

        $count = 0;
        foreach (ArchivoNube::all([Where::eq('estado', ArchivoNube::ESTADO_ERROR)], ['id' => 'ASC'], 0, 0) as $item) {
            $item->estado = ArchivoNube::ESTADO_PENDING;
            $item->intentos = 0;
            if ($item->save()) {
                $count++;
            }
        }

        Tools::log()->notice('facturasnube-enqueued', ['%count%' => $count]);
        return true;
    }

    /** Guarda la configuración del formulario. */
    protected function saveConfigAction(): bool
    {
        if (false === $this->permissions->allowUpdate || false === $this->validateFormToken()) {
            Tools::log()->warning('not-allowed-modify');
            return true;
        }

        $rootBefore = Config::rootFolderId() . '|' . Config::rootFolderName();

        Config::set('activo', (bool)$this->request->input('activo', false));
        Config::set('client_id', trim((string)$this->request->input('client_id', '')));
        Config::set('carpeta_raiz', trim((string)$this->request->input('carpeta_raiz', '')));
        Config::set('carpeta_nombre', trim((string)$this->request->input('carpeta_nombre', '')));
        Config::set('subcarpetas', (bool)$this->request->input('subcarpetas', false));
        Config::set('plantilla_nombre', trim((string)$this->request->input('plantilla_nombre', '')));
        Config::set('al_borrar', (string)$this->request->input('al_borrar', Config::ON_DELETE_TRASH));
        Config::set('scope', (string)$this->request->input('scope', Config::SCOPE_FULL));

        $historicBefore = Config::syncHistoric();
        $startBefore = Config::startDate();
        Config::set('historico', (bool)$this->request->input('historico', false));
        Config::set('fecha_inicio', trim((string)$this->request->input('fecha_inicio', '')));
        Config::set('max_intentos', (int)$this->request->input('max_intentos', 5));
        Config::set('tam_lote', (int)$this->request->input('tam_lote', 25));

        // el secreto solo se sustituye si el usuario escribe uno nuevo,
        // así el formulario puede mostrarlo vacío sin borrarlo al guardar
        $secret = trim((string)$this->request->input('client_secret', ''));
        if ($secret !== '') {
            Config::set('client_secret', $secret);
        }

        if (false === Config::save()) {
            Tools::log()->error('record-save-error');
            return true;
        }

        // si ha cambiado la carpeta raíz, los ids cacheados ya no sirven
        if ($rootBefore !== Config::rootFolderId() . '|' . Config::rootFolderName()) {
            (new GoogleDrive())->forgetFolderCache();
        }

        // si cambia el rango del histórico, el repaso tiene que empezar de nuevo
        if ($startBefore !== Config::startDate() || (false === $historicBefore && Config::syncHistoric())) {
            Config::setSweepCursor(0);
        }

        Tools::log()->notice('record-updated-correctly');
        $this->warnAboutScope();
        return true;
    }

    /**
     * Avisa de las dos formas de dejar la configuración en un estado que no puede
     * funcionar: pedir drive.file y a la vez apuntar a una carpeta ajena, o cambiar
     * el permiso sin volver a conectar la cuenta.
     */
    protected function warnAboutScope(): void
    {
        if (Config::scopeConflictsWithFolder()) {
            Tools::log()->warning('facturasnube-scope-folder-conflict');
        }

        $cuenta = CuentaNube::forService(GoogleDrive::SERVICE);
        if ($cuenta && false === $cuenta->hasScope(Config::scope())) {
            Tools::log()->warning('facturasnube-scope-changed');
        }
    }

    /** Procesa la cola en el momento, sin esperar al cron. */
    protected function syncNowAction(): bool
    {
        if (false === $this->permissions->allowUpdate || false === $this->validateFormToken()) {
            Tools::log()->warning('not-allowed-modify');
            return true;
        }

        if (false === Config::enabled()) {
            Tools::log()->warning('facturasnube-disabled');
            return true;
        }

        $result = Sincronizador::syncPending();
        Tools::log()->notice('facturasnube-sync-result', [
            '%ok%' => $result['ok'],
            '%error%' => $result['error'],
        ]);

        return true;
    }

    /**
     * Sube una tanda pequeña y responde en json cuánto queda, para que el navegador
     * pueda encadenar peticiones hasta vaciar la cola sin que ninguna se eternice.
     */
    protected function syncBatchAction(): bool
    {
        $this->setTemplate(false);

        if (false === $this->permissions->allowUpdate || false === $this->validateFormToken()) {
            $this->response->json(['ok' => false, 'message' => Tools::trans('not-allowed-modify')]);
            return false;
        }

        if (false === Config::enabled()) {
            $this->response->json(['ok' => false, 'message' => Tools::trans('facturasnube-disabled')]);
            return false;
        }

        $result = Sincronizador::syncPending(self::AJAX_BATCH);

        $this->response->json([
            'ok' => true,
            'processed' => $result['total'],
            'uploaded' => $result['ok'],
            'failed' => $result['error'],
            'remaining' => Sincronizador::remainingCount(),
        ]);

        return false;
    }

    /** Comprueba la conexión mostrando el email de la cuenta y la carpeta raíz. */
    public function connectionCheck(): array
    {
        $drive = new GoogleDrive();
        if (false === $drive->isConnected()) {
            return ['ok' => false, 'message' => Tools::trans('facturasnube-not-connected')];
        }

        try {
            $folderId = $drive->rootFolderId();
        } catch (CloudException $ex) {
            return ['ok' => false, 'message' => $ex->getMessage()];
        }

        return ['ok' => true, 'message' => $folderId];
    }
}
