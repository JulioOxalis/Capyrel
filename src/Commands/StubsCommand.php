<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;

/**
 * Publishes capyrel generator stubs to stubs/capyrel/ so developers
 * can customise controller and modal output per-project.
 *
 * Published stubs use {{Placeholder}} tokens that are replaced at generation time.
 * Edit the stubs and re-run model:scaffold to apply your customisations.
 */
class StubsCommand extends Command
{
    protected $signature  = 'capyrel:stubs {--force : Overwrite existing stubs}';
    protected $description = 'Publish capyrel generator stubs to stubs/capyrel/';

    /** Token reference used in stubs */
    private array $tokens = [
        '{{ModelName}}'      => 'Studly class name  — e.g. BlogPost',
        '{{modelName}}'      => 'camelCase variable  — e.g. blogPost',
        '{{modelNames}}'     => 'camelCase plural    — e.g. blogPosts',
        '{{route}}'          => 'kebab-case route    — e.g. blog-posts',
        '{{title}}'          => 'Headline plural     — e.g. Blog Posts',
        '{{eagerLoad}}'      => 'Eager-load string   — e.g. \'comments\', \'tags\'',
        '{{storeRules}}'     => 'Validation rules block for store()',
        '{{updateRules}}'    => 'Validation rules block for update()',
        '{{storeUpload}}'    => 'File upload block for store()',
        '{{updateUpload}}'   => 'File upload block for update()',
        '{{fileCleanup}}'    => 'Storage::delete() calls for destroy()',
        '{{relatedLoads}}'   => 'Related model ->all() loads for index()',
        '{{compactExtra}}'   => 'Extra compact() vars for index view',
    ];

    private array $stubs = [
        'controller.stub' => <<<'STUB'
<?php

namespace App\Http\Controllers;

use App\Models\{{ModelName}};
use Illuminate\Http\Request;

class {{ModelName}}Controller extends Controller
{
    public function index(Request $request)
    {
        $query = {{ModelName}}::with([{{eagerLoad}}]);
{{searchBlock}}
        ${{modelNames}} = $query->latest()->paginate(15);
{{relatedLoads}}
        if ($request->wantsJson()) {
            return response()->json(${{modelNames}});
        }
        return view('{{route}}.index', compact('{{modelNames}}'{{compactExtra}}));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
{{storeRules}}
        ]);
{{storeUpload}}
        ${{modelName}} = {{ModelName}}::create($validated);

        if ($request->wantsJson()) {
            return response()->json(['message' => '{{ModelName}} created successfully.', 'data' => ${{modelName}}], 201);
        }
        return redirect()->route('{{route}}.index')
            ->with('success', '{{ModelName}} created successfully.');
    }

    public function show({{ModelName}} ${{modelName}}, Request $request)
    {
        ${{modelName}}->load([{{eagerLoad}}]);

        if ($request->wantsJson()) {
            return response()->json(${{modelName}});
        }
        return view('{{route}}.show', compact('{{modelName}}'));
    }

    public function update(Request $request, {{ModelName}} ${{modelName}})
    {
        $validated = $request->validate([
{{updateRules}}
        ]);
{{updateUpload}}
        ${{modelName}}->update($validated);

        if ($request->wantsJson()) {
            return response()->json(['message' => '{{ModelName}} updated successfully.', 'data' => ${{modelName}}->fresh()]);
        }
        return redirect()->route('{{route}}.index')
            ->with('success', '{{ModelName}} updated successfully.');
    }

    public function destroy({{ModelName}} ${{modelName}})
    {
{{fileCleanup}}
        ${{modelName}}->delete();

        if (request()->wantsJson()) {
            return response()->json(['message' => '{{ModelName}} deleted.']);
        }
        return redirect()->route('{{route}}.index')
            ->with('success', '{{ModelName}} deleted.');
    }
}
STUB,

        'modal-tw.stub' => <<<'STUB'
{{-- capyrel: Tailwind modal stub — customise and run model:scaffold --}}
<div x-data="{
         open: false, loading: false, errors: {},
         async submit(event) {
             event.preventDefault(); this.loading = true; this.errors = {};
             try {
                 const res = await fetch(event.target.action, { method: 'POST', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(event.target) });
                 const data = await res.json();
                 if (res.ok) { this.open = false; event.target.reset(); window.dispatchEvent(new CustomEvent('capyrel-toast', { detail: data.message })); setTimeout(() => window.location.reload(), 800); }
                 else if (res.status === 422) { this.errors = data.errors ?? {}; }
                 else { window.dispatchEvent(new CustomEvent('capyrel-toast', { detail: { type: 'error', message: data.message } })); }
             } finally { this.loading = false; }
         }
     }"
     @open-create.window="open = true"
     @keydown.escape.window="open = false">
    {{-- Add your custom modal HTML here --}}
</div>
STUB,

        'modal-bs.stub' => <<<'STUB'
{{-- capyrel: Bootstrap offcanvas stub — customise and run model:scaffold --}}
<div class="offcanvas offcanvas-end" tabindex="-1" id="createOffcanvas" style="width:480px">
    <div class="offcanvas-header border-bottom py-4">
        <h5 class="offcanvas-title fw-bold mb-0">New {{ModelName}}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
        {{-- Add your custom offcanvas HTML here --}}
    </div>
</div>
STUB,
    ];

    public function handle(): int
    {
        $stubDir = base_path('stubs/capyrel');

        if (!is_dir($stubDir)) {
            mkdir($stubDir, 0755, true);
            $this->line("  <fg=green>✔</> Created directory <fg=white>stubs/capyrel/</>");
        }

        $published = 0;
        $skipped   = 0;

        foreach ($this->stubs as $filename => $content) {
            $path = "{$stubDir}/{$filename}";

            if (file_exists($path) && !$this->option('force')) {
                $this->line("  <fg=gray>~</> {$filename} already exists — use --force to overwrite");
                $skipped++;
                continue;
            }

            file_put_contents($path, $content);
            $this->line("  <fg=green>✔</> Published <fg=white>stubs/capyrel/{$filename}</>");
            $published++;
        }

        $this->line('');
        $this->line("  <fg=green;options=bold>Done.</> {$published} stub(s) published · {$skipped} skipped");
        $this->line('');
        $this->line('  <fg=yellow>Available tokens:</>');
        foreach ($this->tokens as $token => $desc) {
            $this->line("  <fg=gray>" . str_pad($token, 22) . "</> {$desc}");
        }
        $this->line('');
        $this->line('  Edit the stubs then re-run <fg=white>php artisan model:scaffold</> to apply.');
        $this->line('');

        return self::SUCCESS;
    }
}
