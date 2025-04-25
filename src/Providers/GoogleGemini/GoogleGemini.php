<?php

namespace NeuronAI\Providers\GoogleGemini;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleClient;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;
use GuzzleHttp\Client;

class GoogleGemini implements AIProviderInterface
{
    use HandleClient;
    use HandleWithTools;
    use HandleChat; // Akan diperbarui
    use HandleStream; // Akan diperbarui
    use HandleStructured; // Menggunakan HandleChat yang diperbarui

    /**
     * The Guzzle HTTP client.
     */
    protected Client $client;

    /**
     * Base URI for the Gemini API (v1beta needed for tools/system prompt).
     */
    protected string $baseUri = 'https://generativelanguage.googleapis.com/v1beta/'; // Tetap v1beta untuk kompatibilitas fitur

    /**
     * System instructions for the model.
     */
    protected ?string $system = null;

    /**
     * GoogleGemini constructor.
     *
     * @param string $key Your Google AI API Key.
     * @param string $model The specific Gemini model ID (e.g., 'gemini-1.5-flash-latest').
     * @param array $parameters Additional generation configuration parameters.
     */
    public function __construct(
        protected string $key,
        protected string $model = 'gemini-1.5-flash-latest',
        protected array $parameters = [], // e.g., ['temperature' => 0.7, 'maxOutputTokens' => 1000]
    ) {
        $this->client = new Client([
            'base_uri' => trim($this->baseUri, '/').'/',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            // API Key TIDAK lagi di query default, akan ditambahkan ke URL per request
        ]);
    }

    /**
     * Set system-level instructions.
     */
    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->system = $prompt;
        return $this;
    }

    /**
     * Generate the payload for tools (function declarations) for the Gemini API.
     * Struktur ini harus cocok dengan dokumentasi Function Calling Gemini.
     * https://ai.google.dev/docs/function_calling#function_declaration
     */
    public function generateToolsPayload(): array
    {
        // Format ini berdasarkan dokumentasi Gemini, mungkin perlu penyesuaian
        return [
             [ // Gemini expects an array of tool specifications
             'functionDeclarations' => array_map(function (ToolInterface $tool) {
              $properties = [];
              $required = [];

              foreach ($tool->getProperties() as $property) {
                  /** @var ToolProperty $property */
                  $properties[$property->getName()] = [
                              // Tipe Gemini biasanya uppercase (STRING, NUMBER, INTEGER, BOOLEAN, ARRAY, OBJECT)
                      'type' => strtoupper(is_array($property->getType()) ? 'array' : $property->getType()),
                      'description' => $property->getDescription(),
                              // Handle enums if needed by the API schema for function declarations
                              // if (!empty($property->getEnum())) {
                              //     $properties[$property->getName()]['enum'] = $property->getEnum();
                              // }
                  ];
                  if ($property->isRequired()) {
                      $required[] = $property->getName();
                  }
              }

              return [
                  'name' => $tool->getName(),
                  'description' => $tool->getDescription(),
                  'parameters' => [
                              'type' => 'OBJECT', // Root parameters schema is always OBJECT
                              'properties' => !empty($properties) ? $properties : new \stdClass(), // Empty JSON object {} if no properties
                              'required' => $required,
                          ],
                      ];
                  }, $this->tools)
         ]
     ];
 }

    /**
      * Creates a ToolCallMessage from the Gemini API response parts containing function calls.
      * Assumes $parts is an array of part objects from `candidates[0].content.parts`.
      * https://ai.google.dev/docs/function_calling#function_calling_response
      *
      * @param array $parts Array of parts from the Gemini API response content.
      * @return Message Typically a ToolCallMessage.
      */
    protected function createToolMessage(array $parts): Message
    {
       $toolsToCall = [];
         $assistantContent = null; // Store any text content received alongside tool calls

         foreach ($parts as $part) {
           if (isset($part['functionCall'])) {
               $functionCall = $part['functionCall'];
               $tool = $this->findTool($functionCall['name']);
               if ($tool) {
                      // Gemini API uses 'args'
                   $tool->setInputs($functionCall['args'] ?? []);
                     // Gemini doesn't seem to provide a unique call ID like OpenAI's 'tool_call_id'
                     // Need to manage correlation differently if required.
                   $toolsToCall[] = $tool;
               }
           } elseif (isset($part['text'])) {
               $assistantContent .= $part['text'];
           }
       }

       return new ToolCallMessage(
           $assistantContent,
           $toolsToCall
       );
   }
}