<?php

namespace Julio\Capyrel\Detectors;

use Illuminate\Support\Str;

/**
 * Classifies models as standalone or dependent.
 *
 * STANDALONE — has its own identity, navigated to directly:
 *   User, Post, Product, Category, Team
 *   → gets full CRUD pages at /users, /posts, etc.
 *
 * DEPENDENT — only exists in context of a parent:
 *   Comment (belongs to Post), Attachment (morphTo), OrderItem (belongs to Order)
 *   → gets an inline form on the parent's show page
 *   → uses nested routes: posts.comments, orders.order-items
 *   → NO standalone /comments/create page
 */
class ModelContextClassifier
{
    private array $alwaysStandalone = [
        'User', 'Admin', 'Team', 'Organization', 'Company',
        'Role', 'Permission', 'Category', 'Tag',
    ];

    private array $alwaysDependent = [
        'Comment', 'Reply', 'Attachment', 'Media', 'Image',
        'OrderItem', 'LineItem', 'CartItem', 'InvoiceItem',
        'Notification', 'Log', 'ActivityLog', 'AuditLog',
        'Vote', 'Like', 'Reaction', 'View',
    ];

    public function classify(string $modelName, array $relationships): string
    {
        // Known standalone models
        if (in_array($modelName, $this->alwaysStandalone)) return 'standalone';

        // Known dependent models
        if (in_array($modelName, $this->alwaysDependent)) return 'dependent';

        $types = collect($relationships)->pluck('type');

        $hasDownward = $types->contains(fn($t) => in_array($t, [
            'hasMany', 'hasOne', 'hasManyThrough', 'hasOneThrough',
        ]));

        $hasBtm     = $types->contains('belongsToMany');
        $hasMorphTo = $types->contains('morphTo');
        $hasParent  = $types->contains('belongsTo');

        // Only has belongsTo relationships (always a child)
        if ($hasParent && !$hasDownward && !$hasBtm) return 'dependent';

        // Has morphTo only — polymorphic child
        if ($hasMorphTo && !$hasDownward && !$hasBtm) return 'dependent';

        return 'standalone';
    }

    /**
     * For a dependent model, find the primary parent relationship.
     * Used to determine which parent's show page this form belongs to.
     */
    public function primaryParent(array $relationships): ?array
    {
        // Prefer belongsTo over morphTo
        $belongsTo = collect($relationships)->firstWhere('type', 'belongsTo');
        if ($belongsTo) return $belongsTo;

        return collect($relationships)->firstWhere('type', 'morphTo');
    }

    /**
     * Returns the nested route name for a dependent model.
     * e.g. Comment (belongsTo Post) → "posts.comments"
     */
    public function nestedRoutePrefix(string $modelName, array $relationships): string
    {
        $parent = $this->primaryParent($relationships);
        if (!$parent) return Str::kebab(Str::plural($modelName));

        $parentRoute = Str::kebab(Str::plural($parent['related']));
        $childRoute  = Str::kebab(Str::plural($modelName));

        return "{$parentRoute}.{$childRoute}";
    }

    /**
     * Returns the parent variable name for nested route binding.
     * e.g. Post → $post
     */
    public function parentVariable(array $relationships): ?string
    {
        $parent = $this->primaryParent($relationships);
        if (!$parent) return null;

        return Str::camel($parent['related']);
    }

    /**
     * Returns the FK column linking this model to its parent.
     */
    public function parentForeignKey(array $relationships): ?string
    {
        $parent = $this->primaryParent($relationships);
        return $parent['foreign_key'] ?? ($parent ? Str::snake($parent['related']) . '_id' : null);
    }
}
