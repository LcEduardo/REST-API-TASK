<?php

// Sends the HTTP status code and the JSON-encoded body
function respond(int $status, $body): void {
    http_response_code($status);
    echo json_encode($body);
}

// Error response in the same format the controllers return
function error(int $status, string $message): array {
    return ['status' => $status, 'body' => ['error' => $message]];
}

// id from /resource/{id}, or null if it was not sent
function routeId(array $segments): ?string {
    $id = $segments[1] ?? null;
    return ($id === null || $id === '') ? null : $id;
}

// Only calls $next if the id was sent; otherwise responds 400
function requireId(?string $id, callable $next): array {
    return $id === null ? error(400, 'ID not provided') : $next($id);
}

// Only calls $next if the caller has the required role; otherwise responds 403
function requireRole(?string $role, string $required, callable $next): array {
    return $role === $required ? $next() : error(403, 'Access Denied');
}

// Reads the JSON body and passes it to $next. If it is invalid (or not an object), responds 400.
function withJsonBody(callable $next): array {
    $body = json_decode(file_get_contents('php://input'), true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
        return error(400, 'Invalid JSON body');
    }

    return $next($body);
}
