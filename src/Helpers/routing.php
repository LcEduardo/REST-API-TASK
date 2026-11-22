<?php

// Splits the request path into segments, ignoring the folder index.php lives in and the query string.
// e.g. ('/api/index.php', '/api/tasks/1?page=2') -> ['tasks', '1']
function parseSegments(string $scriptName, string $requestUri): array {
    // "" at the root, or "/subfolder" if index.php lives in one
    $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    $path = parse_url($requestUri, PHP_URL_PATH) ?? '';
    $path = substr($path, strlen($base));

    return explode('/', trim($path, '/'));
}

// Picks the response for the request. $routes is resource => [method => handler];
// each handler receives the id from the URL (or null) and returns ['status', 'body'].
// Headers to send go in the optional 'headers' key, so this function stays free of side effects.
function dispatch(array $routes, string $method, array $segments, ?string $role, bool $authFailed): array {
    $resource = $segments[0] ?? '';

    if ($authFailed) {
        return error(500, 'Database error');
    }

    // No key or invalid key -> 401, before looking at the route (does not reveal which routes exist)
    if ($role === null) {
        return error(401, 'Invalid or missing API key');
    }

    // Unknown route -> 404 (a client error, so not a 500)
    if (!isset($routes[$resource])) {
        return error(404, 'Route not found');
    }

    // Route exists but the method is not supported -> 405, with Allow built from the route table
    if (!isset($routes[$resource][$method])) {
        return error(405, 'Method not allowed')
            + ['headers' => ['Allow' => implode(', ', array_keys($routes[$resource]))]];
    }

    return $routes[$resource][$method](routeId($segments));
}
