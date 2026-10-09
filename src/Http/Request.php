<?php

/*
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace FearlessBull\TradeStation\Sdk\Http;

final readonly class Request
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @param array<string, mixed>|string|null $body
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public array $query = [],
        public array|string|null $body = null,
    ) {
    }
}
