<?php

namespace App\Services;

use GuzzleHttp\Client;

// Talks to the FastAPI model service (my-cow-py). One place for the URL, timeouts and
// response parsing, shared by the quick breed check and animal photo uploads.
class BreedPredictor
{
    public function __construct(private ?Client $client = null)
    {
        $this->client ??= new Client([
            'connect_timeout' => 5,
            'timeout' => 30, // TensorFlow on a small CPU can take a few seconds per photo
        ]);
    }

    /**
     * @param  string  $absolutePath  image file on local disk
     * @return array{class: ?string, confidence: ?float}
     *
     * @throws \GuzzleHttp\Exception\GuzzleException when the model service is unreachable
     */
    public function predict(string $absolutePath): array
    {
        $response = $this->client->post(config('services.ml.predict_url'), [
            'multipart' => [[
                'name' => 'file',
                'contents' => fopen($absolutePath, 'r'),
                'filename' => basename($absolutePath),
            ]],
        ]);

        $body = json_decode((string) $response->getBody(), true) ?: [];

        return [
            'class' => $body['class'] ?? null,
            'confidence' => isset($body['confidence']) ? (float) $body['confidence'] : null,
        ];
    }
}
