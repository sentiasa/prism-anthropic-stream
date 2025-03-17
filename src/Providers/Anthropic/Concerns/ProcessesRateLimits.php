<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Prism\Prism\ValueObjects\ProviderRateLimit;

trait ProcessesRateLimits
{
    /**
     * Process rate limit headers from Anthropic API responses.
     *
     * @param Response $response
     * @return array{0: ProviderRateLimit[], 1: ?int}
     */
    protected function processRateLimits(Response $response): array
    {
        $limitHeaders = array_filter($response->getHeaders(), fn ($headerName) => Str::startsWith($headerName, 'x-ratelimit-'), ARRAY_FILTER_USE_KEY);

        $rateLimits = [];
        $retryAfter = null;

        // Process retry-after header separately
        $retryAfterHeader = $response->header('retry-after');
        if ($retryAfterHeader !== null) {
            $retryAfter = (int) $retryAfterHeader;
        }

        foreach ($limitHeaders as $headerName => $headerValues) {
            $parts = explode('-', $headerName);

            if (count($parts) >= 3) {
                $fieldName = $parts[1]; // limit, remaining, or reset
                $limitName = $parts[2]; // requests, tokens, etc.

                $rateLimits[$limitName][$fieldName] = $headerValues[0];
            }
        }

        $providerRateLimits = array_values(Arr::map($rateLimits, function ($fields, $limitName): ProviderRateLimit {
            return new ProviderRateLimit(
                name: $limitName,
                limit: data_get($fields, 'limit') !== null
                    ? (int) data_get($fields, 'limit')
                    : null,
                remaining: data_get($fields, 'remaining') !== null
                    ? (int) data_get($fields, 'remaining')
                    : null,
                resetsAt: data_get($fields, 'reset') !== null
                    ? Carbon::parse(data_get($fields, 'reset')) // Parse RFC 3339 directly
                    : null
            );
        }));

        return [$providerRateLimits, $retryAfter];
    }
}