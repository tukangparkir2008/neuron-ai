<?php

namespace NeuronAI\Providers\GoogleGemini;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use Psr\Http\Message\StreamInterface;

trait HandleStream
{
    /**
     * Stream response from the Gemini API (:streamGenerateContent endpoint).
     *
     * @param array<Message> $messages
     * @param callable $executeToolsCallback Callback to handle tool execution logic.
     * @return \Generator
     * @throws GuzzleException|ProviderException
     */
    public function stream(array $messages, callable $executeToolsCallback): \Generator
    {
        $mapper = new MessageMapper($messages);
        $geminiMessages = $mapper->map();

        $payload = [
            'contents' => $geminiMessages,
            'generationConfig' => !empty($this->parameters) ? $this->parameters : null,
        ];

        if (isset($this->system) && !empty(trim($this->system))) {
           $payload['systemInstruction'] = ['parts' => [['text' => $this->system]]];
       }
       if (!empty($this->tools)) {
           $payload['tools'] = $this->generateToolsPayload();
       }

       $payload = array_filter($payload, fn($value) => $value !== null);
       if (empty($payload['generationConfig']) && !empty($this->parameters)) {
           $payload['generationConfig'] = new \stdClass();
       }


       $endpoint = "models/{$this->model}:streamGenerateContent";
       $options = [
        'json' => $payload,
            'query' => ['key' => $this->key, 'alt' => 'sse'], // Add key and specify SSE format
            'stream' => true
        ];

        try {
            $response = $this->client->post($endpoint, $options);
            $stream = $response->getBody();

            $fullTextResponse = '';
            $currentFunctionCallParts = []; // Accumulate parts for a potential function call
            $finalUsageData = null;

            while (!$stream->eof()) {
                $line = $this->readLine($stream); // Assumes readLine handles SSE format correctly

                if (str_starts_with($line, 'data:')) {
                    $jsonData = trim(substr($line, 5));
                    if (empty($jsonData)) continue;

                    $chunk = json_decode($jsonData, true);
                    if (json_last_error() !== JSON_ERROR_NONE) continue; // Skip malformed chunks

                    // Capture the final usage metadata
                    if (isset($chunk['usageMetadata'])) {
                        $finalUsageData = $chunk['usageMetadata'];
                    }

                    if (isset($chunk['candidates'][0]['content']['parts'])) {
                        $parts = $chunk['candidates'][0]['content']['parts'];
                        $firstPart = $parts[0] ?? null;

                        // Check if the primary content is a function call
                        if (isset($firstPart['functionCall'])) {
                             // In streaming, Gemini might send the function call details across chunks,
                             // or (more likely) in one go. We accumulate here.
                             // Assuming it comes in one go based on typical patterns.
                           $currentFunctionCallParts = array_merge($currentFunctionCallParts, $parts);
                             // We don't yield function call parts directly, wait until stream ends or finish reason indicates it
                       } else {
                             // Yield text parts as they arrive
                           foreach ($parts as $part) {
                               if (isset($part['text'])) {
                                   $textChunk = $part['text'];
                                   $fullTextResponse .= $textChunk;
                                   yield $textChunk;
                               }
                           }
                       }
                   } elseif (isset($chunk['promptFeedback']['blockReason'])) {
                        // Handle blocking mid-stream if possible
                    throw new ProviderException("Gemini API stream blocked: " . ($chunk['promptFeedback']['blockReason'] ?? 'Unknown reason'));
                }

                    // Check finish reason *within the chunk* (might indicate end of call or text)
                $finishReason = $chunk['candidates'][0]['finishReason'] ?? null;
                if ($finishReason === 'TOOL_CODE' && !empty($currentFunctionCallParts)) {
                        // If finish reason indicates tool call, and we have accumulated parts, execute it.
                    $toolCallMessage = $this->createToolMessage($currentFunctionCallParts);
                    yield from $executeToolsCallback($toolCallMessage);
                        $currentFunctionCallParts = []; // Reset after handling
                        // The stream might continue with the function response processing, handled by the callback recursion.
                    } elseif ($finishReason === 'STOP' && !empty($currentFunctionCallParts)) {
                        // Should not happen often - text finished *after* a function call part was received
                        // but before finishReason was TOOL_CODE. Handle as error or yield text?
                        // For safety, let's ignore leftover function call parts if finish is STOP.
                        $currentFunctionCallParts = [];
                    }

                } // End if(data:)
            } // End while loop

            // After stream ends, yield final usage data if available
            if ($finalUsageData && isset($finalUsageData['promptTokenCount'], $finalUsageData['candidatesTokenCount'])) {
                yield json_encode(['usage' => [
                    'input_tokens' => $finalUsageData['promptTokenCount'],
                    'output_tokens' => $finalUsageData['candidatesTokenCount'],
                ]]);
            } elseif ($finalUsageData && isset($finalUsageData['totalTokenCount'])) {
                 // Fallback usage
               yield json_encode(['usage' => [
                   'input_tokens' => 0,
                   'output_tokens' => $finalUsageData['totalTokenCount'],
               ]]);
           }


       } catch (ClientException $e) {
        $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
        throw new ProviderException("Gemini API stream request failed with status {$e->getCode()}: {$e->getMessage()} - Response: {$responseBody}", $e->getCode(), $e);
    } catch (GuzzleException $e) {
        throw new ProviderException("Gemini API stream request failed: {$e->getMessage()}", $e->getCode(), $e);
    } catch (\Exception $e) {
       throw new ProviderException("Error processing Gemini API stream: {$e->getMessage()}", $e->getCode(), $e);
   }
}

    // readLine helper remains the same as previous example
protected function readLine(StreamInterface $stream): string
{
    $buffer = '';
    while (!$stream->eof()) {
        $byte = $stream->read(1);
        if ($byte === '') {
            return $buffer;
        }
        $buffer .= $byte;
        if ($byte === "\n") {
            break;
        }
    }
    return $buffer;
}
}