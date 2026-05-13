import * as vscode from 'vscode';
import * as cp from 'child_process';
import * as path from 'path';
import * as fs from 'fs';

// ── Helpers ───────────────────────────────────────────────────────────────────

function getWorkspaceRoot(): string | undefined {
    return vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
}

function isLaravelProject(root: string): boolean {
    return fs.existsSync(path.join(root, 'artisan'));
}

function runInTerminal(command: string, terminalName: string = 'Capyrel'): void {
    const root = getWorkspaceRoot();
    if (!root) {
        vscode.window.showErrorMessage('No workspace folder open.');
        return;
    }
    if (!isLaravelProject(root)) {
        vscode.window.showErrorMessage('No Laravel artisan file found. Open a Laravel project first.');
        return;
    }

    // Reuse existing Capyrel terminal if open
    let terminal = vscode.window.terminals.find(t => t.name === terminalName);
    if (!terminal) {
        terminal = vscode.window.createTerminal({
            name: terminalName,
            cwd: root,
        });
    }

    terminal.show();
    terminal.sendText(command);
}

function runAndCapture(command: string): Promise<string> {
    const root = getWorkspaceRoot() ?? process.cwd();
    return new Promise((resolve, reject) => {
        cp.exec(command, { cwd: root }, (err, stdout, stderr) => {
            if (err) reject(stderr || err.message);
            else resolve(stdout);
        });
    });
}

// ── Commands ──────────────────────────────────────────────────────────────────

async function scaffoldDryRun(): Promise<void> {
    runInTerminal('php artisan model:scaffold --dry-run');
}

async function scaffoldWrite(): Promise<void> {
    const choice = await vscode.window.showWarningMessage(
        'Capyrel will write to model files, generate controllers, blade pages, and routes. Continue?',
        'Yes, scaffold',
        'Cancel'
    );
    if (choice === 'Yes, scaffold') {
        runInTerminal('php artisan model:scaffold');
    }
}

async function showMap(): Promise<void> {
    runInTerminal('php artisan model:map');
}

async function exportMermaid(): Promise<void> {
    const root = getWorkspaceRoot();
    if (!root) return;

    try {
        vscode.window.showInformationMessage('Generating Mermaid diagram…');
        const output = await runAndCapture('php artisan model:map --format=mermaid --save=docs/capyrel-schema.md');

        const docPath = path.join(root, 'docs', 'capyrel-schema.md');
        if (fs.existsSync(docPath)) {
            const doc = await vscode.workspace.openTextDocument(docPath);
            await vscode.window.showTextDocument(doc);
            vscode.window.showInformationMessage('Mermaid diagram saved to docs/capyrel-schema.md');
        } else {
            vscode.window.showInformationMessage('Mermaid diagram generated. Check terminal output.');
            runInTerminal('php artisan model:map --format=mermaid');
        }
    } catch (e) {
        runInTerminal('php artisan model:map --format=mermaid');
    }
}

async function generateResources(): Promise<void> {
    const model = await vscode.window.showInputBox({
        prompt: 'Model name (leave blank for all)',
        placeHolder: 'User  — or leave empty for all models',
    });
    const cmd = model ? `php artisan model:resources ${model} --dry-run` : 'php artisan model:resources --dry-run';
    const action = await vscode.window.showQuickPick(['Preview (dry-run)', 'Write files'], {
        placeHolder: 'Preview first or write immediately?',
    });
    if (!action) return;
    const flag = action === 'Write files' ? '' : ' --dry-run';
    runInTerminal(`php artisan model:resources${model ? ' ' + model : ''}${flag}`);
}

async function generateRequests(): Promise<void> {
    const model = await vscode.window.showInputBox({
        prompt: 'Model name (leave blank for all)',
        placeHolder: 'Post  — or leave empty for all models',
    });
    const action = await vscode.window.showQuickPick(['Preview (dry-run)', 'Write files'], {
        placeHolder: 'Preview first or write immediately?',
    });
    if (!action) return;
    const flag = action === 'Write files' ? '' : ' --dry-run';
    runInTerminal(`php artisan model:requests${model ? ' ' + model : ''}${flag}`);
}

async function generateTests(): Promise<void> {
    const model = await vscode.window.showInputBox({
        prompt: 'Model name (leave blank for all)',
        placeHolder: 'User  — or leave empty for all models',
    });
    const action = await vscode.window.showQuickPick(['Preview (dry-run)', 'Write files'], {
        placeHolder: 'Preview first or write immediately?',
    });
    if (!action) return;
    const flag = action === 'Write files' ? '' : ' --dry-run';
    runInTerminal(`php artisan model:tests${model ? ' ' + model : ''}${flag}`);
}

async function migrateSafe(): Promise<void> {
    runInTerminal('php artisan migrate:safe --check');
}

async function startWatch(): Promise<void> {
    runInTerminal('php artisan model:watch', 'Capyrel Watch');
}

async function runDemo(): Promise<void> {
    runInTerminal('php artisan capyrel:demo');
}

// ── Relationship tree view ────────────────────────────────────────────────────

class RelationshipItem extends vscode.TreeItem {
    constructor(
        public readonly label: string,
        public readonly collapsibleState: vscode.TreeItemCollapsibleState,
        public readonly description?: string,
        public readonly iconPath?: vscode.ThemeIcon,
    ) {
        super(label, collapsibleState);
        if (description) this.description = description;
        if (iconPath) this.iconPath = iconPath;
    }
}

class RelationshipProvider implements vscode.TreeDataProvider<RelationshipItem> {
    private _onDidChangeTreeData = new vscode.EventEmitter<RelationshipItem | undefined>();
    readonly onDidChangeTreeData = this._onDidChangeTreeData.event;

    private data: Record<string, Array<{ type: string; related: string; via: string }>> = {};

    async refresh(): Promise<void> {
        try {
            const raw = await runAndCapture('php artisan model:map 2>/dev/null');
            this.data = this.parse(raw);
        } catch {
            this.data = {};
        }
        this._onDidChangeTreeData.fire(undefined);
    }

    private parse(output: string): Record<string, any[]> {
        const result: Record<string, any[]> = {};
        let current = '';

        for (const line of output.split('\n')) {
            const modelMatch = line.match(/^\s{2}([A-Z][A-Za-z]+)\s*$/);
            if (modelMatch) {
                current = modelMatch[1];
                result[current] = [];
                continue;
            }
            if (current) {
                const relMatch = line.match(/[├└]── (\w+)\s+──▶\s+(\w+)(?:\s+\(via ([^)]+)\))?/);
                if (relMatch) {
                    result[current].push({
                        type: relMatch[1].trim(),
                        related: relMatch[2].trim(),
                        via: relMatch[3] ?? '',
                    });
                }
            }
        }

        return result;
    }

    getTreeItem(element: RelationshipItem): vscode.TreeItem {
        return element;
    }

    getChildren(element?: RelationshipItem): RelationshipItem[] {
        if (!element) {
            return Object.keys(this.data).map(model =>
                new RelationshipItem(
                    model,
                    vscode.TreeItemCollapsibleState.Collapsed,
                    `${this.data[model].length} relationship(s)`,
                    new vscode.ThemeIcon('symbol-class')
                )
            );
        }

        const rels = this.data[element.label as string] ?? [];
        return rels.map(r =>
            new RelationshipItem(
                r.related || '(polymorphic)',
                vscode.TreeItemCollapsibleState.None,
                r.type + (r.via ? ` — ${r.via}` : ''),
                new vscode.ThemeIcon(this.iconFor(r.type))
            )
        );
    }

    private iconFor(type: string): string {
        const map: Record<string, string> = {
            hasOne: 'arrow-right',
            hasMany: 'list-tree',
            belongsTo: 'arrow-left',
            belongsToMany: 'git-merge',
            hasManyThrough: 'type-hierarchy-sub',
            morphTo: 'symbol-interface',
            morphMany: 'symbol-interface',
        };
        return map[type] ?? 'symbol-field';
    }
}

// ── Activation ────────────────────────────────────────────────────────────────

export function activate(context: vscode.ExtensionContext): void {
    const provider = new RelationshipProvider();

    vscode.window.registerTreeDataProvider('capyrelRelationships', provider);

    // Auto-refresh when PHP files change
    const watcher = vscode.workspace.createFileSystemWatcher('**/database/migrations/**/*.php');
    watcher.onDidChange(() => provider.refresh());
    watcher.onDidCreate(() => provider.refresh());

    context.subscriptions.push(
        watcher,
        vscode.commands.registerCommand('capyrel.scaffold',      scaffoldDryRun),
        vscode.commands.registerCommand('capyrel.scaffoldWrite',  scaffoldWrite),
        vscode.commands.registerCommand('capyrel.map',            showMap),
        vscode.commands.registerCommand('capyrel.mapMermaid',     exportMermaid),
        vscode.commands.registerCommand('capyrel.resources',      generateResources),
        vscode.commands.registerCommand('capyrel.requests',       generateRequests),
        vscode.commands.registerCommand('capyrel.tests',          generateTests),
        vscode.commands.registerCommand('capyrel.migrateSafe',    migrateSafe),
        vscode.commands.registerCommand('capyrel.watch',          startWatch),
        vscode.commands.registerCommand('capyrel.demo',           runDemo),
        vscode.commands.registerCommand('capyrel.refresh', () => provider.refresh()),
    );

    // Initial load
    provider.refresh();

    vscode.window.showInformationMessage('Capyrel is ready. Open Command Palette → type "Capyrel" to get started.');
}

export function deactivate(): void {}
