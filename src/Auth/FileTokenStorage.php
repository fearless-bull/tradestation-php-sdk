<?php

/*
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace FearlessBull\TradeStation\Sdk\Auth;

use FearlessBull\TradeStation\Sdk\Exception\TradeStationException;

final readonly class FileTokenStorage implements TokenStorageInterface
{
    public function __construct(private string $path)
    {
    }

    public function get(): ?TokenSet
    {
        if (! is_file($this->path)) {
            return null;
        }

        $contents = file_get_contents($this->path);

        if (false === $contents || '' === $contents) {
            return null;
        }

        $payload = json_decode($contents, true);

        return \is_array($payload) ? TokenSet::fromArray($payload) : null;
    }

    public function save(TokenSet $tokenSet): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new TradeStationException("Unable to create token storage directory [{$directory}].");
        }

        $json = json_encode($tokenSet->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (false === file_put_contents($this->path, $json, LOCK_EX)) {
            throw new TradeStationException("Unable to write token storage file [{$this->path}].");
        }

        @chmod($this->path, 0600);
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
