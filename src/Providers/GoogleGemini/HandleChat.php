<?php

namespace NeuronAI\Providers\GoogleGemini;

use GuzzleHttp\Exception\ClientException; // More specific exception for 4xx/5xx errors
use GuzzleHttp\Exception\GuzzleException;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;

trait HandleChat
{
    /**
     * Send messages to the Gemini API (:generateContent endpoint).
     *
     * @param array<Message> $messages
     * @return Message
     * @throws GuzzleException|ProviderException
     */
    public function chat(array $messages): Message
    {
        $mapper = new MessageMapper($messages);
        $geminiMessages = $mapper->map(); // Gets the array for "contents"

        $payload = [
            'contents' => $geminiMessages,
             // generationConfig holds parameters like temperature, maxOutputTokens etc.
            'generationConfig' => !empty($this->parameters) ? $this->parameters : null,
             // Safety settings can also be added here if needed
             // 'safetySettings' => [...]
        ];

        // Add system instruction if set
        if (isset($this->system) && !empty(trim($this->system))) {
             // Gemini expects systemInstruction at the root level
           $payload['systemInstruction'] = ['parts' => [['text' => $this->system]]];
       }

        // Add tools if set
       if (!empty($this->tools)) {
             // Gemini expects tools at the root level
           $payload['tools'] = $this->generateToolsPayload();
       }

        // Remove null values from payload to avoid sending empty keys
       $payload = array_filter($payload, fn($value) => $value !== null);
        // Ensure generationConfig is at least an empty object if parameters were empty but specified
       if (empty($payload['generationConfig']) && !empty($this->parameters)) {
           $payload['generationConfig'] = new \stdClass();
       }


        // Construct the endpoint URL with API key
       $endpoint = "models/{$this->model}:generateContent";
       $options = [
        'json' => $payload,
            'query' => ['key' => $this->key] // Add API key as query param
        ];

        try {
            $response = $this->client->post($endpoint, $options);
            $responseBody = $response->getBody()->getContents();
            $result = json_decode($responseBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ProviderException('Gemini API response is not valid JSON: ' . $responseBody);
            }

            // Process the response based on the structure provided by the user
            if (isset($result['candidates'][0]['content']['parts'])) {
                $parts = $result['candidates'][0]['content']['parts'];
                $usageData = $result['usageMetadata'] ?? null;
                $finishReason = $result['candidates'][0]['finishReason'] ?? null;

                $firstPart = $parts[0] ?? null;

                // Check for function call based on finishReason and part content
                if ($finishReason === 'TOOL_CODE' || (isset($firstPart['functionCall']))) {
                   $responseMessage = $this->createToolMessage($parts);
               } else {
                    // Assume text response
                $textResponse = '';
                foreach ($parts as $part) {
                    $textResponse .= $part['text'] ?? '';
                }
                $responseMessage = new AssistantMessage($textResponse);
            }

                // Attach usage data
            if ($usageData && isset($usageData['promptTokenCount'], $usageData['candidatesTokenCount'])) {
                $responseMessage->setUsage(
                    new Usage($usageData['promptTokenCount'], $usageData['candidatesTokenCount'])
                );
            } else if ($usageData && isset($usageData['totalTokenCount'])) {
                     // Fallback if only totalTokenCount is available (input/output breakdown is preferred)
                     // This is less accurate for NeuronAI's internal tracking
               $responseMessage->setUsage(new Usage(0, $usageData['totalTokenCount']));
           }


           return $responseMessage;

       } elseif (isset($result['promptFeedback']['blockReason'])) {
                 // Handle blocked responses
           throw new ProviderException("Gemini API request blocked: " . ($result['promptFeedback']['blockReason'] ?? 'Unknown reason') . " - Safety Ratings: " . json_encode($result['promptFeedback']['safetyRatings'] ?? []));
       } else {
                 // Handle other unexpected response structures
           throw new ProviderException('Invalid response structure from Gemini API: ' . $responseBody);
       }

   } catch (ClientException $e) {
            // Handle 4xx/5xx HTTP errors specifically
    $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
    throw new ProviderException("Gemini API request failed with status {$e->getCode()}: {$e->getMessage()} - Response: {$responseBody}", $e->getCode(), $e);
} catch (GuzzleException $e) {
            // Handle other Guzzle errors (network, etc.)
    throw new ProviderException("Gemini API request failed: {$e->getMessage()}", $e->getCode(), $e);
} catch (\Exception $e) {
             // Catch other potential errors
   throw new ProviderException("Error processing Gemini API response: {$e->getMessage()}", $e->getCode(), $e);
}
}
}