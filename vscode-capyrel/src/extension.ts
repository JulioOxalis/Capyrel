import * as vscode from 'vscode';
import * as cp from 'child_process';
import * as path from 'path';
import * as fs from 'fs';

// ── Helpers ───────────────────────────────────────────────────────────────────

function getRoot(): string | undefined {
    return vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
}

function isLaravel(root: string): boolean {
    return fs.existsSync(path.join(root, 'artisan'));
}

/** Run an artisan command in the shared "Capyrel" terminal. */
function run(command: string, terminalName = 'Capyrel'): void {
    const root = getRoot();
    if (!root) { vscode.window.showErrorMessage('No workspace folder open.'); return; }
    if (!isLaravel(root)) { vscode.window.showErrorMessage('No artisan file found. Open a Laravel project first.'); return; }

    let terminal = vscode.window.terminals.find(t => t.name === terminalName);
    if (!terminal) {
        terminal = vscode.window.createTerminal({ name: terminalName, cwd: root });
    }
    terminal.show();
    terminal.sendText(command);
}

/** Run a command and capture output (for sidebar refresh). */
function capture(command: string): Promise<string> {
    return new Promise((resolve, reject) => {
        const root = getRoot() ?? process.cwd();
        cp.exec(command, { cwd: root }, (err, stdout, stderr) => {
            if (err) reject(stderr || err.message);
            else resolve(stdout);
        });
    });
}

// ── Command handlers ──────────────────────────────────────────────────────────

async function scaffoldDryRun() { run('php artisan model:scaffold --dry-run'); }

async function scaffoldWrite() {
    const ok = await vscode.window.showWarningMessage(
        'Capyrel will write models, controllers, blade pages, and routes. Continue?',
        'Yes, scaffold', 'Cancel'
    );
    if (ok === 'Yes, scaffold') run('php artisan model:scaffold');
}

async function fullstack() {
    const ok = await vscode.window.showWarningMessage(
        'capyrel:fullstack runs all 12 scaffold steps. Continue?',
        'Yes, run fullstack', 'Cancel'
    );
    if (ok === 'Yes, run fullstack') run('php artisan capyrel:fullstack');
}

async function showMap()     { run('php artisan model:map'); }
async function mermaidMap()  {
    run('php artisan model:map --format=mermaid --save=docs/capyrel-schema.md');
    vscode.window.showInformationMessage('Diagram saved to docs/capyrel-schema.md');
}

async function genResources() {
    const model = await vscode.window.showInputBox({ prompt: 'Model name (blank = all)', placeHolder: 'User' });
    const mode  = await vscode.window.showQuickPick(['Preview (dry-run)', 'Write files']);
    if (!mode) return;
    run(`php artisan model:resources${model ? ' '+model : ''}${mode.includes('Preview') ? ' --dry-run' : ''}`);
}

async function genRequests() {
    const model = await vscode.window.showInputBox({ prompt: 'Model name (blank = all)', placeHolder: 'Post' });
    const mode  = await vscode.window.showQuickPick(['Preview (dry-run)', 'Write files']);
    if (!mode) return;
    run(`php artisan model:requests${model ? ' '+model : ''}${mode.includes('Preview') ? ' --dry-run' : ''}`);
}

async function genTests() {
    const model = await vscode.window.showInputBox({ prompt: 'Model name (blank = all)', placeHolder: 'User' });
    const mode  = await vscode.window.showQuickPick(['Preview (dry-run)', 'Write files']);
    if (!mode) return;
    run(`php artisan model:tests${model ? ' '+model : ''}${mode.includes('Preview') ? ' --dry-run' : ''}`);
}

async function genFactory() {
    const model = await vscode.window.showInputBox({ prompt: 'Model name (blank = all)', placeHolder: 'User' });
    const mode  = await vscode.window.showQuickPick(['Preview (dry-run)', 'Write files']);
    if (!mode) return;
    run(`php artisan model:factory${model ? ' '+model : ''}${mode.includes('Preview') ? ' --dry-run' : ''}`);
}

async function genPolicy() {
    const model = await vscode.window.showInputBox({ prompt: 'Model name (blank = all)', placeHolder: 'Post' });
    const mode  = await vscode.window.showQuickPick(['Preview (dry-run)', 'Write files']);
    if (!mode) return;
    run(`php artisan model:policy${model ? ' '+model : ''}${mode.includes('Preview') ? ' --dry-run' : ''}`);
}

async function genSeed() {
    const count = await vscode.window.showInputBox({ prompt: 'Records per model', placeHolder: '10', value: '10' });
    const mode  = await vscode.window.showQuickPick(['Preview (dry-run)', 'Write files']);
    if (!mode) return;
    run(`php artisan model:seed --count=${count || '10'}${mode.includes('Preview') ? ' --dry-run' : ''}`);
}

async function optimizeModels() {
    const model = await vscode.window.showInputBox({ prompt: 'Model name (blank = all)', placeHolder: 'User' });
    run(`php artisan model:optimize${model ? ' '+model : ''} --dry-run`);
}

async function genLivewire() {
    const model = await vscode.window.showInputBox({ prompt: 'Model name (blank = all)', placeHolder: 'User' });
    const mode  = await vscode.window.showQuickPick(['Preview (dry-run)', 'Write files']);
    if (!mode) return;
    run(`php artisan model:livewire${model ? ' '+model : ''}${mode.includes('Preview') ? ' --dry-run' : ''}`);
}

async function genEnum() {
    const model = await vscode.window.showInputBox({ prompt: 'Model name (blank = all)', placeHolder: 'Post' });
    run(`php artisan model:enum${model ? ' '+model : ''} --dry-run`);
}

async function genEvents() {
    const model = await vscode.window.showInputBox({ prompt: 'Model name (blank = all)', placeHolder: 'Post' });
    run(`php artisan model:events${model ? ' '+model : ''} --dry-run`);
}

async function migrateSafe() { run('php artisan migrate:safe --check'); }

async function runAudit()    { run('php artisan capyrel:audit'); }

async function cleanFiles() {
    const ok = await vscode.window.showWarningMessage(
        'capyrel:clean removes all generated controllers, blade views, and routes. Continue?',
        'Yes, clean', 'Cancel'
    );
    if (ok === 'Yes, clean') run('php artisan capyrel:clean');
}

async function startWatch()  { run('php artisan model:watch', 'Capyrel Watch'); }
async function runDemo()     { run('php artisan capyrel:demo'); }

// ── Relationship sidebar ──────────────────────────────────────────────────────

class RelItem extends vscode.TreeItem {
    constructor(
        label: string,
        collapsible: vscode.TreeItemCollapsibleState,
        description?: string,
        icon?: vscode.ThemeIcon
    ) {
        super(label, collapsible);
        if (description) this.description = description;
        if (icon) this.iconPath = icon;
    }
}

class RelProvider implements vscode.TreeDataProvider<RelItem> {
    private _change = new vscode.EventEmitter<RelItem | undefined>();
    readonly onDidChangeTreeData = this._change.event;
    private data: Record<string, {type:string; related:string}[]> = {};

    async refresh(): Promise<void> {
        try {
            const out = await capture('php artisan model:map 2>&1');
            this.data = this.parse(out);
        } catch { this.data = {}; }
        this._change.fire(undefined);
    }

    private parse(out: string): Record<string, {type:string; related:string}[]> {
        const r: Record<string, {type:string; related:string}[]> = {};
        let cur = '';
        for (const line of out.split('\n')) {
            const m = line.match(/^\s{2}([A-Z][A-Za-z]+)\s*$/);
            if (m) { cur = m[1]; r[cur] = []; continue; }
            if (cur) {
                const rel = line.match(/[├└]── (\w+)\s+──▶\s+(\w+)/);
                if (rel) r[cur].push({ type: rel[1].trim(), related: rel[2].trim() });
            }
        }
        return r;
    }

    getTreeItem(el: RelItem) { return el; }

    getChildren(el?: RelItem): RelItem[] {
        if (!el) {
            return Object.keys(this.data).map(m =>
                new RelItem(m, vscode.TreeItemCollapsibleState.Collapsed,
                    `${this.data[m].length} rel`, new vscode.ThemeIcon('symbol-class'))
            );
        }
        return (this.data[el.label as string] ?? []).map(r =>
            new RelItem(r.related || '(poly)', vscode.TreeItemCollapsibleState.None,
                r.type, new vscode.ThemeIcon(iconFor(r.type)))
        );
    }
}

function iconFor(t: string): string {
    return ({ hasOne:'arrow-right', hasMany:'list-tree', belongsTo:'arrow-left',
              belongsToMany:'git-merge', hasManyThrough:'type-hierarchy-sub',
              morphTo:'symbol-interface', morphMany:'symbol-interface' } as any)[t] ?? 'symbol-field';
}

// ── Activation ────────────────────────────────────────────────────────────────

export function activate(ctx: vscode.ExtensionContext): void {
    const provider = new RelProvider();
    vscode.window.registerTreeDataProvider('capyrelRelationships', provider);

    // Refresh sidebar when migrations change
    const watcher = vscode.workspace.createFileSystemWatcher('**/database/migrations/**/*.php');
    watcher.onDidChange(() => provider.refresh());
    watcher.onDidCreate(() => provider.refresh());
    ctx.subscriptions.push(watcher);

    // Register all commands
    const cmds: [string, () => any][] = [
        ['capyrel.scaffold',      scaffoldDryRun],
        ['capyrel.scaffoldWrite', scaffoldWrite],
        ['capyrel.fullstack',     fullstack],
        ['capyrel.map',           showMap],
        ['capyrel.mapMermaid',    mermaidMap],
        ['capyrel.resources',     genResources],
        ['capyrel.requests',      genRequests],
        ['capyrel.tests',         genTests],
        ['capyrel.factory',       genFactory],
        ['capyrel.policy',        genPolicy],
        ['capyrel.seed',          genSeed],
        ['capyrel.optimize',      optimizeModels],
        ['capyrel.livewire',      genLivewire],
        ['capyrel.enum',          genEnum],
        ['capyrel.events',        genEvents],
        ['capyrel.migrateSafe',   migrateSafe],
        ['capyrel.audit',         runAudit],
        ['capyrel.clean',         cleanFiles],
        ['capyrel.watch',         startWatch],
        ['capyrel.demo',          runDemo],
        ['capyrel.refresh',       () => provider.refresh()],
    ];

    for (const [cmd, handler] of cmds) {
        ctx.subscriptions.push(vscode.commands.registerCommand(cmd, handler));
    }

    provider.refresh();
}

export function deactivate(): void {}
