<?php

namespace Julio\Capyrel\Analyzers;

class Diagnostic
{
    public const ERROR   = 'error';
    public const WARNING = 'warning';
    public const INFO    = 'info';

    public function __construct(
        public readonly string $level,      // error | warning | info
        public readonly string $rule,       // e.g. 'n1_query', 'missing_index'
        public readonly string $model,      // model name or '' for global
        public readonly string $message,    // what was detected
        public readonly string $suggestion, // what to do about it
    ) {}

    public static function error(string $rule, string $model, string $message, string $suggestion): self
    {
        return new self(self::ERROR, $rule, $model, $message, $suggestion);
    }

    public static function warning(string $rule, string $model, string $message, string $suggestion): self
    {
        return new self(self::WARNING, $rule, $model, $message, $suggestion);
    }

    public static function info(string $rule, string $model, string $message, string $suggestion): self
    {
        return new self(self::INFO, $rule, $model, $message, $suggestion);
    }
}
