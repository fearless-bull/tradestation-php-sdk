<?php

/*
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace FearlessBull\TradeStation\Sdk\Auth;

final class InMemoryTokenStorage implements TokenStorageInterface
{
    public function __construct(private ?TokenSet $tokenSet = null)
    {
    }

    public function get(): ?TokenSet
    {
        return $this->tokenSet;
    }

    public function save(TokenSet $tokenSet): void
    {
        $this->tokenSet = $tokenSet;
    }

    public function clear(): void
    {
        $this->tokenSet = null;
    }
}
