<?php
/**
 * Static index of the classes, traits and interfaces in KoolReport packages, shared by build.php
 * (llms.txt generation and validation) and check.php / mcp-server.php (report checking).
 *
 * Static on purpose: no autoloading, because some packages need PHP extensions or a newer PHP
 * than the one running the tool.
 */

/**
 * The KoolReport packages under a directory: <dir>/<package>/composer.json named koolreport/*.
 * Works for the dev tree (GitHub-KoolReport/) and for a Composer install (vendor/koolreport/).
 *
 * @param string $dir Directory holding package folders
 *
 * @return array package => source directory (core => core/src)
 */
function koolreport_package_dirs($dir)
{
    $packages = array();
    foreach (glob(rtrim($dir, "/") . "/*/composer.json") as $composerFile) {
        $composer = json_decode(file_get_contents($composerFile), true);
        $name = isset($composer["name"]) ? $composer["name"] : "";
        if (strpos($name, "koolreport/") !== 0) {
            continue;
        }
        $package = substr($name, strlen("koolreport/"));
        $packageDir = dirname($composerFile);
        $packages[$package] = $package === "core" ? "$packageDir/src" : $packageDir;
    }
    ksort($packages);
    return $packages;
}

/**
 * Index the classes in the given packages
 *
 * @param array  $packageDirs package => directory
 * @param string $base        Prefix stripped from file paths in the index
 *
 * @return array array($classes, $namespaces): lower fqcn => [name, file, package, kind, abstract,
 *               parent (lower fqcn|null), traits, methods]; lower namespace => true
 */
function koolreport_class_index($packageDirs, $base = "")
{
    $classes = array();
    $namespaces = array();
    foreach ($packageDirs as $package => $root) {
        if (!is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function ($f) {
                return !($f->isDir() && in_array($f->getFilename(), array("vendor", "tests", "node_modules", "bower_components", "assets", "examples")));
            }
        ));
        foreach ($it as $file) {
            if (substr($file->getFilename(), -4) !== ".php" || substr($file->getFilename(), -8) === ".tpl.php") {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            if (!preg_match('/^\s*namespace\s+([^;{\s]+)/m', $src, $ns)) {
                continue;
            }
            $namespace = trim($ns[1], "\\");
            $uses = array();
            if (preg_match_all('/^use\s+\\\\?([A-Za-z0-9_\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $src, $um, PREG_SET_ORDER)) {
                foreach ($um as $u) {
                    $parts = explode("\\", $u[1]);
                    $uses[strtolower(isset($u[2]) ? $u[2] : end($parts))] = $u[1];
                }
            }
            $resolve = function ($name) use ($namespace, $uses) {
                if ($name[0] === "\\") {
                    return strtolower(ltrim($name, "\\"));
                }
                $first = strtolower(explode("\\", $name)[0]);
                if (isset($uses[$first])) {
                    return strtolower($uses[$first] . substr($name, strlen($first)));
                }
                return strtolower("$namespace\\$name");
            };
            if (!preg_match_all('/^\s*(abstract\s+|final\s+)?(class|trait|interface)\s+(\w+)(?:\s+extends\s+([\\\\\w]+))?/mi', $src, $cm, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($cm as $c) {
                preg_match_all('/function\s+&?(\w+)\s*\(/', $src, $mm);
                preg_match_all('/^\s+use\s+([\\\\\w]+(?:\s*,\s*[\\\\\w]+)*)\s*;/m', $src, $tm);
                $traits = array();
                foreach ($tm[1] as $list) {
                    foreach (preg_split('/\s*,\s*/', $list) as $t) {
                        $traits[] = $resolve($t);
                    }
                }
                $fq = "$namespace\\{$c[3]}";
                $path = $file->getPathname();
                $classes[strtolower($fq)] = array(
                    "name" => $fq,
                    "file" => $base !== "" && strpos($path, $base) === 0 ? substr($path, strlen($base) + 1) : $path,
                    "package" => $package,
                    "kind" => strtolower($c[2]),
                    "abstract" => strtolower(trim($c[1])) === "abstract",
                    "parent" => isset($c[4]) && $c[4] !== "" ? $resolve($c[4]) : null,
                    "traits" => $traits,
                    "methods" => array_map("strtolower", $mm[1]),
                );
                for ($n = $namespace; $n !== ""; $n = substr($n, 0, max(0, (int)strrpos($n, "\\")))) {
                    $namespaces[strtolower($n)] = true;
                    if (strpos($n, "\\") === false) {
                        break;
                    }
                }
            }
        }
    }
    return array($classes, $namespaces);
}

function hasMethod($classes, $fq, $method, $depth = 0)
{
    if ($depth > 20 || !isset($classes[$fq])) {
        return false;
    }
    $c = $classes[$fq];
    if (in_array(strtolower($method), $c["methods"], true)) {
        return true;
    }
    foreach ($c["traits"] as $t) {
        if (hasMethod($classes, $t, $method, $depth + 1)) {
            return true;
        }
    }
    return $c["parent"] !== null && hasMethod($classes, $c["parent"], $method, $depth + 1);
}

function isA($classes, $fq, $ancestor)
{
    for ($i = 0; $fq !== null && $i < 30; $i++) {
        if ($fq === $ancestor) {
            return true;
        }
        $fq = isset($classes[$fq]) ? $classes[$fq]["parent"] : null;
    }
    return false;
}

/**
 * Real classes that a missing koolreport\ class name probably meant, best first. Same ranking as
 * core's autoloader hint: package named like the requested namespace, then the same class name,
 * then a name that starts the requested one (Line in LineChart), then namespace similarity.
 *
 * @param array  $classes   The class index
 * @param string $classname The missing class
 *
 * @return array Up to 3 fully qualified names
 */
function koolreport_suggest($classes, $classname)
{
    $classname = ltrim($classname, "\\");
    $pos = strrpos($classname, "\\");
    $short = substr($classname, $pos + 1);
    $namespace = strtolower(substr($classname, 0, $pos));
    $hint = strtolower(substr($namespace, strrpos($namespace, "\\") + 1));
    if (in_array($hint, array("koolreport", "widgets"))) {
        $hint = "";
    }
    $hintScore = function ($ns) use ($hint) {
        if ($hint === "") {
            return 0;
        }
        $best = 0;
        foreach (preg_split('#[\\\\/]+#', strtolower($ns)) as $segment) {
            if (strlen($segment) < 4 || in_array($segment, array("koolreport", "widgets", "src"))) {
                continue;
            }
            if (strpos($hint, $segment) === 0 || strpos($segment, $hint) === 0) {
                $best = max($best, 130);
            } elseif (strpos($hint, $segment) !== false || strpos($segment, $hint) !== false) {
                $best = max($best, 100);
            }
        }
        return $best;
    };
    $scored = array();
    foreach ($classes as $c) {
        if ($c["kind"] !== "class" || $c["abstract"]) {
            continue;
        }
        $cpos = strrpos($c["name"], "\\");
        $name = substr($c["name"], $cpos + 1);
        $cns = substr($c["name"], 0, $cpos);
        $sameName = strcasecmp($name, $short) === 0;
        $partOfName = !$sameName && strlen($name) >= 4 && stripos($short, $name) !== false && $hintScore($cns) > 0;
        if (!$sameName && !$partOfName) {
            continue;
        }
        similar_text($namespace, strtolower($cns), $percent);
        $percent += $hintScore($cns) + ($sameName ? 60 : (stripos($short, $name) === 0 ? 20 : 0));
        $scored[$c["name"]] = $percent;
    }
    arsort($scored);
    return array_slice(array_keys($scored), 0, 3);
}
