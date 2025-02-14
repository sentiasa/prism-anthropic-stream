<?php

declare(strict_types=1);

namespace EchoLabs\Prism\Structured;

use Closure;
use EchoLabs\Prism\Concerns\ChecksSelf;
use EchoLabs\Prism\Concerns\HasProviderMeta;
use EchoLabs\Prism\Contracts\Message;
use EchoLabs\Prism\Contracts\PrismRequest;
use EchoLabs\Prism\Contracts\Schema;
use EchoLabs\Prism\Enums\StructuredMode;
use EchoLabs\Prism\ValueObjects\Messages\SystemMessage;
use EchoLabs\Prism\ValueObjects\Messages\UserMessage;

class Request implements PrismRequest
{
    use ChecksSelf, HasProviderMeta;

    /**
     * @param  array<int, Message>  $messages
     * @param  array<string, mixed>  $clientOptions
     * @param  array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool}  $clientRetry
     * @param  array<string, mixed>  $providerMeta
     */
    public function __construct(
        readonly public ?string $systemPrompt,
        readonly public string $model,
        readonly public ?string $prompt,
        readonly public array $messages,
        readonly public ?int $maxTokens,
        readonly public int|float|null $temperature,
        readonly public int|float|null $topP,
        readonly public array $clientOptions,
        readonly public array $clientRetry,
        readonly public Schema $schema,
        readonly public StructuredMode $mode,
        array $providerMeta = [],
    ) {
        $this->providerMeta = $providerMeta;
    }

    public function addMessage(UserMessage|SystemMessage $message): self
    {
        $messages = array_merge($this->messages, [$message]);

        return new self(
            systemPrompt: $this->systemPrompt,
            model: $this->model,
            prompt: $this->prompt,
            messages: $messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            topP: $this->topP,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            schema: $this->schema,
            providerMeta: $this->providerMeta,
            mode: $this->mode,
        );
    }
}
