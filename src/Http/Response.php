<?php

/*
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace FearlessBull\TradeStation\Sdk\Http;

final readonly class Response
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public int $statusCode,
        public array|string|null $body,
        public array $headers = [],
    ) {
    }
}
