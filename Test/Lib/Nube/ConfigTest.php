<?php
/**
 * This file is part of FacturasNube plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Test\Plugins\FacturasNube\Lib\Nube;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FacturasNube\Lib\Nube\Config;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    use LogErrorsTrait;

    /** @var string|null */
    private $siteUrlBackup;

    protected function setUp(): void
    {
        $this->siteUrlBackup = Tools::settings('default', 'site_url');
    }

    protected function tearDown(): void
    {
        Tools::settingsSet('default', 'site_url', $this->siteUrlBackup);

        foreach (['carpeta_nombre', 'al_borrar', 'max_intentos', 'tam_lote', 'plantilla_nombre', 'scope', 'carpeta_raiz'] as $key) {
            Tools::settingsSet(Config::GROUP, $key, null);
        }

        $this->logErrors();
    }

    public function testDefaults(): void
    {
        Tools::settingsSet(Config::GROUP, 'carpeta_nombre', '');
        $this->assertSame('Facturas FacturaScripts', Config::rootFolderName(), 'wrong-default-folder-name');

        Tools::settingsSet(Config::GROUP, 'plantilla_nombre', '');
        $this->assertSame('{codigo}', Config::fileNameTemplate(), 'wrong-default-template');

        Tools::settingsSet(Config::GROUP, 'scope', '');
        $this->assertSame(Config::SCOPE_FULL, Config::scope(), 'wrong-default-scope');
    }

    public function testScopeOnlyAcceptsKnownValues(): void
    {
        Tools::settingsSet(Config::GROUP, 'scope', Config::SCOPE_FILE);
        $this->assertSame(Config::SCOPE_FILE, Config::scope(), 'minimum-scope-rejected');

        // cualquier otra cosa vuelve al permiso completo, que es el que siempre funciona
        Tools::settingsSet(Config::GROUP, 'scope', 'https://example.com/lo-que-sea');
        $this->assertSame(Config::SCOPE_FULL, Config::scope(), 'unknown-scope-accepted');
    }

    public function testMinimumScopeConflictsWithAForeignFolder(): void
    {
        // con drive.file el plugin no ve carpetas que no ha creado él
        Tools::settingsSet(Config::GROUP, 'scope', Config::SCOPE_FILE);
        Tools::settingsSet(Config::GROUP, 'carpeta_raiz', 'id-de-una-carpeta-ajena');
        $this->assertTrue(Config::scopeConflictsWithFolder(), 'conflict-not-detected');

        // sin carpeta fija crea la suya, así que no hay conflicto
        Tools::settingsSet(Config::GROUP, 'carpeta_raiz', '');
        $this->assertFalse(Config::scopeConflictsWithFolder(), 'false-conflict-without-folder');

        // con acceso completo puede escribir donde le digas
        Tools::settingsSet(Config::GROUP, 'scope', Config::SCOPE_FULL);
        Tools::settingsSet(Config::GROUP, 'carpeta_raiz', 'id-de-una-carpeta-ajena');
        $this->assertFalse(Config::scopeConflictsWithFolder(), 'false-conflict-with-full-scope');
    }

    public function testOnDeleteRejectsUnknownValues(): void
    {
        Tools::settingsSet(Config::GROUP, 'al_borrar', 'lo-que-sea');
        $this->assertSame(Config::ON_DELETE_TRASH, Config::onDelete(), 'invalid-value-accepted');

        Tools::settingsSet(Config::GROUP, 'al_borrar', Config::ON_DELETE_DELETE);
        $this->assertSame(Config::ON_DELETE_DELETE, Config::onDelete(), 'valid-value-rejected');
    }

    public function testNumericSettingsFallBackToSaneValues(): void
    {
        Tools::settingsSet(Config::GROUP, 'max_intentos', 0);
        $this->assertSame(5, Config::maxRetries(), 'zero-retries-accepted');

        Tools::settingsSet(Config::GROUP, 'max_intentos', -3);
        $this->assertSame(5, Config::maxRetries(), 'negative-retries-accepted');

        Tools::settingsSet(Config::GROUP, 'max_intentos', 9);
        $this->assertSame(9, Config::maxRetries(), 'configured-retries-ignored');

        Tools::settingsSet(Config::GROUP, 'tam_lote', 0);
        $this->assertSame(25, Config::batchSize(), 'zero-batch-accepted');
    }

    public function testRedirectUriIgnoresStraySpacesInSiteUrl(): void
    {
        $clean = Config::redirectUri();

        // un espacio pegado por accidente en site_url es invisible en pantalla,
        // pero Google compara la URI carácter a carácter y la rechazaría
        foreach ([' ', "\n", "\t", '/', " \n"] as $noise) {
            Tools::settingsSet('default', 'site_url', $noise . 'https://ejemplo.test' . $noise);
            $this->assertSame(
                'https://ejemplo.test/oauth2/facturas-nube/google',
                Config::redirectUri(),
                'stray-whitespace-reaches-the-redirect-uri'
            );
        }

        Tools::settingsSet('default', 'site_url', null);
        $this->assertNotEmpty($clean, 'empty-redirect-uri');
    }

    public function testRedirectUriHasNoQueryString(): void
    {
        $uri = Config::redirectUri();

        // Google exige que el URI de redirección coincida exactamente,
        // por eso la ruta es fija y sin parámetros de consulta
        $this->assertStringEndsWith('/oauth2/facturas-nube/google', $uri, 'wrong-redirect-path');
        $this->assertStringNotContainsString('?', $uri, 'redirect-uri-with-query-string');
    }
}
