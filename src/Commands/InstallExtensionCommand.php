<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;

class InstallExtensionCommand extends Command
{
    protected $signature   = 'capyrel:install-extension';
    protected $description = 'Install the Capyrel VS Code extension into your editor';

    public function handle(): int
    {
        $vsix = $this->findVsix();

        if (!$vsix) {
            $this->error('capyrel-1.0.0.vsix not found in the package directory.');
            $this->line('Run: cd vendor/julio/capyrel/vscode-capyrel && npm install && npm run compile && npx vsce package --no-dependencies');
            return self::FAILURE;
        }

        // Check code CLI is available
        exec('code --version 2>&1', $output, $code);
        if ($code !== 0) {
            $this->error('VS Code CLI (code) is not available in your PATH.');
            $this->line('Install it: open VS Code → Command Palette → "Shell Command: Install code command in PATH"');
            $this->line('');
            $this->line('Then manually install:');
            $this->line("  code --install-extension {$vsix}");
            return self::FAILURE;
        }

        $this->line('');
        $this->line('  <fg=cyan>Installing Capyrel VS Code extension...</>');

        exec("code --install-extension \"{$vsix}\" 2>&1", $out, $exitCode);

        if ($exitCode === 0) {
            $this->line('  <fg=green;options=bold>✔ Extension installed successfully.</>');
            $this->line('');
            $this->line('  <fg=white>How to use it:</>');
            $this->line('  <fg=gray>  1. Open VS Code in your Laravel project</>');
            $this->line('  <fg=gray>  2. Press Ctrl+Shift+P (Cmd+Shift+P on Mac)</>');
            $this->line('  <fg=gray>  3. Type "Capyrel" to see all commands</>');
            $this->line('  <fg=gray>  4. Look for "Capyrel Relationships" in the Explorer panel</>');
            $this->line('');
        } else {
            $this->error('Installation failed. Try manually:');
            $this->line("  code --install-extension {$vsix}");
        }

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function findVsix(): ?string
    {
        // Search for any .vsix in the package directory (future-proof for new versions)
        $searchDirs = [
            base_path('vendor/julio/capyrel/vscode-capyrel'),
            realpath(__DIR__ . '/../../vscode-capyrel'),
        ];

        foreach ($searchDirs as $dir) {
            if (!$dir || !is_dir($dir)) continue;

            // Find any .vsix file (matches capyrel-1.0.0.vsix, capyrel-1.1.0.vsix etc.)
            $files = glob("{$dir}/capyrel-*.vsix");
            if (!empty($files)) {
                // Return the latest version if multiple exist
                rsort($files);
                return realpath($files[0]);
            }
        }

        return null;
    }
}
