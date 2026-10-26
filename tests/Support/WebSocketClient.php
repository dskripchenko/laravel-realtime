<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Tests\Support;

use Dskripchenko\Realtime\WebSocket\Frame;
use Dskripchenko\Realtime\WebSocket\FrameCodec;
use Dskripchenko\Realtime\WebSocket\FrameParser;
use Dskripchenko\Realtime\WebSocket\Handshake;
use Dskripchenko\Realtime\WebSocket\MessageAssembler;
use Dskripchenko\Realtime\WebSocket\Opcode;
use RuntimeException;

/**
 * A small blocking WebSocket client for integration tests.
 */
final class WebSocketClient
{
    /** @var array<string, mixed> the server's "welcome" message, set by tests after connecting */
    public array $welcome = [];

    /** @var list<Frame> */
    private array $pending = [];

    private readonly FrameParser $parser;

    private readonly MessageAssembler $assembler;

    /**
     * @param  resource  $stream
     */
    private function __construct(private $stream, string $leftover)
    {
        $this->parser = new FrameParser(false, 16 * 1024 * 1024);
        $this->assembler = new MessageAssembler(16 * 1024 * 1024);
        if ($leftover !== '') {
            $this->absorb($leftover);
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function connect(string $host, int $port, string $path, array $headers = []): self
    {
        $stream = stream_socket_client("tcp://{$host}:{$port}", $errno, $error, 3.0);
        if ($stream === false) {
            throw new RuntimeException("connect failed: {$error}");
        }
        stream_set_timeout($stream, 5);

        $key = base64_encode(random_bytes(16));
        $request = "GET {$path} HTTP/1.1\r\nHost: {$host}:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n";
        foreach ($headers as $name => $value) {
            $request .= "{$name}: {$value}\r\n";
        }
        fwrite($stream, $request."\r\n");

        $response = '';
        while (! str_contains($response, "\r\n\r\n")) {
            $chunk = fread($stream, 8192);
            if ($chunk === false || $chunk === '') {
                throw new HandshakeFailed(0, $response);
            }
            $response .= $chunk;
        }
        [$head, $leftover] = explode("\r\n\r\n", $response, 2);
        if (! preg_match('#^HTTP/1\.1 (\d{3})#', $head, $m)) {
            throw new HandshakeFailed(0, $head);
        }
        if ($m[1] !== '101') {
            throw new HandshakeFailed((int) $m[1], $response);
        }
        if (! str_contains($head, 'Sec-WebSocket-Accept: '.Handshake::acceptKey($key))) {
            throw new RuntimeException('Bad Sec-WebSocket-Accept');
        }

        return new self($stream, $leftover);
    }

    /**
     * Sends a raw HTTP request and returns the full response.
     */
    public static function http(string $host, int $port, string $request): string
    {
        $stream = stream_socket_client("tcp://{$host}:{$port}", $errno, $error, 3.0);
        if ($stream === false) {
            throw new RuntimeException("connect failed: {$error}");
        }
        stream_set_timeout($stream, 5);
        fwrite($stream, $request);
        $response = stream_get_contents($stream);
        fclose($stream);

        return (string) $response;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function sendJson(array $message): void
    {
        $this->sendFrame(Opcode::Text, json_encode($message, JSON_THROW_ON_ERROR));
    }

    public function sendFrame(Opcode $opcode, string $payload, bool $fin = true): void
    {
        $this->sendRaw(FrameCodec::encode($opcode, $payload, $fin, random_bytes(4)));
    }

    public function sendRaw(string $bytes): void
    {
        fwrite($this->stream, $bytes);
    }

    /**
     * Next complete message or control frame (pings are answered and skipped), or null on timeout/EOF.
     */
    public function receive(float $timeout = 3.0): ?Frame
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            while ($this->pending !== []) {
                $frame = array_shift($this->pending);
                if ($frame->opcode === Opcode::Ping) {
                    $this->sendFrame(Opcode::Pong, $frame->payload);

                    continue;
                }

                return $frame;
            }
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return null;
            }
            $read = [$this->stream];
            $write = $except = null;
            if (stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1e6)) === 0) {
                return null;
            }
            $chunk = fread($this->stream, 65536);
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $this->absorb($chunk);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function receiveJson(float $timeout = 3.0): ?array
    {
        $frame = $this->receive($timeout);
        if ($frame === null) {
            return null;
        }
        if ($frame->opcode !== Opcode::Text) {
            throw new RuntimeException('Expected a text frame, got '.$frame->opcode->name);
        }

        /** @var array<string, mixed> */
        return json_decode($frame->payload, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Reads messages until one matches.
     *
     * @param  callable(array<string, mixed>): bool  $match
     * @return array<string, mixed>
     */
    public function waitFor(callable $match, float $timeout = 3.0): array
    {
        $deadline = microtime(true) + $timeout;
        while (($left = $deadline - microtime(true)) > 0) {
            $message = $this->receiveJson($left);
            if ($message !== null && $match($message)) {
                return $message;
            }
        }
        throw new RuntimeException('Timed out waiting for a matching message');
    }

    public function close(int $code = 1000): void
    {
        @fwrite($this->stream, FrameCodec::close($code, '', random_bytes(4)));
        $this->receive(1.0);
        @fclose($this->stream);
    }

    public function abort(): void
    {
        @fclose($this->stream);
    }

    private function absorb(string $bytes): void
    {
        foreach ($this->parser->push($bytes) as $frame) {
            $message = $this->assembler->add($frame);
            if ($message !== null) {
                $this->pending[] = $message;
            }
        }
    }
}
