# FacturasNube

<p align="center">
  <img src="https://img.shields.io/badge/FacturaScripts-2026+-2670c9?style=for-the-badge&logoColor=white" alt="FacturaScripts 2026+">
  <img src="https://img.shields.io/badge/PHP-8.0+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.0+">
  <img src="https://img.shields.io/github/license/antongomez/FacturasNube?style=for-the-badge" alt="License">
  <img src="https://img.shields.io/badge/Google%20Drive-API%20v3-34A853?style=for-the-badge&logo=googledrive&logoColor=white" alt="Google Drive API v3">
  <a href="https://github.com/antongomez/FacturasNube/releases/latest"><img src="https://img.shields.io/github/v/release/antongomez/FacturasNube?style=for-the-badge&logo=github&logoColor=white" alt="Latest Release"></a>
</p>

Plugin para [FacturaScripts](https://facturascripts.com) que sube automáticamente el PDF
de las facturas de cliente a Google Drive cada vez que se crean o se modifican.
Preparado para añadir OneDrive sin tocar la lógica de sincronización.

## Cómo funciona

El trabajo se hace en dos tiempos a propósito:

1. **Marcar.** Al guardar o eliminar una factura, un worker escucha el evento
   (`Model.FacturaCliente.Save` y `Model.FacturaCliente.Delete`) y solo marca una fila
   como pendiente en `facturas_nube_archivos`. Es una escritura barata: no ralentiza el
   guardado ni depende de que la red funcione en ese momento.
2. **Subir.** El cron (o el botón *Sincronizar ahora*) genera el PDF y lo sube. Así
   varias ediciones seguidas de la misma factura se agrupan en una única subida y un
   fallo de red se reintenta en la siguiente pasada.

Hay como mucho **una fila por factura**, con el id del archivo en Drive. Al modificar
una factura ya subida se reemplaza el contenido del archivo existente, no se crea un
duplicado.

Para no subir lo que no ha cambiado se guarda una huella del documento (cabecera,
líneas, nombre de archivo y carpeta destino). No se puede usar el md5 del PDF porque la
librería le añade la fecha de creación y un identificador aleatorio, así que dos PDF de
la misma factura nunca coinciden byte a byte.

## Requisitos

- FacturaScripts 2026 o superior.
- **El cron de FacturaScripts configurado en el servidor.** Sin él no se sube nada de
  forma automática; solo funcionaría el botón *Sincronizar ahora*.
- `site_url` bien configurada en la empresa: de ahí sale el URI de redirección de OAuth.

## Configuración en Google Cloud Console

1. Entra en [Google Cloud Console](https://console.cloud.google.com/) y crea un proyecto
   (o reutiliza uno).
2. En **APIs y servicios → Biblioteca**, activa la **Google Drive API**.
3. En **APIs y servicios → Pantalla de consentimiento de OAuth**, configúrala. Si la
   cuenta es de Google Workspace puedes marcarla como *Interna*; si es una cuenta
   personal, quedará como *Externa* en modo *Prueba* y tendrás que añadir tu cuenta
   como usuario de prueba.
4. En **APIs y servicios → Credenciales**, crea unas credenciales de tipo
   **ID de cliente de OAuth → Aplicación web**.
5. En **URI de redirección autorizados**, pega el valor exacto que muestra la pantalla
   *Facturas en la nube* del plugin. Tiene esta forma:

   ```
   https://tu-dominio/oauth2/facturas-nube/google
   ```

   Si no coincide carácter por carácter, Google rechazará la conexión.
6. Copia el **Client ID** y el **Client secret** en la configuración del plugin,
   guarda y pulsa **Conectar con Google Drive**.

### Sobre los permisos

Por omisión se pide el permiso `https://www.googleapis.com/auth/drive`, que da acceso
completo al Drive de la cuenta. Hace falta para poder escribir dentro de una carpeta
que ya existía y cuyo id indicas a mano.

El permiso mínimo `https://www.googleapis.com/auth/drive.file` (el plugin solo ve los
archivos que él mismo crea) evita la verificación de Google. Se elige en el desplegable
**Permisos que se piden a Google** de la configuración, y obliga a dejar vacío el
**Id de la carpeta destino** para que el plugin cree su propia carpeta: no puede
escribir en carpetas que no haya creado él. La pantalla avisa si esa combinación es
imposible, y también si cambias el permiso sin volver a conectar la cuenta (el token
guardado conserva los permisos que se concedieron en su momento).

## Configuración del plugin

| Opción | Qué hace |
| --- | --- |
| Sincronización activa | Interruptor general. Apagado, no se marca ni se sube nada. |
| Client ID / Client secret | Credenciales OAuth de Google Cloud Console. |
| Permisos que se piden a Google | `drive.file` (solo lo que crea el plugin) o acceso completo. |
| Id de la carpeta destino | Carpeta de Drive donde guardar. Vacío = el plugin crea la suya. |
| Nombre de la carpeta a crear | Nombre que usa cuando no se indica un id. |
| Organizar en subcarpetas año/mes | Crea `2026/2026-08/` a partir de la fecha de la factura. |
| Nombre del archivo | Plantilla con `{codigo}`, `{numero}`, `{fecha}`, `{cliente}` y `{nif}`. |
| Al eliminar una factura | Papelera, borrado definitivo, o no tocar el archivo. |
| Reintentos máximos | Intentos fallidos seguidos antes de rendirse con una factura. |
| Facturas por pasada del cron | Cuántas filas pendientes procesa cada pasada. |

## Acciones manuales

- **Sincronizar ahora**: procesa la cola en el momento, sin esperar al cron.
- **Reintentar las fallidas**: devuelve a la cola las filas que agotaron los reintentos.
- **Marcar facturas desde**: marca como pendientes las facturas emitidas a partir de una
  fecha, para subir el histórico tras instalar el plugin. Deja la fecha vacía para todas.

## Estados

| Estado | Significado |
| --- | --- |
| `pending` | Marcada, pendiente de subir. |
| `synced` | Subida y al día. |
| `error` | Falló el último intento. El motivo está en la columna `error`. |
| `to-delete` | La factura se eliminó y hay que retirar el archivo de la nube. |
| `deleted` | Archivo ya retirado (o dejado a propósito). |

## Añadir otro servicio

`Lib/Nube/CloudProvider` es el contrato que ve el sincronizador. Para añadir OneDrive
basta con implementar esa interfaz y registrarla en `Sincronizador::provider()`; la
lógica de colas, reintentos, nombres y carpetas se reutiliza tal cual.

## Tests

Copiar la carpeta `Test/` del plugin en `Test/Plugins/FacturasNube/` y ejecutar:

```bash
vendor/bin/phpunit -c phpunit-plugins.xml
```

## Privacidad

El plugin se ejecuta enteramente en tu servidor: no hay ningún servicio del autor de por
medio. Los detalles de qué se guarda y qué se envía a Google están en
[PRIVACY.md](PRIVACY.md), que es también la URL de política de privacidad que pide
Google Cloud Console para publicar la aplicación.

## Idiomas

Las traducciones están solo en `Translation/es_ES.json`. En una instancia configurada en
otro idioma, las cadenas propias del plugin se verán como claves sin traducir.

## Licencia

Este plugin se distribuye bajo la licencia
[GNU Lesser General Public License v3 (LGPL-3.0)](COPYING.LESSER), la misma que
FacturaScripts.

Copyright (C) 2026 Anton Gomez
