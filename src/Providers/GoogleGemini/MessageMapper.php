<?php

namespace NeuronAI\Providers\GoogleGemini;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Tools\ToolInterface;

class MessageMapper
{
    /**
     * Mapped messages for Gemini API.
     *
     * @var array
     */
    protected array $mapping = [];

    /**
     * @param array<Message> $messages
     */
    public function __construct(protected array $messages) {}

    /**
     * Maps NeuronAI messages to Gemini API format.
     * Gemini API expects roles 'user' and 'model'. Tools are handled differently.
     * https://ai.google.dev/tutorials/rest_quickstart#multi-turn_conversations_chat
     * https://ai.google.dev/docs/function_calling#function_calling_request
     */
    public function map(): array
    {
        $this->mapping = []; // Reset mapping
        foreach ($this->messages as $message) {
            if ($message instanceof ToolCallResultMessage) {
                 // Gemini requires tool results to be in a specific 'functionResponse' part
               $this->mapFunctionResponse($message->getTools());
           } elseif ($message instanceof ToolCallMessage) {
                 // The request to call a tool comes from the 'model' (assistant)
               $this->addMessage(Message::ROLE_ASSISTANT, $this->mapFunctionCall($message));
           } elseif ($message->getRole() === Message::ROLE_ASSISTANT) {
               $this->addMessage(Message::ROLE_ASSISTANT, [['text' => $message->getContent()]]);
           } elseif ($message->getRole() === Message::ROLE_USER) {
               $this->addMessage(Message::ROLE_USER, [['text' => $message->getContent()]]);
           }
            // System messages are handled separately in the main request payload
       }
       return $this->mapping;
   }

    /**
     * Maps a single NeuronAI message role/content to Gemini parts format.
     * Handles potential merging of consecutive user/model messages if needed by API structure.
     */
    protected function addMessage(string $neuronRole, array $parts): void
    {
         // Map Neuron roles to Gemini roles
       $geminiRole = ($neuronRole === Message::ROLE_ASSISTANT) ? 'model' : 'user';

       $lastMessage = end($this->mapping);

         // Check if the last message has the same role and can be potentially merged (if API supports it)
         // Gemini generally prefers alternating roles, but let's keep it simple for now.
         // If merging is needed, logic to append parts would go here.
         // For now, we add as a new entry.
       $this->mapping[] = [
           'role' => $geminiRole,
           'parts' => $parts,
       ];
   }

     /**
      * Maps the result of tool executions to Gemini's functionResponse format.
      * https://ai.google.dev/docs/function_calling#function_response
      *
      * @param array<ToolInterface> $tools
      */
     protected function mapFunctionResponse(array $tools): void
     {
         // A single 'user' message can contain multiple function responses
       $functionResponses = [];
       foreach ($tools as $tool) {
             // Assuming getResult() returns a JSON serializable structure or string
           $result = $tool->getResult();
           if (!is_string($result)) {
                 $result = json_encode($result); // Ensure content is serializable
             }

             $functionResponses[] = [
               'functionResponse' => [
                   'name' => $tool->getName(),
                     // Ensure the response content is structured correctly
                     'response' => ['content' => $result], // API might expect a specific structure
                 ]
             ];
         }

         if (!empty($functionResponses)) {
             // Add as a single user message containing all function responses
           $this->addMessage(Message::ROLE_USER, $functionResponses);
       }
   }

     /**
      * Maps an outgoing ToolCallMessage (from Assistant/Model) to Gemini's functionCall format.
      * https://ai.google.dev/docs/function_calling#function_calling_response
      *
      * @param ToolCallMessage $message
      * @return array The parts array for the Gemini message
      */
     protected function mapFunctionCall(ToolCallMessage $message): array
     {
       $parts = [];
         // If there's text content alongside the tool call
       if (!empty($message->getContent())) {
           $parts[] = ['text' => $message->getContent()];
       }

       foreach ($message->getTools() as $tool) {
           $parts[] = [
               'functionCall' => [
                   'name' => $tool->getName(),
                     'args' => $tool->getInputs() ?: new \stdClass(), // Ensure empty args is an empty object {}
                 ]
             ];
         }
         return $parts;
     }
 }