<?php

namespace App\Support;

/**
 * A customer's personal list of names the AI keeps consistent in recaps.
 * Entries carry the original and preferred name, plus optional pronunciation,
 * type, output language and a short note that gives the AI more context.
 */
final class NameDictionary
{
    public const MAX_ENTRIES = 50;

    public const TYPES = ['character', 'place', 'term', 'other'];

    /**
     * Validation rules for a dictionary submitted under the given key.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $key = 'name_dictionary', bool $required = true): array
    {
        return [
            $key => [$required ? 'present' : 'sometimes', 'array', 'max:'.self::MAX_ENTRIES],
            $key.'.*' => ['array:original,translated,pronunciation,type,language,note'],
            $key.'.*.original' => ['required', 'string', 'max:100'],
            $key.'.*.translated' => ['required', 'string', 'max:100'],
            $key.'.*.pronunciation' => ['nullable', 'string', 'max:100'],
            $key.'.*.type' => ['nullable', 'string', 'in:'.implode(',', self::TYPES)],
            $key.'.*.language' => ['nullable', 'string', 'in:'.implode(',', array_diff(MediaSettings::LANGUAGES, ['auto']))],
            $key.'.*.note' => ['nullable', 'string', 'max:160'],
        ];
    }

    /**
     * Trim values and keep optional fields only when they carry information,
     * so older entries stay identical and voice caches are not invalidated.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return list<array<string, string>>
     */
    public static function normalize(array $entries): array
    {
        return array_values(array_map(function (array $entry): array {
            $normalized = [
                'original' => trim((string) ($entry['original'] ?? '')),
                'translated' => trim((string) ($entry['translated'] ?? '')),
                'pronunciation' => trim((string) ($entry['pronunciation'] ?? '')),
            ];
            foreach (['type', 'language', 'note'] as $field) {
                $value = trim((string) ($entry[$field] ?? ''));
                if ($value !== '') {
                    $normalized[$field] = $value;
                }
            }

            return $normalized;
        }, $entries));
    }

    /**
     * The first duplicated original name (case-insensitive), if any.
     *
     * @param  list<array<string, string>>  $entries
     * @return array{0: int, 1: string}|null
     */
    public static function duplicate(array $entries): ?array
    {
        $seen = [];
        foreach ($entries as $index => $entry) {
            $key = mb_strtolower($entry['original']);
            if (isset($seen[$key])) {
                return [$index, $entry['original']];
            }
            $seen[$key] = true;
        }

        return null;
    }
}
