<?php

/*
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace FearlessBull\TradeStation\Sdk\Http;

interface TransportInterface
{
    public function send(Request $request): Response;
}
