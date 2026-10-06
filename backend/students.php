<?php
// Student accounts: creating one student, checking and importing a CSV file, default passwords.
require_once __DIR__ . '/database.php';

const CSV_COLUMNS = ['student_id', 'full_name', 'email', 'course', 'year', 'section', 'contact_number'];
const CSV_MAX_ROWS = 1000;

// Default password used for newly created student accounts.
function make_default_password() {
    return 'Password123!';
}

// The school's levels, courses, year levels and how many sections each has. The Add Student form (JavaScript) and the
// PHP check both use THIS list, so a wrong combination can never be saved.
//   level => [ course => [ year level => number of sections ] ]
function school_structure() {
    return [
        'COLLEGE' => [
            'BSTM' => [1 => 3, 2 => 2, 3 => 4, 4 => 2],
        ],
        'SENIOR HIGH SCHOOL' => [
            'STEM'  => [11 => 2, 12 => 1],
            'HUMSS' => [11 => 3, 12 => 2],
        ],
        'HIGH SCHOOL' => [
            'HIGH SCHOOL' => [7 => 4, 8 => 3, 9 => 5, 10 => 4],
        ],
    ];
}

// "First Year" for college, "Grade 11" for senior high and high school.
function year_label($level, $year) {
    if ($level === 'COLLEGE') return [1 => 'First Year', 2 => 'Second Year', 3 => 'Third Year', 4 => 'Fourth Year'][(int)$year] ?? ('Year ' . (int)$year);
    return 'Grade ' . (int)$year;
}

// "3", "3rd Year", "Third Year", "Grade 11" -> the number. Returns null if there is no number in it.
function parse_year_level($value) {
    $value = mb_strtolower(trim((string)$value));
    $words = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4];
    foreach ($words as $word => $number) if (strpos($value, $word) === 0) return $number;
    return preg_match('/\d+/', $value, $m) ? (int)$m[0] : null;
}

// "Section 2" or "2" -> 2. Returns null if there is no number.
function parse_section($value) {
    return preg_match('/\d+/', (string)$value, $m) ? (int)$m[0] : null;
}

// The school level a course belongs to (the CSV file has no School Level column).
function level_of_course($course) {
    foreach (school_structure() as $level => $courses) {
        foreach ($courses as $name => $years) if (strcasecmp($name, trim((string)$course)) === 0) return $level;
    }
    return null;
}

// Is this combination real? Returns an error text or '' when fine.
function check_school_combination($level, $course, $year, $section) {
    $structure = school_structure();
    if (!isset($structure[$level])) return 'Please choose a valid School Level.';
    if (!isset($structure[$level][$course])) return 'The course does not belong to the selected School Level.';
    if (!isset($structure[$level][$course][$year])) return 'The year level does not belong to the selected course.';
    if ($section < 1 || $section > $structure[$level][$course][$year]) return 'The section does not exist for the selected year level.';
    return '';
}

// Checks one student's data. Returns [cleaned data, list of error messages].
function validate_student($row) {
    $errors = [];
    $studentId = trim((string)($row['student_id'] ?? ''));
    $fullName = preg_replace('/\s+/', ' ', trim((string)($row['full_name'] ?? '')));
    $email = trim((string)($row['email'] ?? ''));
    $contact = trim((string)($row['contact_number'] ?? ''));
    $course = strtoupper(trim((string)($row['course'] ?? '')));
    $level = strtoupper(trim((string)($row['school_level'] ?? '')));
    if ($level === '' && $course !== '') $level = level_of_course($course) ?? '';       // CSV: the course tells the level
    $year = parse_year_level($row['year'] ?? '');
    $section = parse_section($row['section'] ?? '');

    if ($studentId === '') $errors[] = 'Student ID is missing.';
    elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{2,29}$/', $studentId)) $errors[] = 'Student ID may only contain letters, numbers and dashes (3-30 characters).';
    if ($fullName === '') $errors[] = 'Full name is missing.';
    elseif (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 120 || !preg_match('/^[\p{L}\p{M}\s.\'-]+(,[\p{L}\p{M}\s.\'-]+)?$/u', $fullName)) $errors[] = 'Full name is not valid.';
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120)) $errors[] = 'Email address is not valid.';
    if ($contact !== '' && !preg_match('/^[0-9+() -]{7,20}$/', $contact)) $errors[] = 'Contact number is not valid.';

    // School level, course, year and section must be a real combination.
    if ($course === '') $errors[] = 'Course is missing.';
    elseif ($year === null) $errors[] = 'Year level is missing or not a number.';
    elseif ($section === null) $errors[] = 'Section is missing.';
    else {
        $problem = check_school_combination($level, $course, $year, $section);
        if ($problem !== '') $errors[] = $problem;
    }

    return [[
        'student_id' => $studentId, 'full_name' => $fullName, 'email' => $email, 'school_level' => $level, 'course' => $course,
        'year' => $year, 'section' => $section === null ? '' : 'Section ' . $section, 'contact_number' => $contact,
    ], $errors];
}

// Does this student ID (or username) already exist?
function student_id_taken($studentId) {
    return fetch_one('SELECT id FROM users WHERE username = ? OR student_no = ?', [$studentId, $studentId]) !== null;
}

// Creates the login account and the student profile. Returns the new user id.
function create_student($data, $password) {
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        query('INSERT INTO users (username, password_hash, full_name, email, role, student_no, must_change_password) VALUES (?, ?, ?, ?, ?, ?, 1)',
            [$data['student_id'], password_hash($password, PASSWORD_DEFAULT), $data['full_name'],
             $data['email'] !== '' ? $data['email'] : null, 'student', $data['student_id']]);
        $userId = (int)$pdo->lastInsertId();
        query('INSERT INTO students (user_id, school_level, course, year_level, section, contact_no) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $data['school_level'], $data['course'], $data['year'], $data['section'], $data['contact_number'] !== '' ? $data['contact_number'] : null]);
        if ($own) $pdo->commit();
        return $userId;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// Reads the uploaded CSV file into rows. Throws RuntimeException with a clear message if the file is not usable.
// Returns a list of ['line' => number in the file, 'data' => [column => value]].
function read_students_csv($path) {
    $text = file_get_contents($path);
    if ($text === false || trim($text) === '') throw new RuntimeException('The CSV file is empty.');
    if (substr($text, 0, 3) === "\xEF\xBB\xBF") $text = substr($text, 3);                 // Excel adds a BOM
    if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    if (strpos($text, "\0") !== false) throw new RuntimeException('This does not look like a CSV text file.');

    $lines = preg_split('/\r\n|\r|\n/', $text);
    $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $text);
    rewind($stream);

    $header = fgetcsv($stream, 0, $delimiter);
    $header = array_map(fn($h) => strtolower(trim(str_replace([' ', '-'], '_', (string)$h))), $header ?: []);
    // Accept a few common alternative column names.
    $aliases = ['school_level' => 'school_level', 'level' => 'school_level', 'id_number' => 'student_id', 'student_no' => 'student_id', 'id' => 'student_id', 'name' => 'full_name',
                'fullname' => 'full_name', 'year_level' => 'year', 'contact' => 'contact_number', 'contact_no' => 'contact_number',
                'mobile' => 'contact_number', 'phone' => 'contact_number', 'program' => 'course'];
    $header = array_map(fn($h) => $aliases[$h] ?? $h, $header);
    $missing = array_values(array_diff(CSV_COLUMNS, $header));
    if ($missing) throw new RuntimeException('The CSV is missing these columns: ' . implode(', ', $missing) . '. Download the sample file to see the correct format.');

    $rows = [];
    $line = 1;
    while (($fields = fgetcsv($stream, 0, $delimiter)) !== false) {
        $line++;
        if ($fields === [null] || trim(implode('', $fields)) === '') continue; // empty line
        if (count($rows) >= CSV_MAX_ROWS) throw new RuntimeException('The CSV has more than ' . CSV_MAX_ROWS . ' students. Split it into smaller files.');
        $data = [];
        foreach ($header as $i => $name) $data[$name] = $fields[$i] ?? '';
        $rows[] = ['line' => $line, 'data' => $data];
    }
    fclose($stream);
    if (!$rows) throw new RuntimeException('The CSV has no student rows.');
    return $rows;
}

// Checks every row. Nothing is saved. Returns ['valid' => [...], 'failed' => [...]].
function check_students_csv($rows) {
    $valid = []; $failed = []; $seen = [];
    foreach ($rows as $row) {
        [$data, $errors] = validate_student($row['data']);
        if (!$errors) {
            $key = mb_strtolower($data['student_id']);
            if (isset($seen[$key])) $errors[] = "Student ID appears twice in this file (first on line {$seen[$key]}).";
            elseif (student_id_taken($data['student_id'])) $errors[] = 'A student or account with this ID already exists.';
            else $seen[$key] = $row['line'];
        }
        if ($errors) $failed[] = ['line' => $row['line'], 'student_id' => $data['student_id'], 'full_name' => $data['full_name'], 'errors' => $errors];
        else $valid[] = ['line' => $row['line'], 'data' => $data];
    }
    return ['valid' => $valid, 'failed' => $failed];
}

// The sample file the admin downloads on the Students page. The course decides the school level.
function sample_students_csv() {
    return "student_id,full_name,email,course,year,section,contact_number\r\n"
         . "2026-10001,Maria Santos,maria.santos@example.com,BSTM,1,Section 1,09171234567\r\n"
         . "2026-10002,Jose Reyes,jose.reyes@example.com,STEM,Grade 11,Section 2,09181234567\r\n"
         . "2026-10003,Ana Garcia,ana.garcia@example.com,HUMSS,Grade 12,Section 1,09191234567\r\n"
         . "2026-10004,Pedro Lopez,pedro.lopez@example.com,HIGH SCHOOL,Grade 9,Section 5,09201234567\r\n";
}
