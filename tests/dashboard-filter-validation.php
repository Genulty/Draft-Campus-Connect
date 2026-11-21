<?php
// Actual PHP Master Schedule validation and query binding; database-free adapters only.
declare(strict_types=1);
$semesters = json_decode(file_get_contents(__DIR__.'/fixtures/registration-semesters.json'),true,512,JSON_THROW_ON_ERROR);
$bound = [];
function rows(string $sql, array $params = []): array {
    if (str_contains($sql,'FROM Semester')) return $GLOBALS['semesters'];
    if (str_contains($sql,'FROM Department')) return [['dept_ID'=>'CS','dept_Name'=>'Computer Science'],['dept_ID'=>'BIO','dept_Name'=>'Biology']];
    if (str_contains($sql, 'FROM Period')) return [['period_ID'=>1,'label'=>'Period 1 · 8:00 AM – 9:20 AM']];
    if (str_contains($sql, 'FROM Building')) return [['building_ID'=>'LIB','building_Name'=>'Library Hall']];
    if (str_contains($sql, 'FROM Room WHERE')) return [['room_ID'=>'LIB-105']];
    if (str_contains($sql, 'SELECT DISTINCT cs.')) return [];
    throw new RuntimeException('Unexpected fixture query');
}
function one(string $sql, array $params = []): ?array {
    if (str_contains($sql,'FROM Semester')) return ['id'=>'FA26','name'=>'Fall 2026'];
    throw new RuntimeException('Unexpected fixture query');
}
function run(string $sql, array $params = []): object {
    if (!str_starts_with($sql,'SELECT ') || substr_count($sql,'?') !== count($params)) throw new RuntimeException('Unexpected query');
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
    if ($filters[0]['value'] !== $semester || $filters[1]['value'] !== $dept || $bound !== [$semester,$dept,$dept,'','','','']) throw new RuntimeException('Filter validation or binding mismatch');
    if (!in_array('FA26',array_column($filters[0]['options'],0),true) || !in_array('SP27',array_column($filters[0]['options'],0),true)) throw new RuntimeException('Semester option lost');
}
$view = fn (array $query) => buildView(['id'=>1,'role'=>'Faculty'],'master-schedule',$query)['blocks'];
[$filters, $table] = $view(['semester'=>'FA26','day'=>'T','period'=>'1','building'=>'LIB','room'=>'LIB-105']);
if ($bound !== ['FA26','','','','','','','T','1','LIB','LIB-105','T','1','LIB','LIB-105']
    || $filters['clearParams'] !== ['semester'=>'FA26'] || $filters['clearDisabled'] || $table['emptyText'] !== 'No course sections match these filters.') {
    throw new RuntimeException('Meeting parameters or reset state mismatch');
}
$fields = array_column($filters['filters'], null, 'name');
if ($fields['period']['options'][1] !== ['1','Period 1 · 8:00 AM – 9:20 AM']
    || $fields['building']['options'][1] !== ['LIB','Library Hall']) throw new RuntimeException('Missing meeting labels');
[$filters] = $view(['period'=>['1'],'building'=>"LIB' OR 1=1", 'room'=>'unknown', 'day'=>'unknown']);
if ($bound !== ['FA26','','','','','',''] || $filters['clearParams'] !== ['semester'=>'FA26'] || !$filters['clearDisabled']) {
    throw new RuntimeException('Invalid meeting filter applied or reset control missing');
}
if (buildView(['id'=>1,'role'=>'StatDept'],'master-schedule',[]) !== null) throw new RuntimeException('Role guard bypassed');
echo "PASS: 11 actual PHP filter-validation/binding cases, FA26/SP27 options, and role restriction. No database connection or writes.\n";
