<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Outputs a compact schema of all Doctrine entities by parsing #[ORM\...] attributes.
 */
final class DbSchemaRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Output a compact schema of all Doctrine entities by parsing #[ORM\...] attributes';
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--json', 'description' => 'Output schema as JSON'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/claude/db-schema.php',
            'php scripts/claude/db-schema.php --json',
        ];
    }

    public function run(array $args): int
    {
        $jsonMode = in_array('--json', $args, true);
        $entityDir = $this->projectRoot . '/backend/src/Entity';

        if (!is_dir($entityDir)) {
            $this->console->fail("Directory not found: $entityDir");
        }

        $entities = [];

        foreach (glob($entityDir . '/*.php') as $file) {
            $content    = file_get_contents($file);
            $entityName = basename($file, '.php');

            $table = preg_match('/#\[ORM\\\\Table\s*\(\s*name:\s*[\'"]([^\'"]+)[\'"]/', $content, $m)
                ? $m[1]
                : strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $entityName));

            $columns   = [];
            $relations = [];
            $attrs     = [];

            foreach (explode("\n", $content) as $line) {
                $t = trim($line);

                if (preg_match('/#\[ORM\\\\(Column|Id|ManyToOne|OneToMany|ManyToMany|OneToOne)/', $t)) {
                    $attrs[] = $t;
                    continue;
                }

                if (!empty($attrs) && preg_match('/(?:private|public|protected)\s+(\??[\w\\\\]+(?:[|<>\[\] ,\\\\]+)?)\s+\$(\w+)/', $t, $pm)) {
                    $isPk = false;
                    $type = '';
                    $rels = [];

                    foreach ($attrs as $attr) {
                        if (str_contains($attr, 'ORM\\Id')) {
                            $isPk = true;
                        }

                        if (str_contains($attr, 'ORM\\Column')) {
                            if (preg_match('/type:\s*[\'"]([^\'"]+)[\'"]/', $attr, $tm)) {
                                $type = $tm[1];
                            } elseif (preg_match('/enumType:\s*([\w\\\\]+)::class/', $attr, $em)) {
                                $type = 'enum:' . $this->classBasenameSimple($em[1]);
                            } elseif (preg_match('/length:\s*(\d+)/', $attr, $lm)) {
                                $type = 'varchar(' . $lm[1] . ')';
                            } else {
                                $phpType = ltrim($pm[1], '?');
                                $type    = match (true) {
                                    $phpType === 'string'               => 'string',
                                    $phpType === 'int'                  => 'integer',
                                    $phpType === 'bool'                 => 'boolean',
                                    $phpType === 'float'                => 'float',
                                    $phpType === 'array'                => 'json',
                                    str_contains($phpType, 'DateTime') => 'datetime',
                                    default                            => $phpType,
                                };
                            }
                            $nullable        = str_contains($attr, 'nullable: true') || str_starts_with($pm[1], '?');
                            $columns[$pm[2]] = ($isPk ? 'PK ' : '') . ($nullable ? '?' : '') . $type;
                        }

                        if (preg_match('/ORM\\\\(ManyToOne|OneToMany|ManyToMany|OneToOne)\(targetEntity:\s*([\w\\\\]+)::class/', $attr, $rm)) {
                            $rels[] = $rm[1] . '(' . $this->classBasenameSimple($rm[2]) . ')';
                        }
                    }

                    if (!empty($rels)) {
                        $relations[] = implode(', ', $rels) . ' $' . $pm[2];
                    }

                    $attrs = [];
                    continue;
                }

                if (!empty($attrs) && !empty($t) && !str_starts_with($t, '#[') && !str_starts_with($t, '//') && !str_starts_with($t, '*')) {
                    $attrs = [];
                }
            }

            $entities[$entityName] = compact('table', 'columns', 'relations');
        }

        ksort($entities);

        if ($jsonMode) {
            echo json_encode($entities, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            return 0;
        }

        foreach ($entities as $name => $e) {
            echo "\n{$name} [{$e['table']}]\n";
            foreach ($e['columns'] as $col => $type) {
                $suffix = $type ? " ({$type})" : '';
                echo "  col: \${$col}{$suffix}\n";
            }
            foreach ($e['relations'] as $rel) {
                echo "  rel: {$rel}\n";
            }
        }
        echo "\n";

        return 0;
    }

    private function classBasenameSimple(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }
}
