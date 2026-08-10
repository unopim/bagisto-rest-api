# Bagisto REST API

Bagisto REST API is a medium to use the features of the core Bagisto system. By using the Bagisto REST API, you can integrate your application to serve the default content of Bagisto.

## 1. Requirements

- **Bagisto:** v2.4.x
- **PHP:** ^8.3

## 2. Installation

### Option A — Composer (recommended)

Install the package via Composer:

```bash
composer require unopim/bagisto-rest-api
```

### Option B — Manual (place the package in `packages/Webkul`)

Use this when you want to keep the package source inside your project (for example, a fork you maintain).

1. Put the package source in `packages/Webkul/RestApi`:

   ```bash
   git clone https://github.com/unopim/bagisto-rest-api.git packages/Webkul/RestApi
   rm -rf packages/Webkul/RestApi/.git
   ```

2. Register the namespace in the root `composer.json` under `autoload.psr-4`:

   ```json
   "Webkul\\RestApi\\": "packages/Webkul/RestApi/src"
   ```

3. Register the service provider in `bootstrap/providers.php`:

   ```php
   Webkul\RestApi\Providers\RestApiServiceProvider::class,
   ```

4. Install the L5-Swagger dependency and refresh the autoloader:

   ```bash
   composer require darkaonline/l5-swagger:^8.5
   composer dump-autoload
   ```

### Configure (both options)

Add your application's domain to the `.env` file so Sanctum can authenticate stateful requests. Use the **host** only (optionally `host:port`) — do not include the scheme or path:

```dotenv
SANCTUM_STATEFUL_DOMAINS=localhost
```

Publish the L5-Swagger configuration and generate the API documentation:

```bash
php artisan bagisto-rest-api:install
```

> **Manual install only:** because the package lives in `packages/Webkul/RestApi` (not `vendor/`), open the published `config/l5-swagger.php` and change the annotation paths from `base_path('vendor/unopim/bagisto-rest-api/src/Docs/...')` to `base_path('packages/Webkul/RestApi/src/Docs/...')`, then regenerate the docs: `php artisan l5-swagger:generate --all`.

Clear the caches:

```bash
php artisan optimize:clear
```

## 3. API Documentation

Open the following URLs in your browser (replace the host with your application's URL):

- **Admin:** `http://localhost/api/admin/documentation`
- **Shop:** `http://localhost/api/shop/documentation`

## 4. Bulk Product API (Queues)

The Bulk Product API processes products asynchronously through the queue. Set the queue connection in your `.env`:

```dotenv
QUEUE_CONNECTION=database
```

Then run a queue worker so the dispatched bulk jobs are processed:

```bash
php artisan queue:work
```

> See the [L5-Swagger](https://github.com/DarkaOnLine/L5-Swagger) documentation for more details on configuring the API documentation.
