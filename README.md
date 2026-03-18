# Carliyo TikTok Uploader

Un formulario web para subir vídeos a TikTok usando su API. Dark mode, colores TikTok, drag & drop... básicamente lo que TikTok debería darte gratis pero no lo hace.

> Si quieres saber la historia completa de por qué existe esto (y los dramas con la API), lo cuento aquí: [Publicando en TikTok vía API](https://ablancodev.com/build-in-public/publicando-en-tiktok-via-api/)

## Qué hace

- Sube vídeos a TikTok desde archivo (hasta 50MB) o desde URL pública
- Publicación directa o envío a borradores (inbox) para revisar antes
- Muestra el estado del vídeo en tiempo real (subiendo → procesando → listo)
- OAuth 2.0 con PKCE, como manda el estándar

## Configuración rápida

### 1. Crea tu app en TikTok

Ve a [TikTok for Developers](https://developers.tiktok.com/) y crea una app. Necesitas los permisos `video.upload` y `video.publish`.

### 2. Edita `config.php`

Abre `config.php` y rellena tus credenciales:

```php
define('TIKTOK_CLIENT_KEY', 'tu_client_key_aquí');
define('TIKTOK_CLIENT_SECRET', 'tu_client_secret_aquí');
define('TIKTOK_REDIRECT_URI', 'https://tu-dominio.com/oauth.php');
```

La `REDIRECT_URI` debe coincidir **exactamente** con la que configuraste en el portal de TikTok.

### 3. Autoriza tu cuenta

Visita `https://tu-dominio.com/oauth.php` en el navegador. Te redirigirá a TikTok para autorizar la app. Tras aceptar, se guardará un `token.json` con los tokens de acceso.

Esto solo hay que hacerlo una vez (los tokens se refrescan automáticamente).

### 4. Sube vídeos

Abre `index.html` y a darle caña.

## Stack

PHP + JavaScript vanilla. Sin frameworks, sin builds, sin dramas. Ponlo en XAMPP, MAMP o cualquier servidor con PHP y cURL, y listo.

## Archivos clave

| Archivo | Qué hace |
|---------|----------|
| `config.php` | Credenciales y gestión de tokens |
| `oauth.php` | Flujo de autenticación OAuth + PKCE |
| `upload.php` | Toda la lógica de subida y consulta de estado |
| `index.html` | El formulario bonito |
| `app.js` | La magia del frontend |
| `debug.php` | Para cuando algo no funciona (que pasará) |

## Aviso

La API de TikTok en modo sandbox tiene sus limitaciones. La publicación directa puede no funcionar hasta que tu app esté aprobada. Paciencia, el proceso de revisión es... una experiencia.
