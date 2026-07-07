<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ChatbotService
{
    public function __construct(
        private readonly AppSettingsService $settings,
        private readonly ProviderFallbackLogger $fallbackLogger,
    ) {}

    public function chat(string $userMessage, int $userId, array $conversationHistory = []): string
    {
        $providers = $this->settings->orderedConnectedProviders('text_fixer');
        $messages = $this->conversationMessages($userMessage, $conversationHistory);

        foreach ($providers as $position => $provider) {
            try {
                $response = $this->chatWithProvider($provider, $messages);
                $this->updateSessionContext($userId);
                $this->fallbackLogger->recovered('text_fixer', 'chatbot', $provider, $position, request());

                Log::info('Chatbot provider request completed.', [
                    'provider' => $provider['provider'],
                    'model' => $provider['model'],
                    'fallback_position' => $position,
                ]);

                return $response;
            } catch (Throwable $exception) {
                $this->fallbackLogger->failure('text_fixer', 'chatbot', $provider, $position, $exception, request());
                Log::warning('Chatbot provider request failed; trying fallback.', [
                    'provider' => $provider['provider'],
                    'model' => $provider['model'],
                    'fallback_position' => $position,
                    'exception' => $exception::class,
                    'status' => $this->exceptionStatus($exception),
                ]);
            }
        }

        throw new RuntimeException('No configured chatbot provider could complete the request.');
    }

    public function clearSessionContext(int $userId): void
    {
        Cache::forget($this->sessionKey($userId));
        Cache::forget('gemini_session_'.$userId);
    }

    /**
     * @return array{user_id: int, created_at: string, last_activity: string, conversation_turns: int, has_tools_context: bool, available_tools_count: int}
     */
    public function getSessionInfo(int $userId): array
    {
        $context = $this->sessionContext($userId);

        return [
            'user_id' => $context['user_id'],
            'created_at' => $context['created_at'],
            'last_activity' => $context['last_activity'],
            'conversation_turns' => $context['conversation_turns'],
            'has_tools_context' => false,
            'available_tools_count' => 0,
        ];
    }

    /**
     * @param  array{provider: string, model: string, api_key: string}  $provider
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function chatWithProvider(array $provider, array $messages): string
    {
        return match ($provider['provider']) {
            AppSettingsService::PROVIDER_GEMINI => $this->chatWithGemini($provider, $messages),
            AppSettingsService::PROVIDER_GROQ_TEXT_FIXER => $this->chatWithGroq($provider, $messages),
            AppSettingsService::PROVIDER_DEEPSEEK => $this->chatWithDeepSeek($provider, $messages),
            AppSettingsService::PROVIDER_CEREBRAS => $this->chatWithOpenAICompatibleProvider(
                $provider,
                $messages,
                (string) config('services.cerebras.chat_completions_url'),
                $this->settings->cerebrasTimeout(),
                ['reasoning_effort' => 'low'],
            ),
            AppSettingsService::PROVIDER_MISTRAL => $this->chatWithOpenAICompatibleProvider(
                $provider,
                $messages,
                (string) config('services.mistral.chat_completions_url'),
                $this->settings->mistralTimeout(),
            ),
            AppSettingsService::PROVIDER_OPENROUTER => $this->chatWithOpenAICompatibleProvider(
                $provider,
                $messages,
                (string) config('services.openrouter.chat_completions_url'),
                $this->settings->openRouterTimeout(),
            ),
            AppSettingsService::PROVIDER_CLOUDFLARE => $this->chatWithOpenAICompatibleProvider(
                $provider,
                $messages,
                $this->settings->cloudflareChatCompletionsUrl($provider['metadata']['account_id'] ?? null),
                $this->settings->cloudflareTimeout(),
            ),
            default => throw new RuntimeException('Unsupported chatbot provider.'),
        };
    }

    /**
     * @param  array{model: string, api_key: string}  $provider
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function chatWithGemini(array $provider, array $messages): string
    {
        $response = Http::acceptJson()
            ->asJson()
            ->timeout($this->settings->geminiTimeout())
            ->post(
                rtrim((string) config('services.gemini.base_url'), '/')
                    .'/models/'.rawurlencode($provider['model']).':generateContent?key='.urlencode($provider['api_key']),
                [
                    'system_instruction' => [
                        'parts' => [['text' => $this->systemPrompt()]],
                    ],
                    'contents' => array_map(fn (array $message): array => [
                        'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                        'parts' => [['text' => $message['content']]],
                    ], $messages),
                    'generationConfig' => $this->generationConfig(),
                ],
            );

        $this->ensureSuccessful($response);

        return $this->requiredContent($response->json('candidates.0.content.parts.0.text'));
    }

    /**
     * @param  array{model: string, api_key: string}  $provider
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function chatWithGroq(array $provider, array $messages): string
    {
        $response = Http::withToken($provider['api_key'])
            ->acceptJson()
            ->asJson()
            ->timeout($this->settings->groqTimeout())
            ->post((string) config('services.groq.chat_completions_url'), [
                'model' => $provider['model'],
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ...$messages,
                ],
                'temperature' => 0.7,
                'max_completion_tokens' => 1000,
                'top_p' => 0.8,
            ]);

        $this->ensureSuccessful($response);

        return $this->requiredContent($response->json('choices.0.message.content'));
    }

    /**
     * @param  array{model: string, api_key: string}  $provider
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function chatWithDeepSeek(array $provider, array $messages): string
    {
        $response = Http::withToken($provider['api_key'])
            ->acceptJson()
            ->asJson()
            ->timeout($this->settings->deepSeekTimeout())
            ->post((string) config('services.deepseek.chat_completions_url'), [
                'model' => $provider['model'],
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ...$messages,
                ],
                'temperature' => 0.7,
                'max_tokens' => 1000,
                'top_p' => 0.8,
            ]);

        $this->ensureSuccessful($response);

        return $this->requiredContent($response->json('choices.0.message.content'));
    }

    /**
     * @param  array{model: string, api_key: string}  $provider
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $extraPayload
     */
    private function chatWithOpenAICompatibleProvider(
        array $provider,
        array $messages,
        string $endpoint,
        int $timeout,
        array $extraPayload = [],
    ): string {
        if ($endpoint === '') {
            throw new RuntimeException('The chatbot provider requires additional server configuration.');
        }

        $response = Http::withToken($provider['api_key'])
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->post($endpoint, array_merge([
                'model' => $provider['model'],
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ...$messages,
                ],
                'temperature' => 0.7,
                'max_tokens' => 1000,
                'top_p' => 0.8,
            ], $extraPayload));

        $this->ensureSuccessful($response);

        return $this->requiredContent($response->json('choices.0.message.content'));
    }

    private function ensureSuccessful(Response $response): void
    {
        if ($response->failed()) {
            throw new RuntimeException('Chatbot provider request failed.', $response->status());
        }
    }

    private function requiredContent(mixed $content): string
    {
        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Chatbot provider returned an empty response.');
        }

        return trim($content);
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function conversationMessages(string $userMessage, array $history): array
    {
        $messages = collect($history)
            ->filter(fn (mixed $message): bool => is_array($message)
                && in_array($message['role'] ?? null, ['user', 'assistant'], true)
                && ! ($message['hidden'] ?? false)
                && filled($message['content'] ?? null))
            ->take(-10)
            ->map(fn (array $message): array => [
                'role' => $message['role'],
                'content' => trim((string) $message['content']),
            ])
            ->values()
            ->all();

        $lastMessage = end($messages);

        if (! is_array($lastMessage)
            || $lastMessage['role'] !== 'user'
            || $lastMessage['content'] !== trim($userMessage)) {
            $messages[] = ['role' => 'user', 'content' => trim($userMessage)];
        }

        return $messages;
    }

    /**
     * @return array{temperature: float, maxOutputTokens: int, topP: float, topK: int}
     */
    private function generationConfig(): array
    {
        return [
            'temperature' => 0.7,
            'maxOutputTokens' => 1000,
            'topP' => 0.8,
            'topK' => 40,
        ];
    }

    private function systemPrompt(): string
    {
        $path = base_path('app/Services/TranscriptionServerSystemPrompt.txt');

        if (is_file($path)) {
            $prompt = file_get_contents($path);

            if (is_string($prompt) && trim($prompt) !== '') {
                return trim($prompt);
            }
        }

        return 'You are the AI assistant integrated into this transcription app server. '
            .'Help authenticated users manage transcription server workflows, explain API usage, and use only registered system tools when tool access is available.';
    }

    /**
     * @return array{user_id: int, created_at: string, last_activity: string, conversation_turns: int}
     */
    private function sessionContext(int $userId): array
    {
        return Cache::remember($this->sessionKey($userId), now()->addHours(24), fn (): array => [
            'user_id' => $userId,
            'created_at' => now()->toISOString(),
            'last_activity' => now()->toISOString(),
            'conversation_turns' => 0,
        ]);
    }

    private function updateSessionContext(int $userId): void
    {
        $context = $this->sessionContext($userId);
        $context['last_activity'] = now()->toISOString();
        $context['conversation_turns']++;

        Cache::put($this->sessionKey($userId), $context, now()->addHours(24));
    }

    private function sessionKey(int $userId): string
    {
        return 'chatbot_session_'.$userId;
    }

    private function exceptionStatus(Throwable $exception): ?int
    {
        $code = $exception->getCode();

        return is_int($code) && $code >= 100 && $code <= 599 ? $code : null;
    }
}
