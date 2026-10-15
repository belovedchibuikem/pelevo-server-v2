<?php

namespace App\Integrations\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Calls OpenAI chat completions, or a legacy endpoint that already speaks Pelevo's old body.
 */
final class LanguageModel
{
    public function configured(): bool
    {
        return trim((string) config('services.ai.token')) !== '';
    }

    public function model(): string
    {
        $model = trim((string) config('services.ai.model', 'gpt-4.1-mini'));

        return $model !== '' ? $model : 'gpt-4.1-mini';
    }

    public function complete(string $instructions, string $content, int $timeout = 40): LanguageCompletion
    {
        if (! $this->configured()) {
            throw new AiProviderException('AI summaries need an API key. Set AI_PROVIDER_TOKEN and restart the API.');
        }

        try {
            $response = $this->usesOpenAi()
                ? $this->openAi($instructions, $content, $timeout)
                : $this->legacy($instructions, $content, $timeout);
        } catch (AiProviderException $exception) {
            throw $exception;
        } catch (ConnectionException $exception) {
            throw new AiProviderException('The AI provider did not respond in time. Try again.', true, $exception);
        } catch (RequestException $exception) {
            throw $this->fromHttp($exception);
        }

        $text = trim($response['text']);
        if ($text === '') {
            throw new AiProviderException('The model returned an empty answer. Try again.', true);
        }

        return new LanguageCompletion($text, $response['input_tokens'], $response['output_tokens']);
    }

    private function usesOpenAi(): bool
    {
        $provider = strtolower(trim((string) config('services.ai.provider', 'openai')));
        if (in_array($provider, ['legacy', 'custom'], true)) {
            return false;
        }
        if ($provider === 'openai') {
            return true;
        }
        $url = (string) config('services.ai.url');

        return $url === '' || str_contains($url, 'api.openai.com');
    }

    /**
     * @return array{text: string, input_tokens: int, output_tokens: int}
     */
    private function openAi(string $instructions, string $content, int $timeout): array
    {
        $url = trim((string) config('services.ai.url'));
        if ($url === '') {
            $url = 'https://api.openai.com/v1/chat/completions';
        }
        $json = Http::withToken((string) config('services.ai.token'))
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout($timeout)
            ->post($url, [
                'model' => $this->model(),
                'temperature' => 0.4,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $instructions],
                    ['role' => 'user', 'content' => $content],
                ],
            ])
            ->throw()
            ->json();
        $text = data_get($json, 'choices.0.message.content');
        if (! is_string($text)) {
            throw new AiProviderException('The model response could not be read.', true);
        }

        return [
            'text' => $text,
            'input_tokens' => (int) data_get($json, 'usage.prompt_tokens', 0),
            'output_tokens' => (int) data_get($json, 'usage.completion_tokens', 0),
        ];
    }

    /**
     * @return array{text: string, input_tokens: int, output_tokens: int}
     */
    private function legacy(string $instructions, string $content, int $timeout): array
    {
        $url = trim((string) config('services.ai.url'));
        if ($url === '') {
            throw new AiProviderException('Set AI_PROVIDER_URL for a custom AI provider, or set AI_PROVIDER=openai.');
        }
        $json = Http::withToken((string) config('services.ai.token'))
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout($timeout)
            ->post($url, [
                'model' => $this->model(),
                'prompt' => $instructions,
                'input' => ['content' => $content],
            ])
            ->throw()
            ->json();
        $text = data_get($json, 'output');
        if (! is_string($text)) {
            throw new AiProviderException('The custom AI provider did not return an output string.', true);
        }

        return [
            'text' => $text,
            'input_tokens' => (int) data_get($json, 'usage.input_tokens', 0),
            'output_tokens' => (int) data_get($json, 'usage.output_tokens', 0),
        ];
    }

    private function fromHttp(RequestException $exception): AiProviderException
    {
        $status = $exception->response?->status();
        if ($status === 401 || $status === 403) {
            return new AiProviderException('The AI API key was rejected. Create a new key and update AI_PROVIDER_TOKEN.', false, $exception);
        }
        if ($status === 429) {
            return new AiProviderException('The AI provider is rate limiting requests. Try again in a minute.', true, $exception);
        }
        if ($status === 404) {
            return new AiProviderException('The model "'.$this->model().'" was not found. Set AI_MODEL to gpt-4.1-mini.', false, $exception);
        }

        return new AiProviderException('The AI provider did not return a result. Try again.', true, $exception);
    }
}
