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
 * Cuenta conectada de un servicio de almacenamiento en la nube.
 * Guarda los tokens OAuth necesarios para subir archivos en nombre del usuario.
 * Solo puede haber una cuenta por servicio (restricción UNIQUE en servicio).
 */
class CuentaNube extends ModelClass
{
    use ModelTrait;

    /** @var string Token de acceso vigente. */
    public $access_token;

    /** @var string Email de la cuenta conectada, solo informativo. */
    public $email;

    /** @var string Fecha y hora de caducidad del access_token. */
    public $expires;

    /** @var string Fecha y hora de la conexión. */
    public $fecha;

    /** @var int Identificador único. */
    public $id;

    /** @var string Token de refresco, permite renovar el access_token sin intervención del usuario. */
    public $refresh_token;

    /** @var string Permisos concedidos por el usuario. */
    public $scope;

    /** @var string Identificador del servicio: google, onedrive... */
    public $servicio;

    public function clear(): void
    {
        parent::clear();
        $this->fecha = Tools::dateTime();
    }

    /** Devuelve la cuenta conectada del servicio indicado, o null si no hay ninguna. */
    public static function forService(string $servicio): ?self
    {
        return static::findWhere([Where::eq('servicio', $servicio)]);
    }

    /**
     * True si la cuenta conectada tiene concedido el permiso indicado.
     *
     * Sirve para avisar de que se ha cambiado el permiso en la configuración pero
     * el token guardado sigue siendo el anterior: hasta reconectar, Google seguirá
     * aplicando los permisos que se concedieron en su día.
     */
    public function hasScope(string $scope): bool
    {
        if (empty($this->scope)) {
            return false;
        }

        return in_array($scope, explode(' ', $this->scope), true);
    }

    /** True si el access_token ha caducado o está a punto de hacerlo (margen de 60 segundos). */
    public function tokenExpired(): bool
    {
        if (empty($this->access_token) || empty($this->expires)) {
            return true;
        }

        return strtotime($this->expires) <= strtotime(Tools::dateTime()) + 60;
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'facturas_nube_cuentas';
    }

    public function test(): bool
    {
        $this->email = Tools::noHtml($this->email);
        $this->scope = Tools::noHtml($this->scope);
        $this->servicio = Tools::noHtml($this->servicio);

        if (empty($this->servicio)) {
            Tools::log()->error('service-not-set');
            return false;
        }

        return parent::test();
    }
}
