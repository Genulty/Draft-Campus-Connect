<?php
// Reuses SELECT-only Faculty adapters; never changes schedule or academic data.
require __DIR__ . '/faculty-lab-visibility.php';
$evidence = [];
$textIds = rows("SELECT id FROM (SELECT '11' id UNION ALL SELECT '2' UNION ALL SELECT '8' UNION ALL SELECT '1') ids ORDER BY CAST(id AS UNSIGNED), id");
check(array_column($textIds, 'id') === ['1', '2', '8', '11'], 'Text period IDs must sort numerically');
foreach (['FA26', 'SP27'] as $semester) {
    $expected = rows('SELECT DISTINCT CAST(tp.period_ID AS UNSIGNED) AS id
        FROM Course_Section cs JOIN Time_Slot_Period tp ON tp.time_Slot_ID = cs.time_Slot_ID
        WHERE cs.semester_ID = ? UNION
        SELECT DISTINCT CAST(tp.period_ID AS UNSIGNED) AS id
        FROM Course_Section cs JOIN Course_Section_Lab lab ON lab.CRN = cs.CRN
        JOIN Time_Slot_Period tp ON tp.time_Slot_ID = lab.time_Slot_ID
        WHERE cs.semester_ID = ? ORDER BY id', [$semester, $semester]);
    $page = masterSchedule(['semester' => $semester]);
    $filters = array_column($page[0]['filters'], null, 'name');
    $actual = array_map(fn($option) => (int)$option[0], array_slice($filters['period']['options'], 1));
    check($actual === array_map(fn($row) => (int)$row['id'], $expected), 'Used periods must match lectures/labs and numeric order');
    check($page[0]['clearParams'] === ['semester' => $semester], 'Clear must retain semester');
    foreach ($actual as $id) {
        $filtered = masterSchedule(['semester' => $semester, 'period' => (string)$id]);
        check(count($filtered[1]['rows']) > 0, 'Offered period has no matching section');
    }
    $evidence[$semester] = ['periods' => $actual, 'rows' => count($page[1]['rows'])];
}
check($before === fingerprints(), 'Schedule/enrollment data changed');
echo 'PASS used lecture/lab periods, numeric order, period results, semester-preserving clear; no data changes: '.json_encode($evidence).PHP_EOL;
