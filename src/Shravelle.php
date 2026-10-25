<?php
/**
 * Shravelle APM for PHP (dependency-free).
 *
 *   require 'vendor/autoload.php';
 *   \Shravelle\Shravelle::init(['service' => 'shop', 'api_key' => getenv('SHRAVELLE_API_KEY')]);
 *
 * Every web request becomes a transaction (server span) continuing any
 * incoming W3C traceparent; uncaught exceptions, PHP errors and fatal
 * errors are recorded; segment() times database/external/custom work;
 * log() ships logs. Telemetry is sent once per request, after the response
 * has been flushed to the user (fastcgi_finish_request), as OTLP/JSON.
 *
 * For automatic instrumentation of every PDO query, cURL call and framework
 * internal, use the OpenTelemetry PHP extension instead (docs/sdk/php.md).
 */
namespace Shravelle;

final class Shravelle
{
    private static $cfg = null;
    private static $traceId = '';
    private static $root = null;      // root span array
    private static $spans = [];
    private static $stack = [];       // open span ids
    private static $logs = [];
    private static $start = 0.0;

    public static function init(array $opts = [])
    {
        if (self::$cfg !== null) {
            return;
        }
        $env = function ($k, $d = '') { $v = getenv($k); return ($v === false || $v === '') ? $d : $v; };
        self::$cfg = [
            'endpoint'    => rtrim($opts['endpoint'] ?? $env('SHRAVELLE_ENDPOINT', 'http://localhost:4318'), '/'),
            'api_key'     => $opts['api_key'] ?? $env('SHRAVELLE_API_KEY', $env('SHRAVELLE_LICENSE_KEY')),
            'service'     => $opts['service'] ?? $env('SHRAVELLE_APP_NAME', $env('SHRAVELLE_SERVICE_NAME', 'php-app')),
            'version'     => $opts['version'] ?? $env('SHRAVELLE_APP_VERSION', $env('SHRAVELLE_SERVICE_VERSION')),
            'environment' => $opts['environment'] ?? $env('SHRAVELLE_APP_ENV', $env('SHRAVELLE_ENV', 'production')),
            'organisation' => $opts['organisation'] ?? $env('SHRAVELLE_ORGANISATION', $env('SHRAVELLE_ORGANIZATION')),
            'sample_rate' => (float)($opts['sample_rate'] ?? $env('SHRAVELLE_SAMPLE_RATE', '1')),
            'capture_errors' => $opts['capture_errors'] ?? true,
            'timeout'     => (float)($opts['timeout'] ?? 2.0),
        ];
        self::$start = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        self::startTransaction();
        if (self::$cfg['capture_errors']) {
            self::installHandlers();
        }
        register_shutdown_function([self::class, 'finish']);
    }

    // ------------------------------------------------------------ public API

    /** Time a block of work as a child span. $kind: internal|client. */
    public static function segment(string $name, callable $fn, array $attributes = [], string $kind = 'internal')
    {
        $id = self::open($name, $kind, $attributes);
        try {
            return $fn();
        } catch (\Throwable $e) {
            self::recordException($id, $e);
            throw $e;
        } finally {
            self::close($id);
        }
    }

    /** Record a database query that already ran (e.g. from a query listener). */
    public static function recordQuery(string $sql, float $durationMs, string $system = 'mysql', string $db = '')
    {
        $end = microtime(true);
        $id = self::open(strtoupper(preg_split('/\s+/', ltrim($sql), 2)[0]) ?: 'QUERY', 'client', [
            'db.system' => $system, 'db.query.text' => substr($sql, 0, 4000), 'db.namespace' => $db,
        ], $end - $durationMs / 1000);
        self::close($id, $end);
    }

    /** Record an outgoing HTTP call that already ran. */
    public static function recordExternal(string $method, string $url, int $status, float $durationMs)
    {
        $end = microtime(true);
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $id = self::open($method . ' ' . $host, 'client', [
            'http.request.method' => $method, 'url.full' => $url, 'server.address' => $host, 'http.response.status_code' => $status,
        ], $end - $durationMs / 1000);
        if ($status >= 500) {
            self::$spans[$id]['status'] = ['code' => 2];
        }
        self::close($id, $end);
    }

    public static function noticeError(\Throwable $e, array $attributes = [])
    {
        self::recordException(end(self::$stack) ?: self::$root, $e, $attributes);
        self::log('ERROR', get_class($e) . ': ' . $e->getMessage(), $attributes + [
            'exception.type' => get_class($e), 'exception.message' => $e->getMessage(), 'exception.stacktrace' => self::stack($e),
        ]);
    }

    public static function setUser(string $id) { self::addAttribute('enduser.id', $id); }

    public static function setTransactionName(string $name)
    {
        if (self::$root) {
            self::$spans[self::$root]['name'] = $name;
        }
    }

    public static function addAttribute(string $key, $value)
    {
        if (self::$root) {
            self::$spans[self::$root]['attrs'][$key] = $value;
        }
    }

    public static function recordEvent(string $name, array $attributes = [])
    {
        self::log('INFO', $name, ['event.name' => $name] + $attributes);
    }

    public static function log(string $level, string $message, array $attributes = [])
    {
        $sev = ['TRACE' => 1, 'DEBUG' => 5, 'INFO' => 9, 'NOTICE' => 10, 'WARN' => 13, 'WARNING' => 13, 'ERROR' => 17, 'CRITICAL' => 18, 'FATAL' => 21];
        $level = strtoupper($level);
        self::$logs[] = [
            'timeUnixNano' => self::nanos(microtime(true)), 'severityNumber' => $sev[$level] ?? 9, 'severityText' => $level,
            'body' => ['stringValue' => $message], 'attributes' => self::kv($attributes),
            'traceId' => self::$traceId, 'spanId' => self::$root ? self::$spans[self::$root]['spanId'] : '',
        ];
    }

    /** traceparent header for outgoing requests (distributed tracing). */
    public static function traceparent(): string
    {
        $parent = end(self::$stack) ?: self::$root;
        return $parent ? sprintf('00-%s-%s-01', self::$traceId, self::$spans[$parent]['spanId']) : '';
    }

    public static function recordDeployment(string $version, string $commit = '', string $description = ''): bool
    {
        self::init();
        $c = self::$cfg;
        return self::post('/v1/deployments', json_encode(['service' => $c['service'], 'version' => $version, 'commit' => $commit,
            'description' => $description, 'user' => get_current_user()]), 10.0);
    }

    // ------------------------------------------------------------ internals

    private static function startTransaction()
    {
        $tp = $_SERVER['HTTP_TRACEPARENT'] ?? '';
        $parent = '';
        if (preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/', $tp, $m)) {
            self::$traceId = $m[1];
            $parent = $m[2];
        } else {
            self::$traceId = bin2hex(random_bytes(16));
        }
        $cli = PHP_SAPI === 'cli';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $path = $cli ? implode(' ', array_slice($_SERVER['argv'] ?? [], 0, 2)) : (explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0] ?: '/');
        $attrs = $cli ? ['process.command' => $path] : [
            'http.request.method' => $method, 'url.path' => $path, 'server.address' => $_SERVER['HTTP_HOST'] ?? '',
            'user_agent.original' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300), 'client.address' => $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        self::$root = self::open($cli ? 'php ' . $path : $method . ' ' . $path, $cli ? 'internal' : 'server', $attrs, self::$start, $parent);
    }

    private static function open(string $name, string $kind, array $attrs = [], float $start = null, string $parentId = ''): string
    {
        $id = bin2hex(random_bytes(8));
        if ($parentId === '' && ($p = end(self::$stack))) {
            $parentId = self::$spans[$p]['spanId'];
        }
        self::$spans[$id] = ['spanId' => $id, 'parent' => $parentId, 'name' => $name, 'kind' => $kind,
            'start' => $start ?? microtime(true), 'end' => null, 'attrs' => $attrs, 'events' => [], 'status' => ['code' => 0]];
        self::$stack[] = $id;
        return $id;
    }

    private static function close(string $id, $end = null)
    {
        self::$spans[$id]['end'] = $end ?? microtime(true);
        $i = array_search($id, self::$stack, true);
        if ($i !== false) {
            array_splice(self::$stack, $i, 1);
        }
    }

    private static function recordException($id, \Throwable $e, array $attrs = [])
    {
        if (!$id || !isset(self::$spans[$id])) {
            return;
        }
        self::$spans[$id]['events'][] = ['timeUnixNano' => self::nanos(microtime(true)), 'name' => 'exception', 'attributes' => self::kv($attrs + [
            'exception.type' => get_class($e), 'exception.message' => $e->getMessage(), 'exception.stacktrace' => self::stack($e),
        ])];
        self::$spans[$id]['status'] = ['code' => 2, 'message' => $e->getMessage()];
    }

    private static function stack(\Throwable $e): string
    {
        return get_class($e) . ': ' . $e->getMessage() . "\n    at " . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString();
    }

    private static function installHandlers()
    {
        $prevEx = set_exception_handler(null);
        set_exception_handler(function (\Throwable $e) use ($prevEx) {
            self::noticeError($e, ['exception.escaped' => 'true']);
            if (self::$root) {
                self::$spans[self::$root]['attrs']['http.response.status_code'] = 500;
            }
            if ($prevEx) {
                $prevEx($e);
            } else {
                throw $e;
            }
        });
        set_error_handler(function ($no, $str, $file, $line) {
            if (!(error_reporting() & $no) || in_array($no, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED], true)) {
                return false;
            }
            self::log($no === E_WARNING || $no === E_USER_WARNING ? 'WARN' : 'ERROR', $str, ['code.filepath' => $file, 'code.lineno' => $line, 'exception.type' => 'PHP error ' . $no]);
            return false;
        });
    }

    /** @internal shutdown hook: closes the transaction and sends telemetry. */
    public static function finish()
    {
        if (self::$cfg === null || self::$root === null) {
            return;
        }
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            self::$spans[self::$root]['events'][] = ['timeUnixNano' => self::nanos(microtime(true)), 'name' => 'exception', 'attributes' => self::kv([
                'exception.type' => 'PHP Fatal error', 'exception.message' => $err['message'], 'exception.stacktrace' => $err['file'] . ':' . $err['line'],
            ])];
            self::$spans[self::$root]['status'] = ['code' => 2, 'message' => $err['message']];
            self::$spans[self::$root]['attrs']['http.response.status_code'] = 500;
        }
        if (PHP_SAPI !== 'cli' && !isset(self::$spans[self::$root]['attrs']['http.response.status_code'])) {
            self::$spans[self::$root]['attrs']['http.response.status_code'] = http_response_code() ?: 200;
        }
        foreach (array_reverse(self::$stack) as $id) {
            self::close($id);
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request(); // visitor gets the page before we send telemetry
        }
        $sampled = self::$cfg['sample_rate'] >= 1 || (hexdec(substr(self::$traceId, 0, 8)) / 0xffffffff) < self::$cfg['sample_rate'];
        $hasError = self::$spans[self::$root]['status']['code'] === 2;
        $resource = ['attributes' => self::kv(array_merge([
            'service.name' => self::$cfg['service'], 'service.version' => self::$cfg['version'], 'deployment.environment.name' => self::$cfg['environment'],
            'host.name' => gethostname(), 'process.runtime.name' => 'php', 'process.runtime.version' => PHP_VERSION, 'telemetry.distro.name' => 'shravelle-php',
        ], self::$cfg['organisation'] !== '' ? ['shravelle.organization' => self::$cfg['organisation']] : []))];
        if ($sampled || $hasError) {
            $spans = [];
            foreach (self::$spans as $s) {
                $spans[] = [
                    'traceId' => self::$traceId, 'spanId' => $s['spanId'], 'parentSpanId' => $s['parent'], 'name' => $s['name'],
                    'kind' => ['internal' => 1, 'server' => 2, 'client' => 3][$s['kind']] ?? 1,
                    'startTimeUnixNano' => self::nanos($s['start']), 'endTimeUnixNano' => self::nanos($s['end'] ?? microtime(true)),
                    'attributes' => self::kv($s['attrs']), 'events' => $s['events'], 'status' => $s['status'],
                ];
            }
            self::post('/v1/traces', json_encode(['resourceSpans' => [['resource' => $resource, 'scopeSpans' => [['scope' => ['name' => 'shravelle-php'], 'spans' => $spans]]]]]));
        }
        if (self::$logs) {
            self::post('/v1/logs', json_encode(['resourceLogs' => [['resource' => $resource, 'scopeLogs' => [['scope' => ['name' => 'shravelle-php'], 'logRecords' => self::$logs]]]]]));
        }
        self::$root = null;
    }

    private static function post(string $path, string $body, float $timeout = null): bool
    {
        $url = self::$cfg['endpoint'] . $path;
        $headers = ['Content-Type: application/json', 'X-API-Key: ' . self::$cfg['api_key']];
        $timeout = $timeout ?? self::$cfg['timeout'];
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => (int)($timeout * 1000), CURLOPT_CONNECTTIMEOUT_MS => 1000]);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return $code >= 200 && $code < 300;
        }
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true]]);
        return @file_get_contents($url, false, $ctx) !== false;
    }

    private static function kv(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if (is_bool($v)) {
                $out[] = ['key' => (string)$k, 'value' => ['boolValue' => $v]];
            } elseif (is_int($v)) {
                $out[] = ['key' => (string)$k, 'value' => ['intValue' => (string)$v]];
            } elseif (is_float($v)) {
                $out[] = ['key' => (string)$k, 'value' => ['doubleValue' => $v]];
            } else {
                $out[] = ['key' => (string)$k, 'value' => ['stringValue' => is_scalar($v) ? (string)$v : json_encode($v)]];
            }
        }
        return $out;
    }

    private static function nanos(float $t): string
    {
        return sprintf('%.0f', $t * 1e9);
    }
}
