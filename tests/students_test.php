<?php
// Tests the School Level / Course / Year Level / Section rules.   Run:  php tests/students_test.php
require_once __DIR__ . '/../backend/students.php';

$failed = 0;
function expect($name, $actual, $wanted) {
    global $failed;
    if ($actual === $wanted) { echo "  PASS  $name\n"; return; }
    $failed++; echo "  FAIL  $name (got " . var_export($actual, true) . ")\n";
}

// How many sections does each combination have?  [level, course, year, wanted number of sections]
$wanted = [
    ['COLLEGE', 'BSTM', 1, 3], ['COLLEGE', 'BSTM', 2, 2], ['COLLEGE', 'BSTM', 3, 4], ['COLLEGE', 'BSTM', 4, 2],
    ['SENIOR HIGH SCHOOL', 'STEM', 11, 2], ['SENIOR HIGH SCHOOL', 'HUMSS', 11, 3],
    ['SENIOR HIGH SCHOOL', 'STEM', 12, 1], ['SENIOR HIGH SCHOOL', 'HUMSS', 12, 2],
    ['HIGH SCHOOL', 'HIGH SCHOOL', 7, 4], ['HIGH SCHOOL', 'HIGH SCHOOL', 8, 3], ['HIGH SCHOOL', 'HIGH SCHOOL', 9, 5], ['HIGH SCHOOL', 'HIGH SCHOOL', 10, 4],
];
$structure = school_structure();
foreach ($wanted as [$level, $course, $year, $sections]) {
    expect("$level / $course / $year has $sections sections", $structure[$level][$course][$year] ?? null, $sections);
    expect('... the last section is accepted', check_school_combination($level, $course, $year, $sections), '');
    expect('... one more section is refused', check_school_combination($level, $course, $year, $sections + 1) !== '', true);
}
expect('Only 3 school levels', array_keys($structure), ['COLLEGE', 'SENIOR HIGH SCHOOL', 'HIGH SCHOOL']);
expect('College has only BSTM', array_keys($structure['COLLEGE']), ['BSTM']);
expect('College has 4 year levels', array_keys($structure['COLLEGE']['BSTM']), [1, 2, 3, 4]);
expect('Senior high has only Grade 11 and 12', array_keys($structure['SENIOR HIGH SCHOOL']['STEM']), [11, 12]);
expect('High school has Grade 7 to 10', array_keys($structure['HIGH SCHOOL']['HIGH SCHOOL']), [7, 8, 9, 10]);

echo "Wrong combinations are refused\n";
expect('STEM does not belong to COLLEGE', check_school_combination('COLLEGE', 'STEM', 11, 1) !== '', true);
expect('BSTM Grade 11 does not exist', check_school_combination('COLLEGE', 'BSTM', 11, 1) !== '', true);
expect('BSTM year 5 does not exist', check_school_combination('COLLEGE', 'BSTM', 5, 1) !== '', true);
expect('STEM Grade 10 does not exist', check_school_combination('SENIOR HIGH SCHOOL', 'STEM', 10, 1) !== '', true);
expect('Section 0 is refused', check_school_combination('HIGH SCHOOL', 'HIGH SCHOOL', 7, 0) !== '', true);
expect('Unknown school level is refused', check_school_combination('KINDER', 'BSTM', 1, 1) !== '', true);

echo "validate_student\n";
$good = ['student_id' => '2026-10001', 'full_name' => 'Maria Santos', 'email' => 'm@example.com', 'school_level' => 'COLLEGE', 'course' => 'BSTM',
         'year' => '3', 'section' => 'Section 4', 'contact_number' => '09171234567'];
[$data, $errors] = validate_student($good);
expect('Valid student has no errors', $errors, []);
expect('Section is saved as "Section 4"', $data['section'], 'Section 4');
[, $errors] = validate_student(array_merge($good, ['section' => 'Section 5']));
expect('BSTM 3rd year Section 5 is refused', count($errors) > 0, true);
[$data, $errors] = validate_student(['student_id' => '2026-10002', 'full_name' => 'Jose Reyes', 'course' => 'STEM', 'year' => 'Grade 11', 'section' => 'Section 2']);
expect('CSV row without School Level: the course decides it', [$errors, $data['school_level']], [[], 'SENIOR HIGH SCHOOL']);
[, $errors] = validate_student(array_merge($good, ['school_level' => 'HIGH SCHOOL']));
expect('BSTM inside HIGH SCHOOL is refused', count($errors) > 0, true);
[, $errors] = validate_student(array_merge($good, ['student_id' => '']));
expect('Missing Student ID is refused', count($errors) > 0, true);
expect('"Third Year" is understood', parse_year_level('Third Year'), 3);
expect('"Grade 12" is understood', parse_year_level('Grade 12'), 12);
expect('Year labels', [year_label('COLLEGE', 1), year_label('SENIOR HIGH SCHOOL', 11), year_label('HIGH SCHOOL', 7)], ['First Year', 'Grade 11', 'Grade 7']);

echo $failed ? "\n$failed failed\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
