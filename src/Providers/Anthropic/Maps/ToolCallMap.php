<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Maps;

use Prism\Prism\ValueObjects\ToolCall;
use Throwable;

class ToolCallMap
{
    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    public static function map(array $toolCalls): array
    {
        return array_map(function ($toolCall) {
            $id = data_get($toolCall, 'id');
            $name = data_get($toolCall, 'name');
            $arguments = data_get($toolCall, 'arguments', '');

            // Handle string arguments from streaming
            if (is_string($arguments) && !empty($arguments)) {
                // Try to parse as JSON
                try {
                    $args = json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);
                    return new ToolCall($id, $name, $args);
                } catch (Throwable $e) {
                    // If parsing fails, try to repair the JSON
                    $arguments = static::repairJsonIfNeeded($arguments);

                    try {
                        $args = json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);
                        return new ToolCall($id, $name, $args);
                    } catch (Throwable $e) {
                        // If still can't parse, return empty arguments
                        return new ToolCall($id, $name, []);
                    }
                }
            }

            // If arguments is already an array, use it directly
            return new ToolCall($id, $name, is_array($arguments) ? $arguments : []);
        }, $toolCalls);
    }

    /**
     * Attempt to repair malformed JSON from streaming
     *
     * @param string $json
     * @return string
     */
    private static function repairJsonIfNeeded(string $json): string
    {
        $json = trim($json);

        // Add opening brace if missing
        if (!str_starts_with($json, '{')) {
            $json = '{' . $json;
        }

        // Add closing brace if missing
        if (!str_ends_with($json, '}')) {
            $json .= '}';
        }

        return $json;
    }
}
