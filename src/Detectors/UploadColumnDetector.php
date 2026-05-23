<?php

namespace Julio\Capyrel\Detectors;

class UploadColumnDetector
{
    private static array $imageKeywords = [
        'avatar', 'photo', 'image', 'thumbnail', 'cover', 'banner',
        'logo', 'icon', 'picture', 'portrait', 'headshot', 'profile_pic',
        'profile_image', 'featured_image', 'hero_image', 'gallery',
    ];

    private static array $fileKeywords = [
        'attachment', 'document', 'file', 'pdf', 'resume', 'cv',
        'certificate', 'upload', 'media', 'asset', 'resource', 'report',
    ];

    private static array $videoKeywords = [
        'video', 'clip', 'recording', 'footage', 'reel',
    ];

    public static function isImageColumn(string $name): bool
    {
        $name = strtolower($name);
        foreach (self::$imageKeywords as $kw) {
            if (str_contains($name, $kw)) return true;
        }
        return false;
    }

    public static function isFileColumn(string $name): bool
    {
        $name = strtolower($name);
        foreach (self::$fileKeywords as $kw) {
            if (str_contains($name, $kw)) return true;
        }
        return false;
    }

    public static function isVideoColumn(string $name): bool
    {
        $name = strtolower($name);
        foreach (self::$videoKeywords as $kw) {
            if (str_contains($name, $kw)) return true;
        }
        return false;
    }

    public static function isUploadColumn(string $name): bool
    {
        return self::isImageColumn($name) || self::isFileColumn($name) || self::isVideoColumn($name);
    }

    /** Detect columns that store JSON arrays of paths: photos_paths, documents_files */
    public static function isMultiUploadColumn(string $name): bool
    {
        return str_ends_with($name, '_paths')
            || str_ends_with($name, '_files')
            || str_ends_with($name, '_images');
    }

    /**
     * Return Laravel validation rules string (no surrounding quotes) for the column.
     * e.g. "'nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'"
     */
    public static function validationRules(string $name): string
    {
        if (self::isImageColumn($name)) {
            return "'nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'";
        }
        if (self::isVideoColumn($name)) {
            return "'nullable', 'file', 'mimes:mp4,mov,avi,webm', 'max:102400'";
        }
        if (self::isFileColumn($name)) {
            return "'nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt', 'max:10240'";
        }
        return "'nullable', 'file', 'max:10240'";
    }

    /** Storage subdirectory for this column type */
    public static function storagePath(string $name): string
    {
        if (self::isImageColumn($name)) return 'images';
        if (self::isVideoColumn($name)) return 'videos';
        return 'files';
    }

    /** Accept attribute for <input type="file"> */
    public static function acceptAttr(string $name): string
    {
        if (self::isImageColumn($name)) return 'accept="image/jpeg,image/png,image/gif,image/webp"';
        if (self::isVideoColumn($name)) return 'accept="video/mp4,video/mov,video/avi,video/webm"';
        if (self::isFileColumn($name)) return 'accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt"';
        return '';
    }

    /** Collect all upload column names from a columns array */
    public static function collectFrom(array $columns): array
    {
        return array_values(array_filter(
            array_column($columns, 'name'),
            fn($name) => self::isUploadColumn($name)
        ));
    }
}
