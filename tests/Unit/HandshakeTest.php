<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Tests\Unit;

use Dskripchenko\Realtime\WebSocket\Handshake;
use Dskripchenko\Realtime\WebSocket\HandshakeException;
use Dskripchenko\Realtime\WebSocket\HttpRequest;
use PHPUnit\Framework\TestCase;

final class HandshakeTest extends TestCase
{
    private const KEY = 'dGhlIHNhbXBsZSBub25jZQ==';

    public function test_accept_key_matches_rfc_example(): void
    {
        $this->assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', Handshake::acceptKey(self::KEY));
    }

    public function test_parses_request_line_headers_and_query(): void
    {
        $request = $this->request(['X-Repeated' => 'a'], '/socket?ticket=abc&x[]=1')
            ?? $this->fail('request did not parse');

        $this->assertSame('GET', $request->method);
        $this->assertSame('/socket', $request->path);
        $this->assertSame(['ticket' => 'abc'], $request->query);
        $this->assertSame('websocket', $request->header('UPGRADE'));
        $this->assertTrue($request->headerHasToken('connection', 'upgrade'));
    }

    public function test_repeated_headers_are_joined(): void
    {
        $request = HttpRequest::parse("GET / HTTP/1.1\r\nConnection: keep-alive\r\nConnection: Upgrade");
        $this->assertTrue($request?->headerHasToken('connection', 'Upgrade'));
    }

    public function test_malformed_request_line_is_rejected(): void
    {
        $this->assertNull(HttpRequest::parse('NONSENSE'));
        $this->assertNull(HttpRequest::parse("GET / HTTP/1.1\r\nbad header line"));
    }

    public function test_valid_request_returns_key_and_101_response(): void
    {
        $request = $this->request() ?? $this->fail();
        $key = Handshake::validate($request);
        $response = Handshake::response($key);

        $this->assertSame(self::KEY, $key);
        $this->assertStringStartsWith("HTTP/1.1 101 Switching Protocols\r\n", $response);
        $this->assertStringContainsString("Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=\r\n", $response);
        $this->assertStringEndsWith("\r\n\r\n", $response);
    }

    public function test_rejects_wrong_method(): void
    {
        $this->assertStatus(400, $this->request([], '/', 'POST'));
    }

    public function test_rejects_missing_upgrade_headers(): void
    {
        $this->assertStatus(400, $this->request(['Connection' => 'keep-alive']));
    }

    public function test_rejects_unsupported_version_with_426(): void
    {
        try {
            Handshake::validate($this->request(['Sec-WebSocket-Version' => '8']) ?? $this->fail());
            $this->fail('expected exception');
        } catch (HandshakeException $e) {
            $this->assertSame(426, $e->status);
            $this->assertSame(['Sec-WebSocket-Version' => '13'], $e->headers);
        }
    }

    public function test_rejects_bad_key(): void
    {
        $this->assertStatus(400, $this->request(['Sec-WebSocket-Key' => 'short']));
    }

    public function test_origin_allow_list(): void
    {
        $allowed = ['https://app.example.com', '*.tenant.example.com'];

        $this->assertSame(self::KEY, Handshake::validate($this->request(['Origin' => 'https://app.example.com']) ?? $this->fail(), $allowed));
        $this->assertSame(self::KEY, Handshake::validate($this->request(['Origin' => 'https://acme.tenant.example.com']) ?? $this->fail(), $allowed));
        $this->assertStatus(403, $this->request(['Origin' => 'https://evil.com']), $allowed);
        $this->assertStatus(403, $this->request(), $allowed);
        $this->assertFalse(Handshake::originAllowed('https://tenant.example.com.evil.com', $allowed));
    }

    public function test_http_response_has_length_and_close(): void
    {
        $response = Handshake::httpResponse(401, 'nope');
        $this->assertStringStartsWith("HTTP/1.1 401 Unauthorized\r\n", $response);
        $this->assertStringContainsString("Content-Length: 4\r\n", $response);
        $this->assertStringContainsString("Connection: close\r\n", $response);
        $this->assertStringEndsWith("\r\n\r\nnope", $response);
    }

    /**
     * @param  array<string, string>  $override
     */
    private function request(array $override = [], string $target = '/?ticket=abc', string $method = 'GET'): ?HttpRequest
    {
        $headers = array_merge([
            'Host' => 'localhost',
            'Upgrade' => 'websocket',
            'Connection' => 'keep-alive, Upgrade',
            'Sec-WebSocket-Key' => self::KEY,
            'Sec-WebSocket-Version' => '13',
        ], $override);
        $head = "{$method} {$target} HTTP/1.1";
        foreach ($headers as $name => $value) {
            $head .= "\r\n{$name}: {$value}";
        }

        return HttpRequest::parse($head);
    }

    /**
     * @param  list<string>  $origins
     */
    private function assertStatus(int $status, ?HttpRequest $request, array $origins = []): void
    {
        try {
            Handshake::validate($request ?? $this->fail('request did not parse'), $origins);
        } catch (HandshakeException $e) {
            $this->assertSame($status, $e->status);

            return;
        }
        $this->fail('Expected HandshakeException');
    }
}
