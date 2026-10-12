# TradeStation PHP SDK

A PHP client for the [TradeStation v3 API](https://api.tradestation.com/docs/). It covers sign-in, market data, account data, order execution and TradeStation's streaming endpoints, and it has no dependencies beyond PHP's `curl` and `json` extensions.

- **One class to call.** `TradeStation` exposes a method per endpoint and returns the decoded JSON as a PHP array.
- **Tokens handled for you.** The access token is refreshed shortly before it expires and saved to the storage you choose (file, Redis, memory or your own).
- **Streaming.** Quotes, bars, market depth, option chains, orders and positions, one stream at a time or many in parallel.
- **Batches.** Many one-off requests in parallel, with retries when TradeStation rate-limits.
- **Testable.** The HTTP layer is an interface, so tests can replace it with a fake.

## Requirements

- PHP 8.3 or later
- `ext-curl` and `ext-json`
- A TradeStation API key (client id and secret)

## Installation

The package lives in a private repository, so add it to your project's `composer.json` first:

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github.com:fearless-bull/tradestation-php-sdk.git", "no-api": true }
    ],
    "require": {
        "fearless-bull/tradestation-php-sdk": "dev-main"
    },
    "config": {
        "preferred-install": { "fearless-bull/*": "source", "*": "dist" }
    }
}
```

Then run `composer update fearless-bull/tradestation-php-sdk`. Composer clones over SSH, so your machine needs read access to the repository.

## Quick start

```php
use FearlessBull\TradeStation\Sdk\Auth\FileTokenStorage;
use FearlessBull\TradeStation\Sdk\TradeStation;

$tradeStation = new TradeStation(
    config: [
        'clientId' => 'your-client-id',
        'clientSecret' => 'your-client-secret',
        'redirectUri' => 'http://localhost',
    ],
    tokenStorage: new FileTokenStorage(__DIR__.'/tradestation-tokens.json'),
);

// After signing in once (see "Signing in"):
$quotes = $tradeStation->getQuotes(['SPY', 'QQQ']);

foreach ($quotes['Quotes'] as $quote) {
    echo $quote['Symbol'].' '.$quote['Last'].PHP_EOL;
}
```

## Configuration

Pass options to the constructor, or set the matching environment variable. An option passed in code wins over the environment.

| Option | Environment variable | Default |
|---|---|---|
| `baseUrl` | `TRADESTATION_API_BASE_URL` | `https://api.tradestation.com` |
| `authBaseUrl` | `TRADESTATION_AUTH_BASE_URL` | `https://signin.tradestation.com` |
| `clientId` | `TRADESTATION_CLIENT_ID` | none |
| `clientSecret` | `TRADESTATION_CLIENT_SECRET` | none |
| `redirectUri` | `TRADESTATION_REDIRECT_URI` | none |
| `audience` | `TRADESTATION_AUDIENCE` | `https://api.tradestation.com` |
| `scope` | `TRADESTATION_SCOPE` | `openid profile offline_access MarketData ReadAccount Trade` |
| `accessToken` | `TRADESTATION_ACCESS_TOKEN` | none |
| `refreshToken` | `TRADESTATION_REFRESH_TOKEN` | none |
| `tokenRefreshBufferSeconds` | none | `60` |

**Simulator or live.** `baseUrl` decides where requests go. The default is the live API. For paper trading, set it to TradeStation's simulator, `https://sim-api.tradestation.com`; the sign-in address and audience stay the same.

## Signing in

TradeStation uses the OAuth authorization code flow. You do it once; after that the SDK keeps the tokens fresh.

**1. Send the user to TradeStation.**

```php
$url = $tradeStation->authorizationUrl(state: bin2hex(random_bytes(16)));
// Redirect the browser to $url, or open it by hand.
```

**2. Exchange the code.** TradeStation redirects back to your `redirectUri` with a `code` parameter.

```php
$tokens = $tradeStation->exchangeAuthorizationCode($_GET['code']);
```

The tokens are saved to your token storage, and every later call uses them.

**3. Nothing else.** Before each request the SDK checks the access token. If it expires within `tokenRefreshBufferSeconds`, the SDK refreshes it first and saves the new one.

To sign out, call `$tradeStation->revokeRefreshToken()`. It revokes the refresh token at TradeStation and clears the storage.

### Token storage

| Class | Keeps tokens in | Use it for |
|---|---|---|
| `FileTokenStorage($path)` | a JSON file | scripts and single-machine tools |
| `RedisTokenStorage($redis, $key)` | a Redis key (default `tradestation:tokens`) | several processes or apps sharing one sign-in |
| `InMemoryTokenStorage` | memory, for the life of the process | tests and one-off scripts |

`RedisTokenStorage` accepts any client object with `get`, `set` and `del` methods, such as `\Redis` from phpredis. For anything else, implement `TokenStorageInterface` (`get`, `save`, `clear`).

Without a token storage, the SDK uses the `accessToken` and `refreshToken` options and keeps refreshed tokens only in memory.

## Usage

Every request method returns TradeStation's JSON response decoded into an array. Field names are TradeStation's own (`Quotes`, `Bars`, `Accounts`, and so on).

### Market data

```php
// Quotes for one or more symbols
$tradeStation->getQuotes('SPY');
$tradeStation->getQuotes(['SPY', 'QQQ', 'IWM']);

// Historical bars
$bars = $tradeStation->getBars('SPY', [
    'unit' => 'Minute',        // Minute, Daily, Weekly, Monthly
    'interval' => 15,
    'barsBack' => 100,          // or firstDate / lastDate
    'sessionTemplate' => 'Default',
]);

// Symbol lookup
$tradeStation->getSymbolDetails(['SPY', 'AAPL']);
$tradeStation->suggestSymbols('appl');
$tradeStation->searchSymbols('N=SPY&C=StockOption');
```

`firstDate` and `lastDate` accept a `DateTimeInterface` or a string.

### Accounts

```php
$accounts = $tradeStation->getAccounts();

$tradeStation->getBalances('SIM0000001M');
$tradeStation->getBeginningOfDayBalances('SIM0000001M');
$tradeStation->getPositions('SIM0000001M');
$tradeStation->getOrders('SIM0000001M');
$tradeStation->getOrder('SIM0000001M', '123456789');
$tradeStation->getHistoricalOrders('SIM0000001M', ['since' => '2026-10-01']);
```

Account and order arguments take a single id or an array of ids.

### Orders

The order array is sent to TradeStation as written, so it follows TradeStation's own order format.

```php
$order = [
    'AccountID' => 'SIM0000001M',
    'Symbol' => 'SPY',
    'Quantity' => '1',
    'OrderType' => 'Limit',
    'LimitPrice' => '500.00',
    'TradeAction' => 'BUY',
    'TimeInForce' => ['Duration' => 'DAY'],
    'Route' => 'Intelligent',
];

// Ask TradeStation what the order would cost, without sending it
$preview = $tradeStation->confirmOrder($order);

// Send it
$placed = $tradeStation->placeOrder($order);

$tradeStation->replaceOrder($orderId, ['Quantity' => '2', 'LimitPrice' => '499.50']);
$tradeStation->cancelOrder($orderId);
```

Order groups (OCO and bracket orders) use `confirmGroupOrder()` and `placeGroupOrder()`. `getRoutes()` and `getActivationTriggers()` list the valid values for those fields.

> `placeOrder()` sends a real order to whichever environment `baseUrl` points at. Test against the simulator first.

### Streaming

A stream method blocks and calls your callback for each line TradeStation sends. The callback receives the **raw JSON line as a string**; decode it yourself. Return `false` from the callback to close the stream.

```php
$tradeStation->streamQuotes(['SPY', 'QQQ'], function (string $line) {
    $tick = json_decode($line, true);

    if (isset($tick['Last'])) {
        echo $tick['Symbol'].' '.$tick['Last'].PHP_EOL;
    }

    return null;   // keep going; return false to stop
});
```

| Method | Streams |
|---|---|
| `streamQuotes($symbols, $onTick)` | quote updates |
| `streamBars($symbol, $options, $onBar)` | bars as they form |
| `streamMarketDepthQuotes($symbol, $options, $onTick)` | Level 2 order book |
| `streamOptionChain($underlying, $options, $onSpread)` | option chain, optionally with Greeks |
| `streamOrders($accounts, $onOrder)` | order updates |
| `streamPositions($accounts, $options, $onPosition)` | position updates |

Each also takes an optional `$onIdle` callback and an interval in seconds. `$onIdle` runs on that interval even when no data arrives, which is where to put a time limit: return `false` from it to stop.

```php
$started = microtime(true);
$strikes = [];

$tradeStation->streamOptionChain(
    'SPY',
    ['expiration' => '10-16-2026', 'strikeProximity' => 10, 'enableGreeks' => true],
    onSpread: function (string $line) use (&$strikes) {
        $strikes[] = json_decode($line, true);
    },
    onIdle: fn () => microtime(true) - $started < 5 ? null : false,   // stop after 5 seconds
);
```

### Several streams or requests at once

| Method | What it does | Returns when |
|---|---|---|
| `streamMulti($streams, $onReconnect)` | Runs long-lived streams side by side and reconnects any that drops | a callback returns `false` |
| `streamMultiOnce($streams, $idleIntervalSeconds)` | Runs short snapshot streams side by side, each closing after the idle interval | every stream has finished |
| `sendRequestMulti($requests)` | Runs one-off GET requests in parallel | every request has finished |
| `getBarsMulti($requests)` | `getBars()` for many symbols in parallel | every request has finished |

```php
$results = $tradeStation->getBarsMulti([
    ['symbol' => 'SPY', 'options' => ['unit' => 'Daily', 'barsBack' => 250]],
    ['symbol' => 'QQQ', 'options' => ['unit' => 'Daily', 'barsBack' => 250]],
]);

foreach ($results as $symbol => $result) {
    if ($result['error'] !== null) {
        echo "{$symbol} failed: {$result['error']}".PHP_EOL;

        continue;
    }

    echo $symbol.': '.count($result['body']['Bars']).' bars'.PHP_EOL;
}
```

In a batch, one failure does not stop the others: each result carries its own `body`, `statusCode` and `error`. Give an entry a `name` when the same symbol appears twice in one batch.

The stream variants take entries of the form `['name' => ..., 'endpoint' => '/v3/...', 'query' => [...], 'onLine' => callable]`.

**Rate limits.** When TradeStation answers HTTP 429, the batch and multi-stream methods wait for the time given in the `X-RateLimit-Reset` header, or back off exponentially when it is missing, and then retry. Plain single requests do not retry.

### Any other endpoint

Endpoints without a dedicated method can be called directly:

```php
$tradeStation->sendRequest('/v3/marketdata/options/expirations/SPY');
$tradeStation->sendRequest('/v3/orderexecution/orders', body: $order, method: 'POST');
```

`httpRequest()` takes the same arguments and returns the full `Response` (status code, body, headers) without throwing on an error status.

## Errors

A failed request throws `TradeStationException`. Its code is the HTTP status, and `response` holds TradeStation's decoded error body when there is one.

```php
use FearlessBull\TradeStation\Sdk\Exception\TradeStationException;

try {
    $tradeStation->placeOrder($order);
} catch (TradeStationException $exception) {
    echo $exception->getCode().': '.$exception->getMessage();
    var_dump($exception->response);
}
```

Calling the API with no token available throws the same exception, with a message asking you to complete the sign-in.

## Testing your code

Pass your own `TransportInterface` as the second constructor argument to answer requests without touching the network:

```php
use FearlessBull\TradeStation\Sdk\Http\Request;
use FearlessBull\TradeStation\Sdk\Http\Response;
use FearlessBull\TradeStation\Sdk\Http\TransportInterface;

$fake = new class implements TransportInterface {
    public function send(Request $request): Response
    {
        return new Response(200, ['Quotes' => [['Symbol' => 'SPY', 'Last' => '500.00']]]);
    }
};

$tradeStation = new TradeStation(['accessToken' => 'test'], $fake);
```

The fake transport covers plain requests. Streams and the batch methods use cURL directly, so wrap those calls behind your own interface if you need to fake them.

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/php-cs-fixer fix
```

`tradestation-openapi.json` is TradeStation's API description, kept in the repository as the reference for endpoints and field names.

## License

MIT. See [LICENSE.md](LICENSE.md).
