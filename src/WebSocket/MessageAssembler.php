<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\WebSocket;

/**
 * Reassembles fragmented data messages; control frames pass straight through.
 */
final class MessageAssembler
{
    private ?Opcode $opcode = null;

    private string $buffer = '';

    public function __construct(private readonly int $maxMessageSize = 1_048_576) {}

    /**
     * Returns a complete message (opcode Text/Binary, or a control frame), or null while a fragmented message is pending.
     */
    public function add(Frame $frame): ?Frame
    {
        if ($frame->opcode->isControl()) {
            return $frame;
        }

        if ($frame->opcode === Opcode::Continuation) {
            if ($this->opcode === null) {
                throw new WebSocketException('Continuation frame without a message in progress');
            }
        } elseif ($this->opcode !== null) {
            throw new WebSocketException('New data frame while a fragmented message is in progress');
        } else {
            $this->opcode = $frame->opcode;
        }

        if (strlen($this->buffer) + strlen($frame->payload) > $this->maxMessageSize) {
            throw new WebSocketException('Message exceeds the size limit', CloseCode::MESSAGE_TOO_BIG);
        }
        $this->buffer .= $frame->payload;

        if (! $frame->fin) {
            return null;
        }

        $message = new Frame($this->opcode, $this->buffer);
        $this->opcode = null;
        $this->buffer = '';

        if ($message->opcode === Opcode::Text && ! mb_check_encoding($message->payload, 'UTF-8')) {
            throw new WebSocketException('Text message is not valid UTF-8', CloseCode::INVALID_PAYLOAD);
        }

        return $message;
    }
}
