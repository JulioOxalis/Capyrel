<?php

namespace Julio\Capyrel\Wizard;

use Illuminate\Support\Str;

/**
 * Extracts entity names from natural-language project descriptions or
 * explicit comma-separated lists.
 *
 * Examples:
 *   "a blog with posts, tags, and authors"  → ['Post', 'Tag', 'Author']
 *   "Post, Comment, User"                   → ['Post', 'Comment', 'User']
 *   "user management with roles, permissions, and audit logs"
 *                                           → ['User', 'Role', 'Permission', 'AuditLog']
 */
class EntityParser
{
    /** Common English stop-words to ignore when parsing sentences */
    private const STOP_WORDS = [
        'a', 'an', 'the', 'and', 'or', 'but', 'with', 'without', 'for',
        'to', 'of', 'in', 'on', 'at', 'by', 'from', 'as', 'into', 'that',
        'this', 'these', 'those', 'its', 'my', 'your', 'their', 'our',
        'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had',
        'will', 'would', 'can', 'could', 'should', 'need', 'some', 'any',
        'each', 'every', 'where', 'when', 'how', 'which', 'who', 'also',
        'system', 'platform', 'application', 'app', 'management', 'module',
        'feature', 'project', 'website', 'like', 'such', 'including',
    ];

    /** Words that, when seen, suggest the next noun is an entity */
    private const LEAD_WORDS = [
        'with', 'including', 'has', 'have', 'contains', 'consist', 'need',
        'needs', 'require', 'requires', 'manage', 'manages', 'support',
        'supports', 'track', 'tracks', 'store', 'stores',
    ];

    /**
     * Parse a description into singular StudlyCase entity names.
     *
     * @param  string  $input  Free-text or comma/slash-separated entity list
     * @return string[]
     */
    public function parse(string $input): array
    {
        $input = trim($input);

        if (empty($input)) {
            return [];
        }

        // If the input looks like a simple list (no sentence words), parse as-is
        if ($this->looksLikeList($input)) {
            return $this->parseList($input);
        }

        return $this->parseSentence($input);
    }

    /**
     * Normalise an array of raw names into unique StudlyCase singular models.
     *
     * @param  string[]  $names
     * @return string[]
     */
    public function normalise(array $names): array
    {
        $result = [];

        foreach ($names as $name) {
            $name = trim($name);
            if (empty($name)) continue;

            // Replace spaces/hyphens with underscore for multi-word → StudlyCase
            $name = str_replace([' ', '-'], '_', $name);
            $name = Str::studly(Str::singular(strtolower($name)));

            if (strlen($name) < 2) continue;
            if (in_array(strtolower($name), self::STOP_WORDS, true)) continue;

            $result[] = $name;
        }

        // Deduplicate, preserving first occurrence order
        return array_values(array_unique($result));
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function looksLikeList(string $input): bool
    {
        // If there are commas/slashes and the words are mostly short & capitalized
        // or the whole thing has no spaces → treat as list
        if (!str_contains($input, ' ')) return true;

        $words = explode(' ', $input);
        $stopCount = 0;
        foreach ($words as $w) {
            if (in_array(strtolower(trim($w, ',;')), self::STOP_WORDS, true)) {
                $stopCount++;
            }
        }

        // Fewer than 20% stop words → it's a list
        return ($stopCount / count($words)) < 0.2;
    }

    private function parseList(string $input): array
    {
        // Split on commas, semicolons, slashes, " and ", " or "
        $parts = preg_split('/[,;\/]|\s+and\s+|\s+or\s+/i', $input);
        return $this->normalise(array_filter(array_map('trim', $parts)));
    }

    private function parseSentence(string $input): array
    {
        // Lower-case for stop-word matching, but preserve casing for extraction
        $lower = strtolower($input);
        $words = preg_split('/[\s,;\/]+/', $input);

        $candidates = [];
        $afterLead  = false;

        foreach ($words as $i => $word) {
            $clean = trim($word, '.,;:!?()"\'');
            $lower_clean = strtolower($clean);

            if (empty($clean)) continue;

            // Mark that we just saw a lead word
            if (in_array($lower_clean, self::LEAD_WORDS, true)) {
                $afterLead = true;
                continue;
            }

            if (in_array($lower_clean, self::STOP_WORDS, true)) {
                $afterLead = false;
                continue;
            }

            // A word is a candidate if:
            // - It starts with an uppercase letter (user typed it explicitly), OR
            // - It follows a lead word, OR
            // - It appears after a comma in an enumeration context
            if ($afterLead || ctype_upper($clean[0]) || $this->isNoun($lower_clean)) {
                $candidates[] = $clean;
            }

            $afterLead = false;
        }

        return $this->normalise($candidates);
    }

    /** Very lightweight noun detector — checks for common entity-like suffixes */
    private function isNoun(string $word): bool
    {
        // Plural-ish or entity-suffix words
        $entitySuffixes = ['s', 'er', 'or', 'ion', 'ing', 'ment', 'log', 'list', 'item'];
        foreach ($entitySuffixes as $suffix) {
            if (str_ends_with($word, $suffix) && strlen($word) > 3) {
                return true;
            }
        }
        return false;
    }
}
