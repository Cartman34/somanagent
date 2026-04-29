<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Local code refactoring tools for backend source files.
 */
final class CodeRefactoRunner extends AbstractScriptRunner
{
    private const COMMAND_FIX_INLINE_PHPDOC = 'fix-inline-phpdoc';
    private const COMMAND_ADD_MISSING_ARRAY_TYPES = 'add-missing-array-types';
    private const COMMAND_STRIP_WHAT_COMMENTS = 'strip-what-comments';

    protected function getDescription(): string
    {
        return 'Local code refactoring tools for backend source files.';
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
        ]);
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/code-refacto.php fix-inline-phpdoc --dry-run',
            'php scripts/code-refacto.php add-missing-array-types --todo --verbose',
            'php scripts/code-refacto.php strip-what-comments',
        ];
    }

    public function run(array $args): int
    {
        if ($args === []) {
            $this->printHelp();

            return 0;
        }

        $command = array_shift($args);
        $options = $this->parseOptions($args);
        $this->configureExecutionModes($options);

        $files = $this->getBackendFiles();

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

        return 0;
    }

    private function parseOptions(array &$args): array
    {
        $options = [];
        $remainingArgs = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $option = substr($arg, 2);
                if (str_contains($option, '=')) {
                    [$key, $val] = explode('=', $option, 2);
                    $options[$key] = $val;
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
     * @return list<string>
     */
    private function getBackendFiles(): array
    {
        $directory = $this->projectRoot . '/backend/src';
        if (!is_dir($directory)) {
            return [];
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function fixInlinePhpDoc(array $files): int
    {
        $this->console->info('Fixing inline PHPDoc...');
        $count = 0;
        foreach ($files as $file) {
            $content = file_get_contents($file);
            // Fix /* @var to /** @var
            $newContent = str_replace('/* @var', '/** @var', $content);
            if ($newContent !== $content) {
                // Count occurrences
                $count += (strlen($content) - strlen($newContent)) / (strlen('/* @var') - strlen('/** @var'));
                $this->updateFile($file, $content, $newContent);
            }
        }
        $this->console->ok(sprintf('Fixed %d inline PHPDoc blocks.', (int) abs($count)));

        return 0;
    }

    private function addMissingArrayTypes(array $files, bool $todo): int
    {
        $this->console->info('Adding missing array types...');
        $count = 0;
        foreach ($files as $file) {
            $lines = file($file);
            $newLines = $lines;
            $changed = false;

            for ($i = 0; $i < count($lines); $i++) {
                $line = $lines[$i];
                // Match method signature returning array
                if (preg_match('/^(?P<indent>[ \t]+)(?P<signature>(?:public|protected|private)\s+function\s+\w+\s*\(.*?\)\s*:\s*array)/', $line, $matches)) {
                    $indent = $matches['indent'];
                    $signature = $matches['signature'];

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
        $this->console->ok(sprintf('Updated %d files with missing array types.', $count));

        return 0;
    }

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
        if ($this->dryRun) {
            $relativePath = str_replace($this->projectRoot . '/', '', $file);
            if ($this->verbose) {
                $this->console->info(sprintf('File: %s', $relativePath));
            }
            return;
        }

        file_put_contents($file, $newContent);
    }
}
