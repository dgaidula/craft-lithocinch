<?php

namespace iceboxind\crisp\drivers;

use RuntimeException;

/** Decodes a `{ outputs: [...] }` JSON response, base64-decoding each output's data. */
final class Outputs
{
    public static function decode(string $json): array
    {
        $res = json_decode($json, true);
        if (!is_array($res) || !isset($res['outputs'])) {
            $err = is_array($res) && isset($res['error']) ? $res['error'] : 'malformed response';
            throw new RuntimeException("Crisp transform error: {$err}");
        }
        return array_map(static function (array $o): array {
            $o['data'] = base64_decode($o['data'] ?? '', true) ?: '';
            return $o;
        }, $res['outputs']);
    }
}
