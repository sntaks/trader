<?php

use App\Services\WebSocketFrameCodec;

/** Build an unmasked frame the way a server sends it. */
function unmaskedFrame(int $opcode, string $payload, bool $fin = true): string
{
    $length = strlen($payload);
    $header = chr(($fin ? 0x80 : 0) | $opcode);

    if ($length <= 125) {
        return $header.chr($length).$payload;
    }

    if ($length <= 65535) {
        return $header.chr(126).pack('n', $length).$payload;
    }

    return $header.chr(127).pack('J', $length).$payload;
}

test('decodes a single text frame', function () {
    $codec = new WebSocketFrameCodec;
    $codec->push("\x81\x05hello");

    expect($codec->next())->toBe(['opcode' => WebSocketFrameCodec::OPCODE_TEXT, 'payload' => 'hello'])
        ->and($codec->next())->toBeNull();
});

test('decodes 16-bit and 64-bit lengths', function (int $length) {
    $payload = str_repeat('x', $length);
    $codec = new WebSocketFrameCodec;
    $codec->push(unmaskedFrame(WebSocketFrameCodec::OPCODE_TEXT, $payload));

    expect($codec->next())->toBe(['opcode' => WebSocketFrameCodec::OPCODE_TEXT, 'payload' => $payload]);
})->with([
    '16-bit' => 126,
    '64-bit' => 70000,
]);

test('bytes pushed one at a time decode once complete', function () {
    $frame = unmaskedFrame(WebSocketFrameCodec::OPCODE_TEXT, str_repeat('y', 200));
    $codec = new WebSocketFrameCodec;

    foreach (str_split(substr($frame, 0, -1)) as $byte) {
        $codec->push($byte);
        expect($codec->next())->toBeNull();
    }

    $codec->push(substr($frame, -1));

    expect($codec->next()['payload'])->toBe(str_repeat('y', 200));
});

test('reassembles a fragmented message', function () {
    $codec = new WebSocketFrameCodec;
    $codec->push(unmaskedFrame(WebSocketFrameCodec::OPCODE_TEXT, 'ab', fin: false));

    expect($codec->next())->toBeNull();

    $codec->push(unmaskedFrame(WebSocketFrameCodec::OPCODE_CONTINUATION, 'cd'));

    expect($codec->next())->toBe(['opcode' => WebSocketFrameCodec::OPCODE_TEXT, 'payload' => 'abcd']);
});

test('a control frame between fragments comes out first', function () {
    $codec = new WebSocketFrameCodec;
    $codec->push(
        unmaskedFrame(WebSocketFrameCodec::OPCODE_TEXT, 'ab', fin: false)
        .unmaskedFrame(WebSocketFrameCodec::OPCODE_PING, 'p')
        .unmaskedFrame(WebSocketFrameCodec::OPCODE_CONTINUATION, 'cd')
    );

    expect($codec->next())->toBe(['opcode' => WebSocketFrameCodec::OPCODE_PING, 'payload' => 'p'])
        ->and($codec->next())->toBe(['opcode' => WebSocketFrameCodec::OPCODE_TEXT, 'payload' => 'abcd'])
        ->and($codec->next())->toBeNull();
});

test('unmasks a masked frame', function () {
    $codec = new WebSocketFrameCodec;
    $codec->push(WebSocketFrameCodec::encode(WebSocketFrameCodec::OPCODE_TEXT, '{"a":1}'));

    expect($codec->next())->toBe(['opcode' => WebSocketFrameCodec::OPCODE_TEXT, 'payload' => '{"a":1}']);
});

test('encode masks client frames', function () {
    $payload = str_repeat('{"ping":1}', 3);
    $frame = WebSocketFrameCodec::encode(WebSocketFrameCodec::OPCODE_TEXT, $payload);

    expect(ord($frame[0]))->toBe(0x81)
        ->and(ord($frame[1]) & 0x80)->toBe(0x80)
        ->and(ord($frame[1]) & 0x7F)->toBe(strlen($payload))
        ->and(substr($frame, 6))->not->toBe($payload);
});

test('rejects protocol violations', function (string $bytes) {
    $codec = new WebSocketFrameCodec;
    $codec->push($bytes);

    expect(fn () => $codec->next())->toThrow(RuntimeException::class);
})->with([
    'orphan continuation' => fn () => unmaskedFrame(WebSocketFrameCodec::OPCODE_CONTINUATION, 'x'),
    'oversized ping' => fn () => unmaskedFrame(WebSocketFrameCodec::OPCODE_PING, str_repeat('p', 126)),
    'fragmented ping' => fn () => unmaskedFrame(WebSocketFrameCodec::OPCODE_PING, 'p', fin: false),
    'data frame inside a message' => fn () => unmaskedFrame(WebSocketFrameCodec::OPCODE_TEXT, 'a', fin: false).unmaskedFrame(WebSocketFrameCodec::OPCODE_TEXT, 'b'),
]);
