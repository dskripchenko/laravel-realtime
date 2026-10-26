<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\WebSocket;

/**
 * The minimal HTTP/1.1 request head the server needs for the upgrade (or a health probe).
 */
final class HttpRequest
{
    /**
     * @param  array<string, string>  $headers  lower-cased names
     * @param  array<string, string>  $query
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly string $version,
        public readonly array $headers,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Whether a comma-separated header contains the token (case-insensitive).
     */
    public function headerHasToken(string $name, string $token): bool
    {
        $value = $this->header($name);
        if ($value === null) {
            return false;
        }
        foreach (explode(',', $value) as $part) {
            if (strcasecmp(trim($part), $token) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parses a request head (everything before the blank line).
     */
    public static function parse(string $head): ?self
    {
        $lines = explode("\r\n", $head);
        $requestLine = array_shift($lines);
        if (! preg_match('#^([A-Z]+) (\S+) HTTP/(\d\.\d)$#', $requestLine, $m)) {
            return null;
        }

        $headers = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                return null;
            }
            $name = strtolower(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));
            $headers[$name] = isset($headers[$name]) ? $headers[$name].', '.$value : $value;
        }

        $target = $m[2];
        $path = parse_url($target, PHP_URL_PATH);
        $queryString = parse_url($target, PHP_URL_QUERY);
        $query = [];
        if (is_string($queryString)) {
            parse_str($queryString, $parsed);
            foreach ($parsed as $key => $value) {
                if (is_string($value)) {
                    $query[(string) $key] = $value;
                }
            }
        }

        return new self($m[1], is_string($path) ? $path : '/', $query, $m[3], $headers);
    }
}
