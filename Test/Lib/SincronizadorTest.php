<?php
/**
 * This file is part of FacturasNube plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Test\Plugins\FacturasNube\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\ArchivoNube;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Plugins\FacturasNube\Lib\Nube\Config;
use FacturaScripts\Plugins\FacturasNube\Lib\Nube\GoogleDrive;
use FacturaScripts\Plugins\FacturasNube\Lib\Sincronizador;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class SincronizadorTest extends TestCase
{
    use LogErrorsTrait;
    use RandomDataTrait;

    protected function setUp(): void
    {
        Tools::settingsSet(Config::GROUP, 'activo', true);
        Tools::settingsSet(Config::GROUP, 'subcarpetas', true);
        Tools::settingsSet(Config::GROUP, 'plantilla_nombre', '{codigo}');
        Tools::settingsSet(Config::GROUP, 'al_borrar', Config::ON_DELETE_TRASH);
    }

    protected function tearDown(): void
    {
        Tools::settingsSet(Config::GROUP, 'activo', false);
        $this->logErrors();
    }

    public function testFileNameUsesTemplate(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $this->assertTrue($invoice->save());

        $this->assertSame($invoice->codigo . '.pdf', Sincronizador::fileName($invoice), 'wrong-default-file-name');

        Tools::settingsSet(Config::GROUP, 'plantilla_nombre', '{fecha}_{codigo}');
        $expected = date('Y-m-d', strtotime($invoice->fecha)) . '_' . $invoice->codigo . '.pdf';
        $this->assertSame($expected, Sincronizador::fileName($invoice), 'wrong-templated-file-name');

        $this->deleteInvoice($invoice);
    }

    public function testFileNameRemovesPathSeparators(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $this->assertTrue($invoice->save());

        Tools::settingsSet(Config::GROUP, 'plantilla_nombre', 'a/b\\c:d*e?f"g<h>i|j');
        $name = Sincronizador::fileName($invoice);

        foreach (['/', '\\', ':', '*', '?', '"', '<', '>', '|'] as $char) {
            $this->assertStringNotContainsString($char, $name, 'file-name-keeps-invalid-char');
        }
        $this->assertStringEndsWith('.pdf', $name, 'file-name-without-extension');

        $this->deleteInvoice($invoice);
    }

    public function testFolderSegments(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $this->assertTrue($invoice->save());

        $time = strtotime($invoice->fecha);
        $this->assertSame(
            [date('Y', $time), date('Y-m', $time)],
            Sincronizador::folderSegments($invoice),
            'wrong-folder-segments'
        );

        Tools::settingsSet(Config::GROUP, 'subcarpetas', false);
        $this->assertSame([], Sincronizador::folderSegments($invoice), 'subfolders-not-disabled');

        $this->deleteInvoice($invoice);
    }

    public function testDocumentHashDetectsChanges(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $this->assertTrue($invoice->save());

        $name = Sincronizador::fileName($invoice);
        $segments = Sincronizador::folderSegments($invoice);
        $hash = Sincronizador::documentHash($invoice, $name, $segments);

        // el mismo documento da siempre la misma huella
        $this->assertSame($hash, Sincronizador::documentHash($invoice, $name, $segments), 'hash-not-stable');

        // cambiar el nombre o la carpeta destino cambia la huella
        $this->assertNotSame($hash, Sincronizador::documentHash($invoice, 'otro.pdf', $segments), 'hash-ignores-name');
        $this->assertNotSame($hash, Sincronizador::documentHash($invoice, $name, ['x']), 'hash-ignores-folder');

        // modificar el documento también
        $invoice->observaciones = 'texto de prueba';
        $this->assertNotSame($hash, Sincronizador::documentHash($invoice, $name, $segments), 'hash-ignores-document');

        $this->deleteInvoice($invoice);
    }

    public function testEnqueueRequiresPluginEnabled(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $this->assertTrue($invoice->save());

        Tools::settingsSet(Config::GROUP, 'activo', false);
        $this->assertFalse(Sincronizador::enqueue($invoice), 'enqueued-while-disabled');
        $this->assertNull($this->findRow($invoice), 'row-created-while-disabled');

        Tools::settingsSet(Config::GROUP, 'activo', true);
        $this->assertTrue(Sincronizador::enqueue($invoice), 'not-enqueued');

        $row = $this->findRow($invoice);
        $this->assertNotNull($row, 'row-not-created');
        $this->assertSame(ArchivoNube::ESTADO_PENDING, $row->estado, 'wrong-state');
        $this->assertSame($invoice->codigo, $row->codigo, 'wrong-code');

        $this->deleteInvoice($invoice);
    }

    public function testEnqueueReusesTheSameRow(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $this->assertTrue($invoice->save());

        $this->assertTrue(Sincronizador::enqueue($invoice));
        $first = $this->findRow($invoice);

        // simulamos varios intentos fallidos
        $first->fail('boom');
        $this->assertSame(ArchivoNube::ESTADO_ERROR, $first->estado, 'wrong-state-after-fail');
        $this->assertSame(1, (int)$first->intentos, 'attempts-not-increased');

        // volver a encolar reutiliza la fila y devuelve los intentos a cero
        $this->assertTrue(Sincronizador::enqueue($invoice));
        $second = $this->findRow($invoice);

        $this->assertSame($first->id, $second->id, 'duplicated-row');
        $this->assertSame(ArchivoNube::ESTADO_PENDING, $second->estado, 'state-not-reset');
        $this->assertSame(0, (int)$second->intentos, 'attempts-not-reset');
        $this->assertSame(1, $this->countRows($invoice), 'more-than-one-row');

        $this->deleteInvoice($invoice);
    }

    public function testEnqueueDeleteKeepsFileWhenConfigured(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $this->assertTrue($invoice->save());
        $this->assertTrue(Sincronizador::enqueue($invoice));

        $row = $this->findRow($invoice);
        $row->file_id = 'file-de-prueba';
        $this->assertTrue($row->save());

        Tools::settingsSet(Config::GROUP, 'al_borrar', Config::ON_DELETE_KEEP);
        $this->assertTrue(Sincronizador::enqueueDelete($invoice->idfactura));
        $this->assertSame(
            ArchivoNube::ESTADO_DELETED,
            $this->findRow($invoice)->estado,
            'file-marked-for-deletion-while-keeping'
        );

        Tools::settingsSet(Config::GROUP, 'al_borrar', Config::ON_DELETE_TRASH);
        $this->assertTrue(Sincronizador::enqueueDelete($invoice->idfactura));
        $this->assertSame(
            ArchivoNube::ESTADO_TO_DELETE,
            $this->findRow($invoice)->estado,
            'file-not-marked-for-deletion'
        );

        $this->deleteInvoice($invoice);
    }

    public function testSyncFailsWithoutConnectedAccount(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $this->assertTrue($invoice->save());
        $this->assertTrue(Sincronizador::enqueue($invoice));

        // sin cuenta conectada, el intento debe contarse como fallido
        $row = $this->findRow($invoice);
        $this->assertFalse(Sincronizador::syncOne($row), 'sync-reported-success');

        $row = $this->findRow($invoice);
        $this->assertSame(ArchivoNube::ESTADO_ERROR, $row->estado, 'wrong-state-after-failure');
        $this->assertSame(1, (int)$row->intentos, 'attempts-not-increased');
        $this->assertNotEmpty($row->error, 'error-not-stored');

        $this->deleteInvoice($invoice);
    }

    public function testSyncPendingStopsAfterMaxRetries(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $this->assertTrue($invoice->save());
        $this->assertTrue(Sincronizador::enqueue($invoice));

        $row = $this->findRow($invoice);
        $row->intentos = Config::maxRetries();
        $row->estado = ArchivoNube::ESTADO_ERROR;
        $this->assertTrue($row->save());

        $result = Sincronizador::syncPending();
        $this->assertSame(0, $result['total'], 'exhausted-row-processed');

        $this->deleteInvoice($invoice);
    }

    public function testPdfIsGenerated(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $this->assertTrue($invoice->save());

        $pdf = Sincronizador::pdf($invoice);
        $this->assertNotEmpty($pdf, 'empty-pdf');
        $this->assertStringStartsWith('%PDF-', $pdf, 'not-a-pdf');

        $this->deleteInvoice($invoice);
    }

    private function findRow(FacturaCliente $invoice): ?ArchivoNube
    {
        return ArchivoNube::findWhere([
            Where::eq('servicio', GoogleDrive::SERVICE),
            Where::eq('modelo', Sincronizador::MODELO),
            Where::eq('idregistro', (string)$invoice->idfactura),
        ]);
    }

    private function countRows(FacturaCliente $invoice): int
    {
        return ArchivoNube::count([
            Where::eq('modelo', Sincronizador::MODELO),
            Where::eq('idregistro', (string)$invoice->idfactura),
        ]);
    }

    private function deleteInvoice(FacturaCliente $invoice): void
    {
        foreach (ArchivoNube::allWhereEq('idregistro', (string)$invoice->idfactura) as $row) {
            $row->delete();
        }

        $customer = $invoice->getSubject();
        $this->assertTrue($invoice->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($customer->delete());
    }
}
