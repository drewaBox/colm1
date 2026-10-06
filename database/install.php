<?php
// Creates (or updates) the database, the tables, the document catalog and the first accounts.
// Run it:   php database/install.php      (or open it in the browser while APP_ENV=development)
// It is safe to run again and again: it never deletes data, and it upgrades an older database
// (for example it renames the "personnel" role to "cashier" and adds the new columns).
require_once __DIR__ . '/../backend/helpers.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    if (is_production()) { http_response_code(403); exit('Run this from the command line in production.'); }
    header('Content-Type: text/plain; charset=utf-8');
}

function say($text) { echo $text . "\n"; }

// Runs every statement of an SQL text (statements end with ";" at the end of a line).
function run_sql_script($server, $script) {
    foreach (preg_split('/;\s*\n/', $script) as $statement) {
        $lines = array_filter(explode("\n", $statement), fn($l) => !str_starts_with(trim($l), '--'));
        $statement = trim(implode("\n", $lines));
        if ($statement !== '') $server->exec($statement);
    }
}

function column_exists($server, $dbName, $table, $column) {
    $stmt = $server->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$dbName, $table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function table_exists($server, $dbName, $table) {
    $stmt = $server->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $stmt->execute([$dbName, $table]);
    return (int)$stmt->fetchColumn() > 0;
}

// Changes an older database to the current structure. Every step checks first, so it can run twice.
function upgrade_old_database($server, $dbName) {
    $addColumn = function ($table, $column, $definition) use ($server, $dbName) {
        if (table_exists($server, $dbName, $table) && !column_exists($server, $dbName, $table, $column)) {
            $server->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            say("Added column $table.$column");
        }
    };
    $addColumn('users', 'must_change_password', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active');
    $addColumn('documents', 'category', "VARCHAR(60) NOT NULL DEFAULT 'Certification' AFTER description");
    $addColumn('document_requirements', 'requires_name', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER is_required');
    $addColumn('document_requirements', 'keywords', 'VARCHAR(255) NULL AFTER ai_hint');
    $addColumn('ai_verifications', 'name_on_document', 'VARCHAR(150) NULL AFTER confidence');
    $addColumn('ai_verifications', 'name_match_score', 'TINYINT UNSIGNED NULL AFTER name_on_document');
    $addColumn('ai_verifications', 'auto_declined', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER name_match_score');
    $addColumn('students', 'school_level', 'VARCHAR(30) NULL AFTER user_id');
    $addColumn('requests', 'qr_code', 'VARCHAR(255) NULL AFTER total_amount');
    if (table_exists($server, $dbName, 'requests') && column_exists($server, $dbName, 'requests', 'qr_code')) {
        $has = $server->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = " . $server->quote($dbName) . " AND TABLE_NAME = 'requests' AND INDEX_NAME = 'uq_requests_qr'")->fetchColumn();
        if (!$has) $server->exec('ALTER TABLE requests ADD UNIQUE KEY uq_requests_qr (qr_code)');
    }

    // Roles are now: student, cashier, registrar, admin. (The first version called the cashier "personnel".)
    if (table_exists($server, $dbName, 'users')) {
        $type = (string)$server->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = " . $server->quote($dbName) . " AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'")->fetchColumn();
        if ($type !== "enum('student','cashier','registrar','admin')") {
            $server->exec("ALTER TABLE users MODIFY role ENUM('student','personnel','cashier','registrar','admin') NOT NULL");
            $server->exec("UPDATE users SET role = 'cashier' WHERE role = 'personnel'");
            // The demo account named "registrar" was an admin account in the last version: make it a real registrar account.
            $server->exec("UPDATE users SET role = 'registrar' WHERE username = 'registrar' AND role = 'admin'");
            $server->exec("ALTER TABLE users MODIFY role ENUM('student','cashier','registrar','admin') NOT NULL");
            say('Roles updated: personnel -> cashier, the "registrar" account is now a registrar.');
        }
    }

    // The upload check statuses changed (VERIFIED / DECLINED / NEEDS_CORRECTION / PENDING). Unused old checks are just deleted.
    if (table_exists($server, $dbName, 'upload_checks')) {
        $type = (string)$server->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = " . $server->quote($dbName) . " AND TABLE_NAME = 'upload_checks' AND COLUMN_NAME = 'status'")->fetchColumn();
        if (strpos($type, "'ACCEPTED'") !== false) {
            $server->exec("DELETE FROM upload_checks WHERE used_file_id IS NULL");
            $server->exec("ALTER TABLE upload_checks MODIFY status ENUM('ACCEPTED','RECEIVED','DECLINED','VERIFIED','NEEDS_CORRECTION','PENDING') NOT NULL");
            $server->exec("UPDATE upload_checks SET status = 'VERIFIED' WHERE status IN ('ACCEPTED','RECEIVED')");
            $server->exec("ALTER TABLE upload_checks MODIFY status ENUM('VERIFIED','DECLINED','NEEDS_CORRECTION','PENDING') NOT NULL");
            say('Upload check statuses updated.');
        }
    }
}

// Words the document should contain, per requirement (used by the free document check). Only filled when empty,
// so anything the admin typed in the Catalog is never overwritten.
function fill_default_keywords($server) {
    $keywords = [
        'Accomplished request'                          => 'request,form,purpose,transcript',
        'Clearance/completion of requirements'          => 'clearance,cleared,signature,requirements',
        'Request/application'                           => 'request,application,form',
        'Student verification'                          => 'student,id,school,college',
        'Student identification/verification'           => 'student,id,school,college',
        'Student information/verification'              => 'student,id,school,enrollment',
        'Verified graduate record'                      => 'graduate,graduation,diploma,degree',
        'Required clearances/verification as applicable' => 'clearance,cleared,signature',
        'Request and record verification'               => 'request,form,student,record',
        'Verification of academic records'              => 'request,record,student,grades',
        'Required clearance and institutional requirements' => 'clearance,cleared,signature',
        'Original record/request and verification'     => 'record,request,certified,grades',
        'Appropriate request and authorization'         => 'request,authorization,letter,authorize',
        'Verification of institutional record'          => 'certificate,training,internship,ojt',
        'Record verification'                           => 'nstp,cwts,serial,record',
        'Appropriate request and supporting documents'  => 'request,letter,certification,supporting',
    ];
    $update = $server->prepare("UPDATE document_requirements SET keywords = ? WHERE name = ? AND (keywords IS NULL OR keywords = '')");
    foreach ($keywords as $name => $words) $update->execute([$words, $name]);
}

try {
    $dbName = env('DB_NAME', 'colm_registrar');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) exit("DB_NAME may only contain letters, numbers and underscores.\n");

    // 1. Connect to the server and create the database if it does not exist.
    $server = new PDO('mysql:host=' . env('DB_HOST', '127.0.0.1') . ';port=' . env('DB_PORT', '3306') . ';charset=utf8mb4',
        env('DB_USER', 'root'), env('DB_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $server->exec("USE `$dbName`");

    // 2. Upgrade an old database first, then create whatever is still missing.
    upgrade_old_database($server, $dbName);
    $sql = file_get_contents(__DIR__ . '/install.sql');
    [$schemaSql, $seedSql] = explode('-- Document catalog', $sql, 2);
    $seedSql = substr($seedSql, (int)strpos($seedSql, "\n")); // drop the rest of the comment line
    run_sql_script($server, $schemaSql);
    say('Tables are ready.');

    if ((int)$server->query('SELECT COUNT(*) FROM documents')->fetchColumn() === 0) {
        run_sql_script($server, $seedSql);
        say('Document catalog added.');
    }
    fill_default_keywords($server);

    // Every student account needs a row in "students" (course, year, section, photo ...).
    $created = $server->exec("INSERT INTO students (user_id) SELECT u.id FROM users u LEFT JOIN students s ON s.user_id = u.id WHERE u.role = 'student' AND s.id IS NULL");
    if ($created) say("Created $created missing student profile(s).");

    // 3. First accounts. Demo accounts only in development; in production only one admin with your own password.
    if ((int)$server->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $insert = $server->prepare('INSERT INTO users (username, password_hash, full_name, email, role, student_no) VALUES (?, ?, ?, ?, ?, ?)');
        if (is_production()) {
            $password = env('INITIAL_ADMIN_PASSWORD', '');
            if (strlen($password) < 10) exit("Set INITIAL_ADMIN_PASSWORD (at least 10 characters) in .env first.\n");
            $insert->execute(['admin', password_hash($password, PASSWORD_DEFAULT), 'Admin', null, 'admin', null]);
            say('Admin account "admin" created with your INITIAL_ADMIN_PASSWORD.');
        } else {
            $hash = password_hash('Password123!', PASSWORD_DEFAULT);
            // Admin = everything, Registrar = students + documents, Cashier = payments only.
            $demo = [
                ['2026-00001', 'Juan Dela Cruz', 'student',   '2026-00001'],
                ['cashier',    'COLM Cashier',   'cashier',   null],
                ['registrar',  'Registrar',      'registrar', null],
                ['admin',      'Admin',          'admin',     null],
            ];
            foreach ($demo as [$username, $name, $role, $studentNo]) {
                $insert->execute([$username, $hash, $name, null, $role, $studentNo]);
            }
            $server->exec("INSERT INTO students (user_id, school_level, course, year_level, section, contact_no)
                           SELECT id, 'COLLEGE', 'BSTM', 3, 'Section 1', '09171234567' FROM users WHERE username = '2026-00001'");
            say('Demo accounts created (password: Password123!): 2026-00001 (student), cashier, registrar, admin.');
            say('Change these passwords before you deploy.');
        }
    } else {
        say('Accounts already exist, so none were added.');
    }
    say('Done.');
} catch (Throwable $e) {
    say('Install failed: ' . $e->getMessage());
    exit(1);
}
