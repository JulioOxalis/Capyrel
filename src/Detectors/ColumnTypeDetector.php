<?php

namespace Julio\Capyrel\Detectors;

use Illuminate\Support\Str;

/**
 * Detects semantic column types that require specialised form inputs:
 * color pickers, coordinate fields, slug sources, JSON, spatial (POINT).
 */
class ColumnTypeDetector
{
    private static array $colorKeywords = [
        'color', 'colour', 'hex_color', 'hex_colour', 'bg_color', 'background_color',
        'text_color', 'font_color', 'primary_color', 'accent_color', 'brand_color',
    ];

    private static array $coordinateKeywords = [
        'lat', 'lng', 'latitude', 'longitude', 'coord_lat', 'coord_lng',
    ];

    private static array $slugKeywords = [
        'slug', 'handle', 'permalink', 'url_key',
    ];

    private static array $titleKeywords = [
        'name', 'title', 'headline', 'subject', 'label', 'display_name',
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    public static function isColorColumn(string $name): bool
    {
        $name = strtolower($name);
        foreach (self::$colorKeywords as $kw) {
            if ($name === $kw || str_ends_with($name, '_' . $kw) || str_starts_with($name, $kw . '_')) {
                return true;
            }
        }
        return false;
    }

    public static function isCoordinateColumn(string $name): bool
    {
        return in_array(strtolower($name), self::$coordinateKeywords);
    }

    /** Detect MySQL POINT spatial type */
    public static function isSpatialColumn(string $name, string $typeName): bool
    {
        return strtolower($typeName) === 'point'
            || strtolower($typeName) === 'geometry'
            || str_contains(strtolower($name), 'location')
            || str_contains(strtolower($name), 'position');
    }

    public static function isSlugColumn(string $name): bool
    {
        $name = strtolower($name);
        foreach (self::$slugKeywords as $kw) {
            if ($name === $kw || str_ends_with($name, '_' . $kw)) return true;
        }
        return false;
    }

    public static function isTitleColumn(string $name): bool
    {
        $name = strtolower($name);
        foreach (self::$titleKeywords as $kw) {
            if ($name === $kw || str_ends_with($name, '_' . $kw) || str_starts_with($name, $kw . '_')) {
                return true;
            }
        }
        return false;
    }

    public static function isJsonColumn(string $typeName): bool
    {
        return in_array(strtolower($typeName), ['json', 'jsonb']);
    }

    /**
     * Given a list of column names, find the best "source" column for a slug.
     * Returns null if no title-like column exists.
     */
    public static function findSlugSource(array $columnNames): ?string
    {
        // Priority order
        foreach (['name', 'title', 'headline', 'subject', 'label', 'display_name'] as $candidate) {
            if (in_array($candidate, $columnNames)) return $candidate;
        }
        // Partial match
        foreach ($columnNames as $col) {
            if (self::isTitleColumn($col)) return $col;
        }
        return null;
    }

    /**
     * Coordinate step attribute — lat/lng need decimal precision.
     */
    public static function coordinateStep(string $name): string
    {
        return 'any';
    }

    /**
     * Coordinate bounds for min/max attributes.
     * Returns ['min' => ..., 'max' => ...] or empty array.
     */
    public static function coordinateBounds(string $name): array
    {
        $n = strtolower($name);
        if (str_contains($n, 'lat')) return ['min' => '-90', 'max' => '90'];
        if (str_contains($n, 'lng') || str_contains($n, 'lon')) return ['min' => '-180', 'max' => '180'];
        return [];
    }
}
