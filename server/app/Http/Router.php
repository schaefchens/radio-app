<?php
declare(strict_types=1);

namespace Arche\Http;

/** Method + path routing with `{name}` segments; nothing more is needed. */
final class Router
{
    /** @var list<array{0:string,1:string,2:\Closure}> */
    private array $routes = [];

    public function add(string $method, string $pattern, \Closure $handler): void
    {
        $this->routes[] = [$method, $pattern, $handler];
    }

    /** @return array{0:\Closure,1:array<string,string>}|null|false null = no path, false = wrong method */
    public function match(string $method, string $path): array|null|false
    {
        $pathMatched = false;
        foreach ($this->routes as [$m, $pattern, $handler]) {
            $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[A-Za-z0-9_-]+)', $pattern) . '$#';
            if (!preg_match($regex, $path, $mm)) continue;
            $pathMatched = true;
            if ($m !== $method) continue;
            $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
            return [$handler, $params];
        }
        return $pathMatched ? false : null;
    }
}
