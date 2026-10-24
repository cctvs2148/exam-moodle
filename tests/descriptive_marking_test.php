<?php
declare(strict_types=1);

require_once __DIR__.'/../descriptive_marking.php';

$tests = [];
$tests['accepted answer normalizes case, whitespace, and punctuation'] = mark_descriptive_answer(
    '  CA,   2026! ',
    ['CA 2026'],
    [],
    1.0,
    null,
    true,
    0
);
$tests['1-mark exact match awards full marks'] = mark_descriptive_answer('yes', ['yes'], [], 1.0, null, true, 0);
$tests['2-mark rubric awards configured partial marks'] = mark_descriptive_answer('alpha only', ['alpha beta'], [['phrase'=>'alpha','marks'=>1]], 2.0, null, true, 0);
$tests['5-mark rubric adds explicit 3 and 1 mark criteria'] = mark_descriptive_answer('three one', ['complete answer'], [['phrase'=>'three','marks'=>3],['phrase'=>'one','marks'=>1]], 5.0, null, true, 0);
$tests['10-mark rubric caps awards at maximum marks'] = mark_descriptive_answer('key', ['complete'], [['phrase'=>'key','marks'=>12]], 10.0, null, true, 0);
$tests['empty answer deterministically receives zero'] = mark_descriptive_answer('', ['expected'], [], 2.0, null, true, 0);
$tests['numeric tolerance accepts configured range'] = mark_descriptive_answer('10.4', ['10'], [], 3.0, 0.5, true, 0);
$tests['numeric equality preserves decimal punctuation'] = mark_descriptive_answer('10.4', ['10.40'], [], 3.0, null, true, 0);
$tests['punctuation normalization does not erase numeric decimals'] = mark_descriptive_answer('10.4', ['10 4'], [], 3.0, null, true, 0);
$tests['optional spelling tolerance is deterministic'] = mark_descriptive_answer('colur', ['colour'], [], 2.0, null, true, 1);
$tests['unmatched answer without partial rubric requires review'] = mark_descriptive_answer('an uncertain response', ['expected answer'], [], 5.0, null, true, 0);
$tests['keyword matching ignores case and repeated whitespace'] = mark_descriptive_answer('  RED    PLANET ', ['different'], [['phrase'=>'red planet','marks'=>2]], 2.0, null, true, 0);

$failed = 0;
$expectations = [
    'accepted answer normalizes case, whitespace, and punctuation' => ['auto_marked', 1.0],
    '1-mark exact match awards full marks' => ['auto_marked', 1.0],
    '2-mark rubric awards configured partial marks' => ['auto_marked', 1.0],
    '5-mark rubric adds explicit 3 and 1 mark criteria' => ['auto_marked', 4.0],
    '10-mark rubric caps awards at maximum marks' => ['auto_marked', 10.0],
    'empty answer deterministically receives zero' => ['auto_marked', 0.0],
    'numeric tolerance accepts configured range' => ['auto_marked', 3.0],
    'numeric equality preserves decimal punctuation' => ['auto_marked', 3.0],
    'punctuation normalization does not erase numeric decimals' => ['pending_review', null],
    'optional spelling tolerance is deterministic' => ['auto_marked', 2.0],
    'unmatched answer without partial rubric requires review' => ['pending_review', null],
    'keyword matching ignores case and repeated whitespace' => ['auto_marked', 2.0],
];
foreach ($tests as $name => $result) {
    $expected = $expectations[$name] ?? ['unknown', null];
    $valid = $result['status'] === $expected[0] && $result['marks'] === $expected[1];
    if (!$valid) {
        $failed++;
        fwrite(STDERR, "FAIL: {$name} ".json_encode($result)."\n");
    }
}
if ($failed > 0) {
    exit(1);
}
echo count($tests)." descriptive marking checks passed.\n";
