<?php

/*
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace FearlessBull\TradeStation\Sdk\Http;

use FearlessBull\TradeStation\Sdk\Exception\TradeStationException;

class CurlTransport implements TransportInterface
{
    public function send(Request $request): Response
    {
        $url = $this->buildUrl($request->url, $request->query);
        $handle = curl_init($url);

        if (false === $handle) {
            throw new TradeStationException('Unable to initialize cURL.');
        }

        $headers = [];
        foreach ($request->headers as $name => $value) {
            if ('' !== $value) {
                $headers[] = "{$name}: {$value}";
            }
        }

        curl_setopt_array($handle, [
            // Without these, a single unresponsive request (a network black
            // hole, a TradeStation-side hang) blocks curl_exec() below
            // forever — there's no other timeout mechanism in this
            // single-request path at all. CONNECTTIMEOUT covers a
            // connection that never establishes; TIMEOUT covers one that
            // establishes but never finishes responding. 60s is generous
            // for even a large chunked GetBars payload.
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
        ]);

        if (null !== $request->body) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $this->encodeBody($request));
        }

        $rawResponse = curl_exec($handle);

        if (false === $rawResponse) {
            $message = curl_error($handle);
            curl_close($handle);

            throw new TradeStationException($message);
        }

        $statusCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        $rawHeaders = substr($rawResponse, 0, $headerSize);
        $rawBody = substr($rawResponse, $headerSize);

        return new Response(
            statusCode: $statusCode,
            body: $this->decodeBody($rawBody),
            headers: $this->parseHeaders($rawHeaders),
        );
    }

    private function encodeBody(Request $request): string
    {
        if (\is_string($request->body)) {
            return $request->body;
        }

        $contentType = strtolower($request->headers['content-type'] ?? '');

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            return http_build_query($request->body);
        }

        return json_encode($request->body, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function buildUrl(string $url, array $query): string
    {
        $query = array_filter($query, static fn (mixed $value): bool => null !== $value);

        if ([] === $query) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($query);
    }

    private function decodeBody(string $body): array|string|null
    {
        if ('' === $body) {
            return null;
        }

        $decoded = json_decode($body, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
    }

    /**
     * @return array<string, list<string>>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];

        foreach (explode("\r\n", trim($rawHeaders)) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower($name)][] = trim($value);
        }

        return $headers;
    }
}
