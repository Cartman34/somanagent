<?php
/**
 * Generic Static Analysis Script for Refactoring.
 * 
 * Usage: php scripts/analyze-code.php --files=local/tmp/filelist.json --rules=local/tmp/checklist.json --report=local/tmp/report.json
 * 
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
declare(strict_types=1);

$options = getopt('', ['files:', 'rules:', 'report:']);

if (!isset($options['files']) || !isset($options['rules']) || !isset($options['report'])) {
    echo "Usage: php scripts/analyze-code.php --files=<filelist.json> --rules=<checklist.json> --report=<report.json>\n";
    exit(1);
}

$filesPath = (string) $options['files'];
$rulesPath = (string) $options['rules'];
$reportPath = (string) $options['report'];

if (!file_exists($filesPath)) {
    echo "Error: Filelist not found: $filesPath\n";
    exit(1);
}
if (!file_exists($rulesPath)) {
    echo "Error: Rules file not found: $rulesPath\n";
    exit(1);
}

$files = json_decode(file_get_contents($filesPath), true);
$rules = json_decode(file_get_contents($rulesPath), true);

if (!is_array($files) || !is_array($rules)) {
    echo "Error: Invalid JSON format in configuration files.\n";
    exit(1);
}

$report = [];
$totalIssues = 0;
$scannedFiles = 0;

foreach ($files as $file) {
    if (!file_exists($file)) {
        echo "Warning: File not found: $file\n";
        continue;
    }

    $scannedFiles++;
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        continue;
    }

    foreach ($lines as $lineNumber => $lineContent) {
        $displayLineNumber = $lineNumber + 1; 

        foreach ($rules as $ruleKey => $pattern) {
            // Build full regex with delimiters
            $regex = '/' . $pattern . '/';
            
            try {
                if (preg_match($regex, $lineContent, $matches) === 1) {
                    if (!isset($report[$file])) {
                        $report[$file] = [];
                    }
                    $report[$file][] = [
                        'line' => $displayLineNumber,
                        'key' => $ruleKey,
                        'match' => trim($matches[0])
                    ];
                    $totalIssues++;
                }
            } catch (\Throwable $e) {
                echo "Error matching pattern '$pattern' in file $file at line $displayLineNumber: " . $e->getMessage() . "\n";
            }
        }
    }
}

$finalReport = [
    'summary' => [
        'total_issues' => $totalIssues,
        'scanned_files' => $scannedFiles,
        'files_with_issues' => count($report),
        'timestamp' => date('Y-m-d H:i:s'),
    ],
    'issues' => $report
];

file_put_contents($reportPath, json_encode($finalReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Analysis complete.\n";
echo "- Scanned files: $scannedFiles\n";
echo "- Found issues: $totalIssues in " . count($report) . " files.\n";
echo "- Report saved to: $reportPath\n";

exit($totalIssues > 0 ? 1 : 0);
