<?php
/**
 * The deploy primitives of pull.php, loaded for a test.
 *
 * pull.php is a single script that runs on include — it renders a form or
 * performs a deploy — so it cannot simply be required. The three functions and
 * the keep-list are lifted out of the real file instead of being copied here:
 * a test with its own copy of the code would keep passing after the real one
 * changed.
 */
$source = file_get_contents(dirname(__DIR__) . '/pull.php');
if ($source === false) {
    fwrite(STDERR, "tests: cannot read pull.php\n");
    exit(2);
}

$parts = [];
if (preg_match('/^const ALWAYS_KEEP = .*?;$/m', $source, $m)) $parts[] = $m[0];
foreach (['copyTree', 'purgeExtra', 'rmTree'] as $fn) {
    if (preg_match('/^function ' . $fn . '\(.*?^\}/ms', $source, $m)) $parts[] = $m[0];
}
if (count($parts) !== 4) {
    fwrite(STDERR, "tests: pull.php no longer exposes ALWAYS_KEEP + copyTree/purgeExtra/rmTree\n");
    exit(2);
}
eval(implode("\n\n", $parts));
