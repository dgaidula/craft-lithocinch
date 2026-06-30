<?php

namespace iceboxind\crisp\drivers;

/**
 * A backend that turns source image bytes + a transform request into one or more
 * optimized outputs. Implementations: CliDriver (Node on the server), HttpDriver
 * (the `serve` daemon or the hosted Lambda). Bytes in, bytes out.
 */
interface DriverInterface
{
    /**
     * @param string $imageBytes raw source image bytes
     * @param array $request {ops?: array, format?: string, batch?: array, defaults?: array}
     * @return array<int, array{format:string,width:?int,height:?int,bytes:int,ssim:?float,engine:?string,data:string}>
     *               where `data` is the raw (decoded) output bytes
     */
    public function transform(string $imageBytes, array $request): array;
}
