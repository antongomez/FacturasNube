/**
 * This file is part of FacturasNube plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 *
 * Sube la cola en tandas pequeñas encadenadas en lugar de en una sola petición.
 * Subir cientos de PDF tarda minutos y el servidor cortaría la petición por
 * max_execution_time a mitad; así cada petición dura unos segundos y el aviso
 * de "procesando" se mantiene hasta que de verdad no queda nada por subir.
 */
(function () {
    'use strict';

    /**
     * Vuelve a cargar la pantalla con un GET.
     *
     * No sirve el recargado normal del navegador: repite el método de la petición
     * original, y esta pantalla se carga con POST cada vez que se pulsa uno de sus
     * botones. Recargar así reenviaba aquel formulario con su token ya gastado, y
     * el usuario veía un "petición duplicada" que no tenía nada que ver con lo que
     * acababa de hacer.
     */
    function reloadAsGet(done, failed) {
        const url = new URL(window.location.href);

        // el endpoint de tanda responde en json y no deja rastro en el registro,
        // así que el resultado viaja en la url para que la pantalla lo cuente al
        // recargarse. Sin esto, no tener nada pendiente era indistinguible de un fallo:
        // la página se recargaba en silencio y parecía que no había hecho nada.
        if (typeof done === 'number') {
            url.searchParams.set('fsnube_sync', done + '-' + failed);
        }

        window.location.assign(url.pathname + url.search);
    }

    function replaceSpinner(message, title) {
        document.querySelectorAll('#messages-toasts .toast-spinner').forEach(function (el) {
            el.remove();
        });
        setToast(message, 'spinner', title, 0);
    }

    function format(template, done, failed, remaining) {
        return template
            .replace('%done%', done)
            .replace('%error%', failed)
            .replace('%remaining%', remaining);
    }

    async function run(form) {
        const url = form.getAttribute('action') || window.location.pathname + window.location.search;
        const tokenField = form.querySelector('input[name="multireqtoken"]');
        const title = form.dataset.fsnubeTitle || '';
        const progressText = form.dataset.fsnubeProgress || '';
        const errorText = form.dataset.fsnubeError || '';

        let done = 0;
        let failed = 0;
        let round = 0;

        animateSpinner('add');

        while (true) {
            round++;

            const data = new FormData();
            data.append('action', 'sync-batch');
            // el token no se puede repetir o la petición se rechaza por duplicada;
            // el core admite variar su parte aleatoria desde javascript
            data.append('multireqtoken', tokenField.value + round);

            let payload;
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest'}
                });
                payload = await response.json();
            } catch (error) {
                animateSpinner('remove');
                setToast(errorText, 'danger', '', 0);
                return;
            }

            if (!payload.ok) {
                animateSpinner('remove');
                setToast(payload.message || errorText, 'danger', '', 0);
                return;
            }


            done += payload.uploaded;
            failed += payload.failed;

            // ya no queda nada, o no queda nada que se pueda procesar
            if (payload.remaining <= 0 || payload.processed === 0) {
                break;
            }

            // una tanda entera fallida significa que algo va mal —la nube no responde,
            // la cuenta se desconectó—: paramos en lugar de agotar aquí los reintentos
            // de cada factura, que es trabajo que el cron puede repetir más tarde
            if (payload.uploaded === 0 && payload.failed > 0) {
                animateSpinner('remove');
                reloadAsGet(done, failed);
                return;
            }

            replaceSpinner(format(progressText, done, failed, payload.remaining), title);
        }

        animateSpinner('remove');
        reloadAsGet(done, failed);
    }

    /**
     * Quita de la url los parámetros que solo sirven para contar el resultado de la
     * acción anterior. Los formularios de esta pantalla se envían a la url actual,
     * así que si se quedaran pegados, cada acción posterior repetiría un mensaje que
     * ya no viene a cuento.
     */
    function cleanUrl() {
        const url = new URL(window.location.href);
        let changed = false;

        ['fsnube_sync', 'fsnube'].forEach(function (key) {
            if (url.searchParams.has(key)) {
                url.searchParams.delete(key);
                changed = true;
            }
        });

        if (changed) {
            window.history.replaceState({}, '', url.pathname + url.search + url.hash);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        cleanUrl();

        const form = document.getElementById('fsnube-sync-form');
        if (!form) {
            return;
        }

        // sin javascript el formulario sigue enviándose de la forma habitual,
        // solo que subiendo una tanda por pulsación
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            run(form);
        });
    });
})();
