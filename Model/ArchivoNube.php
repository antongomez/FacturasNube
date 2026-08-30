<?php
/**
 * This file is part of FacturasNube plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\FacturasNube\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;

/**
 * Estado de sincronización de un documento con un servicio en la nube.
 * Hay como mucho una fila por combinación de servicio + modelo + registro,
 * de forma que al modificar una factura se actualiza el archivo ya subido
 * en lugar de crear un duplicado.
 */
class ArchivoNube extends ModelClass
{
    use ModelTrait;

    const ESTADO_DELETED = 'deleted';
    const ESTADO_ERROR = 'error';
    const ESTADO_PENDING = 'pending';
    const ESTADO_SYNCED = 'synced';
    const ESTADO_TO_DELETE = 'to-delete';

    /** @var string Ruta de la carpeta destino dentro del servicio, por ejemplo 2026/2026-08. */
    public $carpeta;

    /** @var string Código del documento, solo informativo. */
    public $codigo;

    /** @var string Último error registrado. */
    public $error;

    /** @var string Estado de la sincronización. */
    public $estado;

    /** @var string Fecha y hora de creación de la fila. */
    public $fecha;

    /** @var string Fecha y hora de la última sincronización correcta. */
    public $fecha_sync;

    /** @var string Identificador del archivo dentro del servicio. */
    public $file_id;

    /** @var string Enlace para ver el archivo en el servicio. */
    public $file_url;

    /** @var string md5 del PDF subido, para no volver a subir un contenido idéntico. */
    public $hash;

    /** @var int Identificador único. */
    public $id;

    /** @var string Clave primaria del documento de origen. */
    public $idregistro;

    /** @var int Número de intentos fallidos consecutivos. */
    public $intentos;

    /** @var string Nombre del modelo de origen, por ejemplo FacturaCliente. */
    public $modelo;

    /** @var string Nombre del archivo en el servicio. */
    public $nombre;

    /** @var string Identificador del servicio: google, onedrive... */
    public $servicio;

    public function clear(): void
    {
        parent::clear();
        $this->estado = self::ESTADO_PENDING;
        $this->fecha = Tools::dateTime();
        $this->intentos = 0;
    }

    /**
     * Devuelve la fila del documento indicado, creándola en memoria si no existía.
     */
    public static function forDocument(string $servicio, string $modelo, $idregistro): self
    {
        $where = [
            Where::eq('servicio', $servicio),
            Where::eq('modelo', $modelo),
            Where::eq('idregistro', (string)$idregistro),
        ];

        $item = static::findWhere($where);
        if ($item) {
            return $item;
        }

        $item = new static();
        $item->servicio = $servicio;
        $item->modelo = $modelo;
        $item->idregistro = (string)$idregistro;
        return $item;
    }

    /** Marca la fila como fallida y guarda el motivo. */
    public function fail(string $message): bool
    {
        $this->estado = self::ESTADO_ERROR;
        $this->error = mb_substr($message, 0, 2000);
        $this->intentos = (int)$this->intentos + 1;
        return $this->save();
    }

    /** Marca la fila como sincronizada correctamente. */
    public function success(string $fileId, string $fileUrl, string $hash): bool
    {
        $this->estado = self::ESTADO_SYNCED;
        $this->error = null;
        $this->fecha_sync = Tools::dateTime();
        $this->file_id = $fileId;
        $this->file_url = $fileUrl;
        $this->hash = $hash;
        $this->intentos = 0;
        return $this->save();
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'codigo';
    }

    public static function tableName(): string
    {
        return 'facturas_nube_archivos';
    }

    public function test(): bool
    {
        $this->carpeta = Tools::noHtml($this->carpeta);
        $this->codigo = Tools::noHtml($this->codigo);
        $this->nombre = Tools::noHtml($this->nombre);

        if (empty($this->servicio) || empty($this->modelo) || empty($this->idregistro)) {
            Tools::log()->error('record-not-specified');
            return false;
        }

        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'ListArchivoNube?activetab=List'): string
    {
        return parent::url($type, 'FacturasNube?activetab=ListArchivoNube');
    }
}
