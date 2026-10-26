<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Broker;

use RuntimeException;

/**
 * A minimal non-blocking RESP2 pub/sub client.
 *
 * phpredis' subscribe() blocks the whole process, which cannot coexist with the socket loop,
 * so the server keeps one raw socket that stream_select() can watch alongside client sockets.
 */
final class RespSubscriber
{
    /** @var resource|null */
    private $stream = null;

    private string $buffer = '';

    /** @var list<array{string, string}> */
    private array $queue = [];

    /** @var array<string, true> */
    private array $channels = [];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly bool $tls = false,
        private readonly float $timeout = 3.0,
    ) {}

    /**
     * @param  array<string, mixed>  $config  a Laravel "database.redis.<name>" entry
     */
    public static function fromConfig(array $config): self
    {
        $url = $config['url'] ?? null;
        if (is_string($url) && $url !== '') {
            $parts = parse_url($url);
            if (is_array($parts)) {
                $config = array_merge($config, array_filter([
                    'host' => $parts['host'] ?? null,
                    'port' => $parts['port'] ?? null,
                    'username' => isset($parts['user']) ? urldecode($parts['user']) : null,
                    'password' => isset($parts['pass']) ? urldecode($parts['pass']) : null,
                    'scheme' => $parts['scheme'] ?? null,
                ], static fn ($v) => $v !== null));
            }
        }

        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 6379;
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;
        $scheme = $config['scheme'] ?? 'tcp';

        return new self(
            is_string($host) ? $host : '127.0.0.1',
            is_numeric($port) ? (int) $port : 6379,
            is_string($username) && $username !== '' ? $username : null,
            is_string($password) && $password !== '' ? $password : null,
            in_array($scheme, ['tls', 'rediss'], true),
        );
    }

    public function connect(): void
    {
        $this->disconnect();
        $address = ($this->tls ? 'tls' : 'tcp').'://'.$this->host.':'.$this->port;
        $stream = @stream_socket_client($address, $errno, $error, $this->timeout);
        if ($stream === false) {
            throw new RuntimeException("Cannot connect to Redis at {$address}: {$error}");
        }
        $this->stream = $stream;
        stream_set_blocking($stream, false);

        if ($this->password !== null) {
            $auth = $this->username !== null ? ['AUTH', $this->username, $this->password] : ['AUTH', $this->password];
            $reply = $this->request($auth, static fn (mixed $r): bool => true);
            if ($reply instanceof RuntimeException) {
                $this->disconnect();
                throw $reply;
            }
        }

        foreach (array_keys($this->channels) as $channel) {
            $this->subscribe($channel, true);
        }
    }

    public function isConnected(): bool
    {
        return $this->stream !== null;
    }

    /**
     * @return resource|null
     */
    public function stream()
    {
        return $this->stream;
    }

    /**
     * Subscribes and waits for the confirmation, so a replay read issued right after cannot miss a message.
     */
    public function subscribe(string $channel, bool $force = false): void
    {
        if (isset($this->channels[$channel]) && ! $force) {
            return;
        }
        $this->channels[$channel] = true;
        $reply = $this->request(['SUBSCRIBE', $channel], static fn (mixed $r): bool => is_array($r)
            && ($r[0] ?? null) === 'subscribe' && ($r[1] ?? null) === $channel);
        if ($reply instanceof RuntimeException) {
            throw $reply;
        }
    }

    public function unsubscribe(string $channel): void
    {
        unset($this->channels[$channel]);
        if ($this->stream !== null) {
            $this->write(['UNSUBSCRIBE', $channel]);
        }
    }

    public function ping(): void
    {
        if ($this->stream !== null) {
            $this->write(['PING']);
        }
    }

    /**
     * Reads what is available and returns pub/sub messages as [channel, payload] pairs.
     *
     * @return list<array{string, string}>
     */
    public function read(): array
    {
        if ($this->stream !== null) {
            $this->fill();
            $this->drain();
        }
        $messages = $this->queue;
        $this->queue = [];

        return $messages;
    }

    /**
     * Messages queued while waiting for a SUBSCRIBE confirmation.
     */
    public function hasQueued(): bool
    {
        return $this->queue !== [];
    }

    public function disconnect(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
        }
        $this->stream = null;
        $this->buffer = '';
    }

    /**
     * @param  list<string>  $command
     * @param  callable(mixed): bool  $isReply
     */
    private function request(array $command, callable $isReply): mixed
    {
        $this->write($command);
        $deadline = microtime(true) + $this->timeout;

        while (microtime(true) < $deadline) {
            while (($value = $this->parse()) !== null) {
                [$reply] = $value;
                if ($reply instanceof RuntimeException || $isReply($reply)) {
                    return $reply;
                }
                $this->enqueue($reply);
            }
            $stream = $this->stream ?? throw new RuntimeException('Redis subscriber disconnected');
            $read = [$stream];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, 200_000) > 0) {
                $this->fill();
            }
        }

        throw new RuntimeException('Timed out waiting for Redis reply to '.$command[0]);
    }

    /**
     * @param  list<string>  $command
     */
    private function write(array $command): void
    {
        $payload = '*'.count($command)."\r\n";
        foreach ($command as $part) {
            $payload .= '$'.strlen($part)."\r\n".$part."\r\n";
        }

        $stream = $this->stream ?? throw new RuntimeException('Redis subscriber disconnected');
        // Commands are tiny; a short blocking write keeps them whole without an outgoing buffer.
        stream_set_blocking($stream, true);
        $written = @fwrite($stream, $payload);
        stream_set_blocking($stream, false);
        if ($written !== strlen($payload)) {
            $this->disconnect();
            throw new RuntimeException('Redis subscriber write failed');
        }
    }

    private function fill(): void
    {
        $stream = $this->stream ?? throw new RuntimeException('Redis subscriber disconnected');
        $chunk = @fread($stream, 65536);
        if ($chunk === false || ($chunk === '' && feof($stream))) {
            $this->disconnect();
            throw new RuntimeException('Redis subscriber connection lost');
        }
        $this->buffer .= $chunk;
    }

    private function drain(): void
    {
        while (($value = $this->parse()) !== null) {
            [$reply] = $value;
            if ($reply instanceof RuntimeException) {
                throw $reply;
            }
            $this->enqueue($reply);
        }
    }

    private function enqueue(mixed $reply): void
    {
        if (is_array($reply) && ($reply[0] ?? null) === 'message' && is_string($reply[1] ?? null) && is_string($reply[2] ?? null)) {
            $this->queue[] = [$reply[1], $reply[2]];
        }
    }

    /**
     * Parses one complete value from the buffer and consumes it.
     *
     * @return array{mixed}|null
     */
    private function parse(): ?array
    {
        $offset = 0;
        $value = Resp::parse($this->buffer, $offset);
        if ($value === null) {
            return null;
        }
        $this->buffer = substr($this->buffer, $offset);

        return $value;
    }
}
