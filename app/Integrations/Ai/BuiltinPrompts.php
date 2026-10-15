<?php

namespace App\Integrations\Ai;

final class BuiltinPrompts
{
    /**
     * @param  array<string, mixed>  $input
     */
    public static function system(string $type): string
    {
        $json = match ($type) {
            'chapters' => '{"chapters":[{"label":"","note":""}]}',
            'highlights' => '{"highlights":[{"title":"","detail":""}]}',
            'transcript' => '{"summary":"","takeaways":[],"topics":[]}',
            default => '{"summary":"","takeaways":[],"topics":[]}',
        };

        return <<<PROMPT
You write listening aids for a podcast app. Use only the episode material you are given. Do not invent quotes, guests, statistics, or chapters that the material does not support. If the material is thin, say so in the summary and keep the lists short. Reply with JSON only, no markdown, in this shape: {$json}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function user(string $type, EpisodeBrief $brief, array $input): string
    {
        $preference = match ($type) {
            'chapters' => 'Chapter style: '.self::choice($input, 'style', 'balanced').'.',
            'highlights' => 'Highlight focus: '.self::choice($input, 'focus', 'insights').'.',
            default => 'Summary length: '.self::choice($input, 'length', 'medium').'.',
        };

        return $preference."\n\n".$brief->asText();
    }

    /**
     * @return array{text: string, takeaways: list<string>, topics: list<string>}
     */
    public static function presentation(string $type, string $raw): array
    {
        $decoded = self::decode($raw);
        if (! is_array($decoded)) {
            return ['text' => trim($raw), 'takeaways' => [], 'topics' => []];
        }

        if ($type === 'chapters') {
            $lines = [];
            foreach (is_array($decoded['chapters'] ?? null) ? $decoded['chapters'] : [] as $chapter) {
                if (! is_array($chapter)) {
                    continue;
                }
                $label = trim((string) ($chapter['label'] ?? ''));
                $note = trim((string) ($chapter['note'] ?? ''));
                if ($label === '' && $note === '') {
                    continue;
                }
                $lines[] = $note === '' ? $label : $label.' — '.$note;
            }

            return [
                'text' => $lines === [] ? 'The episode material did not include enough detail for chapters.' : implode("\n", $lines),
                'takeaways' => [],
                'topics' => [],
            ];
        }

        if ($type === 'highlights') {
            $lines = [];
            foreach (is_array($decoded['highlights'] ?? null) ? $decoded['highlights'] : [] as $highlight) {
                if (! is_array($highlight)) {
                    continue;
                }
                $title = trim((string) ($highlight['title'] ?? ''));
                $detail = trim((string) ($highlight['detail'] ?? ''));
                if ($title === '' && $detail === '') {
                    continue;
                }
                $lines[] = $detail === '' ? $title : $title.' — '.$detail;
            }

            return [
                'text' => $lines === [] ? 'The episode material did not include enough detail for highlights.' : implode("\n", $lines),
                'takeaways' => [],
                'topics' => [],
            ];
        }

        $summary = trim((string) ($decoded['summary'] ?? ''));

        return [
            'text' => $summary !== '' ? $summary : 'The episode material did not include enough detail for a summary.',
            'takeaways' => self::strings($decoded['takeaways'] ?? []),
            'topics' => self::strings($decoded['topics'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function choice(array $input, string $key, string $fallback): string
    {
        $value = $input[$key] ?? null;
        if (! is_string($value)) {
            return $fallback;
        }
        $value = strtolower(trim($value));

        return $value !== '' ? $value : $fallback;
    }

    private static function decode(string $raw): mixed
    {
        $clean = trim($raw);
        $clean = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $clean) ?? $clean;
        try {
            return json_decode($clean, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return array_values($items);
    }
}
