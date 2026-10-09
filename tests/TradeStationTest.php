<?php

/*
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace FearlessBull\TradeStation\Sdk\Tests;

use DateTimeImmutable;
use FearlessBull\TradeStation\Sdk\Auth\InMemoryTokenStorage;
use FearlessBull\TradeStation\Sdk\Auth\TokenSet;
use FearlessBull\TradeStation\Sdk\Exception\TradeStationException;
use FearlessBull\TradeStation\Sdk\Http\Response;
use FearlessBull\TradeStation\Sdk\TradeStation;
use PHPUnit\Framework\TestCase;

class TradeStationTest extends TestCase
{
    public function testItBuildsAuthorizationUrl(): void
    {
        $tradeStation = new TradeStation([
            'authBaseUrl' => 'https://signin.example.test',
            'clientId' => 'client-id',
            'redirectUri' => 'http://localhost:8080',
            'scope' => 'openid offline_access MarketData',
        ]);

        $url = $tradeStation->authorizationUrl('state-value', ['prompt' => 'login']);

        self::assertStringStartsWith('https://signin.example.test/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('code', $query['response_type']);
        self::assertSame('client-id', $query['client_id']);
        self::assertSame('https://api.tradestation.com', $query['audience']);
        self::assertSame('http://localhost:8080', $query['redirect_uri']);
        self::assertSame('openid offline_access MarketData', $query['scope']);
        self::assertSame('state-value', $query['state']);
        self::assertSame('login', $query['prompt']);
    }

    public function testItExchangesAuthorizationCodeAndStoresTokens(): void
    {
        $storage = new InMemoryTokenStorage();
        $transport = new FakeTransport(new Response(200, [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'id_token' => 'id-token',
            'token_type' => 'Bearer',
            'scope' => 'openid offline_access',
            'expires_in' => 1200,
        ]));
        $tradeStation = new TradeStation([
            'authBaseUrl' => 'https://signin.example.test',
            'clientId' => 'client-id',
            'clientSecret' => 'client-secret',
            'redirectUri' => 'http://localhost:8080',
        ], $transport, $storage);

        $token = $tradeStation->exchangeAuthorizationCode('authorization-code');

        self::assertSame('access-token', $token->accessToken);
        self::assertSame('refresh-token', $storage->get()?->refreshToken);

        $request = $transport->requests[0];
        self::assertSame('POST', $request->method);
        self::assertSame('https://signin.example.test/oauth/token', $request->url);
        self::assertSame('application/x-www-form-urlencoded', $request->headers['content-type']);
        self::assertSame('authorization_code', $request->body['grant_type']);
        self::assertSame('authorization-code', $request->body['code']);
    }

    public function testItAcceptsKeyAndSecretAliasesForOauth(): void
    {
        $transport = new FakeTransport(new Response(200, [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 1200,
        ]));
        $tradeStation = new TradeStation([
            'authBaseUrl' => 'https://signin.example.test',
            'key' => 'api-key',
            'secret' => 'api-secret',
            'redirectUri' => 'http://localhost:8080',
        ], $transport);

        $tradeStation->exchangeAuthorizationCode('authorization-code');

        $request = $transport->requests[0];
        self::assertSame('api-key', $request->body['client_id']);
        self::assertSame('api-secret', $request->body['client_secret']);
    }
    public function testItRefreshesExpiredStoredTokenBeforeApiRequest(): void
    {
        $storage = new InMemoryTokenStorage(new TokenSet(
            accessToken: 'expired-token',
            refreshToken: 'refresh-token',
            expiresAt: time() - 10,
        ));
        $transport = new FakeTransport(
            new Response(200, [
                'access_token' => 'fresh-token',
                'expires_in' => 1200,
                'token_type' => 'Bearer',
            ]),
            new Response(200, ['Quotes' => []]),
        );
        $tradeStation = new TradeStation([
            'baseUrl' => 'https://api.example.test',
            'authBaseUrl' => 'https://signin.example.test',
            'clientId' => 'client-id',
            'clientSecret' => 'client-secret',
        ], $transport, $storage);

        $tradeStation->getQuotes('AAPL');

        self::assertCount(2, $transport->requests);
        self::assertSame('/oauth/token', parse_url($transport->requests[0]->url, PHP_URL_PATH));
        self::assertSame('refresh_token', $transport->requests[0]->body['grant_type']);
        self::assertSame('Bearer fresh-token', $transport->requests[1]->headers['Authorization']);
        self::assertSame('fresh-token', $storage->get()?->accessToken);
        self::assertSame('refresh-token', $storage->get()?->refreshToken);
    }

    public function testItBuildsAuthenticatedMarketDataRequests(): void
    {
        $transport = new FakeTransport(new Response(200, ['Quotes' => []]));
        $tradeStation = new TradeStation([
            'baseUrl' => 'https://api.example.test',
            'accessToken' => 'token',
        ], $transport);

        $result = $tradeStation->getQuotes(['AAPL', 'MSFT']);

        self::assertSame(['Quotes' => []], $result);
        self::assertCount(1, $transport->requests);

        $request = $transport->requests[0];
        self::assertSame('GET', $request->method);
        self::assertSame('https://api.example.test/v3/marketdata/quotes/AAPL,MSFT', $request->url);
        self::assertSame('Bearer token', $request->headers['Authorization']);
        self::assertSame('application/json', $request->headers['Accept']);
    }

    public function testItMapsBarChartOptions(): void
    {
        $transport = new FakeTransport();
        $tradeStation = new TradeStation([
            'baseUrl' => 'https://api.example.test',
            'accessToken' => 'token',
        ], $transport);

        $tradeStation->getBars('AAPL', [
            'interval' => 5,
            'unit' => 'Minute',
            'barsBack' => 100,
            'firstDate' => new DateTimeImmutable('2026-05-19T13:30:00+00:00'),
            'lastDate' => '2026-05-19T20:00:00Z',
            'sessionTemplate' => 'USEQPreAndPost',
        ]);

        $request = $transport->requests[0];
        self::assertSame('/v3/marketdata/barcharts/AAPL', parse_url($request->url, PHP_URL_PATH));
        self::assertSame([
            'interval' => 5,
            'unit' => 'Minute',
            'barsback' => 100,
            'firstdate' => '2026-05-19T13:30:00+00:00',
            'lastdate' => '2026-05-19T20:00:00Z',
            'sessiontemplate' => 'USEQPreAndPost',
        ], $request->query);
    }

    public function testItMapsSymbolSuggestionOptions(): void
    {
        $transport = new FakeTransport();
        $tradeStation = new TradeStation([
            'baseUrl' => 'https://api.example.test',
            'accessToken' => 'token',
        ], $transport);

        $tradeStation->suggestSymbols('AAP', [
            'top' => 5,
            'filter' => "Category eq 'Stock'",
        ]);

        $request = $transport->requests[0];
        self::assertSame('/v2/data/symbols/suggest/AAP', parse_url($request->url, PHP_URL_PATH));
        self::assertSame([
            '$top' => 5,
            '$filter' => "Category eq 'Stock'",
        ], $request->query);
    }

    public function testItBuildsBrokerageRequests(): void
    {
        $transport = new FakeTransport(new Response(200, ['Orders' => []]));
        $tradeStation = new TradeStation([
            'baseUrl' => 'https://api.example.test',
            'accessToken' => 'token',
        ], $transport);

        $tradeStation->getOrders(['ABC123', 'DEF456'], [
            'pageSize' => 50,
            'nextToken' => null,
        ]);

        $request = $transport->requests[0];
        self::assertSame('https://api.example.test/v3/brokerage/accounts/ABC123,DEF456/orders', $request->url);
        self::assertSame(['pageSize' => 50], $request->query);
    }

    public function testItBuildsOrderExecutionRequests(): void
    {
        $transport = new FakeTransport(new Response(200, ['OrderID' => 'order-id']));
        $tradeStation = new TradeStation([
            'baseUrl' => 'https://api.example.test',
            'accessToken' => 'token',
        ], $transport);

        $result = $tradeStation->placeOrder([
            'AccountID' => 'ABC123',
            'Symbol' => 'AAPL',
            'Quantity' => '1',
            'OrderType' => 'Market',
            'TradeAction' => 'BUY',
            'TimeInForce' => ['Duration' => 'DAY'],
        ]);

        self::assertSame(['OrderID' => 'order-id'], $result);

        $request = $transport->requests[0];
        self::assertSame('POST', $request->method);
        self::assertSame('https://api.example.test/v3/orderexecution/orders', $request->url);
        self::assertSame('AAPL', $request->body['Symbol']);
    }

    public function testItRevokesRefreshTokenAndClearsStorage(): void
    {
        $storage = new InMemoryTokenStorage(new TokenSet(
            accessToken: 'access-token',
            refreshToken: 'refresh-token',
        ));
        $transport = new FakeTransport(new Response(200, null));
        $tradeStation = new TradeStation([
            'authBaseUrl' => 'https://signin.example.test',
            'clientId' => 'client-id',
            'clientSecret' => 'client-secret',
        ], $transport, $storage);

        $tradeStation->revokeRefreshToken();

        self::assertNull($storage->get());
        self::assertSame('https://signin.example.test/oauth/revoke', $transport->requests[0]->url);
        self::assertSame('refresh-token', $transport->requests[0]->body['token']);
    }

    public function testItThrowsForApiErrors(): void
    {
        $transport = new FakeTransport(new Response(400, [
            'Message' => 'invalid order',
        ]));
        $tradeStation = new TradeStation([
            'baseUrl' => 'https://api.example.test',
            'accessToken' => 'token',
        ], $transport);

        $this->expectException(TradeStationException::class);
        $this->expectExceptionMessage('invalid order');
        $this->expectExceptionCode(400);

        $tradeStation->placeOrder([]);
    }

    public function testRateLimitBackoffPrefersTheResetHeaderWhenNumeric(): void
    {
        self::assertSame(12.0, $this->rateLimitBackoffSeconds('12', 0));
        self::assertSame(12.0, $this->rateLimitBackoffSeconds('12', 5));
    }

    public function testRateLimitBackoffFloorsTheResetHeaderAtOneSecond(): void
    {
        self::assertSame(1.0, $this->rateLimitBackoffSeconds('0', 0));
    }

    public function testRateLimitBackoffFallsBackToExponentialDelayWithoutAUsableHeader(): void
    {
        self::assertSame(1.0, $this->rateLimitBackoffSeconds(null, 0));
        self::assertSame(2.0, $this->rateLimitBackoffSeconds('not-a-number', 1));
        self::assertSame(4.0, $this->rateLimitBackoffSeconds(null, 2));
    }

    public function testRateLimitBackoffCapsTheExponentialFallbackAtSixtySeconds(): void
    {
        self::assertSame(60.0, $this->rateLimitBackoffSeconds(null, 10));
    }

    private function rateLimitBackoffSeconds(?string $resetHeader, int $consecutiveFailures): float
    {
        $method = new \ReflectionMethod(TradeStation::class, 'rateLimitBackoffSeconds');

        return $method->invoke(null, $resetHeader, $consecutiveFailures);
    }
}
