<?php
declare(strict_types=1);

namespace Arche\Http;

use Arche\Api\Routes;
use Arche\ApiError;
use Arche\App;

/**
 * The front controller behind /api/index.php: route, run, answer as JSON.
 *
 * After answering a session or pulse request it closes the connection and,
 * if the cron has not ticked for 90 s, runs a tick itself — so a late or
 * missing konsoleH cron slows the station down but never stops it while
 * anyone is listening.
 */
final class Kernel
{
    public function __construct(private App $app) {}

    public static function run(App $app): void
    {
        $req = Request::fromGlobals();
        $kernel = new self($app);
        $kernel->handle($req)->send();
        if ($req->method === 'POST' && in_array($req->path, ['/api/session', '/api/pulse'], true)) {
            ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            else flush();
            try {
                $app->tick()->runIfLate();
            } catch (\Throwable) {
                // The tick records its own failures; the listener already has their answer.
            }
        }
    }

    public function handle(Request $req): Response
    {
        if ($req->method === 'OPTIONS') return new Response([], 204);
        $path = (string) preg_replace('#^/api(?=/|$)#', '', $req->path);
        $path = $path === '' ? '/' : rtrim($path, '/');
        $match = Routes::router()->match($req->method, $path === '' ? '/' : $path);
        if ($match === null) return new Response(['error' => 'not_found'], 404);
        if ($match === false) return new Response(['error' => 'method_not_allowed'], 405);
        [$handler, $params] = $match;
        try {
            $out = $handler(new Context($this->app, $req), $params);
            return $out instanceof Response ? $out : new Response($out);
        } catch (ApiError $e) {
            return new Response(['error' => $e->error] + $e->extra, $e->status);
        } catch (\Throwable $e) {
            try {
                $this->app->store()->audit('api', 'Unhandled error on ' . $req->method . ' ' . $path, $e::class . ': ' . $e->getMessage());
            } catch (\Throwable) {
            }
            error_log('[arche] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return new Response(['error' => 'server_error'], 500);
        }
    }
}
