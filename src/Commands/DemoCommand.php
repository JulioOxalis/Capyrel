<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DemoCommand extends Command
{
    protected $signature   = 'capyrel:demo';
    protected $description = 'Build a demo SQLite database and run model:scaffold so you can see capyrel in action';

    public function handle(): int
    {
        $this->line('');
        $this->line('  <fg=cyan>Setting up demo database...</>');

        // Use a file-based SQLite demo DB so it persists for the scaffold run
        $dbPath = database_path('capyrel_demo.sqlite');

        if (!file_exists($dbPath)) {
            touch($dbPath);
        }

        // Point the sqlite connection at our demo file
        config(['database.connections.sqlite.database' => $dbPath]);

        $schema = Schema::connection('sqlite');

        // Drop old demo tables if re-running
        foreach (['comments', 'likes', 'role_user', 'posts', 'profiles', 'roles', 'users'] as $t) {
            $schema->dropIfExists($t);
        }

        // ── users ──────────────────────────────────────────────
        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        // ── profiles  (hasOne from users) ──────────────────────
        $schema->create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('bio')->nullable();
            $table->string('avatar')->nullable();
            $table->timestamps();
        });

        // ── roles ──────────────────────────────────────────────
        $schema->create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // ── role_user  (belongsToMany pivot) ───────────────────
        $schema->create('role_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('assigned_by')->nullable();
        });

        // ── posts  (hasMany from users) ────────────────────────
        $schema->create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });

        // ── comments  (hasMany from posts + morphTo) ───────────
        $schema->create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->morphs('commentable'); // commentable_type + commentable_id
            $table->text('body');
            $table->timestamps();
        });

        // ── likes  (morphTo) ───────────────────────────────────
        $schema->create('likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('likeable'); // likeable_type + likeable_id
            $table->timestamps();
        });

        $this->line('  <fg=green>✔</> Demo tables created:');
        $this->line('    users · profiles · roles · role_user (pivot) · posts · comments · likes');
        $this->line('');
        $this->line('  <fg=cyan>Launching capyrel wizard in dry-run mode...</>');
        $this->line('');

        $this->call('model:scaffold', [
            '--connection' => 'sqlite',
            '--dry-run'    => true,
        ]);

        // Clean up demo file (suppress resource-lock on Windows)
        @unlink($dbPath);

        return self::SUCCESS;
    }
}
