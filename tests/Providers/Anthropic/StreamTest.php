<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'fake-key'));
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-basic-text');

    $response = Prism::text()
        ->using('anthropic', 'claude-3-7-sonnet-20250219')
        ->withPrompt('Who are you?')
        ->asStream();

    $text = '';
    $chunks = [];

    foreach ($response as $chunk) {
        $chunks[] = $chunk;
        $text .= $chunk->text;
    }

    expect($chunks)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $body['stream'] === true;
    });
});

it('can generate text using tools with streaming', function (): void {
    FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-tools');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),

        Tool::as('search')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => "Search results for: {$query}"),
    ];

    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStream();

    $text = '';
    $chunks = [];
    $toolCallFound = false;
    $toolResults = [];

    foreach ($response as $chunk) {
        $chunks[] = $chunk;

        if ($chunk->toolCalls !== []) {
            $toolCallFound = true;
            expect($chunk->toolCalls[0]->name)->not->toBeEmpty();
            expect($chunk->toolCalls[0]->arguments())->toBeArray();
        }

        if ($chunk->toolResults !== []) {
            $toolResults = array_merge($toolResults, $chunk->toolResults);
        }

        $text .= $chunk->text;
    }

    expect($chunks)->not->toBeEmpty();
    expect($toolCallFound)->toBeTrue('Expected to find at least one tool call in the stream');

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && isset($body['tools'])
            && $body['stream'] === true;
    });
});

it('can process a complete conversation with multiple tool calls', function (): void {
    FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-multi-tool-conversation');

    $tools = [
        Tool::as('weather')
            ->for('Get weather information')
            ->withStringParameter('city', 'City name')
            ->using(fn (string $city): string => "The weather in {$city} is 75° and sunny."),

        Tool::as('search')
            ->for('Search for information')
            ->withStringParameter('query', 'The search query')
            ->using(fn (string $query): string => 'Tigers game is at 3pm in Detroit today.'),
    ];

    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
        ->withTools($tools)
        ->withMaxSteps(5) // Allow multiple tool call rounds
        ->withPrompt('What time is the Tigers game today and should I wear a coat in Detroit?')
        ->asStream();

    $fullResponse = '';
    $toolCallCount = 0;

    foreach ($response as $chunk) {
        if ($chunk->toolCalls !== []) {
            $toolCallCount++;
        }
        $fullResponse .= $chunk->text;
    }

    expect($toolCallCount)->toBeGreaterThanOrEqual(1);
    expect($fullResponse)->not->toBeEmpty();

    // Verify we made multiple requests for a conversation with tool calls
    Http::assertSentCount(3);
});

it('can process streams with text that includes citations', function (): void {
    FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-citations');

    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
        ->withPrompt('Tell me about the benefits of exercise with citations')
        ->asStream();

    $text = '';
    $chunks = [];

    foreach ($response as $chunk) {
        $chunks[] = $chunk;
        $text .= $chunk->text;
    }

    expect($chunks)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();
    // Check that text contains citation-like patterns
    expect($text)->toContain('et al', '2006');

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $body['stream'] === true;
    });
});

it('can process streams with thinking enabled', function (): void {
    FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-extended-thinking');

    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
        ->withPrompt('What is the meaning of life?')
        ->withProviderMeta(Provider::Anthropic, ['thinking' => ['enabled' => true]])
        ->asStream();

    $chunks = [];
    $foundThinking = false;

    foreach ($response as $chunk) {
        $chunks[] = $chunk;

        // Check if thinking content exists in any chunk
        if ($chunk->content !== null) {
            $contentData = json_decode($chunk->content, true);
            if (isset($contentData['thinking'])) {
                $foundThinking = true;
            }
        }
    }

    expect($chunks)->not->toBeEmpty();
    expect($foundThinking)->toBeTrue('No thinking content found in any chunk');

    $lastChunk = end($chunks);
    expect($lastChunk->content)->not->toBeNull('Last chunk should have content');

    $lastChunkData = json_decode($lastChunk->content, true);

    expect($lastChunkData)->toHaveKey('thinking');
    expect($lastChunkData['thinking'])->toContain('The question is asking about');

    expect($lastChunkData)->toHaveKey('thinking_signature');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && isset($body['thinking'])
            && $body['thinking']['type'] === 'enabled'
            && isset($body['thinking']['budget_tokens'])
            && $body['thinking']['budget_tokens'] === config('prism.anthropic.default_thinking_budget', 1024);
    });
});

it('can process streams with thinking enabled with custom budget', function (): void {
    FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-with-extended-thinking');

    $customBudget = 2048;
    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
        ->withPrompt('What is the meaning of life?')
        ->withProviderMeta(Provider::Anthropic, [
            'thinking' => [
                'enabled' => true,
                'budgetTokens' => $customBudget,
            ],
        ])
        ->asStream();

    foreach ($response as $chunk) {
        // Process stream
    }

    // Verify custom budget was sent
    Http::assertSent(function (Request $request) use ($customBudget): bool {
        $body = json_decode($request->body(), true);

        return isset($body['thinking'])
            && $body['thinking']['type'] === 'enabled'
            && $body['thinking']['budget_tokens'] === $customBudget;
    });
});

describe('exception handling', function (): void {
    it('throws a PrismRateLimitedException with a 429 response code', function (): void {
        Http::fake([
            '*' => Http::response(
                status: 429,
            ),
        ])->preventStrayRequests();

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-20250219')
            ->withPrompt('Who are you?')
            ->asStream();

        foreach ($response as $chunk) {
            // Don't remove me rector!
        }
    })->throws(PrismRateLimitedException::class);

    it('sets the correct data on the RateLimitException', function (): void {
        $requests_reset = Carbon::now()->addSeconds(30);

        Http::fake([
            '*' => Http::response(
                status: 429,
                headers: [
                    'anthropic-ratelimit-requests-limit' => 1000,
                    'anthropic-ratelimit-requests-remaining' => 500,
                    'anthropic-ratelimit-requests-reset' => $requests_reset->toISOString(),
                    'anthropic-ratelimit-input-tokens-limit' => 80000,
                    'anthropic-ratelimit-input-tokens-remaining' => 0,
                    'anthropic-ratelimit-input-tokens-reset' => Carbon::now()->addSeconds(60)->toISOString(),
                    'anthropic-ratelimit-output-tokens-limit' => 16000,
                    'anthropic-ratelimit-output-tokens-remaining' => 15000,
                    'anthropic-ratelimit-output-tokens-reset' => Carbon::now()->addSeconds(5)->toISOString(),
                    'anthropic-ratelimit-tokens-limit' => 96000,
                    'anthropic-ratelimit-tokens-remaining' => 15000,
                    'anthropic-ratelimit-tokens-reset' => Carbon::now()->addSeconds(5)->toISOString(),
                    'retry-after' => 40,
                ]
            ),
        ])->preventStrayRequests();

        try {
            $response = Prism::text()
                ->using('anthropic', 'claude-3-5-sonnet-20240620')
                ->withPrompt('Hello world!')
                ->asStream();

            foreach ($response as $chunk) {
                // Don't remove me rector!
            }
        } catch (PrismRateLimitedException $e) {
            expect($e->retryAfter)->toEqual(40);
            expect($e->rateLimits)->toHaveCount(4);
            expect($e->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
            expect($e->rateLimits[0]->name)->toEqual('requests');
            expect($e->rateLimits[0]->limit)->toEqual(1000);
            expect($e->rateLimits[0]->remaining)->toEqual(500);
            expect($e->rateLimits[0]->resetsAt)->toEqual($requests_reset);

            expect($e->rateLimits[1]->name)->toEqual('input-tokens');
            expect($e->rateLimits[1]->limit)->toEqual(80000);
            expect($e->rateLimits[1]->remaining)->toEqual(0);
        }
    });

    it('throws an overloaded exception if the Anthropic responds with a 529', function (): void {
        Http::fake([
            '*' => Http::response(
                status: 529,
            ),
        ])->preventStrayRequests();

        $response = Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withPrompt('Hello world!')
            ->asStream();

        foreach ($response as $chunk) {
            // Don't remove me rector!
        }

    })->throws(PrismProviderOverloadedException::class);

    it('throws a request too large exception if the Anthropic responds with a 413', function (): void {
        Http::fake([
            '*' => Http::response(
                status: 413,
            ),
        ])->preventStrayRequests();

        $response = Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withPrompt('Hello world!')
            ->asStream();

        foreach ($response as $chunk) {
            // Don't remove me rector!
        }

    })->throws(PrismRequestTooLargeException::class);
});
