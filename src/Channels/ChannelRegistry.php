<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Channels;

use Closure;

/**
 * Authorization callbacks keyed by patterns such as "board.{id}", mirroring routes/channels.php.
 */
final class ChannelRegistry
{
    /** @var array<string, array{regex: string, callback: Closure}> */
    private array $channels = [];

    public function register(string $pattern, callable $callback): void
    {
        $regex = preg_replace_callback(
            '/\\\\\{([A-Za-z_][A-Za-z0-9_]*)\\\\\}/',
            static fn (array $m): string => '(?<'.$m[1].'>[^.]+)',
            preg_quote($pattern, '#'),
        );

        $this->channels[$pattern] = [
            'regex' => '#^'.$regex.'$#',
            'callback' => Closure::fromCallable($callback),
        ];
    }

    /**
     * Finds the callback for a bare channel name and the positional parameters extracted from it.
     *
     * @return array{Closure, list<string>}|null
     */
    public function match(string $bareName): ?array
    {
        foreach ($this->channels as $channel) {
            if (preg_match($channel['regex'], $bareName, $matches) === 1) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[] = $value;
                    }
                }

                return [$channel['callback'], $params];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function patterns(): array
    {
        return array_keys($this->channels);
    }
}
