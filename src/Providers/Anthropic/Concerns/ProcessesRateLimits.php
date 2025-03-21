<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Str;

trait ProcessesRateLimits
{
    /**
     * @return array{0: ProviderRateLimit[], 1: ?int}
     */
    protected function processRateLimits(?Response $response = null): array
    {
        if (!$response instanceof Response) {
            return [[], null];
        }

        $rate_limits = [];
        $retryAfter = $response->header('retry-after') !== null ? (int) $response->header('retry-after') : null;

        foreach ($response->getHeaders() as $headerName => $headerValues) {
            if (Str::startsWith($headerName, 'anthropic-ratelimit-') === false) {
                continue;
            }

            $limit_name = Str::of($headerName)->after('anthropic-ratelimit-')->beforeLast('-')->toString();

            $field_name = Str::of($headerName)->afterLast('-')->toString();

            $rate_limits[$limit_name][$field_name] = $headerValues[0];
        }

        $providerRateLimits = array_values(Arr::map($rate_limits, function ($fields, $limit_name): ProviderRateLimit {
            $resets_at = data_get($fields, 'reset');

            return new ProviderRateLimit(
                name: $limit_name,
                limit: data_get($fields, 'limit') !== null
                    ? (int) data_get($fields, 'limit')
                    : null,
                remaining: data_get($fields, 'remaining') !== null
                    ? (int) data_get($fields, 'remaining')
                    : null,
                resetsAt: data_get($fields, 'reset') !== null ? new Carbon($resets_at) : null
            );
        }));

        return [$providerRateLimits, $retryAfter];
    }
}
