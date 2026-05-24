<?php

namespace Julio\Capyrel\UI\Contracts;

interface UiAdapter
{
    /**
     * Adapter name — used in registry and config.
     */
    public function name(): string;

    /**
     * Capabilities this adapter supports.
     * e.g. ['list', 'create', 'show', 'feed', 'comments', 'realtime']
     */
    public function capabilities(): array;

    /**
     * Render the list / collection screen from a UI Contract.
     */
    public function renderList(array $contract): string;

    /**
     * Render the create / form screen from a UI Contract.
     */
    public function renderCreate(array $contract): string;

    /**
     * Render the edit / update form screen from a UI Contract.
     * Like create but pre-populated with the model's current values.
     * The variable name for the model instance is Str::camel($contract['entity']).
     */
    public function renderEdit(array $contract): string;

    /**
     * Render the show / detail screen from a UI Contract.
     */
    public function renderShow(array $contract): string;
}
