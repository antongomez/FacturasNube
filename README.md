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

**Las facturas que ya existían antes de instalar el plugin también se suben.** El worker
solo se entera de las que se guardan a partir de ahora, así que cada pasada repasa además
un puñado del histórico, avanzando por `idfactura` hasta terminarlo. No hay que marcar
nada a mano, y el contador *Por revisar* de la pantalla indica cuánto queda. Se puede
desactivar, o limitarlo con una fecha de inicio.

Para no subir lo que no ha cambiado se guarda una huella del documento (cabecera,
líneas, nombre de archivo y carpeta destino). No se puede usar el md5 del PDF porque la
librería le añade la fecha de creación y un identificador aleatorio, así que dos PDF de
la misma factura nunca coinciden byte a byte.

## Requisitos

- FacturaScripts 2026 o superior.
- **El cron de FacturaScripts configurado en el servidor.** Sin él no se sube nada de
  forma automática; solo funcionaría el botón *Sincronizar ahora*.
- `site_url` bien configurada en la empresa: de ahí sale el URI de redirección de OAuth.
- Un dominio público para la conexión inicial con Google. Si tu servidor está en
  Tailscale, en una VPN o en la red local, mira
  [Servidor sin dominio público](#servidor-sin-dominio-público-tailscale-vpn-red-local):
  se resuelve conectando una única vez a través de `localhost`.

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

   Si no coincide carácter por carácter, Google rechazará la conexión. Y si ese dominio
   no es público y verificable, Google lo rechazará de todas formas: en ese caso ve a
   [Servidor sin dominio público](#servidor-sin-dominio-público-tailscale-vpn-red-local).
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

### Servidor sin dominio público (Tailscale, VPN, red local)

Si tu FacturaScripts no está en un dominio público del que puedas demostrar la
propiedad, Google **no aceptará su URL como URI de redirección**. Pasa con nombres de
Tailscale (`*.ts.net`), VPN, dominios internos o direcciones de red local: son de otro
o no existen en el DNS público, así que no puedes verificarlos en Search Console.

Verás uno de estos dos errores, y conviene distinguirlos porque significan cosas
distintas:

| Error | Qué significa |
| --- | --- |
| `Error 400: invalid_request`, «doesn't comply with Google's OAuth 2.0 policy» | Google rechaza el dominio de la URI. No es un fallo de configuración: esa URL nunca va a funcionar. |
| `Error 400: redirect_uri_mismatch` | El dominio le vale, pero la URI que envía el plugin no coincide **carácter a carácter** con ninguna de las registradas. |

La solución se apoya en un detalle importante:

> **La URI de redirección solo se usa una vez.** En cuanto la cuenta queda conectada se
> guarda el token de refresco en el servidor, y ni el cron ni las subidas vuelven a usar
> ninguna redirección. Da igual que esa URL no sirva el resto del tiempo.

Así que basta con hacer la conexión una sola vez a través de `localhost`, que Google
exime tanto del requisito de HTTPS como del de verificar el dominio.

**1. En Google Cloud Console → Clients → tu cliente:**

- **Borra** la URI del dominio no público (por ejemplo la de `*.ts.net`). Mientras siga
  registrada puede seguir bloqueando el cliente entero por incumplir la política.
- Añade `http://localhost:8000/oauth2/facturas-nube/google`.
- Deja los *Authorised domains* como estén: son para la portada y la política de
  privacidad, y no tienen nada que ver con esto.

**2. Abre un túnel SSH** desde el equipo donde vayas a usar el navegador, apuntando al
puerto en el que sirve FacturaScripts (aquí, el 80 del servidor):

```bash
ssh -L 8000:localhost:80 tu-servidor
```

**3. Cambia la url del sitio**: **Panel de control → general → avanzado → URL**, ponla
en `http://localhost:8000` y **guarda**.

**4. Comprueba que el cambio ha entrado.** Este es el paso que más veces se salta: entra
en **Facturas en la nube** y mira el campo *URI de redirección autorizado*. Tiene que
mostrar ya `http://localhost:8000/...`. Si sigue mostrando el dominio anterior, el
guardado no ha cuajado y al pulsar Conectar tendrás un `redirect_uri_mismatch`.

**5. Abre `http://localhost:8000` en el navegador** y vuelve a iniciar sesión: es otro
host, así que la cookie de sesión anterior no viaja hasta aquí.

**6. Pulsa Conectar con Google Drive** y autoriza el acceso.

**7. Con la cuenta ya conectada, devuelve la URL del sitio a su valor real**
(`https://tu-servidor.tured.ts.net:8080` o el que sea) y guarda. La conexión sigue
funcionando: el token ya está guardado.

**8. Comprueba con *Sincronizar ahora*.**

Si más adelante necesitas reconectar la cuenta (porque cambies los permisos o
desconectes), hay que repetir los pasos 2 a 7.

#### Si aun así falla

La URL de la propia página de error de Google lleva dentro la URI exacta que envió el
plugin. Descodifícala y compárala con la registrada:

```bash
python3 -c "import sys,urllib.parse as u; print(u.parse_qs(u.urlparse(sys.argv[1]).query)['redirect_uri'][0])" 'URL_DE_LA_PAGINA_DE_ERROR'
```

Causas habituales, por orden de frecuencia:

1. La url del sitio no llegó a guardarse (paso 4).
2. Los cambios en el cliente de Google tardan en propagarse: de 5 minutos a unas horas.
3. El Client ID configurado es de un cliente distinto de aquel donde registraste la URI.
4. Diferencias literales: `https` en vez de `http`, `127.0.0.1` en vez de `localhost`,
   el puerto ausente o una barra final de más.

## Configuración del plugin

| Opción | Qué hace |
| --- | --- |
| Sincronización activa | Interruptor general. Apagado, no se marca ni se sube nada. |
| Subir también las facturas anteriores | Repasa el histórico poco a poco hasta subirlo entero. |
| Sincronizar solo desde | Las facturas anteriores a esa fecha no se suben nunca, ni al modificarlas. |
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

- **Sincronizar ahora**: procesa la cola en el momento, sin esperar al cron. El aviso de
  *procesando* se mantiene hasta que no queda nada por subir, y va indicando cuántas
  llevan y cuántas faltan. Por dentro encadena tandas pequeñas en lugar de mandarlo todo
  en una petición: subir cientos de PDF tarda minutos y el servidor la cortaría a mitad
  por `max_execution_time`. Si una tanda entera falla, se detiene y avisa en lugar de
  agotar ahí los reintentos. Sin javascript el botón sigue funcionando, subiendo una
  tanda por pulsación.
- **Reintentar las fallidas**: devuelve a la cola las filas que agotaron los reintentos.
- **Marcar facturas desde**: fuerza un repaso de las facturas emitidas a partir de una
  fecha. No hace falta para subir el histórico —de eso se encarga solo—, sirve para
  volver a comprobar un tramo concreto. Deja la fecha vacía para todas. Es barato: las
  que no han cambiado se detectan por su huella y se saltan sin generar el PDF.

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
