<?php

namespace iceboxind\lithocinch\drivers;

use GuzzleHttp\Client;

/**
 * POSTs the transform request to an HTTP endpoint — either the local `serve`
 * daemon or the hosted Lambda Function URL. Same contract, different URL/auth.
 */
class HttpDriver implements DriverInterface
{
    public function __construct(
        private string $url,
        private ?string $token,
        private int $timeout,
    ) {
    }

    public function transform(string $imageBytes, array $request): array
    {
        $request['image'] = base64_encode($imageBytes);

        $headers = ['Content-Type' => 'application/json'];
        if ($this->token) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        $client = new Client(['timeout' => $this->timeout]);
        $response = $client->post(rtrim($this->url, '/') . '/transform', [
            'headers' => $headers,
            'body' => json_encode($request, JSON_THROW_ON_ERROR),
        ]);

        return Outputs::decode((string)$response->getBody());
    }
}
