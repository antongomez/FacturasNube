<?php
/**
 * This file is part of FacturasNube plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\FacturasNube\Lib\Nube;

/**
 * Contrato que debe cumplir cada servicio de almacenamiento en la nube
 * (Google Drive, OneDrive...). El servicio de sincronización solo habla con
 * esta interfaz, de forma que añadir un proveedor nuevo no obliga a tocar
 * la lógica de subida de facturas.
 */
interface CloudProvider
{
    /** Identificador corto del servicio: google, onedrive... */
    public function serviceName(): string;

    /** True si hay una cuenta conectada con tokens utilizables. */
    public function isConnected(): bool;

    /** Email de la cuenta conectada, cadena vacía si no hay ninguna. */
    public function accountEmail(): string;

    /**
     * Devuelve el identificador de la carpeta indicada por su ruta relativa
     * a la carpeta raíz configurada, creando las carpetas que falten.
     *
     * @param string[] $segments Por ejemplo ['2026', '2026-08'].
     *
     * @throws CloudException
     */
    public function ensureFolder(array $segments): string;

    /**
     * Sube un archivo nuevo a la carpeta indicada.
     *
     * @return array ['id' => string, 'url' => string]
     *
     * @throws CloudException
     */
    public function uploadFile(string $folderId, string $fileName, string $content, string $mimeType): array;

    /**
     * Reemplaza el contenido de un archivo ya existente.
     *
     * @return array ['id' => string, 'url' => string]
     *
     * @throws CloudException
     */
    public function updateFile(string $fileId, string $fileName, string $content, string $mimeType): array;

    /**
     * Lleva el archivo a otra carpeta conservando su id, para que no se rompan los
     * enlaces ya compartidos. Devuelve true si hubo que moverlo y false si ya
     * estaba en la carpeta indicada.
     *
     * @throws CloudException
     */
    public function moveFile(string $fileId, string $folderId): bool;

    /**
     * True si el archivo sigue existiendo en el servicio. Debe lanzar una excepción
     * si no se puede saber: devolver false ante un fallo de red haría que se subiera
     * un duplicado en lugar de reintentar.
     *
     * @throws CloudException
     */
    public function fileExists(string $fileId): bool;

    /**
     * Elimina el archivo. Si $trash es true lo envía a la papelera,
     * en caso contrario lo borra definitivamente.
     *
     * @throws CloudException
     */
    public function deleteFile(string $fileId, bool $trash = true): bool;
}
