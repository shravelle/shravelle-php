<?php
namespace Shravelle\Laravel;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Shravelle\Shravelle;

/**
 * Auto-discovered Laravel integration: names transactions after routes,
 * records every database query, reports exceptions and the authenticated
 * user. Configure with SHRAVELLE_* environment variables.
 */
class ShravelleServiceProvider extends ServiceProvider
{
    public function register()
    {
        Shravelle::init(['service' => env('SHRAVELLE_SERVICE_NAME', config('app.name', 'laravel'))]);
    }

    public function boot()
    {
        DB::listen(function (QueryExecuted $q) {
            Shravelle::recordQuery($q->sql, (float)$q->time, $q->connection->getDriverName(), $q->connection->getDatabaseName());
        });
        Event::listen(\Illuminate\Routing\Events\RouteMatched::class, function ($e) {
            $uri = $e->route->uri();
            Shravelle::setTransactionName($e->request->method() . ' /' . ltrim($uri, '/'));
            Shravelle::addAttribute('http.route', '/' . ltrim($uri, '/'));
        });
        Event::listen(\Illuminate\Auth\Events\Authenticated::class, function ($e) {
            Shravelle::setUser((string)$e->user->getAuthIdentifier());
        });
        Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($e) {
            if (in_array($e->level, ['error', 'critical', 'alert', 'emergency', 'warning'], true)) {
                if (isset($e->context['exception']) && $e->context['exception'] instanceof \Throwable) {
                    Shravelle::noticeError($e->context['exception']);
                } else {
                    Shravelle::log($e->level === 'warning' ? 'WARN' : 'ERROR', $e->message);
                }
            }
        });
    }
}
