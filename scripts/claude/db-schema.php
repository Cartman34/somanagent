<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

/**
 * Outputs a compact schema of all Doctrine entities.
 *
 * Usage: php scripts/claude/db-schema.php [--json]
 *
 * Parses #[ORM\...] attributes without booting Symfony or the database.
 */

$jsonMode  = in_array('--json', $argv ?? [], true);
$root      = realpath(__DIR__ . '/../../');
$entityDir = $root . '/backend/src/Entity';

if (!is_dir($entityDir)) {
    fwrite(STDERR, "Directory not found: $entityDir\n");
    exit(1);
}

$entities = [];

foreach (glob($entityDir . '/*.php') as $file) {
    $content    = file_get_contents($file);
    $entityName = basename($file, '.php');

    // Table name from ORM\Table or Doctrine snake_case convention
    $table = preg_match('/#\[ORM\\\\Table\s*\(\s*name:\s*[\'"]([^\'"]+)[\'"]/', $content, $m)
        ? $m[1]
        : strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $entityName));

    $columns   = []; // ['propName' => 'type string']
    $relations = []; // ['ManyToOne(Target) $prop']
    $attrs     = []; // pending #[ORM\...] attribute lines

    foreach (explode("\n", $content) as $line) {
        $t = trim($line);

        // Collect recognised ORM attribute lines
        if (preg_match('/#\[ORM\\\\(Column|Id|ManyToOne|OneToMany|ManyToMany|OneToOne)/', $t)) {
            $attrs[] = $t;
            continue;
        }

        // Property declaration → process accumulated attributes
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
                        $type = 'enum:' . class_basename_simple($em[1]);
                    } elseif (preg_match('/length:\s*(\d+)/', $attr, $lm)) {
                        $type = 'varchar(' . $lm[1] . ')';
                    } else {
                        // Infer from PHP type hint
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
                    $rels[] = $rm[1] . '(' . class_basename_simple($rm[2]) . ')';
                }
            }

            if (!empty($rels)) {
                $relations[] = implode(', ', $rels) . ' $' . $pm[2];
            }

            $attrs = [];
            continue;
        }

        // Reset pending attrs on any significant non-attribute line
        if (!empty($attrs) && !empty($t) && !str_starts_with($t, '#[') && !str_starts_with($t, '//') && !str_starts_with($t, '*')) {
            $attrs = [];
        }
    }

    $entities[$entityName] = compact('table', 'columns', 'relations');
}

ksort($entities);

// ── Output ───────────────────────────────────────────────────────────────────

if ($jsonMode) {
    echo json_encode($entities, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
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

// ── Helpers ───────────────────────────────────────────────────────────────────

function class_basename_simple(string $fqcn): string
{
    $parts = explode('\\', $fqcn);
    return end($parts);
}
