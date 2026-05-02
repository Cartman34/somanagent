<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Local code refactoring tools for backend and scripts source files.
 */
final class CodeRefactoRunner extends AbstractScriptRunner
{
    public const NAME = 'code-refacto';

    protected function getName(): string
    {
        return self::NAME;
    }

    private const COMMAND_FIX_INLINE_PHPDOC = 'fix-inline-phpdoc';
    private const COMMAND_ADD_MISSING_ARRAY_TYPES = 'add-missing-array-types';
    private const COMMAND_STRIP_WHAT_COMMENTS = 'strip-what-comments';

    private const SCOPE_BACKEND = 'backend';
    private const SCOPE_SCRIPTS = 'scripts';

    /** @var array<string, array{directories: list<string>}> */
    private const SCOPES = [
        self::SCOPE_BACKEND => ['directories' => ['backend/src']],
        self::SCOPE_SCRIPTS => ['directories' => ['scripts/src']],
    ];

    protected function getDescription(): string
    {
        return 'Local code refactoring tools for backend and scripts source files.';
    }

    protected function getCommands(): array
    {
        return [
            ['name' => self::COMMAND_FIX_INLINE_PHPDOC, 'description' => 'Fix formatting of inline PHPDoc @var blocks'],
            ['name' => self::COMMAND_ADD_MISSING_ARRAY_TYPES, 'description' => 'Add missing array types with TODO for specific typing'],
            ['name' => self::COMMAND_STRIP_WHAT_COMMENTS, 'description' => 'Strip PHPDoc comments that paraphrase the method name'],
        ];
    }

    protected function getOptions(): array
    {
        return array_merge(parent::getExecutionModeOptions(), [
            ['name' => '--todo', 'description' => 'Used with add-missing-array-types to inject TODO comments'],
            ['name' => '--scope', 'description' => 'Process one scope; repeat --scope=backend --scope=scripts for multiple scopes'],
        ]);
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/code-refacto.php fix-inline-phpdoc --dry-run',
            'php scripts/code-refacto.php add-missing-array-types --todo --verbose',
            'php scripts/code-refacto.php strip-what-comments --scope=scripts',
        ];
    }

    /**
     * Executes the refactoring command.
     *
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        if ($args === []) {
            $this->printHelp();

            return 0;
        }

        $command = $args[0];
        $remainingArgs = array_values(array_slice($args, 1));
        $options = $this->parseOptions($remainingArgs);
        $this->configureExecutionModes($options);

        $files = $this->getSourceFiles($options);

        switch ($command) {
            case self::COMMAND_FIX_INLINE_PHPDOC:
                return $this->fixInlinePhpDoc($files);
            case self::COMMAND_ADD_MISSING_ARRAY_TYPES:
                return $this->addMissingArrayTypes($files, isset($options['todo']));
            case self::COMMAND_STRIP_WHAT_COMMENTS:
                return $this->stripWhatComments($files);
            default:
                $this->console->fail(sprintf('Unknown command: %s', $command));
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, string|true|list<string>>
     */
    private function parseOptions(array &$args): array
    {
        $options = [];
        $remainingArgs = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $option = substr($arg, 2);
                if (str_contains($option, '=')) {
                    [$key, $val] = explode('=', $option, 2);
                    if ($key === 'scope') {
                        $scopes = $options[$key] ?? [];
                        $options[$key] = array_merge(is_array($scopes) ? $scopes : [], [$val]);
                    } else {
                        $options[$key] = $val;
                    }
                } else {
                    $options[$option] = true;
                }
            } else {
                $remainingArgs[] = $arg;
            }
        }
        $args = $remainingArgs;

        return $options;
    }

    /**
     * @param array<string, string|true|list<string>> $options
     *
     * @return list<string>
     */
    private function getSourceFiles(array $options): array
    {
        $directories = [];
        foreach ($this->resolveScopes($options) as $scope) {
            foreach (self::SCOPES[$scope]['directories'] as $directory) {
                $directories[] = $this->projectRoot . '/' . $directory;
            }
        }

        $files = [];
        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * @param array<string, string|true|list<string>> $options
     * @return list<string>
     */
    private function resolveScopes(array $options): array
    {
        $scopeOption = $options['scope'] ?? null;
        $scopeOptions = is_array($scopeOption) ? $scopeOption : (is_string($scopeOption) ? [$scopeOption] : []);

        $scopes = $scopeOptions;
        if ($scopes === []) {
            $scopes = array_keys(self::SCOPES);
        }

        foreach ($scopes as $scope) {
            if (!isset(self::SCOPES[$scope])) {
                throw new \RuntimeException(sprintf('Unknown scope "%s". Available scopes: %s.', $scope, implode(', ', array_keys(self::SCOPES))));
            }
        }

        return array_values(array_unique($scopes));
    }

    /**
     * @param list<string> $files
     */
    private function fixInlinePhpDoc(array $files): int
    {
        $this->console->info('Fixing inline PHPDoc...');
        $count = 0;
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                throw new \RuntimeException(sprintf('Unable to read file: %s', $file));
            }
            $newContent = $content;

            // Fix /* @var to /** @var
            $fixed = str_replace('/* @var', '/** @var', $newContent);
            if ($fixed !== $newContent) {
                $count += substr_count($newContent, '/* @var');
                $newContent = $fixed;
            }

            // Fix single-line /** @tag … */ to multi-line
            $fixed = preg_replace_callback(
                '/^([ \t]*)\/\*\* (@(?:return|param|var|throws)[^*\n]+?) \*\/$/m',
                function (array $m) use (&$count): string {
                    $count++;
                    return $m[1] . "/**\n" . $m[1] . " * " . rtrim($m[2]) . "\n" . $m[1] . " */";
                },
                $newContent
            );
            if ($fixed !== null && $fixed !== $newContent) {
                $newContent = $fixed;
            }

            if ($newContent !== $content) {
                $this->updateFile($file, $content, $newContent);
            }
        }
        $this->console->ok(sprintf('Fixed %d inline PHPDoc blocks.', $count));

        return 0;
    }

    /**
     * @param list<string> $files
     */
    private function addMissingArrayTypes(array $files, bool $todo): int
    {
        $this->console->info('Adding missing array types...');
        $count = 0;
        foreach ($files as $file) {
            $lines = file($file);
            if ($lines === false) {
                throw new \RuntimeException(sprintf('Unable to read file: %s', $file));
            }
            $newLines = $lines;
            $changed = false;

            for ($i = 0; $i < count($lines); $i++) {
                $line = $lines[$i];
                // Match method signature returning array
                if (preg_match('/^(?P<indent>[ \t]+)(?P<signature>(?:public|protected|private)\s+function\s+\w+\s*\(.*?\)\s*:\s*array)/', $line, $matches)) {
                    $indent = $matches['indent'];
                    // Look for docblock just above
                    $docStart = -1;
                    $docEnd = -1;
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prevLine = trim($lines[$j]);
                        if ($prevLine === '') {
                            continue;
                        }
                        if ($prevLine === '*/') {
                            $docEnd = $j;
                            for ($k = $j - 1; $k >= 0; $k--) {
                                if (str_starts_with(trim($lines[$k]), '/**')) {
                                    $docStart = $k;
                                    break;
                                }
                            }
                        }
                        break;
                    }

                    $returnTag = $todo ? '@return array<string, mixed> // TODO: specify type' : '@return array<string, mixed>';

                    if ($docStart === -1) {
                        // No docblock, inject one
                        array_splice($newLines, $i + (count($newLines) - count($lines)), 0, [
                            $indent . "/**\n",
                            $indent . " * " . $returnTag . "\n",
                            $indent . " */\n"
                        ]);
                        $changed = true;
                        $count++;
                    } else {
                        // Check if already has @return
                        $hasReturn = false;
                        for ($l = $docStart; $l <= $docEnd; $l++) {
                            if (str_contains($lines[$l], '@return')) {
                                $hasReturn = true;
                                break;
                            }
                        }
                        if (!$hasReturn) {
                            // Inject into docblock
                            array_splice($newLines, $docEnd + (count($newLines) - count($lines)), 0, [
                                $indent . " * " . $returnTag . "\n"
                            ]);
                            $changed = true;
                            $count++;
                        }
                    }
                }
            }

            if ($changed) {
                $this->updateFile($file, implode('', $lines), implode('', $newLines));
            }
        }
        $this->console->ok(sprintf('Added @return to %d method(s).', $count));

        return 0;
    }

    /**
     * @param list<string> $files
     */
    private function stripWhatComments(array $files): int
    {
        $this->console->info('Stripping what-comments...');
        $count = 0;
        $weakComments = [
            'Initializes the object.',
            'Constructor.',
            'Getter.',
            'Setter.',
            'Converts to array.',
        ];
        
        foreach ($files as $file) {
            $lines = file($file);
            if ($lines === false) {
                throw new \RuntimeException(sprintf('Unable to read file: %s', $file));
            }
            $newLines = $lines;
            $changed = false;

            for ($i = 0; $i < count($lines); $i++) {
                if (preg_match('/^(?P<indent>[ \t]+)(?:public|protected|private)\s+function\s+\w+/', $lines[$i])) {
                    // Look for docblock just above
                    $docStart = -1;
                    $docEnd = -1;
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prevLine = trim($lines[$j]);
                        if ($prevLine === '') {
                            continue;
                        }
                        if ($prevLine === '*/') {
                            $docEnd = $j;
                            for ($k = $j - 1; $k >= 0; $k--) {
                                if (str_starts_with(trim($lines[$k]), '/**')) {
                                    $docStart = $k;
                                    break;
                                }
                            }
                        }
                        break;
                    }

                    if ($docStart !== -1) {
                        // Check if it's a weak comment (only one line of content)
                        if ($docEnd - $docStart === 2) {
                            $commentLine = trim(str_replace('*', '', $lines[$docStart + 1]));
                            $isWeak = false;
                            foreach ($weakComments as $weak) {
                                if (stripos($commentLine, $weak) === 0) {
                                    $isWeak = true;
                                    break;
                                }
                            }
                            if ($isWeak) {
                                // Remove the docblock
                                array_splice($newLines, $docStart + (count($newLines) - count($lines)), $docEnd - $docStart + 1);
                                $changed = true;
                                $count++;
                            }
                        }
                    }
                }
            }

            if ($changed) {
                $this->updateFile($file, implode('', $lines), implode('', $newLines));
            }
        }
        $this->console->ok(sprintf('Stripped %d what-comments.', $count));

        return 0;
    }

    private function updateFile(string $file, string $oldContent, string $newContent): void
    {
        if ($this->verbose) {
            $relativePath = str_replace($this->projectRoot . '/', '', $file);
            $this->console->info(sprintf('File: %s', $relativePath));

            $tmpOld = tempnam(sys_get_temp_dir(), 'somanagent_old_');
            $tmpNew = tempnam(sys_get_temp_dir(), 'somanagent_new_');
            if ($tmpOld !== false && $tmpNew !== false) {
                file_put_contents($tmpOld, $oldContent);
                file_put_contents($tmpNew, $newContent);

                $output = [];
                exec(sprintf('diff -u --label a/%1$s --label b/%1$s %2$s %3$s', escapeshellarg($relativePath), escapeshellarg($tmpOld), escapeshellarg($tmpNew)), $output);

                foreach ($output as $line) {
                    $this->console->line($line);
                }

                unlink($tmpOld);
                unlink($tmpNew);
            }
        }

        if ($this->dryRun) {
            return;
        }

        file_put_contents($file, $newContent);
    }
}
