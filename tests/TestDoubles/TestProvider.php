<?php

declare(strict_types=1);

namespace Tests\TestDoubles;

use Generator;
use Prism\Prism\Contracts\Provider;
use Prism\Prism\Embeddings\Request as EmbeddingRequest;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderResponse;
use Prism\Prism\ValueObjects\Usage;

class TestProvider implements Provider
{
    public StructuredRequest|TextRequest|EmbeddingRequest $request;

    /** @var array<string, mixed> */
    public array $clientOptions;

    /** @var array<mixed> */
    public array $clientRetry;

    /** @var array<int, StructuredResponse|TextResponse|EmbeddingResponse> */
    public array $responses = [];

    public $callCount = 0;

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $this->callCount++;

        $this->request = $request;

        return $this->responses[$this->callCount - 1] ?? new TextResponse(
            text: "I'm nyx!",
            steps: collect([]),
            responseMessages: collect([]),
            toolCalls: [],
            toolResults: [],
            usage: new Usage(10, 10),
            finishReason: FinishReason::Stop,
            meta: new Meta('123', 'claude-3-5-sonnet-20240620'),
            messages: collect([]),
        );
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $this->callCount++;

        $this->request = $request;

        return $this->responses[$this->callCount - 1] ?? new StructuredResponse(
            text: json_encode([]),
            structured: [],
            steps: collect([]),
            responseMessages: collect([]),
            usage: new Usage(10, 10),
            finishReason: FinishReason::Stop,
            meta: new Meta('123', 'claude-3-5-sonnet-20240620')
        );
    }

    #[\Override]
    public function embeddings(EmbeddingRequest $request): EmbeddingResponse
    {
        $this->callCount++;

        $this->request = $request;

        return $this->responses[$this->callCount - 1] ?? new EmbeddingResponse(
            embeddings: [],
            usage: new EmbeddingsUsage(10),
        );
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        throw PrismException::unsupportedProviderAction(__METHOD__, class_basename($this));
    }

    public function withResponse(StructuredResponse|TextResponse $response): Provider
    {
        $this->responses[] = $response;

        return $this;
    }

    /**
     * @param  array<int, ProviderResponse>  $responses
     */
    public function withResponseChain(array $responses): Provider
    {

        $this->responses = $responses;

        return $this;
    }
}
