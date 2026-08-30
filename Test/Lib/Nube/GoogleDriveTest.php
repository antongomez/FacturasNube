<?php
/**
 * This file is part of FacturasNube plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Test\Plugins\FacturasNube\Lib\Nube;

use FacturaScripts\Core\Tools;
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

    protected function setUp(): void
    {
        $this->clientIdBackup = Tools::settings(Config::GROUP, 'client_id');
    }

    protected function tearDown(): void
    {
        Tools::settingsSet(Config::GROUP, 'client_id', $this->clientIdBackup);
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
}
