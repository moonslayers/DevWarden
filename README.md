# DevWarden

Asistente de desarrollo personal. DevWarden es una aplicación web que te permite tener tu propio asistente IA de desarrollo, local-first, conversando con él desde Telegram y configurando todo desde una interfaz web.

## Principio central

**Toda la configuración se realiza vía web UI y se persiste en la base de datos.** No se usan archivos `.env` ni archivos de config para las features de la aplicación. Los proveedores de IA, el bot de Telegram y cualquier otra configuración se administran desde la interfaz web.

## Roadmap

- **Stage 1 (actual): Integración Telegram + configuración web de IA**
  - Chatear con el bot desde Telegram
  - Configurar el bot de Telegram (token, etc.) desde la web UI
  - Configurar proveedores de IA desde la web UI
  - Seleccionar el proveedor que usa el bot, con soporte de failover
  - Todo lo necesario para empezar a chatear con el bot desde Telegram

## Requisitos

- PHP >= 8.3 (recomendado 8.5)
- Composer
- Node.js + npm
- SQLite
- Extensión PHP **FFI** habilitada (requerida por la memoria del bot: embeddings locales con codewithkyrian/transformers)

En Linux, habilita `extension=ffi` en tu `php.ini` (descomenta la línea `;extension=ffi`)
o ejecuta `sudo sed -i 's/^;extension=ffi$/extension=ffi/' /etc/php/php.ini`. La primera vez
que el bot genera un embedding se descarga el modelo (~133 MB) a `storage/app/embedding-models`.

## Instalación local

### Opción rápida (script de setup)

```bash
composer run setup
```

Esto instala dependencias, crea el `.env`, genera la app key, migra la base de datos, instala assets y compila el frontend.

### Opción manual

```bash
git clone <repo-url> devwarden
cd devwarden

composer install
cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate

npm install
npm run build

php artisan serve --port=8012
```

Abre http://localhost:8012 en tu navegador.

## Stage 1: Bot de Telegram

### Arrancar todo (web + scheduler + queue)

La forma más simple es un solo comando que lanza web, scheduler y queue en paralelo
(se detienen todos con `Ctrl+C`):

```bash
composer run dev:full
```

Equivale a abrir tres terminales en la raíz del proyecto:

```bash
php artisan serve --port=8012  # web UI en http://localhost:8012
php artisan schedule:work  # ejecuta el scheduler (long polling cada minuto)
php -d extension=ffi artisan queue:work   # procesa los jobs de respuesta (FFI para embeddings locales)
```

Requiere `QUEUE_CONNECTION=database` en `.env` (es el valor por defecto del proyecto). El
worker de cola debe correr con FFI habilitada (`php -d extension=ffi artisan queue:work`)
para la feature de memoria con embeddings locales del bot; `composer run dev:full` ya lo hace.

### Configuración inicial desde la web UI

Después de `composer run setup` y de registrarte/iniciar sesión, configura en este orden:

1. **`/settings/telegram`** — pega el token del bot que te dio [@BotFather](https://t.me/BotFather)
   y añade tu ID de Telegram a *Allowed user IDs* (solo esos usuarios pueden chatear con el bot).
2. **`/settings/providers`** — añade al menos un proveedor de IA con su API key
   (p. ej. OpenAI, Anthropic, DeepSeek o un endpoint compatible `openai-compatible`).
3. **`/settings/bot`** — deja el *system prompt* por defecto (o personalízalo) y selecciona
   tu usuario como *owner* (el dueño del bot).

Escribe a tu bot desde Telegram y te responderá con el asistente configurado.

### Cómo funciona el polling

El bot usa **long polling**, no webhook:

- `php artisan schedule:work` ejecuta `telegram:poll` cada minuto (con bloqueo
  `withoutOverlapping` para que nunca corra dos veces a la vez).
- El comando consulta `getUpdates` contra Telegram, encola un job por mensaje autorizado
  y guarda el offset para no re-procesar los mismos mensajes.
- `php artisan queue:work` consume esos jobs: re-sincronizan los proveedores de la BD,
  generan la respuesta con el agente y la envían de vuelta a Telegram.

La latencia típica de respuesta es de **~1-2 segundos** después de escribir al bot.
Si nada responde, revisa que el scheduler y el queue estén corriendo y que el token y
los IDs permitidos estén bien configurados.

### Añadir más proveedores más adelante

Solo tienes que añadir filas desde `/settings/providers` (no editar `.env`). El orden de
failover se controla con *Failover order* (0 = primero). El bot probará los proveedores
habilitados en orden y saltará al siguiente si uno falla.

## Stack tecnológico

| Capa | Tecnología |
| --- | --- |
| Backend | Laravel 13 (PHP 8.5) |
| Frontend | Inertia.js v3 + Vue 3 + Tailwind CSS 4 (Vite) |
| Auth | Laravel Fortify |
| IA | laravel/ai (agentes, generación de texto, failover entre proveedores) + codewithkyrian/transformers (embeddings locales) |
| Base de datos | SQLite |
| Colas | Database driver |
| Testing | Pest 5, Laravel Pint, PHPStan (larastan) |

## Estructura del proyecto

```
app/                 Lógica de la aplicación (controllers, models, services)
config/              Configuración de framework (no config de features)
database/            Migraciones, factories, seeders
resources/js/        Frontend Vue (Inertia)
  pages/             Páginas Inertia
    settings/        Páginas de settings
  components/        Componentes Vue reutilizables
  layouts/           Layouts de la aplicación
routes/              Definición de rutas (web.php, settings.php)
tests/               Tests Pest (Feature y Unit)
```

## Testing

```bash
php artisan test --compact          # Tests rápidos
composer run test                   # Pint + PHPStan + Pest (suite completa)
```
