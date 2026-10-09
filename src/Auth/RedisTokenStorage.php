<?php

/*
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace FearlessBull\TradeStation\Sdk\Auth;

final readonly class RedisTokenStorage implements TokenStorageInterface
{
    public function __construct(private object $redis, private string $key = 'tradestation:tokens')
    {
    }

    public function get(): ?TokenSet
    {
        $contents = $this->redis->get($this->key);

        if (! \is_string($contents) || '' === $contents) {
            return null;
        }

        $payload = json_decode($contents, true);

        return \is_array($payload) ? TokenSet::fromArray($payload) : null;
    }

    public function save(TokenSet $tokenSet): void
    {
        $this->redis->set($this->key, json_encode($tokenSet->toArray(), JSON_THROW_ON_ERROR));
    }

    public function clear(): void
    {
        $this->redis->del($this->key);
    }
}
