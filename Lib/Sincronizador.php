<?php
/**
 * This file is part of FacturasNube plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\FacturasNube\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\ExportManager;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Plugins\FacturasNube\Lib\Nube\CloudException;
use FacturaScripts\Plugins\FacturasNube\Lib\Nube\CloudProvider;
use FacturaScripts\Plugins\FacturasNube\Lib\Nube\Config;
use FacturaScripts\Plugins\FacturasNube\Lib\Nube\GoogleDrive;
use FacturaScripts\Plugins\FacturasNube\Model\ArchivoNube;
use Throwable;

/**
 * Sincroniza los PDF de las facturas con los servicios en la nube.
 *
 * El trabajo se hace en dos tiempos a propósito:
 *   1. Al guardar o eliminar una factura, el worker solo marca una fila como
 *      pendiente. Es una escritura barata que no bloquea la petición ni depende
 *      de que la red funcione en ese momento.
 *   2. El cron (o el botón "sincronizar ahora") genera el PDF y lo sube.
 *      Así varias modificaciones seguidas de la misma factura se agrupan en una
 *      única subida, y un fallo de red se reintenta en la siguiente pasada.
 */
class Sincronizador
{
    const MIME_PDF = 'application/pdf';
    const MODELO = 'FacturaCliente';

    /** @var CloudProvider[] */
    private static $providers = [];

    /**
     * Marca una factura como pendiente de subir. No hace ninguna llamada de red.
     */
    public static function enqueue(
        FacturaCliente $factura,
        string $servicio = GoogleDrive::SERVICE,
        bool $force = false
    ): bool {
        if (false === Config::enabled() || empty($factura->idfactura)) {
            return false;
        }

        if (false === self::inRange($factura)) {
            return false;
        }

        $item = ArchivoNube::forDocument($servicio, self::MODELO, $factura->idfactura);
        $item->codigo = $factura->codigo;
        $item->estado = ArchivoNube::ESTADO_PENDING;
        $item->error = null;

        // una modificación nueva merece una tanda de reintentos nueva
        $item->intentos = 0;

        // olvidar la huella obliga a regenerar el PDF y volver a subirlo aunque el
        // documento no haya cambiado. Se conserva el id del archivo, así que se
        // reemplaza el que ya está en la nube en lugar de crear un duplicado.
        if ($force) {
            $item->hash = null;
        }

        return $item->save();
    }

    /**
     * Marca el archivo de una factura eliminada para que se retire de la nube.
     * Si la configuración dice que no hay que tocar nada, la fila se cierra sin más.
     */
    public static function enqueueDelete($idfactura, string $servicio = GoogleDrive::SERVICE): bool
    {
        $item = ArchivoNube::findWhere([
            Where::eq('servicio', $servicio),
            Where::eq('modelo', self::MODELO),
            Where::eq('idregistro', (string)$idfactura),
        ]);

        if (null === $item) {
            return false;
        }

        if (Config::onDelete() === Config::ON_DELETE_KEEP || empty($item->file_id)) {
            $item->estado = ArchivoNube::ESTADO_DELETED;
            return $item->save();
        }

        $item->estado = ArchivoNube::ESTADO_TO_DELETE;
        $item->intentos = 0;
        $item->error = null;
        return $item->save();
    }

    /**
     * Procesa las filas pendientes. Devuelve el recuento por resultado.
     *
     * @return array ['total' => int, 'ok' => int, 'error' => int]
     */
    public static function syncPending(int $limit = 0, string $servicio = GoogleDrive::SERVICE): array
    {
        $result = ['total' => 0, 'ok' => 0, 'error' => 0];
        if (false === Config::enabled()) {
            return $result;
        }

        // antes de nada, damos de alta las facturas que aún no están en la cola.
        // Así el histórico entra solo, sin obligar a marcarlo a mano.
        $limit = $limit > 0 ? $limit : Config::batchSize();
        self::sweepHistoric($limit, $servicio);

        $where = [
            Where::eq('servicio', $servicio),
            Where::in('estado', [
                ArchivoNube::ESTADO_PENDING,
                ArchivoNube::ESTADO_ERROR,
                ArchivoNube::ESTADO_TO_DELETE,
            ]),
            Where::lt('intentos', Config::maxRetries()),
        ];

        foreach (ArchivoNube::all($where, ['id' => 'ASC'], 0, $limit) as $item) {
            $result['total']++;
            if (self::syncOne($item, $servicio)) {
                $result['ok']++;
            } else {
                $result['error']++;
            }
        }

        return $result;
    }

    /**
     * Da de alta en la cola las facturas que todavía no tienen fila, avanzando poco a
     * poco por el histórico. Devuelve cuántas ha añadido.
     *
     * Recorre las facturas por idfactura ascendente desde el último punto alcanzado.
     * Como idfactura es una secuencia, nunca aparecen facturas por debajo del cursor,
     * de modo que el repaso termina solo y a partir de ahí no cuesta nada.
     */
    public static function sweepHistoric(int $limit = 0, string $servicio = GoogleDrive::SERVICE): int
    {
        if (false === Config::enabled() || false === Config::syncHistoric()) {
            return 0;
        }

        $limit = $limit > 0 ? $limit : Config::batchSize();
        $cursor = Config::sweepCursor();

        $facturas = FacturaCliente::all(
            [Where::gt('idfactura', $cursor)],
            ['idfactura' => 'ASC'],
            0,
            $limit
        );

        if (empty($facturas)) {
            return 0;
        }

        // preguntamos de una vez cuáles de este lote ya están en la cola
        $ids = [];
        foreach ($facturas as $factura) {
            $ids[] = (string)$factura->idfactura;
        }

        $known = [];
        $where = [
            Where::eq('servicio', $servicio),
            Where::eq('modelo', self::MODELO),
            Where::in('idregistro', $ids),
        ];
        foreach (ArchivoNube::all($where, [], 0, 0) as $row) {
            $known[(string)$row->idregistro] = true;
        }

        $added = 0;
        $lastId = $cursor;
        foreach ($facturas as $factura) {
            $lastId = (int)$factura->idfactura;

            if (isset($known[(string)$factura->idfactura])) {
                continue;
            }

            if (self::enqueue($factura, $servicio)) {
                $added++;
            }
        }

        Config::setSweepCursor($lastId);

        return $added;
    }

    /** Filas de la cola que todavía se pueden procesar. */
    public static function pendingCount(string $servicio = GoogleDrive::SERVICE): int
    {
        return ArchivoNube::count([
            Where::eq('servicio', $servicio),
            Where::in('estado', [
                ArchivoNube::ESTADO_PENDING,
                ArchivoNube::ESTADO_ERROR,
                ArchivoNube::ESTADO_TO_DELETE,
            ]),
            Where::lt('intentos', Config::maxRetries()),
        ]);
    }

    /** Trabajo que queda por hacer: cola pendiente más histórico sin repasar. */
    public static function remainingCount(string $servicio = GoogleDrive::SERVICE): int
    {
        return self::pendingCount($servicio) + self::pendingSweepCount();
    }

    /** Facturas que aún no ha mirado el repaso del histórico. */
    public static function pendingSweepCount(): int
    {
        if (false === Config::syncHistoric()) {
            return 0;
        }

        return FacturaCliente::count([Where::gt('idfactura', Config::sweepCursor())]);
    }

    /** True si la factura entra dentro del rango de fechas configurado. */
    public static function inRange(FacturaCliente $factura): bool
    {
        $start = Config::startDate();
        if ($start === '') {
            return true;
        }

        return strtotime($factura->fecha) >= strtotime($start);
    }

    /** Procesa una única fila: subida, actualización o borrado. */
    public static function syncOne(ArchivoNube $item, string $servicio = GoogleDrive::SERVICE): bool
    {
        try {
            $provider = self::provider($servicio);
            if (false === $provider->isConnected()) {
                return self::fail($item, Tools::trans('facturasnube-not-connected'));
            }

            if ($item->estado === ArchivoNube::ESTADO_TO_DELETE) {
                return self::runDelete($item, $provider);
            }

            return self::runUpload($item, $provider);
        } catch (CloudException $ex) {
            return self::fail($item, $ex->getMessage());
        } catch (Throwable $ex) {
            Tools::log('facturasnube')->error($ex->getMessage(), [
                '%file%' => $ex->getFile(),
                '%line%' => $ex->getLine(),
            ]);
            return self::fail($item, $ex->getMessage());
        }
    }

    /**
     * Registra el fallo en la fila y devuelve siempre false, para que quien llama
     * cuente el intento como fallido aunque la fila se haya guardado bien.
     */
    protected static function fail(ArchivoNube $item, string $message): bool
    {
        $item->fail($message);
        return false;
    }

    /**
     * @throws CloudException
     */
    protected static function runDelete(ArchivoNube $item, CloudProvider $provider): bool
    {
        if (!empty($item->file_id)) {
            $provider->deleteFile($item->file_id, Config::onDelete() !== Config::ON_DELETE_DELETE);
        }

        $item->estado = ArchivoNube::ESTADO_DELETED;
        $item->error = null;
        $item->fecha_sync = Tools::dateTime();
        return $item->save();
    }

    /**
     * @throws CloudException
     */
    protected static function runUpload(ArchivoNube $item, CloudProvider $provider): bool
    {
        $factura = new FacturaCliente();
        if (false === $factura->load($item->idregistro)) {
            // la factura ya no está: tratamos la fila como un borrado pendiente
            $item->estado = ArchivoNube::ESTADO_TO_DELETE;
            return self::runDelete($item, $provider);
        }

        $fileName = self::fileName($factura);
        $segments = self::folderSegments($factura);
        $hash = self::documentHash($factura, $fileName, $segments);

        // si nada ha cambiado y el archivo sigue en la nube, nos ahorramos
        // generar el PDF y volver a subirlo
        if ($hash === $item->hash && $provider->fileExists((string)$item->file_id)) {
            return $item->success((string)$item->file_id, (string)$item->file_url, $hash);
        }

        $pdf = self::pdf($factura);
        if ($pdf === '') {
            return self::fail($item, Tools::trans('facturasnube-pdf-error'));
        }

        $folderId = $provider->ensureFolder($segments);

        $exists = !empty($item->file_id) && $provider->fileExists($item->file_id);

        // si ha cambiado el esquema de carpetas, el archivo se lleva a la nueva
        // conservando su id, para no romper los enlaces ya compartidos
        if ($exists && $item->carpeta !== implode('/', $segments)) {
            $provider->moveFile($item->file_id, $folderId);
        }

        $data = $exists
            ? $provider->updateFile($item->file_id, $fileName, $pdf, self::MIME_PDF)
            : $provider->uploadFile($folderId, $fileName, $pdf, self::MIME_PDF);

        if (empty($data['id'])) {
            return self::fail($item, Tools::trans('facturasnube-upload-error'));
        }

        $item->carpeta = implode('/', $segments);
        $item->codigo = $factura->codigo;
        $item->nombre = $fileName;

        // al actualizar, Drive no siempre devuelve webViewLink: conservamos el que ya teníamos
        $url = $data['url'] !== '' ? $data['url'] : (string)$item->file_url;
        return $item->success($data['id'], $url, $hash);
    }

    /**
     * Huella del estado de la factura, para no volver a subir lo que no ha cambiado.
     *
     * No se puede usar el md5 del PDF: la librería le mete la fecha de creación y un
     * identificador aleatorio, así que dos PDF de la misma factura nunca coinciden.
     * Por eso la huella se calcula sobre la cabecera y las líneas del documento,
     * más el nombre y la carpeta de destino, que también deciden dónde acaba el archivo.
     *
     * @param string[] $segments
     */
    public static function documentHash(FacturaCliente $factura, string $fileName, array $segments): string
    {
        $data = [
            'doc' => $factura->toArray(),
            'lines' => [],
            'name' => $fileName,
            'folder' => implode('/', $segments),
        ];

        foreach ($factura->getLines() as $line) {
            $data['lines'][] = $line->toArray();
        }

        return md5(json_encode($data));
    }

    /** Genera el PDF de la factura y lo devuelve como cadena. Vacía si falla. */
    public static function pdf(FacturaCliente $factura): string
    {
        // la librería de PDF cachea las fuentes en MyFiles/Cache y falla si la
        // carpeta no existe. El cron corre sin nadie delante, así que la creamos.
        Tools::folderCheckOrCreate(Tools::folder('MyFiles', 'Cache'));

        $lang = $factura->getSubject()->langcode ?? '';
        $title = Tools::lang($lang)->trans('invoice') . ' ' . $factura->codigo;

        $exportManager = new ExportManager();
        $exportManager->newDoc('PDF', $title, 0, $lang);
        $exportManager->addBusinessDocPage($factura);

        return (string)$exportManager->getDoc();
    }

    /** Nombre del archivo en la nube, según la plantilla configurada. */
    public static function fileName(FacturaCliente $factura): string
    {
        $replacements = [
            '{codigo}' => (string)$factura->codigo,
            '{numero}' => (string)$factura->numero,
            '{fecha}' => date('Y-m-d', strtotime($factura->fecha)),
            '{cliente}' => (string)$factura->nombrecliente,
            '{nif}' => (string)$factura->cifnif,
        ];

        $name = strtr(Config::fileNameTemplate(), $replacements);

        // quitamos lo que no puede formar parte de un nombre de archivo
        $name = preg_replace('/[\/\\\\:*?"<>|\r\n]+/', '-', $name);
        $name = trim(preg_replace('/\s+/', ' ', $name));

        if ($name === '') {
            $name = (string)$factura->codigo;
        }

        return mb_substr($name, 0, 200) . '.pdf';
    }

    /**
     * Subcarpetas destino relativas a la carpeta raíz, por ejemplo ['2026', '2026-08'].
     *
     * @return string[]
     */
    public static function folderSegments(FacturaCliente $factura): array
    {
        if (false === Config::useSubfolders()) {
            return [];
        }

        $time = strtotime($factura->fecha);
        return [date('Y', $time), date('Y-m', $time)];
    }

    /** Devuelve el proveedor del servicio indicado, reutilizándolo dentro de la misma petición. */
    public static function provider(string $servicio = GoogleDrive::SERVICE): CloudProvider
    {
        if (isset(self::$providers[$servicio])) {
            return self::$providers[$servicio];
        }

        switch ($servicio) {
            case GoogleDrive::SERVICE:
            default:
                self::$providers[$servicio] = new GoogleDrive();
                break;
        }

        return self::$providers[$servicio];
    }

    /** Olvida los proveedores cacheados. Necesario tras conectar o desconectar una cuenta. */
    public static function clearProviders(): void
    {
        self::$providers = [];
    }
}
