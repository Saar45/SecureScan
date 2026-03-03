<?php

namespace App\Service;

use App\Entity\Finding;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiFixService
{
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Calls the Gemini API to generate a corrected version of vulnerable code.
     *
     * Returns the fixed code string, or null if the API key is missing or the call fails.
     */
    public function generateFix(Finding $finding): ?string
    {
        $apiKey = $_ENV['GEMINI_API_KEY'] ?? $_SERVER['GEMINI_API_KEY'] ?? '';
        if ($apiKey === '') {
            return null;
        }

        $rawCode = $finding->getRawCode();
        if ($rawCode === null || trim($rawCode) === '') {
            return null;
        }

        $prompt = $this->buildPrompt($finding);

        try {
            $response = $this->httpClient->request('POST', self::GEMINI_ENDPOINT, [
                'query' => ['key' => $apiKey],
                'json' => [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'maxOutputTokens' => 2048,
                    ],
                ],
            ]);

            $data = $response->toArray();

            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if ($text === null) {
                return null;
            }

            return $this->extractCode($text);
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildPrompt(Finding $finding): string
    {
        $parts = [];
        $parts[] = 'You are a security expert. Fix the following vulnerable code.';
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
        $parts[] = 'Return ONLY the corrected code, without any explanation, markdown fences, or surrounding text. Output the fixed code and nothing else.';

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
