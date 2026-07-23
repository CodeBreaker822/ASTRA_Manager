<?php

namespace App\Http\Controllers\AIBot;

use App\Http\Controllers\Controller;
use App\Services\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class AIController extends Controller
{
    public function __construct(private readonly ChatbotService $chatbot) {}

    /**
     * Store chat messages in session temporarily
     * In production, this should be stored in database
     */
    /**
     * @return array<int, array<string, mixed>>
     */
    private function getChatHistory(): array
    {
        $history = session('chat_history', []);

        return is_array($history) ? $history : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $history
     */
    private function saveChatHistory(array $history): void
    {
        // Keep only last 20 messages to manage memory
        session(['chat_history' => array_slice($history, -20)]);
    }

    /**
     * Process user message and return AI response
     */
    public function sendMessage(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'message' => 'required|string|max:2000',
            ]);

            $userMessage = (string) $request->input('message');
            $userId = (int) $request->user()->id;

            // Get current chat history
            $history = $this->getChatHistory();

            // Add user message to history
            $history[] = [
                'role' => 'user',
                'content' => $userMessage,
                'timestamp' => now()->toISOString(),
            ];

            // Get AI response
            $aiResponse = $this->getAIResponse($userMessage, $history);

            // Check for tool calls in the response
            $toolCalls = $this->extractToolCalls($aiResponse);

            if (! empty($toolCalls)) {
                // Add tool calls to history (hidden from user)
                $history[] = [
                    'role' => 'assistant',
                    'content' => $aiResponse,
                    'timestamp' => now()->toISOString(),
                    'hidden' => true,
                    'tool_calls' => $toolCalls,
                ];

                // Process tool calls
                $toolResults = $this->processToolCalls($toolCalls, $userId);

                // Add delay for fluid UX (2 seconds)
                sleep(2);

                // Add tool results to history
                $history[] = [
                    'role' => 'system',
                    'content' => json_encode($toolResults),
                    'timestamp' => now()->toISOString(),
                ];

                // Get AI response based on tool results
                $aiResponse = $this->getAIResponse($userMessage, $history);

                // Clean the AI response to remove any tool call syntax
                $cleanedResponse = $this->cleanAIResponse($aiResponse);

                // Add final AI response to history
                $history[] = [
                    'role' => 'assistant',
                    'content' => $cleanedResponse,
                    'timestamp' => now()->toISOString(),
                ];

                // Save updated history
                $this->saveChatHistory($history);

                // Return bundled response with proper JSON structure
                $responseData = [
                    'success' => true,
                    'messages' => [
                        [
                            'type' => 'system',
                            'message' => 'Calling tool...',
                            'timestamp' => now()->format('h:i A'),
                        ],
                        [
                            'type' => 'assistant',
                            'message' => $cleanedResponse,
                            'timestamp' => now()->format('h:i A'),
                        ],
                    ],
                ];

                return response()->json($responseData);
            }

            // No tool calls, normal response

            // Clean the AI response to remove any tool call syntax
            $cleanedResponse = $this->cleanAIResponse($aiResponse);

            $history[] = [
                'role' => 'assistant',
                'content' => $cleanedResponse,
                'timestamp' => now()->toISOString(),
            ];

            // Save updated history
            $this->saveChatHistory($history);

            $responseData = [
                'success' => true,
                'message' => $cleanedResponse,
                'timestamp' => now()->format('h:i A'),
            ];

            return response()->json($responseData);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid message format',
                'details' => $e->errors(),
            ], 422);

        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to process your message. Please try again.',
            ], 500);
        }
    }

    /**
     * Clear chat history
     */
    public function clearChat(Request $request): JsonResponse
    {
        try {
            session(['chat_history' => []]);

            // Clear AI session context as well
            $this->chatbot->clearSessionContext((int) $request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'Chat history cleared',
            ]);

        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to clear chat history',
            ], 500);
        }
    }

    /**
     * Get chat history
     */
    public function getHistory(Request $request): JsonResponse
    {
        try {
            $history = $this->getChatHistory();

            return response()->json([
                'success' => true,
                'history' => $history,
            ]);

        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve chat history',
            ], 500);
        }
    }

    /**
     * Get AI session info for debugging
     */
    public function getSessionInfo(Request $request): JsonResponse
    {
        try {
            $sessionInfo = $this->chatbot->getSessionInfo((int) $request->user()->id);

            return response()->json([
                'success' => true,
                'session' => $sessionInfo,
            ]);

        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve session info',
            ], 500);
        }
    }

    /**
     * Clean AI response by removing tool call syntax
     */
    private function cleanAIResponse(string $response): string
    {
        // Remove tool call syntax like *CallToolList()* or *ToolName(data)*
        $cleanedResponse = preg_replace('/\*[^*]+\*/', '', $response);

        // Clean up any extra whitespace
        $cleanedResponse = preg_replace('/\s+/', ' ', $cleanedResponse);
        $cleanedResponse = trim($cleanedResponse);

        // If response is empty after cleaning, provide honest fallback
        if (empty($cleanedResponse)) {
            return "I can only provide general information right now. Some actions are not available for this account.";
        }

        return $cleanedResponse;
    }

    /**
     * Extract tool calls from AI response
     */
    /**
     * @return array<int, string>
     */
    private function extractToolCalls(string $response): array
    {
        $toolCalls = [];

        if (empty($response)) {
            return $toolCalls;
        }

        // Match patterns like *CallToolList()* or *ToolName({"key":"value"})*
        // Also handle variations with different whitespace
        if (preg_match_all('/\*([^*]+)\*/', $response, $matches)) {
            foreach ($matches[1] as $match) {
                $cleanMatch = trim($match);
                if (! empty($cleanMatch)) {
                    $toolCalls[] = $cleanMatch;
                }
            }
        }

        return $toolCalls;
    }

    /**
     * Process tool calls and return results
     */
    /**
     * @param  array<int, string>  $toolCalls
     * @return array<string, mixed>
     */
    private function processToolCalls(array $toolCalls, int $userId): array
    {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            try {
                // Parse tool call: either function name or function with JSON data
                if (str_contains($toolCall, '(')) {
                    // Extract function name and parameters
                    if (! preg_match('/^([^(]+)\((.*)\)$/', $toolCall, $parts)) {
                        continue;
                    }

                    $functionName = trim($parts[1]);
                    $params = trim($parts[2], '"');

                    // Execute tool based on function name
                    $result = $this->executeTool($functionName, $params, $userId);
                    $results[$functionName] = $result;
                }
            } catch (Throwable) {
                $results[$toolCall] = [
                    'success' => false,
                    'error' => 'Tool execution failed',
                ];
            }
        }

        return $results;
    }

    /**
     * Execute individual tool
     */
    private function executeTool(string $functionName, string $params, int $userId): mixed
    {
        // TODO: Implement actual tool execution
        // For now, return mock responses

        switch ($functionName) {
            case 'CallToolList':
                // Return null since tools aren't implemented yet
                return null;

            default:
                return [
                    'success' => false,
                    'error' => 'Unknown tool',
                ];
        }
    }

    /**
     * Process user request through AI
     */
    /**
     * @param  array<int, array<string, mixed>>  $history
     */
    private function getAIResponse(string $userMessage, array $history): string
    {
        try {
            $userId = (int) request()->user()->id;

            return $this->chatbot->chat($userMessage, $userId, $history);

        } catch (Throwable $e) {
            // Check if it's a rate limit error
            if (str_contains($e->getMessage(), 'busy')) {
                return 'Chat is busy please try again in a minute';
            }

            return 'I encountered an error while processing your request. Please try again later.';
        }
    }
}
