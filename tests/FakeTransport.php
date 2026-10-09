<?php

/*
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace FearlessBull\TradeStation\Sdk\Tests;

use FearlessBull\TradeStation\Sdk\Http\Request;
use FearlessBull\TradeStation\Sdk\Http\Response;
use FearlessBull\TradeStation\Sdk\Http\TransportInterface;

class FakeTransport implements TransportInterface
{
    /**
     * @var list<Request>
     */
    public array $requests = [];

    /**
     * @var list<Response>
     */
    private array $responses;

    public function __construct(Response ...$responses)
    {
        $this->responses = $responses === [] ? [new Response(200, ['ok' => true])] : array_values($responses);
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;

        return array_shift($this->responses) ?? new Response(200, ['ok' => true]);
    }
}
