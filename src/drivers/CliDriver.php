<?php

namespace iceboxind\lithocinch\drivers;

use iceboxind\lithocinch\models\Settings;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Shells out to `lithocinch transform`, sending the request as JSON on stdin
 * and reading the JSON response from stdout. Requires Node + the tool on the box.
 */
class CliDriver implements DriverInterface
{
    public function __construct(private Settings $settings)
    {
    }

    public function transform(string $imageBytes, array $request): array
    {
        $request['image'] = base64_encode($imageBytes);

        $cmd = [];
        if ($this->settings->nodePath !== '') {
            $cmd[] = $this->settings->nodePath;
        }
        $cmd[] = $this->settings->toolPath;
        $cmd[] = 'transform';

        $process = new Process($cmd);
        $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
        $process->setTimeout($this->settings->timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Lithocinch cli transform failed: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return Outputs::decode($process->getOutput());
    }
}
