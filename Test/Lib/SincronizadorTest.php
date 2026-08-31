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

    /**
     * Opciones que tocan estos tests. Config::setSweepCursor() guarda la configuración
     * en la base de datos, y Tools::settingsSave() vuelca de paso todo lo que haya en
     * memoria, así que sin copia de seguridad los tests dejarían el plugin activado
     * y el cursor movido en la instalación donde se ejecutan.
     *
     * @var string[]
     */
    const TOUCHED_SETTINGS = [
        'activo', 'al_borrar', 'carpeta_nombre', 'carpeta_raiz', 'cursor_historico',
        'fecha_inicio', 'historico', 'plantilla_nombre', 'subcarpetas', 'tam_lote',
    ];

    /** @var array */
    private $settingsBackup = [];

    protected function setUp(): void
    {
        foreach (self::TOUCHED_SETTINGS as $key) {
            $this->settingsBackup[$key] = Tools::settings(Config::GROUP, $key);
        }

        Tools::settingsSet(Config::GROUP, 'activo', true);
        Tools::settingsSet(Config::GROUP, 'historico', true);
        Tools::settingsSet(Config::GROUP, 'fecha_inicio', '');
        Tools::settingsSet(Config::GROUP, 'subcarpetas', true);
        Tools::settingsSet(Config::GROUP, 'plantilla_nombre', '{codigo}');
        Tools::settingsSet(Config::GROUP, 'al_borrar', Config::ON_DELETE_TRASH);
        Tools::settingsSet(Config::GROUP, 'carpeta_raiz', '');
        Tools::settingsSet(Config::GROUP, 'carpeta_nombre', 'carpeta-de-prueba');
    }

    protected function tearDown(): void
    {
        foreach ($this->settingsBackup as $key => $value) {
            Tools::settingsSet(Config::GROUP, $key, $value);
        }
        Tools::settingsSet(Config::GROUP, 'activo', false);
        Tools::settingsSave();
        Tools::settingsClear();

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

        // la carpeta raíz también decide dónde acaba el archivo: cambiarla (por nombre
        // o por id) tiene que cambiar la huella, o el archivo no se recolocaría nunca
        Tools::settingsSet(Config::GROUP, 'carpeta_nombre', 'otra-carpeta-de-prueba');
        $withRootName = Sincronizador::documentHash($invoice, $name, $segments);
        $this->assertNotSame($hash, $withRootName, 'hash-ignores-root-name');

        Tools::settingsSet(Config::GROUP, 'carpeta_raiz', 'id-de-prueba');
        $this->assertNotSame($withRootName, Sincronizador::documentHash($invoice, $name, $segments), 'hash-ignores-root-id');

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

        // el repaso del histórico traería otras facturas de la base de datos de pruebas
        // y enturbiaría el recuento, así que aquí solo nos interesa la cola
        Tools::settingsSet(Config::GROUP, 'historico', false);
        Sincronizador::syncPending();

        // la fila agotada tiene que seguir intacta: ni un intento más
        $row = $this->findRow($invoice);
        $this->assertSame(Config::maxRetries(), (int)$row->intentos, 'exhausted-row-processed');
        $this->assertSame(ArchivoNube::ESTADO_ERROR, $row->estado, 'exhausted-row-state-changed');

        $this->deleteInvoice($invoice);
    }

    public function testSweepQueuesInvoicesNobodyMarked(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $this->assertTrue($invoice->save());

        // nadie la ha marcado: es exactamente el caso del histórico anterior al plugin
        $this->assertNull($this->findRow($invoice), 'unexpected-row');

        // colocamos el repaso justo antes de esta factura
        Config::setSweepCursor((int)$invoice->idfactura - 1);
        $this->assertSame(1, Sincronizador::sweepHistoric(1), 'sweep-queued-nothing');

        $row = $this->findRow($invoice);
        $this->assertNotNull($row, 'row-not-created-by-sweep');
        $this->assertSame(ArchivoNube::ESTADO_PENDING, $row->estado, 'wrong-state');

        // el cursor ha avanzado, así que repasar otra vez no duplica nada
        $this->assertSame(0, Sincronizador::sweepHistoric(1), 'sweep-queued-twice');
        $this->assertSame(1, $this->countRows($invoice), 'duplicated-row');

        $this->deleteInvoice($invoice);
    }

    public function testSweepDoesNotResetAnAlreadySyncedRow(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $this->assertTrue($invoice->save());
        $this->assertTrue(Sincronizador::enqueue($invoice));

        // la damos por subida
        $row = $this->findRow($invoice);
        $this->assertTrue($row->success('id-de-prueba', 'https://ejemplo.test/f', 'hash-de-prueba'));

        // el repaso vuelve a pasar por encima y no debe tocarla
        Config::setSweepCursor((int)$invoice->idfactura - 1);
        $this->assertSame(0, Sincronizador::sweepHistoric(1), 'synced-row-requeued');

        $row = $this->findRow($invoice);
        $this->assertSame(ArchivoNube::ESTADO_SYNCED, $row->estado, 'synced-state-lost');
        $this->assertSame('id-de-prueba', $row->file_id, 'file-id-lost');

        $this->deleteInvoice($invoice);
    }

    public function testStartDateExcludesOlderInvoices(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $this->assertTrue($invoice->save());

        // una fecha de inicio posterior deja la factura fuera, incluso al modificarla
        Tools::settingsSet(Config::GROUP, 'fecha_inicio', date('Y-m-d', strtotime($invoice->fecha . ' +1 day')));
        $this->assertFalse(Sincronizador::inRange($invoice), 'invoice-should-be-out-of-range');
        $this->assertFalse(Sincronizador::enqueue($invoice), 'out-of-range-invoice-queued');

        Config::setSweepCursor((int)$invoice->idfactura - 1);
        $this->assertSame(0, Sincronizador::sweepHistoric(1), 'out-of-range-invoice-swept');
        $this->assertNull($this->findRow($invoice), 'row-created-out-of-range');

        // con la fecha de inicio en la propia factura sí entra
        Tools::settingsSet(Config::GROUP, 'fecha_inicio', date('Y-m-d', strtotime($invoice->fecha)));
        $this->assertTrue(Sincronizador::inRange($invoice), 'invoice-should-be-in-range');
        $this->assertTrue(Sincronizador::enqueue($invoice), 'in-range-invoice-not-queued');

        $this->deleteInvoice($invoice);
    }

    public function testSweepCanBeTurnedOff(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $this->assertTrue($invoice->save());

        Tools::settingsSet(Config::GROUP, 'historico', false);
        Config::setSweepCursor((int)$invoice->idfactura - 1);

        $this->assertSame(0, Sincronizador::sweepHistoric(1), 'swept-while-disabled');
        $this->assertSame(0, Sincronizador::pendingSweepCount(), 'counts-while-disabled');
        $this->assertNull($this->findRow($invoice), 'row-created-while-disabled');

        $this->deleteInvoice($invoice);
    }

    public function testRemainingCountAddsQueueAndSweep(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $this->assertTrue($invoice->save());
        $this->assertTrue(Sincronizador::enqueue($invoice));

        // el histórico ya repasado no suma, así que solo cuenta la cola
        Config::setSweepCursor((int)$invoice->idfactura);
        $queue = Sincronizador::pendingCount();
        $this->assertGreaterThan(0, $queue, 'queue-not-counted');
        $this->assertSame($queue, Sincronizador::remainingCount(), 'sweep-should-be-finished');

        // una fila que agotó los reintentos ya no es trabajo pendiente
        $row = $this->findRow($invoice);
        $row->intentos = Config::maxRetries();
        $row->estado = ArchivoNube::ESTADO_ERROR;
        $this->assertTrue($row->save());
        $this->assertSame($queue - 1, Sincronizador::pendingCount(), 'exhausted-row-still-counted');

        // retroceder el cursor devuelve trabajo al repaso del histórico
        Config::setSweepCursor((int)$invoice->idfactura - 1);
        $this->assertGreaterThan(
            Sincronizador::pendingCount(),
            Sincronizador::remainingCount(),
            'sweep-not-added-to-remaining'
        );

        $this->deleteInvoice($invoice);
    }

    public function testForceClearsTheHashSoTheFileIsUploadedAgain(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $this->assertTrue($invoice->save());
        $this->assertTrue(Sincronizador::enqueue($invoice));

        // la damos por subida, con su huella y su id de archivo
        $row = $this->findRow($invoice);
        $this->assertTrue($row->success('id-de-prueba', 'https://ejemplo.test/f', 'huella-de-prueba'));

        // encolar sin forzar conserva la huella, así que la subida se saltaría
        $this->assertTrue(Sincronizador::enqueue($invoice));
        $row = $this->findRow($invoice);
        $this->assertSame('huella-de-prueba', $row->hash, 'hash-cleared-without-force');

        // forzando se olvida la huella, pero se conserva el id: se reemplaza el
        // archivo que ya está en la nube en lugar de crear un duplicado
        $this->assertTrue(Sincronizador::enqueue($invoice, GoogleDrive::SERVICE, true));
        $row = $this->findRow($invoice);
        $this->assertNull($row->hash, 'hash-not-cleared-with-force');
        $this->assertSame('id-de-prueba', $row->file_id, 'file-id-lost-on-force');
        $this->assertSame(ArchivoNube::ESTADO_PENDING, $row->estado, 'wrong-state-after-force');

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
