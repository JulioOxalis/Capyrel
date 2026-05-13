# Capyrel — Test Projects

5 projects designed to fully stress-test capyrel's relationship detection across every relationship type, pattern, and edge case.

Build them in order — each one adds complexity on top of the previous.

---

## How to use this guide

For each project:

```bash
# 1. Create a fresh Laravel app
composer create-project laravel/laravel capyrel-test-{name}
cd capyrel-test-{name}

# 2. Install capyrel
composer require julio/capyrel

# 3. Create the migrations listed below
php artisan make:migration create_{table}_table

# 4. Run migrations
php artisan migrate

# 5. Preview what capyrel detects
php artisan model:scaffold --dry-run
php artisan model:map

# 6. Check the boxes in the verification list
```

---

## Project 1 — Blog Platform

**Goal:** Verify the 4 core relationships work perfectly.
**Covers:** `hasOne` · `hasMany` · `belongsTo` · `belongsToMany`

### Tables to create

```
users           id, name, email, password, timestamps
profiles        id, user_id (unique), bio, avatar, timestamps
roles           id, name, timestamps
role_user       user_id, role_id, assigned_by (pivot)
posts           id, user_id, title, body, published_at, deleted_at, timestamps
comments        id, post_id, user_id, body, timestamps
tags            id, name, slug, timestamps
post_tag        post_id, tag_id (pivot)
```

### Key migrations

```php
// profiles — unique on user_id triggers hasOne detection
Schema::create('profiles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
    $table->string('bio')->nullable();
    $table->string('avatar')->nullable();
    $table->timestamps();
});

// posts — deleted_at triggers soft-delete warning
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('title');
    $table->text('body');
    $table->timestamp('published_at')->nullable();
    $table->softDeletes(); // triggers SoftDeleteAnalyzer
    $table->timestamps();
});

// role_user — pivot table with extra column
Schema::create('role_user', function (Blueprint $table) {
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('role_id')->constrained()->cascadeOnDelete();
    $table->string('assigned_by')->nullable();
});

// post_tag — pure pivot
Schema::create('post_tag', function (Blueprint $table) {
    $table->foreignId('post_id')->constrained()->cascadeOnDelete();
    $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
});
```

### What capyrel must detect

```
User
├── hasOne           → Profile       [profiles.user_id, unique]
├── hasMany          → Post          [posts.user_id]
├── hasMany          → Comment       [comments.user_id]
└── belongsToMany    → Role          [pivot: role_user]

Post
├── belongsTo        → User          [user_id]
├── hasMany          → Comment       [comments.post_id]
└── belongsToMany    → Tag           [pivot: post_tag]

Profile
└── belongsTo        → User          [user_id]

Comment
├── belongsTo        → Post          [post_id]
└── belongsTo        → User          [user_id]

Role
└── belongsToMany    → User          [pivot: role_user]

Tag
└── belongsToMany    → Post          [pivot: post_tag]
```

### Health check must flag

- `⚠ posts table has deleted_at but Post model doesn't use SoftDeletes`
- `⚠ comments.user_id → users has no ON DELETE CASCADE`
- `⚠ comments.post_id → posts has no ON DELETE CASCADE` *(if you don't add cascadeOnDelete)*

### Verification checklist

- [ ] `User hasOne Profile` detected (not hasMany — because of the unique index)
- [ ] `User hasMany Post` detected
- [ ] Both pivot tables detected (`role_user`, `post_tag`)
- [ ] Both sides of belongsToMany written (User→Role AND Role→User)
- [ ] Soft-delete warning fires for `posts`
- [ ] `model:map` shows all 6 models connected correctly
- [ ] `model:requests` generates `'published_at' => ['nullable', 'date']`

---

## Project 2 — E-Commerce Store

**Goal:** Verify `hasManyThrough` detection and multi-hop FK chains.
**Covers:** `hasManyThrough` · `hasMany` · `belongsTo` · `belongsToMany`

### Tables to create

```
users           id, name, email, password, timestamps
addresses       id, user_id (unique), street, city, country, timestamps
categories      id, name, parent_id (nullable — self-ref), timestamps
products        id, category_id, name, price, stock, deleted_at, timestamps
orders          id, user_id, status, total, timestamps
order_items     id, order_id, product_id, quantity, unit_price, timestamps
product_tag     product_id, tag_id (pivot)
tags            id, name, timestamps
```

### Key migrations

```php
// categories — self-referential (parent_id)
Schema::create('categories', function (Blueprint $table) {
    $table->id();
    $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
    $table->string('name');
    $table->timestamps();
});

// products — links to category
Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->foreignId('category_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->decimal('price', 8, 2);
    $table->unsignedInteger('stock')->default(0);
    $table->softDeletes();
    $table->timestamps();
});

// orders
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('status')->default('pending');
    $table->decimal('total', 10, 2);
    $table->timestamps();
});

// order_items — links orders to products
Schema::create('order_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->constrained()->cascadeOnDelete();
    $table->foreignId('product_id')->constrained()->cascadeOnDelete();
    $table->unsignedInteger('quantity');
    $table->decimal('unit_price', 8, 2);
    $table->timestamps();
});
```

### What capyrel must detect

```
User
├── hasMany          → Order         [orders.user_id]
└── hasManyThrough   → OrderItem     [User → Order → OrderItem]

Category
├── hasMany          → Product       [products.category_id]
├── belongsTo        → Category      [parent_id]   ← self-referential
└── hasMany          → Category      [categories.parent_id]

Product
├── belongsTo        → Category      [category_id]
├── hasMany          → OrderItem     [order_items.product_id]
└── belongsToMany    → Tag           [pivot: product_tag]

Order
├── belongsTo        → User          [user_id]
└── hasMany          → OrderItem     [order_items.order_id]

OrderItem
├── belongsTo        → Order         [order_id]
└── belongsTo        → Product       [product_id]
```

### Health check must flag

- `⚠ products.deleted_at — Product model doesn't use SoftDeletes`
- `ℹ Category → Category self-referential` *(parent_id detected as belongsTo Category)*

### Verification checklist

- [ ] `User hasManyThrough OrderItem` detected (via Order)
- [ ] `Category belongsTo Category` detected (self-referential parent_id)
- [ ] `Category hasMany Category` detected (children)
- [ ] `model:tests` generates `it('User::orderItems() returns HasManyThrough')`
- [ ] `model:resources` generates `UserResource` with `'orders' => OrderResource::collection($this->whenLoaded('orders'))`
- [ ] `model:requests` generates `'price' => ['required', 'numeric']` for Product

---

## Project 3 — School Management

**Goal:** Verify pivot tables with extra data columns and deeper `hasManyThrough` chains.
**Covers:** `belongsToMany` with pivot data · `hasManyThrough` (3 levels) · `hasOne`

### Tables to create

```
users           id, name, email, password, role (teacher/student), timestamps
departments     id, name, head_user_id (unique), timestamps
courses         id, department_id, teacher_id, title, credits, timestamps
enrollments     id, user_id, course_id, grade, enrolled_at (pivot+)
lessons         id, course_id, title, content, order, timestamps
submissions     id, lesson_id, user_id, content, score, timestamps
```

### Key migrations

```php
// departments — head_user_id unique triggers hasOne from User
Schema::create('departments', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->foreignId('head_user_id')->unique()->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
});

// courses — two FKs to different tables
Schema::create('courses', function (Blueprint $table) {
    $table->id();
    $table->foreignId('department_id')->constrained()->cascadeOnDelete();
    $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
    $table->string('title');
    $table->unsignedTinyInteger('credits');
    $table->timestamps();
});

// enrollments — pivot with real data columns
Schema::create('enrollments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('course_id')->constrained()->cascadeOnDelete();
    $table->decimal('grade', 4, 2)->nullable();
    $table->timestamp('enrolled_at')->nullable();
    $table->timestamps();
});

// submissions — hasManyThrough: User → Lesson → Submission
Schema::create('submissions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->text('content');
    $table->decimal('score', 4, 2)->nullable();
    $table->timestamps();
});
```

### What capyrel must detect

```
User
├── hasOne           → Department    [departments.head_user_id, unique]
├── hasMany          → Course        [courses.teacher_id]   ← via teacher_id not user_id
├── belongsToMany    → Course        [pivot: enrollments]
├── hasMany          → Submission    [submissions.user_id]
└── hasManyThrough   → Submission    [User → Lesson → Submission]

Department
├── belongsTo        → User          [head_user_id]
└── hasMany          → Course        [courses.department_id]

Course
├── belongsTo        → Department    [department_id]
├── belongsTo        → User          [teacher_id]
├── hasMany          → Lesson        [lessons.course_id]
└── belongsToMany    → User          [pivot: enrollments]

Lesson
├── belongsTo        → Course        [course_id]
└── hasMany          → Submission    [submissions.lesson_id]
```

### Verification checklist

- [ ] `User hasOne Department` detected (head_user_id with unique index)
- [ ] `enrollments` detected as pivot (even though it has extra `grade` and `enrolled_at` columns)
- [ ] `model:requests` generates `'grade' => ['nullable', 'numeric']` for Enrollment
- [ ] `model:map --format=mermaid` shows the full chain User → Course → Lesson
- [ ] `migrate:safe --check` shows clean (no dangerous patterns in these migrations)

---

## Project 4 — Social Media Platform

**Goal:** Verify polymorphic relationship detection (`morphTo` / `morphMany`).
**Covers:** `morphTo` · `morphMany` · `morphToMany` · `hasManyThrough`

### Tables to create

```
users           id, name, email, username, password, deleted_at, timestamps
posts           id, user_id, body, deleted_at, timestamps
comments        id, user_id, commentable_type, commentable_id, body, timestamps
likes           id, user_id, likeable_type, likeable_id, timestamps
media           id, mediable_type, mediable_id, url, type, size, timestamps
notifications   id, user_id, type, notifiable_type, notifiable_id, read_at, timestamps
```

### Key migrations

```php
// comments — morphTo (commentable_type + commentable_id)
Schema::create('comments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->morphs('commentable'); // generates commentable_type + commentable_id
    $table->text('body');
    $table->timestamps();
});

// likes — morphTo (likeable_type + likeable_id)
Schema::create('likes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->morphs('likeable');
    $table->timestamps();
});

// media — morphTo (mediable_type + mediable_id)
Schema::create('media', function (Blueprint $table) {
    $table->id();
    $table->morphs('mediable');
    $table->string('url');
    $table->string('type'); // image, video, file
    $table->unsignedBigInteger('size');
    $table->timestamps();
});

// notifications — morphTo (notifiable_type + notifiable_id)
Schema::create('notifications', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('type');
    $table->morphs('notifiable');
    $table->timestamp('read_at')->nullable();
    $table->timestamps();
});

// posts — with soft deletes
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->text('body');
    $table->softDeletes();
    $table->timestamps();
});
```

### What capyrel must detect

```
Comment
├── belongsTo        → User          [user_id]
└── morphTo          → (polymorphic) [commentable_type / commentable_id]

Like
├── belongsTo        → User          [user_id]
└── morphTo          → (polymorphic) [likeable_type / likeable_id]

Medium
└── morphTo          → (polymorphic) [mediable_type / mediable_id]

Notification
├── belongsTo        → User          [user_id]
└── morphTo          → (polymorphic) [notifiable_type / notifiable_id]

Post
├── belongsTo        → User          [user_id]
└── hasMany          → Comment       [comments.post_id — via commentable convention]

User
├── hasMany          → Post          [posts.user_id]
├── hasMany          → Comment       [comments.user_id]
├── hasMany          → Like          [likes.user_id]
└── hasMany          → Notification  [notifications.user_id]
```

### Health check must flag

- `⚠ Comment::commentable() uses morphTo but no Relation::morphMap() found`
- `⚠ Like::likeable() uses morphTo but no Relation::morphMap() found`
- `⚠ Medium::mediable() uses morphTo but no Relation::morphMap() found`
- `⚠ posts table has deleted_at but Post model doesn't use SoftDeletes`
- `⚠ users table has deleted_at but User model doesn't use SoftDeletes`

### What morphMap suggestion should look like

```php
// capyrel suggests adding to AppServiceProvider::boot():
Relation::morphMap([
    'post'    => Post::class,
    'comment' => Comment::class,
    'user'    => User::class,
]);
```

### Verification checklist

- [ ] 4 separate `morphTo` relationships detected across 4 tables
- [ ] `commentable`, `likeable`, `mediable`, `notifiable` each detected independently
- [ ] Morph registry warning fires for all 4 morphTo models
- [ ] `model:tests` generates `it('Comment::commentable() returns MorphTo')`
- [ ] `model:map` shows morph relationships with `→ (polymorphic)` label

---

## Project 5 — Project Management App

**Goal:** The hardest test. Multiple FKs to the same table, self-referential models, everything combined.
**Covers:** All 8 relationship types · multiple FKs to same table · self-referential · cascade risk

### Tables to create

```
users           id, name, email, password, timestamps
teams           id, name, owner_id, timestamps
team_user       team_id, user_id, role (pivot+)
projects        id, team_id, created_by, name, status, deleted_at, timestamps
tasks           id, project_id, assigned_to, created_by, parent_id (nullable), title, due_at, timestamps
comments        id, commentable_type, commentable_id, user_id, body, timestamps
attachments     id, attachable_type, attachable_id, filename, path, timestamps
time_logs       id, task_id, user_id, minutes, logged_at, timestamps
```

### Key migrations

```php
// teams — owner_id FK to users (different from team_user pivot)
Schema::create('teams', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
    $table->timestamps();
});

// team_user — pivot with role extra column
Schema::create('team_user', function (Blueprint $table) {
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('role')->default('member'); // owner, admin, member
});

// projects — TWO FKs: team_id and created_by
Schema::create('projects', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
    $table->string('name');
    $table->string('status')->default('active');
    $table->softDeletes();
    $table->timestamps();
});

// tasks — THREE FKs + self-referential parent_id
Schema::create('tasks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('project_id')->constrained()->cascadeOnDelete();
    $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
    $table->foreignId('parent_id')->nullable()->constrained('tasks')->cascadeOnDelete();
    $table->string('title');
    $table->timestamp('due_at')->nullable();
    $table->timestamps();
});

// comments — morphTo
Schema::create('comments', function (Blueprint $table) {
    $table->id();
    $table->morphs('commentable');
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->text('body');
    $table->timestamps();
});

// attachments — morphTo
Schema::create('attachments', function (Blueprint $table) {
    $table->id();
    $table->morphs('attachable');
    $table->string('filename');
    $table->string('path');
    $table->timestamps();
});

// time_logs — hasManyThrough: User → Task → TimeLog
Schema::create('time_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('task_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->unsignedInteger('minutes');
    $table->timestamp('logged_at');
    $table->timestamps();
});
```

### What capyrel must detect

```
User
├── hasMany          → Team          [teams.owner_id]
├── belongsToMany    → Team          [pivot: team_user]
├── hasMany          → Project       [projects.created_by]
├── hasMany          → Task          [tasks.assigned_to]
├── hasMany          → Task          [tasks.created_by]
├── hasMany          → Comment       [comments.user_id]
├── hasMany          → TimeLog       [time_logs.user_id]
└── hasManyThrough   → TimeLog       [User → Task → TimeLog]

Team
├── belongsTo        → User          [owner_id]
├── belongsToMany    → User          [pivot: team_user]
└── hasMany          → Project       [projects.team_id]

Project
├── belongsTo        → Team          [team_id]
├── belongsTo        → User          [created_by]
└── hasMany          → Task          [tasks.project_id]

Task
├── belongsTo        → Project       [project_id]
├── belongsTo        → User          [assigned_to]
├── belongsTo        → User          [created_by]
├── belongsTo        → Task          [parent_id]    ← self-referential
├── hasMany          → Task          [tasks.parent_id]  ← children
├── hasMany          → TimeLog       [time_logs.task_id]
└── morphMany        → Comment       [commentable]
   └── morphMany     → Attachment    [attachable]

Comment
├── morphTo          → (polymorphic) [commentable_type / commentable_id]
└── belongsTo        → User          [user_id]

Attachment
└── morphTo          → (polymorphic) [attachable_type / attachable_id]

TimeLog
├── belongsTo        → Task          [task_id]
└── belongsTo        → User          [user_id]
```

### Health check must flag

- `⚠ Project has deleted_at but no SoftDeletes trait`
- `⚠ Multiple FKs to users (assigned_to, created_by) — verify method names don't clash`
- `⚠ Task::parent() self-referential — guard against recursive eager loading`
- `⚠ Morph registry missing for Comment::commentable and Attachment::attachable`
- `ℹ User hasManyThrough TimeLog is a 3-level chain`

### Verification checklist

- [ ] Self-referential `Task belongsTo Task` (parent) detected
- [ ] Self-referential `Task hasMany Task` (children) detected
- [ ] TWO `belongsTo User` on Task detected (`assigned_to` and `created_by`)
- [ ] `User belongsToMany Team` AND `User hasMany Team` both detected (different FKs)
- [ ] `User hasManyThrough TimeLog` detected (via Task)
- [ ] Both morphTo (`commentable`, `attachable`) detected
- [ ] No naming conflicts between the two User→Task relationships
- [ ] `model:map --format=mermaid` produces a valid diagram with all models
- [ ] `migrate:safe --check` passes clean on these migrations
- [ ] `model:tests` generates 15+ test cases for User alone

---

## Relationship coverage summary

| Relationship type | Tested in project |
|---|---|
| `hasOne` | 1 (profile) · 3 (department head) |
| `hasMany` | 1 · 2 · 3 · 4 · 5 |
| `belongsTo` | 1 · 2 · 3 · 4 · 5 |
| `belongsToMany` | 1 (roles, tags) · 2 (products) · 3 (enrollments) · 5 (teams) |
| `hasManyThrough` | 2 (user→orders→items) · 3 (user→lesson→submission) · 5 (user→task→timelog) |
| `morphTo` | 4 (4 separate morphTo) · 5 (2 morphTo) |
| `morphMany` | 4 · 5 |
| Self-referential | 2 (category parent) · 5 (task parent) |
| Multiple FKs to same table | 3 (teacher_id) · 5 (assigned_to + created_by) |
| Pivot with extra columns | 1 (role_user.assigned_by) · 3 (enrollments.grade) · 5 (team_user.role) |
| Soft deletes | 1 (posts) · 2 (products) · 4 (posts, users) · 5 (projects) |

If all 5 pass, capyrel handles every real-world schema it will encounter in production.

---

## Quick test script

Run this after building each project to verify capyrel's output in seconds:

```bash
# Full dry-run — relationships + health check
php artisan model:scaffold --dry-run

# Map to see the full picture
php artisan model:map --format=both

# Preview what tests would be generated
php artisan model:tests --dry-run

# Preview what resources would be generated
php artisan model:resources --dry-run

# Check migrations are safe
php artisan migrate:safe --check
```
