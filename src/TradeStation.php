<?php

/*
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace FearlessBull\TradeStation\Sdk;

use DateTimeInterface;
use FearlessBull\TradeStation\Sdk\Auth\TokenSet;
use FearlessBull\TradeStation\Sdk\Auth\TokenStorageInterface;
use FearlessBull\TradeStation\Sdk\Exception\TradeStationException;
use FearlessBull\TradeStation\Sdk\Http\CurlTransport;
use FearlessBull\TradeStation\Sdk\Http\Request;
use FearlessBull\TradeStation\Sdk\Http\Response;
use FearlessBull\TradeStation\Sdk\Http\TransportInterface;

class TradeStation
{
    /**
     * @var array<string, mixed>
     */
    private array $configuration;

    public function __construct(array $config = [], private readonly ?TransportInterface $transport = null, private readonly ?TokenStorageInterface $tokenStorage = null)
    {
        $this->configuration = [
            'baseUrl' => $config['baseUrl'] ?? getenv('TRADESTATION_API_BASE_URL') ?: 'https://api.tradestation.com',
            'authBaseUrl' => $config['authBaseUrl'] ?? getenv('TRADESTATION_AUTH_BASE_URL') ?: 'https://signin.tradestation.com',
            'clientId' => $config['clientId'] ?? $config['key'] ?? $config['apiKey'] ?? getenv('TRADESTATION_CLIENT_ID') ?: getenv('TRADESTATION_API_KEY') ?: '',
            'clientSecret' => $config['clientSecret'] ?? $config['secret'] ?? $config['apiSecret'] ?? getenv('TRADESTATION_CLIENT_SECRET') ?: getenv('TRADESTATION_API_SECRET') ?: '',
            'redirectUri' => $config['redirectUri'] ?? getenv('TRADESTATION_REDIRECT_URI') ?: '',
            'audience' => $config['audience'] ?? getenv('TRADESTATION_AUDIENCE') ?: 'https://api.tradestation.com',
            'scope' => $config['scope'] ?? getenv('TRADESTATION_SCOPE') ?: 'openid profile offline_access MarketData ReadAccount Trade',
            'accessToken' => $config['accessToken'] ?? getenv('TRADESTATION_ACCESS_TOKEN') ?: '',
            'refreshToken' => $config['refreshToken'] ?? getenv('TRADESTATION_REFRESH_TOKEN') ?: '',
            'tokenRefreshBufferSeconds' => (int) ($config['tokenRefreshBufferSeconds'] ?? 60),
            'verbose' => $config['verbose'] ?? false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        return $this->configuration;
    }

    public function authorizationUrl(?string $state = null, array $options = []): string
    {
        $query = $this->compact([
            'response_type' => 'code',
            'client_id' => $options['clientId'] ?? $this->configuration['clientId'],
            'redirect_uri' => $options['redirectUri'] ?? $this->configuration['redirectUri'],
            'audience' => $options['audience'] ?? $this->configuration['audience'],
            'scope' => $options['scope'] ?? $this->configuration['scope'],
            'state' => $state,
            'prompt' => $options['prompt'] ?? null,
        ]);

        return $this->joinUrl($this->configuration['authBaseUrl'], '/authorize') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeAuthorizationCode(string $code, ?string $redirectUri = null): TokenSet
    {
        return $this->requestToken([
            'grant_type' => 'authorization_code',
            'client_id' => $this->configuration['clientId'],
            'client_secret' => $this->configuration['clientSecret'],
            'code' => $code,
            'redirect_uri' => $redirectUri ?? $this->configuration['redirectUri'],
        ]);
    }

    public function refreshAccessToken(?string $refreshToken = null): TokenSet
    {
        $current = $this->currentTokenSet();
        $refreshToken ??= $current?->refreshToken ?? $this->configuration['refreshToken'];

        if (! \is_string($refreshToken) || '' === $refreshToken) {
            throw new TradeStationException('Unable to refresh TradeStation access token without a refresh token.');
        }

        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'client_id' => $this->configuration['clientId'],
            'client_secret' => $this->configuration['clientSecret'],
            'refresh_token' => $refreshToken,
        ], $current);
    }

    public function revokeRefreshToken(?string $refreshToken = null): void
    {
        $refreshToken ??= $this->currentTokenSet()?->refreshToken ?? $this->configuration['refreshToken'];

        if (! \is_string($refreshToken) || '' === $refreshToken) {
            throw new TradeStationException('Unable to revoke TradeStation refresh token without a refresh token.');
        }

        $response = $this->getTransport()->send(new Request(
            method: 'POST',
            url: $this->joinUrl($this->configuration['authBaseUrl'], '/oauth/revoke'),
            headers: ['content-type' => 'application/json', 'Accept' => 'application/json'],
            body: $this->compact([
                'client_id' => $this->configuration['clientId'],
                'client_secret' => $this->configuration['clientSecret'],
                'token' => $refreshToken,
            ]),
        ));

        if ($response->statusCode >= 400) {
            throw new TradeStationException($this->errorMessage($response), $response->statusCode, \is_array($response->body) ? $response->body : null);
        }

        $this->tokenStorage?->clear();
    }

    public function token(): ?TokenSet
    {
        return $this->currentTokenSet();
    }

    public function suggestSymbols(string $text, array $options = []): array|string|null
    {
        return $this->sendRequest('/v2/data/symbols/suggest/' . rawurlencode($text), $this->compact([
            '$top' => $options['top'] ?? $options['$top'] ?? null,
            '$filter' => $options['filter'] ?? $options['$filter'] ?? null,
        ]));
    }

    public function searchSymbols(string $criteria, array $options = []): array|string|null
    {
        return $this->sendRequest('/v2/data/symbols/search/' . rawurlencode($criteria), $this->compact([
            '$top' => $options['top'] ?? $options['$top'] ?? null,
            '$filter' => $options['filter'] ?? $options['$filter'] ?? null,
        ]));
    }

    public function getAccounts(): array|string|null
    {
        return $this->sendRequest('/v3/brokerage/accounts');
    }

    public function getBalances(array|string $accounts): array|string|null
    {
        return $this->sendRequest('/v3/brokerage/accounts/' . $this->csv($accounts) . '/balances');
    }

    public function getBeginningOfDayBalances(array|string $accounts): array|string|null
    {
        return $this->sendRequest('/v3/brokerage/accounts/' . $this->csv($accounts) . '/bodbalances');
    }

    public function getOrders(array|string $accounts, array $options = []): array|string|null
    {
        return $this->sendRequest('/v3/brokerage/accounts/' . $this->csv($accounts) . '/orders', $this->compact([
            'pageSize' => $options['pageSize'] ?? null,
            'nextToken' => $options['nextToken'] ?? null,
        ]));
    }

    public function getOrder(array|string $accounts, array|string $orderIds): array|string|null
    {
        return $this->sendRequest('/v3/brokerage/accounts/' . $this->csv($accounts) . '/orders/' . $this->csv($orderIds));
    }

    public function getHistoricalOrders(array|string $accounts, array $options = []): array|string|null
    {
        return $this->sendRequest('/v3/brokerage/accounts/' . $this->csv($accounts) . '/historicalorders', $this->compact([
            'since' => $this->formatDateTime($options['since'] ?? null),
            'pageSize' => $options['pageSize'] ?? null,
            'nextToken' => $options['nextToken'] ?? null,
        ]));
    }

    public function getHistoricalOrder(array|string $accounts, array|string $orderIds): array|string|null
    {
        return $this->sendRequest('/v3/brokerage/accounts/' . $this->csv($accounts) . '/historicalorders/' . $this->csv($orderIds));
    }

    public function getPositions(array|string $accounts, array $options = []): array|string|null
    {
        return $this->sendRequest('/v3/brokerage/accounts/' . $this->csv($accounts) . '/positions', $this->compact([
            'symbols' => isset($options['symbols']) ? $this->csv($options['symbols']) : null,
        ]));
    }

    public function getBars(string $symbol, array $options = []): array|string|null
    {
        return $this->sendRequest('/v3/marketdata/barcharts/' . rawurlencode($symbol), $this->compact([
            'interval' => $options['interval'] ?? null,
            'unit' => $options['unit'] ?? null,
            'barsback' => $options['barsBack'] ?? $options['barsback'] ?? null,
            'firstdate' => $this->formatDateTime($options['firstDate'] ?? $options['firstdate'] ?? null),
            'lastdate' => $this->formatDateTime($options['lastDate'] ?? $options['lastdate'] ?? null),
            'sessiontemplate' => $options['sessionTemplate'] ?? $options['sessiontemplate'] ?? null,
        ]));
    }

    /**
     * Concurrent counterpart to getBars() — fetches bars for multiple symbols at once via
     * sendRequestMulti() instead of one blocking request per symbol. Each entry may carry its
     * own $options (same shape getBars() itself accepts) and an optional `name` to key its
     * result by, instead of its bare `symbol` — needed when the same symbol appears more than
     * once in the same batch (e.g. two different date-range chunks for that symbol), since
     * sendRequestMulti() requires every entry's own key to be unique. Defaults to `symbol` when
     * omitted, matching the common one-request-per-symbol case.
     *
     * @param  list<array{name?: string, symbol: string, options?: array}>  $requests
     * @return array<string, array{body: array|string|null, statusCode: int, error: ?string}> keyed by each request's own `name` (or `symbol`)
     */
    public function getBarsMulti(array $requests): array
    {
        return $this->sendRequestMulti(array_map(
            fn (array $request): array => [
                'name' => $request['name'] ?? $request['symbol'],
                'endpoint' => '/v3/marketdata/barcharts/' . rawurlencode($request['symbol']),
                'query' => [
                    'interval' => $request['options']['interval'] ?? null,
                    'unit' => $request['options']['unit'] ?? null,
                    'barsback' => $request['options']['barsBack'] ?? $request['options']['barsback'] ?? null,
                    'firstdate' => $this->formatDateTime($request['options']['firstDate'] ?? $request['options']['firstdate'] ?? null),
                    'lastdate' => $this->formatDateTime($request['options']['lastDate'] ?? $request['options']['lastdate'] ?? null),
                    'sessiontemplate' => $request['options']['sessionTemplate'] ?? $request['options']['sessiontemplate'] ?? null,
                ],
            ],
            $requests,
        ));
    }

    public function streamBars(string $symbol, array $options = [], ?callable $onBar = null, ?callable $onIdle = null, float $idleIntervalSeconds = 1.0): void
    {
        $this->streamRequest('/v3/marketdata/stream/barcharts/' . rawurlencode($symbol), $this->compact([
            'interval' => $options['interval'] ?? null,
            'unit' => $options['unit'] ?? null,
            'barsback' => $options['barsBack'] ?? $options['barsback'] ?? null,
            'sessiontemplate' => $options['sessionTemplate'] ?? $options['sessiontemplate'] ?? null,
        ]), $onBar, $onIdle, $idleIntervalSeconds);
    }

    public function getQuotes(array|string $symbols): array|string|null
    {
        return $this->sendRequest('/v3/marketdata/quotes/' . $this->csv($symbols));
    }

    public function streamQuotes(array|string $symbols, ?callable $onTick = null, ?callable $onIdle = null, float $idleIntervalSeconds = 1.0): void
    {
        $this->streamRequest('/v3/marketdata/stream/quotes/' . $this->csv($symbols), [], $onTick, $onIdle, $idleIntervalSeconds);
    }

    /**
     * Streams market depth (Level 2 order book) quotes for a single symbol. A separate update is
     * returned for each price, side, and participant.
     *
     * @param  array{maxLevels?: int}  $options
     */
    public function streamMarketDepthQuotes(string $symbol, array $options = [], ?callable $onTick = null, ?callable $onIdle = null, float $idleIntervalSeconds = 1.0): void
    {
        $this->streamRequest('/v3/marketdata/stream/marketdepth/quotes/' . rawurlencode($symbol), $this->compact([
            'maxlevels' => $options['maxLevels'] ?? $options['maxlevels'] ?? null,
        ]), $onTick, $onIdle, $idleIntervalSeconds);
    }

    /**
     * Streams a chain of option spreads for a given underlying symbol, spread type, and
     * expiration. TradeStation allows a maximum of 10 concurrent option chain streams.
     *
     * @param  array{expiration?: string, expiration2?: string, strikeProximity?: int, spreadType?: string, riskFreeRate?: float, priceCenter?: float, strikeInterval?: int, enableGreeks?: bool, strikeRange?: string, optionType?: string}  $options
     */
    public function streamOptionChain(string $underlying, array $options = [], ?callable $onSpread = null, ?callable $onIdle = null, float $idleIntervalSeconds = 1.0): void
    {
        $enableGreeks = $options['enableGreeks'] ?? null;

        $this->streamRequest('/v3/marketdata/stream/options/chains/' . rawurlencode($underlying), $this->compact([
            'expiration' => $options['expiration'] ?? null,
            'expiration2' => $options['expiration2'] ?? null,
            'strikeProximity' => $options['strikeProximity'] ?? null,
            'spreadType' => $options['spreadType'] ?? null,
            'riskFreeRate' => $options['riskFreeRate'] ?? null,
            'priceCenter' => $options['priceCenter'] ?? null,
            'strikeInterval' => $options['strikeInterval'] ?? null,
            'enableGreeks' => null === $enableGreeks ? null : ($enableGreeks ? 'true' : 'false'),
            'strikeRange' => $options['strikeRange'] ?? null,
            'optionType' => $options['optionType'] ?? null,
        ]), $onSpread, $onIdle, $idleIntervalSeconds);
    }

    /**
     * Streams order updates for the given accounts (1-25 Account IDs).
     */
    public function streamOrders(array|string $accounts, ?callable $onOrder = null, ?callable $onIdle = null, float $idleIntervalSeconds = 1.0): void
    {
        $this->streamRequest('/v3/brokerage/stream/accounts/' . $this->csv($accounts) . '/orders', [], $onOrder, $onIdle, $idleIntervalSeconds);
    }

    /**
     * Streams position updates for the given accounts (1-25 Account IDs). With
     * `$options['changes'] = true`, the stream returns a full snapshot first, then only the
     * fields that changed (plus PositionID) on every update after that.
     *
     * @param  array{changes?: bool}  $options
     */
    public function streamPositions(array|string $accounts, array $options = [], ?callable $onPosition = null, ?callable $onIdle = null, float $idleIntervalSeconds = 1.0): void
    {
        $this->streamRequest('/v3/brokerage/stream/accounts/' . $this->csv($accounts) . '/positions', $this->compact([
            'changes' => isset($options['changes']) ? ($options['changes'] ? 'true' : 'false') : null,
        ]), $onPosition, $onIdle, $idleIntervalSeconds);
    }

    /**
     * Run multiple GET streams concurrently in a single curl_multi event loop.
     * Each entry must provide a `name` (used to dispatch to its callback), an `endpoint` path,
     * an optional `query` array, and an `onLine` callable. Returning false from any callback
     * stops every stream; nulls keep them running.
     *
     * Any stream whose connection ends — a server-side disconnect, a one-shot error response that
     * closes the body, a network blip — is reconnected automatically (fresh handle, fresh token)
     * rather than left dead while the other streams carry on; the whole call only ever returns once
     * a callback returns false. $onReconnect, if given, is invoked with the stream's name right
     * before each reconnect attempt (plus a second, optional context string when the reconnect was
     * delayed for rate-limiting — see below), so a caller can log it.
     *
     * A stream that gets rate-limited (HTTP 429) backs off before reconnecting instead of retrying
     * on the same flat ~0.5s cadence as a normal disconnect: it waits for the duration in the
     * response's X-RateLimit-Reset header (per TradeStation's docs, "the time, in seconds, until
     * the rate limit fully resets"), or an exponential fallback (1s, 2s, 4s, ... capped at 60s) if
     * that header is missing, doubling further on each consecutive 429 for that same stream.
     * Retrying immediately against an active limit would otherwise just re-trigger the same 429
     * roughly twice a second until the window rolls over on its own — strictly worse than waiting,
     * since it also keeps consuming whatever sliver of quota a 429'd request itself still costs.
     * Streams are backed off independently: one stream waiting out a 429 never blocks the others
     * from reconnecting on their own normal schedule.
     *
     * @param  list<array{name: string, endpoint: string, query?: array<string, mixed>, onLine: callable}>  $streams
     */
    public function streamMulti(array $streams, ?callable $onReconnect = null): void
    {
        if ([] === $streams) {
            return;
        }

        $multi = curl_multi_init();

        if (false === $multi) {
            throw new TradeStationException('Unable to initialize curl_multi.');
        }

        $urls = [];
        $buffers = [];
        $callbacks = [];
        $handles = [];
        $handleNames = [];
        $responseHeaders = [];
        $rateLimitFailures = [];
        $pendingReopen = [];
        $stopped = false;

        $openHandle = function (string $name) use (&$urls, &$buffers, &$callbacks, &$handles, &$handleNames, &$responseHeaders, &$stopped, $multi): void {
            $handle = curl_init($urls[$name]);

            if (false === $handle) {
                throw new TradeStationException('Unable to initialize cURL for stream "' . $name . '".');
            }

            $buffers[$name] = '';
            $responseHeaders[$name] = [];

            curl_setopt_array($handle, [
                // No CURLOPT_TIMEOUT — these are meant to stay open
                // indefinitely (that's the whole point of a persistent
                // stream). CONNECTTIMEOUT still guards against the
                // connection itself never establishing (DNS/TCP/TLS hang),
                // which would otherwise block this stream's slot forever.
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $this->accessToken(),
                ],
                CURLOPT_HEADERFUNCTION => function ($_handle, string $headerLine) use ($name, &$responseHeaders): int {
                    $parts = explode(':', $headerLine, 2);
                    if (2 === \count($parts)) {
                        $responseHeaders[$name][strtolower(trim($parts[0]))] = trim($parts[1]);
                    }

                    return \strlen($headerLine);
                },
                CURLOPT_WRITEFUNCTION => function ($_handle, string $chunk) use ($name, &$buffers, &$callbacks, &$stopped): int {
                    if ($stopped) {
                        return 0;
                    }

                    $buffers[$name] .= $chunk;

                    while (preg_match('/\\r?\\n/', $buffers[$name], $match, PREG_OFFSET_CAPTURE)) {
                        $position = $match[0][1];
                        $line = trim(substr($buffers[$name], 0, $position));
                        $buffers[$name] = substr($buffers[$name], $position + \strlen($match[0][0]));

                        if ('' === $line) {
                            continue;
                        }

                        if ($callbacks[$name]($line) === false) {
                            $stopped = true;

                            return 0;
                        }
                    }

                    return \strlen($chunk);
                },
            ]);

            curl_multi_add_handle($multi, $handle);
            $handles[$name] = $handle;
            $handleNames[spl_object_id($handle)] = $name;
        };

        foreach ($streams as $stream) {
            $name = (string) $stream['name'];
            $endpoint = (string) $stream['endpoint'];
            $query = $this->compact($stream['query'] ?? []);
            $url = $this->joinUrl($this->configuration['baseUrl'], $endpoint);

            if ([] !== $query) {
                $url .= '?' . http_build_query($query);
            }

            $urls[$name] = $url;
            $callbacks[$name] = $stream['onLine'];
        }

        foreach (array_keys($urls) as $name) {
            $openHandle($name);
        }

        while (! $stopped) {
            curl_multi_exec($multi, $running);

            while (false !== ($info = curl_multi_info_read($multi))) {
                $handle = $info['handle'];
                $name = $handleNames[spl_object_id($handle)] ?? null;
                $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);

                if (null === $name) {
                    continue;
                }

                unset($handleNames[spl_object_id($handle)], $handles[$name]);

                $remaining = trim($buffers[$name] ?? '');

                if ('' !== $remaining && $callbacks[$name]($remaining) === false) {
                    $stopped = true;
                }

                if (! $stopped) {
                    if (429 === $httpCode) {
                        $resetHeader = $responseHeaders[$name]['x-ratelimit-reset'] ?? null;
                        $failures = $rateLimitFailures[$name] ?? 0;
                        $delaySeconds = self::rateLimitBackoffSeconds($resetHeader, $failures);
                        $rateLimitFailures[$name] = $failures + 1;

                        if (null !== $onReconnect) {
                            $onReconnect($name, \sprintf('rate limited (429); backing off %.1fs before reconnecting', $delaySeconds));
                        }

                        $pendingReopen[$name] = microtime(true) + $delaySeconds;
                    } else {
                        unset($rateLimitFailures[$name]);

                        if (null !== $onReconnect) {
                            $onReconnect($name);
                        }

                        $pendingReopen[$name] = microtime(true) + 0.5;
                    }
                }
            }

            if ($stopped) {
                break;
            }

            $now = microtime(true);
            foreach ($pendingReopen as $name => $retryAt) {
                if ($retryAt <= $now) {
                    unset($pendingReopen[$name]);
                    $openHandle($name);
                }
            }

            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            } elseif ([] !== $pendingReopen) {
                usleep(100_000);
            }
        }

        foreach ($handles as $handle) {
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }

        curl_multi_close($multi);
    }

    /**
     * How long to wait before reconnecting a stream that just got HTTP 429'd. Prefers the
     * server's own X-RateLimit-Reset header (a duration in seconds, per TradeStation's docs) when
     * present and numeric; otherwise falls back to an exponential delay (1s, 2s, 4s, ...) based on
     * how many consecutive 429s this same stream has already seen, capped at 60s so a long-lived
     * process can't end up waiting an unreasonable amount of time on a single stream.
     */
    private static function rateLimitBackoffSeconds(?string $resetHeader, int $consecutiveFailures): float
    {
        if (null !== $resetHeader && is_numeric($resetHeader)) {
            return max(1.0, (float) $resetHeader);
        }

        return min(60.0, 2.0 ** $consecutiveFailures);
    }

    /**
     * Runs multiple one-shot GET requests concurrently in a single curl_multi event loop —
     * the request/response counterpart to streamMulti()'s concurrent persistent streams.
     * Each entry must provide a `name` (used to key the returned result), an `endpoint` path,
     * and an optional `query` array. Unlike streamMulti(), this returns once every request has
     * either succeeded, exhausted its retries, or failed outright — there's no persistent
     * reconnect-forever loop, since these are one-shot calls (e.g. GetBars), not streams.
     *
     * A request that comes back HTTP 429 is retried with the same backoff streamMulti() uses:
     * the duration in the response's X-RateLimit-Reset header when present, or an exponential
     * fallback (1s, 2s, 4s, ...) otherwise, doubling on each consecutive 429 for that same
     * request — up to $maxAttempts total attempts before it's given up on and returned as a
     * failed result. One request retrying never blocks the others from completing or retrying
     * on their own schedule.
     *
     * A per-request failure (whether a curl-level error, an exhausted 429, or any other HTTP
     * error status) is captured in that entry's own result rather than thrown — a batch of
     * many symbols shouldn't abort entirely because one of them failed.
     *
     * @param  list<array{name: string, endpoint: string, query?: array<string, mixed>}>  $requests
     * @return array<string, array{body: array|string|null, statusCode: int, error: ?string}> keyed by each request's own `name`
     */
    public function sendRequestMulti(array $requests, int $maxAttempts = 4): array
    {
        if ([] === $requests) {
            return [];
        }

        $multi = curl_multi_init();

        if (false === $multi) {
            throw new TradeStationException('Unable to initialize curl_multi.');
        }

        $pending = [];
        $handles = [];
        $handleNames = [];
        $responseHeaders = [];
        $responseBodies = [];
        $results = [];
        $retryQueue = [];

        foreach ($requests as $request) {
            $name = (string) $request['name'];
            $endpoint = (string) $request['endpoint'];
            $query = $this->compact($request['query'] ?? []);
            $url = $this->joinUrl($this->configuration['baseUrl'], $endpoint);

            if ([] !== $query) {
                $url .= '?' . http_build_query($query);
            }

            $pending[$name] = ['url' => $url, 'attempts' => 0];
        }

        $openHandle = function (string $name) use (&$pending, &$handles, &$handleNames, &$responseHeaders, &$responseBodies, $multi): void {
            $handle = curl_init($pending[$name]['url']);

            if (false === $handle) {
                throw new TradeStationException('Unable to initialize cURL for request "' . $name . '".');
            }

            $responseHeaders[$name] = [];
            $responseBodies[$name] = '';
            $pending[$name]['attempts']++;

            curl_setopt_array($handle, [
                // Without these, a single unresponsive request in the batch
                // (a network black hole, a TradeStation-side hang) blocks
                // this whole method forever — the loop below only exits
                // once every handle has either completed or moved to the
                // retry queue, and curl itself has no timeout by default.
                // CONNECTTIMEOUT covers a connection that never establishes;
                // TIMEOUT covers a connection that establishes but never
                // finishes responding. 60s is generous for even a large
                // chunked GetBars payload.
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $this->accessToken(),
                ],
                CURLOPT_HEADERFUNCTION => function ($_handle, string $headerLine) use ($name, &$responseHeaders): int {
                    $parts = explode(':', $headerLine, 2);

                    if (2 === \count($parts)) {
                        $responseHeaders[$name][strtolower(trim($parts[0]))] = trim($parts[1]);
                    }

                    return \strlen($headerLine);
                },
                CURLOPT_WRITEFUNCTION => function ($_handle, string $chunk) use ($name, &$responseBodies): int {
                    $responseBodies[$name] .= $chunk;

                    return \strlen($chunk);
                },
            ]);

            curl_multi_add_handle($multi, $handle);
            $handles[$name] = $handle;
            $handleNames[spl_object_id($handle)] = $name;
        };

        foreach (array_keys($pending) as $name) {
            $openHandle($name);
        }

        while ([] !== $handles || [] !== $retryQueue) {
            curl_multi_exec($multi, $running);

            while (false !== ($info = curl_multi_info_read($multi))) {
                $handle = $info['handle'];
                $name = $handleNames[spl_object_id($handle)] ?? null;

                curl_multi_remove_handle($multi, $handle);

                if (null === $name) {
                    curl_close($handle);

                    continue;
                }

                $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
                $curlError = curl_error($handle);
                curl_close($handle);
                unset($handleNames[spl_object_id($handle)], $handles[$name]);

                if ('' !== $curlError) {
                    $results[$name] = ['body' => null, 'statusCode' => 0, 'error' => $curlError];

                    continue;
                }

                if (429 === $httpCode && $pending[$name]['attempts'] < $maxAttempts) {
                    $resetHeader = $responseHeaders[$name]['x-ratelimit-reset'] ?? null;
                    $delaySeconds = self::rateLimitBackoffSeconds($resetHeader, $pending[$name]['attempts'] - 1);
                    $retryQueue[$name] = microtime(true) + $delaySeconds;

                    continue;
                }

                $body = self::decodeMultiBody($responseBodies[$name] ?? '');

                $results[$name] = [
                    'body' => $body,
                    'statusCode' => $httpCode,
                    'error' => $httpCode >= 400 ? $this->errorMessage(new Response($httpCode, $body)) : null,
                ];
            }

            $now = microtime(true);

            foreach ($retryQueue as $name => $retryAt) {
                if ($retryAt <= $now) {
                    unset($retryQueue[$name]);
                    $openHandle($name);
                }
            }

            if ([] !== $handles) {
                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }
            } elseif ([] !== $retryQueue) {
                usleep(100_000);
            }
        }

        curl_multi_close($multi);

        return $results;
    }

    private static function decodeMultiBody(string $body): array|string|null
    {
        if ('' === $body) {
            return null;
        }

        $decoded = json_decode($body, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
    }

    /**
     * Runs multiple short-lived GET streams concurrently in a single curl_multi event loop, each
     * one closing independently once $idleIntervalSeconds have elapsed since IT opened — unlike
     * streamMulti(), there is no auto-reconnect and no single global stop signal shared across
     * the batch: every stream in the batch runs to its own completion on its own schedule
     * (whichever comes first: the idle cutoff, the server closing the connection on its own, or
     * that stream's own `onLine` returning false), and the whole call returns once every stream
     * has finished. Built for a batch of bursty snapshot-style streams — e.g. streamOptionChain(),
     * whose useful data normally arrives well within the first couple of seconds and never
     * changes again for a one-shot caller's purposes — not for long-lived streams meant to run
     * for the life of a process (that's what streamMulti() is for).
     *
     * A per-stream HTTP 429 is retried with the same backoff streamMulti() uses (honoring
     * X-RateLimit-Reset, or an exponential fallback), up to $maxAttempts total attempts for that
     * stream before it's given up on and reported as a failed result; other streams in the batch
     * are unaffected by one stream's retry.
     *
     * @param  list<array{name: string, endpoint: string, query?: array<string, mixed>, onLine: callable}>  $streams
     * @return array<string, array{error: ?string}> keyed by each stream's own `name` — $error is null for a
     *                                               stream that ran to completion normally (idle cutoff reached, connection closed on its own, or
     *                                               its own onLine asked to stop), and set only for an actual failure (a curl error, an HTTP error
     *                                               status, or exhausted 429 retries).
     */
    public function streamMultiOnce(array $streams, float $idleIntervalSeconds = 2.0, int $maxAttempts = 3): array
    {
        if ([] === $streams) {
            return [];
        }

        $idleIntervalSeconds = max(0.1, $idleIntervalSeconds);

        $multi = curl_multi_init();

        if (false === $multi) {
            throw new TradeStationException('Unable to initialize curl_multi.');
        }

        $specs = [];
        $handles = [];
        $handleNames = [];
        $buffers = [];
        $errorBodies = [];
        $responseHeaders = [];
        $openedAt = [];
        $results = [];
        $retryQueue = [];

        foreach ($streams as $stream) {
            $name = (string) $stream['name'];
            $endpoint = (string) $stream['endpoint'];
            $query = $this->compact($stream['query'] ?? []);
            $url = $this->joinUrl($this->configuration['baseUrl'], $endpoint);

            if ([] !== $query) {
                $url .= '?' . http_build_query($query);
            }

            $specs[$name] = ['url' => $url, 'onLine' => $stream['onLine'], 'attempts' => 0];
        }

        $openHandle = function (string $name) use (&$specs, &$handles, &$handleNames, &$buffers, &$errorBodies, &$responseHeaders, &$openedAt, $multi): void {
            $handle = curl_init($specs[$name]['url']);

            if (false === $handle) {
                throw new TradeStationException('Unable to initialize cURL for stream "' . $name . '".');
            }

            $buffers[$name] = '';
            $errorBodies[$name] = '';
            $responseHeaders[$name] = [];
            $openedAt[$name] = microtime(true);
            $specs[$name]['attempts']++;

            curl_setopt_array($handle, [
                // No CURLOPT_TIMEOUT — the idle-cutoff loop above already
                // force-closes this handle after $idleIntervalSeconds
                // regardless of its connection state, so a total timeout
                // would be redundant. CONNECTTIMEOUT still fails fast on a
                // connection that never establishes at all, rather than
                // waiting out the full idle interval for something that was
                // never going to respond.
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $this->accessToken(),
                ],
                CURLOPT_HEADERFUNCTION => function ($_handle, string $headerLine) use ($name, &$responseHeaders): int {
                    $parts = explode(':', $headerLine, 2);

                    if (2 === \count($parts)) {
                        $responseHeaders[$name][strtolower(trim($parts[0]))] = trim($parts[1]);
                    }

                    return \strlen($headerLine);
                },
                CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use ($name, &$buffers, &$errorBodies, &$specs): int {
                    if ((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) >= 400) {
                        $errorBodies[$name] .= $chunk;

                        return \strlen($chunk);
                    }

                    $buffers[$name] .= $chunk;

                    while (preg_match('/\r?\n/', $buffers[$name], $match, PREG_OFFSET_CAPTURE)) {
                        $position = $match[0][1];
                        $line = trim(substr($buffers[$name], 0, $position));
                        $buffers[$name] = substr($buffers[$name], $position + \strlen($match[0][0]));

                        if ('' === $line) {
                            continue;
                        }

                        if (false === $specs[$name]['onLine']($line)) {
                            return 0;
                        }
                    }

                    return \strlen($chunk);
                },
            ]);

            curl_multi_add_handle($multi, $handle);
            $handles[$name] = $handle;
            $handleNames[spl_object_id($handle)] = $name;
        };

        foreach (array_keys($specs) as $name) {
            $openHandle($name);
        }

        while ([] !== $handles || [] !== $retryQueue) {
            curl_multi_exec($multi, $running);

            // Idle cutoff — each stream closes independently once it's been
            // open for $idleIntervalSeconds, regardless of what the others
            // are doing.
            $now = microtime(true);

            foreach ($handles as $name => $handle) {
                if (($now - $openedAt[$name]) >= $idleIntervalSeconds) {
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                    unset($handleNames[spl_object_id($handle)], $handles[$name]);
                    $results[$name] = ['error' => null];
                }
            }

            while (false !== ($info = curl_multi_info_read($multi))) {
                $handle = $info['handle'];
                $name = $handleNames[spl_object_id($handle)] ?? null;

                curl_multi_remove_handle($multi, $handle);

                if (null === $name) {
                    curl_close($handle);

                    continue;
                }

                $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
                $curlError = curl_error($handle);
                curl_close($handle);
                unset($handleNames[spl_object_id($handle)], $handles[$name]);

                $remaining = trim($buffers[$name] ?? '');

                if ('' !== $remaining) {
                    ($specs[$name]['onLine'])($remaining);
                }

                if (429 === $httpCode && $specs[$name]['attempts'] < $maxAttempts) {
                    $resetHeader = $responseHeaders[$name]['x-ratelimit-reset'] ?? null;
                    $delaySeconds = self::rateLimitBackoffSeconds($resetHeader, $specs[$name]['attempts'] - 1);
                    $retryQueue[$name] = microtime(true) + $delaySeconds;

                    continue;
                }

                if ('' !== $curlError) {
                    // CURLE_WRITE_ERROR from onLine deliberately returning 0
                    // to stop early is a normal, successful completion, not
                    // a failure — cURL can't tell the two apart itself, so
                    // this treats any write-triggered abort as success. A
                    // genuine transport failure (DNS, TLS, connection reset,
                    // etc.) would also land here indistinguishably; callers
                    // needing to tell them apart should have their onLine
                    // track its own "did I ask to stop" flag.
                    $results[$name] = ['error' => null];
                } elseif ($httpCode >= 400) {
                    $results[$name] = ['error' => self::streamErrorMessage($httpCode, $errorBodies[$name] ?? '')];
                } else {
                    $results[$name] = ['error' => null];
                }
            }

            $retryNow = microtime(true);

            foreach ($retryQueue as $name => $retryAt) {
                if ($retryAt <= $retryNow) {
                    unset($retryQueue[$name]);
                    $openHandle($name);
                }
            }

            if ([] !== $handles) {
                if ($running > 0) {
                    curl_multi_select($multi, 0.25);
                }
            } elseif ([] !== $retryQueue) {
                usleep(100_000);
            }
        }

        curl_multi_close($multi);

        return $results;
    }

    public function getSymbolDetails(array|string $symbols): array|string|null
    {
        return $this->sendRequest('/v3/marketdata/symbols/' . $this->csv($symbols));
    }

    public function getCryptoSymbolNames(): array|string|null
    {
        return $this->sendRequest('/v3/marketdata/symbollists/cryptopairs/symbolnames');
    }

    public function confirmOrder(array $order): array|string|null
    {
        return $this->sendRequest('/v3/orderexecution/orderconfirm', body: $order, method: 'POST');
    }

    public function confirmGroupOrder(array $orderGroup): array|string|null
    {
        return $this->sendRequest('/v3/orderexecution/ordergroupconfirm', body: $orderGroup, method: 'POST');
    }

    public function placeOrder(array $order): array|string|null
    {
        return $this->sendRequest('/v3/orderexecution/orders', body: $order, method: 'POST');
    }

    public function replaceOrder(string $orderId, array $order): array|string|null
    {
        return $this->sendRequest('/v3/orderexecution/orders/' . rawurlencode($orderId), body: $order, method: 'PUT');
    }

    public function cancelOrder(string $orderId): array|string|null
    {
        return $this->sendRequest('/v3/orderexecution/orders/' . rawurlencode($orderId), method: 'DELETE');
    }

    public function placeGroupOrder(array $orderGroup): array|string|null
    {
        return $this->sendRequest('/v3/orderexecution/ordergroups', body: $orderGroup, method: 'POST');
    }

    public function getActivationTriggers(): array|string|null
    {
        return $this->sendRequest('/v3/orderexecution/activationtriggers');
    }

    public function getRoutes(): array|string|null
    {
        return $this->sendRequest('/v3/orderexecution/routes');
    }

    /**
     *  array<string, mixed>
     *  null|callable(string): false|null
     */
    private function streamRequest(string $endpoint, array $queryParams = [], ?callable $onChunk = null, ?callable $onIdle = null, float $idleIntervalSeconds = 1.0): void
    {
        $queryParams = $this->compact($queryParams);
        $url = $this->joinUrl($this->configuration['baseUrl'], $endpoint);

        if ([] !== $queryParams) {
            $url .= '?' . http_build_query($queryParams);
        }

        $multi = curl_multi_init();

        if (false === $multi) {
            throw new TradeStationException('Unable to initialize cURL multi stream.');
        }

        $handle = curl_init($url);

        if (false === $handle) {
            curl_multi_close($multi);

            throw new TradeStationException('Unable to initialize cURL.');
        }

        $buffer = '';
        $errorBody = '';
        $stopped = false;

        curl_setopt_array($handle, [
            // No CURLOPT_TIMEOUT — this may be a genuinely long-lived
            // stream. CONNECTTIMEOUT still fails fast if the connection
            // itself never establishes, rather than relying entirely on an
            // $onIdle callback (which the caller may not have provided) to
            // ever notice.
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->accessToken(),
            ],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$buffer, &$errorBody, &$stopped, $onChunk): int {
                // Once the response status is known to be an error, the body is a
                // single JSON error object (e.g. {"Message":"Missing required
                // scope.",...}), not newline-delimited stream data — forwarding
                // those raw bytes to the per-line $onChunk callback would make an
                // auth/scope/rate-limit failure look like a malformed data line
                // instead of a request failure. Buffer it separately so the
                // status check below can report it properly.
                if ((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) >= 400) {
                    $errorBody .= $chunk;

                    return \strlen($chunk);
                }

                $buffer .= $chunk;

                while (preg_match('/\r?\n/', $buffer, $match, PREG_OFFSET_CAPTURE)) {
                    $position = $match[0][1];
                    $line = trim(substr($buffer, 0, $position));
                    $buffer = substr($buffer, $position + \strlen($match[0][0]));

                    if ('' !== $line && null !== $onChunk && $onChunk($line) === false) {
                        $stopped = true;

                        return 0;
                    }
                }

                return \strlen($chunk);
            },
        ]);

        curl_multi_add_handle($multi, $handle);
        $running = 0;
        $lastIdleAt = microtime(true);
        $idleIntervalSeconds = max(0.1, $idleIntervalSeconds);

        do {
            do {
                $status = curl_multi_exec($multi, $running);
            } while (CURLM_CALL_MULTI_PERFORM === $status);

            if (CURLM_OK !== $status) {
                break;
            }

            $now = microtime(true);

            if (! $stopped && null !== $onIdle && ($now - $lastIdleAt) >= $idleIntervalSeconds) {
                $lastIdleAt = $now;

                if ($onIdle() === false) {
                    $stopped = true;
                    break;
                }
            }

            if ($running > 0 && ! $stopped) {
                curl_multi_select($multi, min(1.0, $idleIntervalSeconds));
            }
        } while ($running > 0 && ! $stopped);

        $statusCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        if ('' !== trim($buffer) && ! $stopped && null !== $onChunk && $onChunk(trim($buffer)) === false) {
            $stopped = true;
        }

        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
        curl_multi_close($multi);

        if ('' !== $error && ! $stopped) {
            throw new TradeStationException($error);
        }

        if ($statusCode >= 400) {
            throw new TradeStationException(self::streamErrorMessage($statusCode, $errorBody), $statusCode);
        }
    }

    private static function streamErrorMessage(int $statusCode, string $body): string
    {
        $decoded = json_decode(trim($body), true);
        $detail = \is_array($decoded)
            ? (string) ($decoded['Message'] ?? $decoded['message'] ?? $decoded['Error'] ?? $decoded['error'] ?? '')
            : trim($body);

        return '' !== $detail
            ? \sprintf('TradeStation stream request failed (HTTP %d): %s', $statusCode, $detail)
            : \sprintf('TradeStation stream request failed (HTTP %d).', $statusCode);
    }

    public function sendRequest(string $endpoint, array $queryParams = [], ?array $body = null, string $method = 'GET'): array|string|null
    {
        $response = $this->httpRequest($endpoint, $queryParams, $body, $method);

        if ($response->statusCode >= 400) {
            throw new TradeStationException(
                message: $this->errorMessage($response),
                code: $response->statusCode,
                response: \is_array($response->body) ? $response->body : null,
            );
        }

        return $response->body;
    }

    public function httpRequest(string $endpoint, array $queryParams = [], ?array $body = null, string $method = 'GET'): Response
    {
        return $this->getTransport()->send(new Request(
            method: strtoupper($method),
            url: $this->joinUrl($this->configuration['baseUrl'], $endpoint),
            headers: $this->headers($method),
            query: $this->compact($queryParams),
            body: $body,
        ));
    }

    /**
     * @param array<string, string> $parameters
     */
    private function requestToken(array $parameters, ?TokenSet $previous = null): TokenSet
    {
        $response = $this->getTransport()->send(new Request(
            method: 'POST',
            url: $this->joinUrl($this->configuration['authBaseUrl'], '/oauth/token'),
            headers: ['content-type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            body: $this->compact($parameters),
        ));

        if ($response->statusCode >= 400) {
            throw new TradeStationException($this->errorMessage($response), $response->statusCode, \is_array($response->body) ? $response->body : null);
        }

        if (! \is_array($response->body) || ! \is_string($response->body['access_token'] ?? null)) {
            throw new TradeStationException('TradeStation token response did not include an access token.');
        }

        $tokenSet = TokenSet::fromTokenResponse($response->body, $previous);
        $this->saveTokenSet($tokenSet);

        return $tokenSet;
    }

    private function currentTokenSet(): ?TokenSet
    {
        $stored = $this->tokenStorage?->get();

        if (null !== $stored) {
            return $stored;
        }

        if ('' === $this->configuration['accessToken']) {
            return null;
        }

        return new TokenSet(
            accessToken: (string) $this->configuration['accessToken'],
            refreshToken: '' === $this->configuration['refreshToken'] ? null : (string) $this->configuration['refreshToken'],
        );
    }

    private function saveTokenSet(TokenSet $tokenSet): void
    {
        $this->configuration['accessToken'] = $tokenSet->accessToken;
        $this->configuration['refreshToken'] = $tokenSet->refreshToken ?? '';
        $this->tokenStorage?->save($tokenSet);
    }

    private function accessToken(): string
    {
        $tokenSet = $this->currentTokenSet();

        if (null !== $tokenSet?->refreshToken && $tokenSet->expiresWithin($this->configuration['tokenRefreshBufferSeconds'])) {
            $tokenSet = $this->refreshAccessToken($tokenSet->refreshToken);
        }

        if (null === $tokenSet || '' === $tokenSet->accessToken) {
            throw new TradeStationException('Missing TradeStation access token. Complete the auth code flow or provide token storage.');
        }

        return $tokenSet->accessToken;
    }

    private function getTransport(): TransportInterface
    {
        return $this->transport ?? new CurlTransport();
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $method): array
    {
        return [
            'Accept' => 'application/json',
            'content-type' => strtoupper($method) !== 'DELETE' ? 'application/json' : '',
            'Authorization' => 'Bearer ' . $this->accessToken(),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function compact(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    private function formatDateTime(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $value;
    }

    private function csv(array|string $value): string
    {
        if (! \is_array($value)) {
            return rawurlencode($value);
        }

        return implode(',', array_map(static fn (string $item): string => rawurlencode($item), $value));
    }

    private function joinUrl(string ...$parts): string
    {
        $first = array_shift($parts) ?? '';
        $url = rtrim($first, '/');

        foreach ($parts as $part) {
            $url .= '/' . trim($part, '/');
        }

        return $url;
    }

    private function errorMessage(Response $response): string
    {
        if (\is_array($response->body)) {
            return (string) ($response->body['error_description'] ?? $response->body['Message'] ?? $response->body['message'] ?? $response->body['Error'] ?? $response->body['error'] ?? 'TradeStation API request failed.');
        }

        if (\is_string($response->body) && '' !== $response->body) {
            return $response->body;
        }

        return 'TradeStation API request failed.';
    }
}
