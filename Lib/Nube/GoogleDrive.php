<?php
/**
 * This file is part of FacturasNube plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\FacturasNube\Lib\Nube;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Http;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FacturasNube\Model\CuentaNube;

/**
 * Proveedor de Google Drive sobre la API REST v3.
 *
 * No usa el SDK oficial de Google: todas las llamadas se hacen con la clase Http
 * del core, de modo que el plugin no añade dependencias de composer.
 *
 * El flujo de autorización es OAuth 2.0 con access_type=offline, por lo que
 * Google devuelve un refresh_token que permite renovar el acceso sin volver a
 * pedir permiso al usuario.
 */
class GoogleDrive implements CloudProvider
{
    const SERVICE = 'google';

    const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    const API_URL = 'https://www.googleapis.com/drive/v3';
    const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3';

    const FOLDER_MIME = 'application/vnd.google-apps.folder';

    /** @var CuentaNube|null */
    protected $cuenta;

    /** Carpetas ya comprobadas en este proceso, para no repetir la llamada. @var array<string,bool> */
    protected $aliveFolders = [];

    public function __construct(?CuentaNube $cuenta = null)
    {
        $this->cuenta = $cuenta ?? CuentaNube::forService(self::SERVICE);
    }

    public function serviceName(): string
    {
        return self::SERVICE;
    }

    public function isConnected(): bool
    {
        return $this->cuenta && !empty($this->cuenta->refresh_token);
    }

    public function accountEmail(): string
    {
        return $this->cuenta ? (string)$this->cuenta->email : '';
    }

    // --- OAuth ---------------------------------------------------------

    /** URL a la que hay que enviar al usuario para que autorice el acceso. */
    public static function authUrl(string $state): string
    {
        $params = [
            'client_id' => Config::clientId(),
            'redirect_uri' => Config::redirectUri(),
            'response_type' => 'code',
            'scope' => Config::scope(),
            'access_type' => 'offline',
            // forzamos el consentimiento para que Google devuelva siempre refresh_token
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Canjea el código de autorización por los tokens y guarda la cuenta.
     *
     * @throws CloudException
     */
    public static function exchangeCode(string $code): CuentaNube
    {
        $response = Http::post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => Config::clientId(),
            'client_secret' => Config::clientSecret(),
            'redirect_uri' => Config::redirectUri(),
            'grant_type' => 'authorization_code',
        ])->setTimeout(30);

        $data = $response->json();
        if ($response->failed() || empty($data['access_token'])) {
            throw new CloudException(self::errorFrom($response, $data), $response->status());
        }

        $cuenta = CuentaNube::forService(self::SERVICE) ?? new CuentaNube();
        $cuenta->servicio = self::SERVICE;
        $cuenta->access_token = $data['access_token'];
        $cuenta->expires = Tools::dateTime('+' . (int)($data['expires_in'] ?? 3600) . ' seconds');
        $cuenta->scope = $data['scope'] ?? Config::scope();
        $cuenta->fecha = Tools::dateTime();

        // Google solo envía refresh_token la primera vez que se autoriza;
        // si no llega, conservamos el que ya teníamos.
        if (!empty($data['refresh_token'])) {
            $cuenta->refresh_token = $data['refresh_token'];
        }

        if (empty($cuenta->refresh_token)) {
            throw new CloudException(Tools::trans('facturasnube-no-refresh-token'));
        }

        if (false === $cuenta->save()) {
            throw new CloudException(Tools::trans('record-save-error'));
        }

        // guardamos el email de la cuenta, solo para mostrarlo en la configuración
        $drive = new self($cuenta);
        $email = $drive->fetchAccountEmail();
        if ($email !== '' && $email !== $cuenta->email) {
            $cuenta->email = $email;
            $cuenta->save();
        }

        return $cuenta;
    }

    /** Revoca el permiso en Google y elimina la cuenta local. */
    public function disconnect(): bool
    {
        if (null === $this->cuenta) {
            return true;
        }

        $token = $this->cuenta->refresh_token ?: $this->cuenta->access_token;
        if (!empty($token)) {
            // si la revocación falla no bloqueamos el borrado local
            Http::post(self::REVOKE_URL, ['token' => $token])->setTimeout(15)->status();
        }

        $done = $this->cuenta->delete();
        $this->cuenta = null;
        $this->forgetFolderCache();
        return $done;
    }

    /**
     * Devuelve un access_token válido, renovándolo con el refresh_token si hace falta.
     *
     * @throws CloudException
     */
    public function accessToken(): string
    {
        if (false === $this->isConnected()) {
            throw new CloudException(Tools::trans('facturasnube-not-connected'));
        }

        if (false === $this->cuenta->tokenExpired()) {
            return $this->cuenta->access_token;
        }

        $response = Http::post(self::TOKEN_URL, [
            'client_id' => Config::clientId(),
            'client_secret' => Config::clientSecret(),
            'refresh_token' => $this->cuenta->refresh_token,
            'grant_type' => 'refresh_token',
        ])->setTimeout(30);

        $data = $response->json();
        if ($response->failed() || empty($data['access_token'])) {
            throw new CloudException(self::errorFrom($response, $data), $response->status());
        }

        $this->cuenta->access_token = $data['access_token'];
        $this->cuenta->expires = Tools::dateTime('+' . (int)($data['expires_in'] ?? 3600) . ' seconds');
        if (!empty($data['refresh_token'])) {
            $this->cuenta->refresh_token = $data['refresh_token'];
        }
        $this->cuenta->save();

        return $this->cuenta->access_token;
    }

    /** Email de la cuenta según Drive. Cadena vacía si no se puede obtener. */
    public function fetchAccountEmail(): string
    {
        try {
            $data = $this->apiGet('/about', ['fields' => 'user']);
        } catch (CloudException $ex) {
            Tools::log('facturasnube')->warning($ex->getMessage());
            return '';
        }

        return (string)($data['user']['emailAddress'] ?? '');
    }

    // --- Carpetas ------------------------------------------------------

    /**
     * @param string[] $segments
     *
     * @throws CloudException
     */
    public function ensureFolder(array $segments): string
    {
        $parent = $this->rootFolderId();
        $path = [];

        foreach ($segments as $segment) {
            $segment = trim((string)$segment);
            if ($segment === '') {
                continue;
            }

            $path[] = $segment;
            $parent = $this->findOrCreateFolder($segment, $parent, implode('/', $path));
        }

        return $parent;
    }

    /**
     * Id de la carpeta raíz: la configurada por el usuario o, si no hay ninguna,
     * una carpeta creada por el plugin en la raíz del Drive.
     *
     * @throws CloudException
     */
    public function rootFolderId(): string
    {
        $configured = Config::rootFolderId();
        if ($configured !== '') {
            // un id que apunta a la papelera aceptaría subidas sin quejarse; mejor
            // avisar de que la configuración ya no vale que hacer subidas invisibles
            if (false === $this->folderAlive($configured)) {
                throw new CloudException(Tools::trans('facturasnube-root-folder-missing', ['%id%' => $configured]));
            }

            return $configured;
        }

        return $this->findOrCreateFolder(Config::rootFolderName(), 'root', '');
    }

    /**
     * Busca una carpeta por nombre dentro de otra y la crea si no existe.
     * El resultado se cachea porque en régimen normal siempre es la misma carpeta.
     *
     * @throws CloudException
     */
    protected function findOrCreateFolder(string $name, string $parentId, string $cachePath): string
    {
        $cacheKey = 'facturasnube-google-folder-' . md5($parentId . '/' . $name);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            if ($this->folderAlive($cached)) {
                return $cached;
            }

            // la carpeta cacheada ya no está (o está en la papelera): se olvida
            // y se busca o crea de nuevo como si fuera la primera vez
            Cache::delete($cacheKey);
        }

        $query = "mimeType = '" . self::FOLDER_MIME . "'"
            . " and name = '" . $this->escapeQuery($name) . "'"
            . " and '" . $this->escapeQuery($parentId) . "' in parents"
            . " and trashed = false";

        $data = $this->apiGet('/files', [
            'q' => $query,
            'fields' => 'files(id,name)',
            'pageSize' => 10,
            'spaces' => 'drive',
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ]);

        $folderId = $data['files'][0]['id'] ?? '';
        if ($folderId === '') {
            $created = $this->apiPostJson('/files', [
                'name' => $name,
                'mimeType' => self::FOLDER_MIME,
                'parents' => [$parentId],
            ], ['fields' => 'id', 'supportsAllDrives' => 'true']);

            $folderId = $created['id'] ?? '';
        }

        if ($folderId === '') {
            throw new CloudException(Tools::trans('facturasnube-folder-error', ['%name%' => $name]));
        }

        Cache::set($cacheKey, $folderId);
        $this->aliveFolders[$folderId] = true;
        return $folderId;
    }

    /**
     * True si la carpeta sigue existiendo fuera de la papelera. En Drive "borrar" es
     * enviar a la papelera, y una carpeta en la papelera sigue aceptando archivos sin
     * dar ningún error: todo lo que se sube a ella nace invisible. Por eso un id
     * guardado no se puede dar por bueno sin comprobarlo, una vez por proceso.
     *
     * @throws CloudException
     */
    protected function folderAlive(string $folderId): bool
    {
        if (isset($this->aliveFolders[$folderId])) {
            return true;
        }

        if ($this->fileExists($folderId)) {
            $this->aliveFolders[$folderId] = true;
            return true;
        }

        return false;
    }

    /** Olvida los ids de carpeta cacheados. Necesario al cambiar de cuenta o de carpeta raíz. */
    public function forgetFolderCache(): void
    {
        Cache::deleteMulti('facturasnube-google-folder-');
    }

    // --- Archivos ------------------------------------------------------

    /**
     * @throws CloudException
     */
    public function uploadFile(string $folderId, string $fileName, string $content, string $mimeType): array
    {
        // si ya hay un archivo con ese nombre en la carpeta, lo reutilizamos. Evita
        // duplicados cuando se ha perdido el id guardado: al reinstalar el plugin,
        // al borrar filas del registro, o si una subida anterior se quedó a medias.
        $existingId = $this->findInFolder($folderId, $fileName);
        if ($existingId !== '') {
            return $this->updateFile($existingId, $fileName, $content, $mimeType);
        }

        $metadata = [
            'name' => $fileName,
            'parents' => [$folderId],
        ];

        $data = $this->uploadMultipart('POST', '/files', $metadata, $content, $mimeType);
        return [
            'id' => (string)($data['id'] ?? ''),
            'url' => (string)($data['webViewLink'] ?? ''),
        ];
    }

    /**
     * @throws CloudException
     */
    public function updateFile(string $fileId, string $fileName, string $content, string $mimeType): array
    {
        // en un PATCH no se puede enviar "parents": para mover un archivo
        // Drive exige los parámetros addParents/removeParents.
        $metadata = ['name' => $fileName];

        $data = $this->uploadMultipart('PATCH', '/files/' . rawurlencode($fileId), $metadata, $content, $mimeType);
        return [
            'id' => (string)($data['id'] ?? $fileId),
            'url' => (string)($data['webViewLink'] ?? ''),
        ];
    }

    /**
     * @throws CloudException
     */
    /**
     * Id del archivo con ese nombre dentro de la carpeta, o cadena vacía si no lo hay.
     *
     * @throws CloudException
     */
    public function findInFolder(string $folderId, string $fileName): string
    {
        $query = "name = '" . $this->escapeQuery($fileName) . "'"
            . " and '" . $this->escapeQuery($folderId) . "' in parents"
            . " and trashed = false";

        $data = $this->apiGet('/files', [
            'q' => $query,
            'fields' => 'files(id)',
            'pageSize' => 1,
            'spaces' => 'drive',
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ]);

        return (string)($data['files'][0]['id'] ?? '');
    }

    /**
     * Lleva el archivo a otra carpeta conservando su id, de forma que los enlaces
     * que ya se hubieran compartido siguen siendo válidos.
     *
     * @throws CloudException
     */
    public function moveFile(string $fileId, string $folderId): bool
    {
        $data = $this->apiGet('/files/' . rawurlencode($fileId), [
            'fields' => 'parents',
            'supportsAllDrives' => 'true',
        ]);

        $current = $data['parents'] ?? [];
        if ($current === [$folderId]) {
            return true;
        }

        // Drive no admite cambiar "parents" en un PATCH normal: hay que usar
        // los parámetros addParents y removeParents
        $query = [
            'addParents' => $folderId,
            'supportsAllDrives' => 'true',
        ];
        if (!empty($current)) {
            $query['removeParents'] = implode(',', $current);
        }

        $this->apiPatchJson('/files/' . rawurlencode($fileId), [], $query);

        return true;
    }

    public function fileExists(string $fileId): bool
    {
        if (empty($fileId)) {
            return false;
        }

        try {
            $data = $this->apiGet('/files/' . rawurlencode($fileId), [
                'fields' => 'id,trashed',
                'supportsAllDrives' => 'true',
            ]);
        } catch (CloudException $ex) {
            // solo un 404 confirma que el archivo ya no está. Ante cualquier otro
            // fallo propagamos el error: dar el archivo por perdido subiría un duplicado
            if ($ex->getStatusCode() === 404) {
                return false;
            }

            throw $ex;
        }

        return !empty($data['id']) && empty($data['trashed']);
    }

    /**
     * @throws CloudException
     */
    public function deleteFile(string $fileId, bool $trash = true): bool
    {
        if (empty($fileId)) {
            return true;
        }

        if ($trash) {
            $this->apiPatchJson('/files/' . rawurlencode($fileId), ['trashed' => true], [
                'supportsAllDrives' => 'true',
            ]);
            return true;
        }

        $url = self::API_URL . '/files/' . rawurlencode($fileId) . '?' . http_build_query(['supportsAllDrives' => 'true']);
        $response = Http::delete($url)
            ->setBearerToken($this->accessToken())
            ->setTimeout(30);

        // un DELETE correcto devuelve 204, que Http::ok() no considera éxito
        $status = $response->status();
        if ($status !== 204 && $status !== 200 && $status !== 404) {
            throw new CloudException(self::errorFrom($response, $response->json()), $status);
        }

        return true;
    }

    // --- Llamadas de bajo nivel ----------------------------------------

    /**
     * @throws CloudException
     */
    protected function apiGet(string $path, array $query = []): array
    {
        $response = Http::get(self::API_URL . $path, $query)
            ->setBearerToken($this->accessToken())
            ->setTimeout(30);

        return $this->decode($response);
    }

    /**
     * @throws CloudException
     */
    protected function apiPostJson(string $path, array $body, array $query = []): array
    {
        $url = self::API_URL . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $response = Http::postJson($url, $body)
            ->setBearerToken($this->accessToken())
            ->setTimeout(30);

        return $this->decode($response);
    }

    /**
     * @throws CloudException
     */
    protected function apiPatchJson(string $path, array $body, array $query = []): array
    {
        $url = self::API_URL . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $response = Http::patch($url, json_encode($body))
            ->setHeader('Content-Type', 'application/json')
            ->setBearerToken($this->accessToken())
            ->setTimeout(30);

        return $this->decode($response);
    }

    /**
     * Sube metadatos y contenido en una sola petición multipart/related,
     * que es lo que espera el endpoint de subida de Drive con uploadType=multipart.
     *
     * @throws CloudException
     */
    protected function uploadMultipart(string $method, string $path, array $metadata, string $content, string $mimeType): array
    {
        $boundary = 'fsnube' . bin2hex(random_bytes(12));
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . json_encode($metadata) . "\r\n"
            . '--' . $boundary . "\r\n"
            . 'Content-Type: ' . $mimeType . "\r\n\r\n"
            . $content . "\r\n"
            . '--' . $boundary . '--';

        $url = self::UPLOAD_URL . $path . '?' . http_build_query([
                'uploadType' => 'multipart',
                'supportsAllDrives' => 'true',
                'fields' => 'id,name,webViewLink',
            ]);

        $request = $method === 'PATCH' ? Http::patch($url, $body) : Http::post($url, $body);
        $response = $request
            ->setHeader('Content-Type', 'multipart/related; boundary=' . $boundary)
            ->setBearerToken($this->accessToken())
            ->setTimeout(120);

        return $this->decode($response);
    }

    /**
     * @throws CloudException
     */
    protected function decode(Http $response): array
    {
        $data = $response->json();
        if ($response->failed()) {
            throw new CloudException(self::errorFrom($response, $data), $response->status());
        }

        return is_array($data) ? $data : [];
    }

    /** Compone un mensaje de error legible a partir de la respuesta de Google. */
    protected static function errorFrom(Http $response, $data): string
    {
        if (is_array($data)) {
            $message = $data['error']['message']
                ?? $data['error_description']
                ?? (is_string($data['error'] ?? null) ? $data['error'] : '');
            if (!empty($message)) {
                return 'Google Drive (' . $response->status() . '): ' . $message;
            }
        }

        $curlError = $response->errorMessage();
        if (!empty($curlError)) {
            return 'Google Drive: ' . $curlError;
        }

        return 'Google Drive (' . $response->status() . '): ' . mb_substr($response->body(), 0, 300);
    }

    /** Escapa el texto para poder incrustarlo en el parámetro q de la API. */
    protected function escapeQuery(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }
}
