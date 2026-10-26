<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\WebSocket;

/**
 * Server side of the RFC 6455 opening handshake.
 */
final class Handshake
{
    public const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public const MAX_HEAD_SIZE = 8192;

    public static function acceptKey(string $key): string
    {
        return base64_encode(sha1($key.self::GUID, true));
    }

    public static function isUpgrade(HttpRequest $request): bool
    {
        return $request->headerHasToken('upgrade', 'websocket');
    }

    /**
     * Validates an upgrade request. Throws HandshakeException carrying the HTTP status to reply with.
     *
     * @param  list<string>  $allowedOrigins  empty means any origin
     */
    public static function validate(HttpRequest $request, array $allowedOrigins = []): string
    {
        if ($request->method !== 'GET' || $request->version !== '1.1') {
            throw new HandshakeException(400, 'WebSocket upgrade requires GET over HTTP/1.1');
        }
        if (! self::isUpgrade($request) || ! $request->headerHasToken('connection', 'upgrade')) {
            throw new HandshakeException(400, 'Missing Upgrade/Connection headers');
        }
        if ($request->header('sec-websocket-version') !== '13') {
            throw new HandshakeException(426, 'Unsupported WebSocket version', ['Sec-WebSocket-Version' => '13']);
        }
        $key = $request->header('sec-websocket-key') ?? '';
        $decoded = base64_decode($key, true);
        if ($decoded === false || strlen($decoded) !== 16) {
            throw new HandshakeException(400, 'Invalid Sec-WebSocket-Key');
        }
        if ($allowedOrigins !== []) {
            $origin = $request->header('origin');
            if ($origin === null || ! self::originAllowed($origin, $allowedOrigins)) {
                throw new HandshakeException(403, 'Origin not allowed');
            }
        }

        return $key;
    }

    /**
     * @param  list<string>  $allowed  exact origins or "*.example.com" wildcards
     */
    public static function originAllowed(string $origin, array $allowed): bool
    {
        $origin = strtolower(rtrim($origin, '/'));
        $host = parse_url($origin, PHP_URL_HOST);
        foreach ($allowed as $pattern) {
            $pattern = strtolower(rtrim($pattern, '/'));
            if ($pattern === '*' || $pattern === $origin) {
                return true;
            }
            if (str_starts_with($pattern, '*.') && is_string($host) && str_ends_with($host, substr($pattern, 1))) {
                return true;
            }
        }

        return false;
    }

    public static function response(string $key): string
    {
        return "HTTP/1.1 101 Switching Protocols\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            .'Sec-WebSocket-Accept: '.self::acceptKey($key)."\r\n\r\n";
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function httpResponse(int $status, string $body, string $contentType = 'text/plain', array $headers = []): string
    {
        $reasons = [200 => 'OK', 400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
            426 => 'Upgrade Required', 431 => 'Request Header Fields Too Large', 503 => 'Service Unavailable'];
        $head = 'HTTP/1.1 '.$status.' '.($reasons[$status] ?? 'Error')."\r\n"
            .'Content-Type: '.$contentType."\r\n"
            .'Content-Length: '.strlen($body)."\r\n"
            ."Connection: close\r\n";
        foreach ($headers as $name => $value) {
            $head .= $name.': '.$value."\r\n";
        }

        return $head."\r\n".$body;
    }
}
