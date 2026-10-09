<?php

/*
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace FearlessBull\TradeStation\Sdk\Exception;

use RuntimeException;

class TradeStationException extends RuntimeException
{
    /**
     * @param array<string, mixed>|null $response
     */
    public function __construct(string $message = '', int $code = 0, public readonly ?array $response = null)
    {
        parent::__construct($message, $code);
    }
}
