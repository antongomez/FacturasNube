<?php
/**
 * This file is part of FacturasNube plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Test\Plugins\FacturasNube\Lib\Nube;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FacturasNube\Lib\Nube\CloudException;
use FacturaScripts\Plugins\FacturasNube\Lib\Nube\CloudProvider;
use FacturaScripts\Plugins\FacturasNube\Lib\Nube\Config;
use FacturaScripts\Plugins\FacturasNube\Lib\Nube\GoogleDrive;
use FacturaScripts\Plugins\FacturasNube\Model\CuentaNube;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

final class GoogleDriveTest extends TestCase
{
    use LogErrorsTrait;

    /** @var mixed */
    private $clientIdBackup;

    /** @var mixed */
    private $rootFolderBackup;

    protected function setUp(): void
    {
        $this->clientIdBackup = Tools::settings(Config::GROUP, 'client_id');
        $this->rootFolderBackup = Tools::settings(Config::GROUP, 'carpeta_raiz');
    }

    protected function tearDown(): void
    {
        Tools::settingsSet(Config::GROUP, 'client_id', $this->clientIdBackup);
        Tools::settingsSet(Config::GROUP, 'carpeta_raiz', $this->rootFolderBackup);
        $this->logErrors();
    }

    public function testImplementsTheProviderContract(): void
    {
        $this->assertInstanceOf(CloudProvider::class, new GoogleDrive(), 'contract-not-implemented');
        $this->assertSame('google', (new GoogleDrive())->serviceName(), 'wrong-service-name');
    }

    public function testAuthUrlAsksForOfflineAccess(): void
    {
        Tools::settingsSet(Config::GROUP, 'client_id', 'test-client-id');

        $url = GoogleDrive::authUrl('estado-de-prueba');
        $this->assertStringStartsWith(GoogleDrive::AUTH_URL, $url, 'wrong-auth-endpoint');

        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame('test-client-id', $params['client_id'] ?? '', 'client-id-not-sent');
        $this->assertSame('code', $params['response_type'] ?? '', 'wrong-response-type');
        $this->assertSame('estado-de-prueba', $params['state'] ?? '', 'state-not-sent');
        $this->assertSame(Config::redirectUri(), $params['redirect_uri'] ?? '', 'wrong-redirect-uri');

        // sin offline + consent Google no devuelve refresh_token,
        // y sin refresh_token el cron no puede subir nada por su cuenta
        $this->assertSame('offline', $params['access_type'] ?? '', 'not-asking-for-offline-access');
        $this->assertSame('consent', $params['prompt'] ?? '', 'not-forcing-consent');
    }

    public function testHasScopeReadsTheGrantedList(): void
    {
        $account = new CuentaNube();
        $account->servicio = GoogleDrive::SERVICE;

        // sin permisos guardados no se puede afirmar que tenga ninguno
        $this->assertFalse($account->hasScope(Config::SCOPE_FILE), 'scope-granted-without-data');

        // Google devuelve los permisos concedidos separados por espacios
        $account->scope = Config::SCOPE_FILE . ' https://www.googleapis.com/auth/userinfo.email';
        $this->assertTrue($account->hasScope(Config::SCOPE_FILE), 'granted-scope-not-detected');

        // el permiso mínimo no implica el completo: hay que reconectar para ampliarlo
        $this->assertFalse($account->hasScope(Config::SCOPE_FULL), 'wider-scope-assumed');
    }

    public function testIsNotConnectedWithoutAccount(): void
    {
        $account = CuentaNube::forService(GoogleDrive::SERVICE);
        $this->assertNull($account, 'unexpected-account-in-test-database');

        $drive = new GoogleDrive();
        $this->assertFalse($drive->isConnected(), 'connected-without-account');
        $this->assertSame('', $drive->accountEmail(), 'unexpected-email');
    }

    public function testTokenExpiryIsDetected(): void
    {
        $account = new CuentaNube();
        $account->servicio = GoogleDrive::SERVICE;
        $account->refresh_token = 'refresh-de-prueba';

        // sin token de acceso se considera caducado
        $this->assertTrue($account->tokenExpired(), 'missing-token-not-expired');

        $account->access_token = 'token-de-prueba';
        $account->expires = Tools::dateTime('-1 hour');
        $this->assertTrue($account->tokenExpired(), 'past-token-not-expired');

        // el margen de seguridad hace que un token a punto de caducar cuente como caducado
        $account->expires = Tools::dateTime('+30 seconds');
        $this->assertTrue($account->tokenExpired(), 'token-about-to-expire-not-renewed');

        $account->expires = Tools::dateTime('+1 hour');
        $this->assertFalse($account->tokenExpired(), 'valid-token-marked-as-expired');
    }

    public function testCachedFolderInTrashIsForgottenAndRecreated(): void
    {
        // en Drive "borrar" es enviar a la papelera, y una carpeta en la papelera
        // sigue aceptando subidas sin dar error: todo lo que entra nace invisible.
        // Un id cacheado no se puede devolver sin comprobar que sigue vivo.
        $cacheKey = 'facturasnube-google-folder-' . md5('padre/Facturas');
        Cache::set($cacheKey, 'carpeta-en-papelera');

        $drive = $this->fakeDrive(['carpeta-en-papelera' => false]);
        $folderId = $drive->findOrCreate('Facturas', 'padre');

        $this->assertSame('carpeta-nueva', $folderId, 'trashed-folder-id-still-used');
        $this->assertSame(['Facturas'], $drive->created, 'folder-not-recreated');
        $this->assertSame('carpeta-nueva', Cache::get($cacheKey), 'cache-not-refreshed');

        Cache::delete($cacheKey);
    }

    public function testCachedFolderIsReusedWhileItExists(): void
    {
        $cacheKey = 'facturasnube-google-folder-' . md5('padre/Facturas');
        Cache::set($cacheKey, 'carpeta-viva');

        $drive = $this->fakeDrive(['carpeta-viva' => true]);
        $this->assertSame('carpeta-viva', $drive->findOrCreate('Facturas', 'padre'), 'cached-folder-not-reused');
        $this->assertSame([], $drive->created, 'folder-recreated-while-alive');

        Cache::delete($cacheKey);
    }

    public function testConfiguredRootFolderInTrashStopsTheUpload(): void
    {
        // el id lo escribió el usuario: si apunta a la papelera hay que avisar,
        // no trabajar contra una carpeta que nadie va a ver
        Tools::settingsSet(Config::GROUP, 'carpeta_raiz', 'id-en-papelera');

        $drive = $this->fakeDrive([]);
        $this->expectException(CloudException::class);
        $drive->rootFolderId();
    }

    /**
     * Un GoogleDrive sin red: las carpetas "vivas" se declaran en el mapa,
     * la búsqueda por nombre nunca encuentra nada y crear siempre funciona.
     *
     * @param array<string,bool> $alive
     */
    private function fakeDrive(array $alive)
    {
        return new class($alive) extends GoogleDrive {
            /** @var array<string,bool> */
            public $alive;

            /** @var string[] */
            public $created = [];

            public function __construct(array $alive)
            {
                parent::__construct(new CuentaNube());
                $this->alive = $alive;
            }

            public function fileExists(string $fileId): bool
            {
                return !empty($this->alive[$fileId]);
            }

            public function findOrCreate(string $name, string $parentId): string
            {
                return $this->findOrCreateFolder($name, $parentId, $name);
            }

            protected function apiGet(string $path, array $query = []): array
            {
                return ['files' => []];
            }

            protected function apiPostJson(string $path, array $body, array $query = []): array
            {
                $this->created[] = (string)($body['name'] ?? '');
                return ['id' => 'carpeta-nueva'];
            }
        };
    }
}
