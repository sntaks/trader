<?php

namespace App\Services;

use RuntimeException;

/**
 * Decodes WebSocket frames from a server and encodes masked client frames (RFC 6455).
 *
 * Bytes are buffered until a whole frame has arrived, so a read that times out mid-frame
 * loses nothing: the rest is pushed later and decoding carries on from the same place.
 */
class WebSocketFrameCodec
{
    public const OPCODE_CONTINUATION = 0;

    public const OPCODE_TEXT = 1;

    public const OPCODE_BINARY = 2;

    public const OPCODE_CLOSE = 8;

    public const OPCODE_PING = 9;

    public const OPCODE_PONG = 10;

    private const MAX_CONTROL_PAYLOAD = 125;

    private string $buffer = '';

    /** Opcode of the fragmented data message being reassembled, if any. */
    private ?int $messageOpcode = null;

    private string $messagePayload = '';

    public function push(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    /**
     * The next complete control frame or reassembled data message, or null until more bytes arrive.
     *
     * @return array{opcode: int, payload: string}|null
     *
     * @throws RuntimeException On a frame sequence RFC 6455 forbids.
     */
    public function next(): ?array
    {
        while (($frame = $this->takeFrame()) !== null) {
            ['fin' => $fin, 'opcode' => $opcode, 'payload' => $payload] = $frame;

            if ($opcode >= self::OPCODE_CLOSE) {
                if (! $fin || strlen($payload) > self::MAX_CONTROL_PAYLOAD) {
                    throw new RuntimeException('Invalid WebSocket control frame.');
                }

                return ['opcode' => $opcode, 'payload' => $payload];
            }

            if ($opcode === self::OPCODE_CONTINUATION) {
                if ($this->messageOpcode === null) {
                    throw new RuntimeException('WebSocket continuation frame without a message.');
                }

                $this->messagePayload .= $payload;
            } else {
                if ($this->messageOpcode !== null) {
                    throw new RuntimeException('WebSocket data frame inside a fragmented message.');
                }

                $this->messageOpcode = $opcode;
                $this->messagePayload = $payload;
            }

            if ($fin) {
                $message = ['opcode' => $this->messageOpcode, 'payload' => $this->messagePayload];
                $this->messageOpcode = null;
                $this->messagePayload = '';

                return $message;
            }
        }

        return null;
    }

    /** A masked client frame with FIN set. */
    public static function encode(int $opcode, string $payload): string
    {
        $length = strlen($payload);
        $frame = chr(0x80 | $opcode);

        if ($length <= 125) {
            $frame .= chr(0x80 | $length);
        } elseif ($length <= 65535) {
            $frame .= chr(0x80 | 126).pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127).pack('J', $length);
        }

        $mask = random_bytes(4);

        return $frame.$mask.($payload ^ str_repeat($mask, intdiv($length, 4) + 1));
    }

    /**
     * Remove one whole frame from the buffer, or return null when it has not fully arrived.
     *
     * @return array{fin: bool, opcode: int, payload: string}|null
     */
    private function takeFrame(): ?array
    {
        $available = strlen($this->buffer);

        if ($available < 2) {
            return null;
        }

        $first = ord($this->buffer[0]);
        $second = ord($this->buffer[1]);
        $length = $second & 0x7F;
        $offset = 2;

        if ($length === 126) {
            if ($available < 4) {
                return null;
            }

            $length = unpack('n', substr($this->buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if ($available < 10) {
                return null;
            }

            $length = unpack('J', substr($this->buffer, 2, 8))[1];
            $offset = 10;
        }

        $mask = null;

        if (($second & 0x80) !== 0) {
            if ($available < $offset + 4) {
                return null;
            }

            $mask = substr($this->buffer, $offset, 4);
            $offset += 4;
        }

        if ($available < $offset + $length) {
            return null;
        }

        $payload = substr($this->buffer, $offset, $length);
        $this->buffer = substr($this->buffer, $offset + $length);

        if ($mask !== null) {
            $payload ^= str_repeat($mask, intdiv($length, 4) + 1);
        }

        return ['fin' => ($first & 0x80) !== 0, 'opcode' => $first & 0x0F, 'payload' => $payload];
    }
}
