<?php

/*
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace FearlessBull\TradeStation\Sdk\Auth;

interface TokenStorageInterface
{
    public function get(): ?TokenSet;

    public function save(TokenSet $tokenSet): void;

    public function clear(): void;
}
