<?php
/**
 * This file is part of FacturasNube plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\FacturasNube\Lib\Nube;

use FacturaScripts\Core\Tools;

/**
 * Acceso tipado a la configuración del plugin, guardada en el grupo
 * de settings "facturasnube". Centraliza aquí los valores por defecto
 * para no repetirlos en controladores, workers y proveedores.
 */
class Config
{
    const GROUP = 'facturasnube';

    /**
     * Permiso mínimo: el plugin solo ve los archivos que él mismo crea. Google no lo
     * considera sensible, así que no exige verificar la aplicación. A cambio, no puede
     * escribir dentro de una carpeta preexistente indicada por su id.
     */
    const SCOPE_FILE = 'https://www.googleapis.com/auth/drive.file';

    /** Acceso completo al Drive. Necesario para escribir en una carpeta ya existente. */
    const SCOPE_FULL = 'https://www.googleapis.com/auth/drive';

    /** Acciones posibles al eliminar una factura ya subida. */
    const ON_DELETE_KEEP = 'keep';
    const ON_DELETE_TRASH = 'trash';
    const ON_DELETE_DELETE = 'delete';

    public static function enabled(): bool
    {
        return (bool)Tools::settings(self::GROUP, 'activo', false);
    }

    public static function clientId(): string
    {
        return (string)Tools::settings(self::GROUP, 'client_id', '');
    }

    public static function clientSecret(): string
    {
        return (string)Tools::settings(self::GROUP, 'client_secret', '');
    }

    /** Id de la carpeta raíz en Drive. Vacío significa "créala tú con el nombre configurado". */
    public static function rootFolderId(): string
    {
        return trim((string)Tools::settings(self::GROUP, 'carpeta_raiz', ''));
    }

    /** Nombre de la carpeta raíz a crear cuando no se indica un id. */
    public static function rootFolderName(): string
    {
        $name = trim((string)Tools::settings(self::GROUP, 'carpeta_nombre', ''));
        return $name === '' ? 'Facturas FacturaScripts' : $name;
    }

    /** True si hay que organizar los archivos en subcarpetas año/año-mes. */
    public static function useSubfolders(): bool
    {
        return (bool)Tools::settings(self::GROUP, 'subcarpetas', true);
    }

    /** Plantilla del nombre de archivo. Admite {codigo}, {numero}, {fecha}, {cliente} y {nif}. */
    public static function fileNameTemplate(): string
    {
        $tpl = trim((string)Tools::settings(self::GROUP, 'plantilla_nombre', ''));
        return $tpl === '' ? '{codigo}' : $tpl;
    }

    /** Qué hacer en la nube cuando se elimina la factura en FacturaScripts. */
    public static function onDelete(): string
    {
        $value = (string)Tools::settings(self::GROUP, 'al_borrar', self::ON_DELETE_TRASH);
        $valid = [self::ON_DELETE_KEEP, self::ON_DELETE_TRASH, self::ON_DELETE_DELETE];
        return in_array($value, $valid, true) ? $value : self::ON_DELETE_TRASH;
    }

    /** Número máximo de intentos fallidos antes de dejar de reintentar una factura. */
    public static function maxRetries(): int
    {
        $value = (int)Tools::settings(self::GROUP, 'max_intentos', 5);
        return $value > 0 ? $value : 5;
    }

    /** Número de documentos que procesa cada pasada del cron. */
    public static function batchSize(): int
    {
        $value = (int)Tools::settings(self::GROUP, 'tam_lote', 25);
        return $value > 0 ? $value : 25;
    }

    /** Permisos que se piden a Google. */
    public static function scope(): string
    {
        $scope = trim((string)Tools::settings(self::GROUP, 'scope', ''));
        return in_array($scope, [self::SCOPE_FILE, self::SCOPE_FULL], true) ? $scope : self::SCOPE_FULL;
    }

    /**
     * True si la combinación de permiso y carpeta destino no puede funcionar:
     * con drive.file el plugin no ve las carpetas que no ha creado él, así que
     * indicar el id de una carpeta preexistente da siempre un error de permisos.
     */
    public static function scopeConflictsWithFolder(): bool
    {
        return self::scope() === self::SCOPE_FILE && self::rootFolderId() !== '';
    }

    /**
     * URL de retorno que hay que dar de alta en Google Cloud Console.
     * Depende de site_url, por eso conviene tenerlo configurado en la empresa.
     */
    public static function redirectUri(): string
    {
        return rtrim(Tools::siteUrl(), '/') . '/oauth2/facturas-nube/google';
    }

    public static function set(string $key, $value): void
    {
        Tools::settingsSet(self::GROUP, $key, $value);
    }

    public static function save(): bool
    {
        $done = Tools::settingsSave();
        Tools::settingsClear();
        return $done;
    }
}
