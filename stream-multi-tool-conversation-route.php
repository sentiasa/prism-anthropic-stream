<?php

$apiKey = env('ANTHROPIC_API_KEY');
$baseFilePath = storage_path('app/anthropic/stream-test');

// Make sure the directory exists
if (!file_exists(dirname($baseFilePath))) {
    mkdir(dirname($baseFilePath), 0755, true);
}

// Set up the client
$client = new \GuzzleHttp\Client();

try {
    // STEP 1: Initial request that should trigger tool use
    $initialResponse = $client->post('https://api.anthropic.com/v1/messages', [
        'headers' => [
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ],
        'json' => [
            'model' => 'claude-3-7-sonnet-20250219',
            'max_tokens' => 1000,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'What time is the tigers game today and should I wear a coat?'
                ]
            ],
            'stream' => true,
            'tools' => [
                [
                    'name' => 'weather',
                    'description' => 'useful when you need to search for current weather conditions',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'city' => [
                                'type' => 'string',
                                'description' => 'The city that you want the weather for'
                            ]
                        ],
                        'required' => ['city']
                    ]
                ],
                [
                    'name' => 'search',
                    'description' => 'useful for searching current events or data',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'The detailed search query'
                            ]
                        ],
                        'required' => ['query']
                    ]
                ]
            ],
        ],
        'stream' => true,
    ]);

    // Save the first response (which should contain tool_use blocks)
    $fixture1Path = $baseFilePath . '-with-tools-1.sse';
    $fileHandle = fopen($fixture1Path, 'w');
    $body = $initialResponse->getBody();
    while (!$body->eof()) {
        $chunk = $body->read(1024);
        fwrite($fileHandle, $chunk);
    }
    fclose($fileHandle);

    // For the second request, we need to parse the first response to get the tool_use ID
    // For simplicity in this example, we'll use a hardcoded ID, but in production
    // you should parse the first response and extract the actual ID
    $toolUseId = 'toolu_01XBQHBVGNignRLP45bzMbwf'; // This would normally be parsed from response 1

    // STEP 2: Second request with tool results for the first tool
    $secondResponse = $client->post('https://api.anthropic.com/v1/messages', [
        'headers' => [
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ],
        'json' => [
            'model' => 'claude-3-7-sonnet-20250219',
            'max_tokens' => 1000,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'What time is the tigers game today and should I wear a coat?'
                ],
                // Instead of including tool_use in the assistant message,
                // we'll include it in the content array as shown in the documentation
                [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'text', 'text' => "I'll help you find information about the Tigers game today and whether you should wear a coat."],
                        [
                            'type' => 'tool_use',
                            'id' => $toolUseId,
                            'name' => 'search',
                            'input' => ['query' => 'Detroit Tigers game schedule today']
                        ]
                    ]
                ],
                // Now we can provide the tool result
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'tool_result',
                            'tool_use_id' => $toolUseId,
                            'content' => 'Search results for: Detroit Tigers game schedule today'
                        ]
                    ]
                ]
            ],
            'stream' => true,
            'tools' => [
                [
                    'name' => 'weather',
                    'description' => 'useful when you need to search for current weather conditions',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'city' => [
                                'type' => 'string',
                                'description' => 'The city that you want the weather for'
                            ]
                        ],
                        'required' => ['city']
                    ]
                ],
                [
                    'name' => 'search',
                    'description' => 'useful for searching current events or data',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'The detailed search query'
                            ]
                        ],
                        'required' => ['query']
                    ]
                ]
            ],
        ],
        'stream' => true,
    ]);

    // Save the second response
    $fixture2Path = $baseFilePath . '-with-tools-2.sse';
    $fileHandle = fopen($fixture2Path, 'w');
    $body = $secondResponse->getBody();
    while (!$body->eof()) {
        $chunk = $body->read(1024);
        fwrite($fileHandle, $chunk);
    }
    fclose($fileHandle);

    // For the third step, we would use the first search result and then a weather tool call
    // Again, these IDs would normally be parsed from previous responses
    $weatherToolUseId = 'toolu_02XCRDTVGNignRLP45bzMqqw';

    // STEP 3: Final request with all tool results
    $finalResponse = $client->post('https://api.anthropic.com/v1/messages', [
        'headers' => [
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ],
        'json' => [
            'model' => 'claude-3-7-sonnet-20250219',
            'max_tokens' => 1000,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'What time is the tigers game today and should I wear a coat?'
                ],
                // First assistant message with search tool use
                [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'text', 'text' => "I'll help you find information about the Tigers game today and whether you should wear a coat."],
                        [
                            'type' => 'tool_use',
                            'id' => $toolUseId,
                            'name' => 'search',
                            'input' => ['query' => 'Detroit Tigers game schedule today']
                        ]
                    ]
                ],
                // First user response with search tool result
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'tool_result',
                            'tool_use_id' => $toolUseId,
                            'content' => 'Search results for: Detroit Tigers game schedule today'
                        ]
                    ]
                ],
                // Second assistant message with weather tool use
                [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'text', 'text' => "Now I'll check the weather in Detroit."],
                        [
                            'type' => 'tool_use',
                            'id' => $weatherToolUseId,
                            'name' => 'weather',
                            'input' => ['city' => 'Detroit']
                        ]
                    ]
                ],
                // Second user response with weather tool result
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'tool_result',
                            'tool_use_id' => $weatherToolUseId,
                            'content' => 'The weather will be 75° and sunny in Detroit'
                        ]
                    ]
                ]
            ],
            'stream' => true,
        ],
        'stream' => true,
    ]);

    // Save the final response
    $fixture3Path = $baseFilePath . '-with-tools-3.sse';
    $fileHandle = fopen($fixture3Path, 'w');
    $body = $finalResponse->getBody();
    while (!$body->eof()) {
        $chunk = $body->read(1024);
        fwrite($fileHandle, $chunk);
    }
    fclose($fileHandle);

    return "Fixtures saved to:<br>1. $fixture1Path<br>2. $fixture2Path<br>3. $fixture3Path";
} catch (\Exception $e) {
    if (method_exists($e, 'getResponse') && $e->getResponse()) {
        return "Error: " . $e->getMessage() . "<br>Response: " . $e->getResponse()->getBody()->getContents();
    }
    return "Error: " . $e->getMessage() . "<br>Line: " . $e->getLine();
}