<?php
/**
 * Static checks for KoolReport report code: classes that do not exist (with suggestions),
 * export targets that are not data stores, and the lint rules in facts.php. Used by check.php
 * (command line) and mcp-server.php (assistants).
 */

require_once __DIR__ . "/ClassIndex.php";

/**
 * Check a set of PHP files
 *
 * @param array $files      file name => contents
 * @param array $classes    Class index from koolreport_class_index()
 * @param array $namespaces Namespace index from koolreport_class_index()
 * @param array $lint       The "lint" rules from facts.php
 *
 * @return array Findings: [file, line, level (error|warning), rule, message], errors first
 */
function koolreport_check_sources($files, $classes, $namespaces, $lint)
{
    $findings = array();
    $all = implode("\n", $files);
    $lineOf = function ($source, $offset) {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    };

    // Data stores the report fills or reads, for the export-target check.
    preg_match_all('/dataStore\s*\(\s*["\']([^"\']+)["\']/', $all, $m);
    $stores = array_unique($m[1]);

    foreach ($files as $name => $source) {
        // Every fully qualified koolreport\... name in use statements, new, ::, and "class" strings.
        $patterns = array(
            '/^\s*use\s+\\\\?(koolreport\\\\[\w\\\\]+)\s*(?:as\s+\w+\s*)?;/mi',
            '/new\s+\\\\?(koolreport\\\\[\w\\\\]+)/i',
            '/\\\\?(koolreport\\\\[\w\\\\]+)\s*::/i',
            '/["\']\\\\{0,2}(koolreport(?:\\\\{1,2}\w+)+)["\']/i',
        );
        $seen = array();
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $source, $mm, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($mm[1] as $hit) {
                $fq = str_replace("\\\\", "\\", $hit[0]);
                $lower = strtolower($fq);
                if (isset($seen[$lower]) || isset($classes[$lower]) || isset($namespaces[$lower])) {
                    continue;
                }
                $seen[$lower] = true;
                $suggestions = koolreport_suggest($classes, $fq);
                // A use statement whose short name is never used again fails nothing at runtime.
                $short = substr($fq, strrpos($fq, "\\") + 1);
                $unused = $pattern === $patterns[0]
                    && preg_match_all('/\\b' . preg_quote($short, "/") . '\\b/', $all) <= 1;
                $findings[] = array(
                    "file" => $name, "line" => $lineOf($source, $hit[1]), "level" => $unused ? "warning" : "error",
                    "rule" => $unused ? "unknown-class-unused" : "unknown-class",
                    "message" => "$fq does not exist" . ($unused ? " (imported but not used)" : "")
                        . ($suggestions ? ". Did you mean " . implode(" or ", $suggestions) . "?" : "."),
                );
            }
        }

        // Report classes are not widgets: new MyReport, ->run()->render(); ::create() does not exist on them.
        if (preg_match_all('/class\s+(\w+)\s+extends\s+\\\\?koolreport\\\\KoolReport\b/i', $all, $rm)) {
            foreach (array_unique($rm[1]) as $reportClass) {
                if (preg_match('/\b' . preg_quote($reportClass, "/") . '::create\s*\(/', $source, $hit, PREG_OFFSET_CAPTURE)) {
                    $findings[] = array(
                        "file" => $name, "line" => $lineOf($source, $hit[0][1]), "level" => "error",
                        "rule" => "report-create",
                        "message" => "$reportClass is a report, not a widget: use \$report = new $reportClass(...); \$report->run()->render(); ::create() exists only on widgets.",
                    );
                }
            }
        }

        // exportToCSV("x") / exportToJSON("x") must name a data store.
        if (preg_match_all('/->exportTo(CSV|JSON)\(\s*["\']([^"\']+)["\']/', $source, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($mm as $hit) {
                if (!in_array($hit[2][0], $stores, true)) {
                    $findings[] = array(
                        "file" => $name, "line" => $lineOf($source, $hit[0][1]), "level" => "error",
                        "rule" => "export-unknown-store",
                        "message" => "exportTo{$hit[1][0]}(\"{$hit[2][0]}\") names no data store of the report"
                            . ($stores ? " (it has: " . implode(", ", $stores) . ")" : "")
                            . "; before excel 12.0.2 this exports an empty file without an error.",
                    );
                }
            }
        }

        foreach ($lint as $rule) {
            if ((isset($rule["requires"]) && !preg_match($rule["requires"], $all))
                || (isset($rule["unless"]) && preg_match($rule["unless"], $all))
                || !preg_match($rule["pattern"], $source, $hit, PREG_OFFSET_CAPTURE)
            ) {
                continue;
            }
            $findings[] = array(
                "file" => $name, "line" => $lineOf($source, $hit[0][1]), "level" => $rule["level"],
                "rule" => $rule["id"], "message" => $rule["message"],
            );
        }
    }
    usort($findings, function ($a, $b) {
        return array($a["level"] !== "error", $a["file"], $a["line"]) <=> array($b["level"] !== "error", $b["file"], $b["line"]);
    });
    return $findings;
}

/**
 * Where to index KoolReport from: the nearest vendor/koolreport above $path, else the packages
 * next to this tool (its own vendor/koolreport when installed, the dev tree in tools/llms).
 *
 * @param string $path A file or directory being checked
 *
 * @return string Directory holding KoolReport package folders
 */
function koolreport_find_install($path)
{
    for ($dir = realpath(is_dir($path) ? $path : dirname($path)); $dir && $dir !== "/"; $dir = dirname($dir)) {
        if (is_dir("$dir/vendor/koolreport/core")) {
            return realpath("$dir/vendor/koolreport");
        }
    }
    // Not inside a project: as the koolreport/mcp package the KoolReport packages are its siblings
    // (vendor/koolreport/), in the dev tree they are three levels up from tools/llms/lib.
    foreach (array(dirname(dirname(__DIR__)), dirname(dirname(dirname(__DIR__)))) as $candidate) {
        if (is_file("$candidate/core/composer.json")) {
            return realpath($candidate);
        }
    }
    return realpath(__DIR__ . "/../../..");
}

/**
 * The lint rules and the reference text, from wherever this code runs: the tools/llms source
 * (facts.php, build/llms-full.txt) or the koolreport/mcp package (resources/rules.json,
 * resources/reference.md).
 *
 * @param string $base The directory holding lib/ (tools/llms or the package root)
 *
 * @return array array($rules, $referenceText)
 */
function koolreport_resources($base)
{
    if (is_file("$base/resources/rules.json")) {
        $rules = json_decode(file_get_contents("$base/resources/rules.json"), true);
        $reference = is_file("$base/resources/reference.md") ? file_get_contents("$base/resources/reference.md") : "";
    } else {
        $facts = require "$base/facts.php";
        $rules = $facts["lint"];
        $reference = is_file("$base/build/llms-full.txt") ? file_get_contents("$base/build/llms-full.txt") : "";
    }
    return array($rules, $reference);
}

