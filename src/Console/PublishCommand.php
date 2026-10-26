<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Console;

use Dskripchenko\Realtime\RealtimeManager;
use Illuminate\Console\Command;
use JsonException;

/**
 * Publishes one event from the shell: handy for smoke tests and operations.
 */
final class PublishCommand extends Command
{
    protected $signature = 'realtime:publish
        {channel : Channel name, e.g. news or private-board.1}
        {event : Event name}
        {data=null : JSON payload}
        {--scope= : Scope (tenant); default is the current scope}';

    protected $description = 'Publish an event to a realtime channel';

    public function handle(RealtimeManager $realtime): int
    {
        $channel = $this->argument('channel');
        $event = $this->argument('event');
        $json = $this->argument('data');
        $scope = $this->option('scope');
        if (! is_string($channel) || ! is_string($event) || ! is_string($json)) {
            return self::INVALID;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error('Payload is not valid JSON: '.$e->getMessage());

            return self::INVALID;
        }

        $seq = $realtime->publish($channel, $event, $data, is_string($scope) ? $scope : null);
        $this->line("Published {$event} to {$channel} as seq {$seq}");

        return self::SUCCESS;
    }
}
