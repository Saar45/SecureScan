<?php

namespace App\Service;

use App\Entity\Finding;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiFixService
{
    private const GROQ_ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';
    private const MODEL = 'llama-3.3-70b-versatile';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Calls the Groq API (Llama 3.3 70B) to generate a corrected version of vulnerable code.
     *
     * Returns the fixed code string, or null if the API key is missing or the call fails.
     */
    public function generateFix(Finding $finding): ?string
    {
        $apiKey = $_ENV['GROQ_API_KEY'] ?? $_SERVER['GROQ_API_KEY'] ?? '';
        if ($apiKey === '') {
            return null;
        }

        $rawCode = $finding->getRawCode();
        if ($rawCode === null || trim($rawCode) === '') {
            return null;
        }

        $prompt = $this->buildPrompt($finding);

        try {
            $response = $this->httpClient->request('POST', self::GROQ_ENDPOINT, [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $apiKey),
                ],
                'json' => [
                    'model' => self::MODEL,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a security expert. You fix vulnerable code. Return ONLY the corrected code, without any explanation, markdown fences, or surrounding text.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 2048,
                ],
            ]);

            $data = $response->toArray();

            $text = $data['choices'][0]['message']['content'] ?? null;
            if ($text === null) {
                return null;
            }

            return $this->extractCode($text);
        } catch (\Throwable $e) {
            if (\defined('STDERR')) {
                fwrite(STDERR, sprintf("[AI-FIX-ERROR] %s\n", $e->getMessage()));
            }
            return null;
        }
    }

    private function buildPrompt(Finding $finding): string
    {
        $parts = [];
        $parts[] = sprintf('File: %s', $finding->getFilePath());

        if ($finding->getLineNumber() !== null) {
            $parts[] = sprintf('Line: %d', $finding->getLineNumber());
        }

        $parts[] = sprintf('Severity: %s', $finding->getSeverity());
        $parts[] = sprintf('Vulnerability: %s', $finding->getDescription());

        if ($finding->getOwaspCategory() !== null) {
            $parts[] = sprintf('OWASP Category: %s', $finding->getOwaspCategory());
        }

        $parts[] = '';
        $parts[] = 'Vulnerable code:';
        $parts[] = '```';
        $parts[] = $finding->getRawCode();
        $parts[] = '```';
        $parts[] = '';
        $parts[] = 'Return ONLY the corrected code, without any explanation, markdown fences, or surrounding text.';

        return implode("\n", $parts);
    }

    /**
     * Strips markdown code fences if the model included them despite instructions.
     */
    private function extractCode(string $text): string
    {
        $text = trim($text);

        // Remove leading ```lang and trailing ```
        if (preg_match('/^```[\w]*\n(.*?)```$/s', $text, $matches)) {
            return trim($matches[1]);
        }

        return $text;
    }
}
