<?php
// Actual PHP Master Schedule validation and query binding; database-free adapters only.
declare(strict_types=1);
$semesters = json_decode(file_get_contents(__DIR__.'/fixtures/registration-semesters.json'),true,512,JSON_THROW_ON_ERROR);
$bound = [];
function rows(string $sql, array $params = []): array {
    if (str_contains($sql,'FROM Semester')) return $GLOBALS['semesters'];
    if (str_contains($sql,'FROM Department')) return [['dept_ID'=>'CS','dept_Name'=>'Computer Science'],['dept_ID'=>'BIO','dept_Name'=>'Biology']];
    throw new RuntimeException('Unexpected fixture query');
}
function one(string $sql, array $params = []): ?array {
    if (str_contains($sql,'FROM Semester')) return ['id'=>'FA26','name'=>'Fall 2026'];
    throw new RuntimeException('Unexpected fixture query');
}
function run(string $sql, array $params = []): object {
    if (!str_starts_with($sql,'SELECT ') || substr_count($sql,'?') !== 3) throw new RuntimeException('Unexpected query');
    $GLOBALS['bound']=$params;
    return new class {
        public function columnCount(): int { return 0; }
        public function fetchAll(int $mode): array { return []; }
    };
}
require __DIR__.'/../api/views.php';
$cases = [
    [['semester'=>'SP27','dept'=>'CS'],'SP27','CS'],
    [['semester'=>'FA26','dept'=>'BIO'],'FA26','BIO'],
    [[], 'FA26',''],
    [['semester'=>'SP27'],'SP27',''],
    [['dept'=>'CS'],'FA26','CS'],
    [['semester'=>[],'dept'=>['CS']],'FA26',''],
    [['semester'=>"SP27' OR 1=1",'dept'=>"CS' OR 1=1"],'FA26',''],
    [['semester'=>null,'dept'=>false],'FA26',''],
    [['semester'=>'invalid','dept'=>'invalid'],'FA26',''],
];
foreach ($cases as [$query,$semester,$dept]) {
    $page=buildView(['id'=>1,'role'=>'Student'],'master-schedule',$query);
    $filters=$page['blocks'][0]['filters'];
    if ($filters[0]['value'] !== $semester || $filters[1]['value'] !== $dept || $bound !== [$semester,$dept,$dept]) throw new RuntimeException('Filter validation or binding mismatch');
    if (!in_array('FA26',array_column($filters[0]['options'],0),true) || !in_array('SP27',array_column($filters[0]['options'],0),true)) throw new RuntimeException('Semester option lost');
}
if (buildView(['id'=>1,'role'=>'StatDept'],'master-schedule',[]) !== null) throw new RuntimeException('Role guard bypassed');
echo "PASS: 9 actual PHP filter-validation/binding cases, FA26/SP27 options, and role restriction. No database connection or writes.\n";
