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
                setToast(format(progressText, done, failed, payload.remaining), 'danger', '', 0);
                window.setTimeout(function () {
                    window.location.reload();
                }, 4000);
                return;
            }

            replaceSpinner(format(progressText, done, failed, payload.remaining), title);
        }

        animateSpinner('remove');
        window.location.reload();
    }

    document.addEventListener('DOMContentLoaded', function () {
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
