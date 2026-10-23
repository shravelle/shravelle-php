# PHP (Laravel, Symfony, WordPress, plain PHP)

## Option A: Shravelle PHP SDK (no extension needed)
```php
require 'vendor/autoload.php';   // composer require shravelle/shravelle (sdks/shravelle-sdk-php)
\Shravelle\Shravelle::init(['service' => 'shop', 'api_key' => getenv('SHRAVELLE_API_KEY'),
                        'endpoint' => getenv('SHRAVELLE_ENDPOINT')]);
```
Each web request becomes a transaction (it continues an incoming `traceparent`). Uncaught exceptions, PHP
errors and fatal errors are recorded. Telemetry is sent as OTLP/JSON **after** the response is flushed
(`fastcgi_finish_request`), so users never wait for it.
```php
use Shravelle\Shravelle;
$rows = Shravelle::segment('db.orders', fn() => $pdo->query($sql)->fetchAll(), [], 'client');
Shravelle::recordQuery($sql, $ms, 'mysql', 'shop');
Shravelle::recordExternal('POST', 'https://api.razorpay.com/v1/orders', 200, $ms);
Shravelle::noticeError($e, ['order_id' => 123]);
Shravelle::setUser($userId);  Shravelle::setTransactionName('POST /checkout');
Shravelle::addAttribute('cart_value', 1450);  Shravelle::recordEvent('coupon_applied', ['code' => 'DIWALI']);
Shravelle::log('error', 'payment failed', ['gateway' => 'payu']);
$headers[] = 'traceparent: ' . Shravelle::traceparent();      // propagate to downstream services
Shravelle::recordDeployment('1.4.2', $sha);
```
**Laravel:** register `Shravelle\Laravel\ShravelleServiceProvider`. It sets transaction names from routes,
records DB queries (`DB::listen`) and reports exceptions.

## Option B: full auto-instrumentation with OpenTelemetry
```bash
pecl install opentelemetry
composer require open-telemetry/sdk open-telemetry/exporter-otlp \
  open-telemetry/opentelemetry-auto-laravel open-telemetry/opentelemetry-auto-pdo open-telemetry/opentelemetry-auto-curl
OTEL_PHP_AUTOLOAD_ENABLED=true OTEL_EXPORTER_OTLP_ENDPOINT=https://ingest.example.com \
OTEL_EXPORTER_OTLP_HEADERS=x-api-key=shv_ing_xxx OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf php-fpm
```
Status: the PHP SDK was not run in this build environment.


---
Part of [Shravelle](https://shravelle.com). Setup guide for every platform: [shravelle.com/docs](https://shravelle.com/docs.html) · Get your keys: [shravelle.com/get-started](https://shravelle.com/get-started.html)
