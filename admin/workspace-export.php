<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
require_once dirname(__DIR__) . '/includes/export.php';
adiwira_require_permission($pdo, 'plugin.study-interest.workspace.export', false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { adiwira_render_404(); return; }

$dataset = (string)($_POST['dataset'] ?? '');
$format = (string)($_POST['format'] ?? 'xlsx');
$returnRoute = $dataset === 'participants' ? 'participants' : 'sessions';
$returnUrl = study_interest_admin_url($returnRoute);
if (!in_array($dataset, ['participants', 'sessions'], true) || !in_array($format, ['csv', 'xlsx'], true)) study_interest_admin_redirect('error', __('Select a valid export format.'), $returnUrl);
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) study_interest_admin_redirect('error', __('The security token expired. Please try again.'), $returnUrl);

$status = trim((string)($_POST['status'] ?? ''));
if (!in_array($status, ['', 'started', 'completed'], true)) $status = '';
$q = mb_substr(trim((string)($_POST['q'] ?? '')), 0, 120);
$versionId = filter_var($_POST['version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($versionId === false) $versionId = null;
$dateFrom = trim((string)($_POST['date_from'] ?? ''));
$dateTo = trim((string)($_POST['date_to'] ?? ''));
$fromDate = $dateFrom !== '' && function_exists('app_parse_exact_datetime') ? app_parse_exact_datetime($dateFrom, 'Y-m-d', app_timezone()) : null;
$toDate = $dateTo !== '' && function_exists('app_parse_exact_datetime') ? app_parse_exact_datetime($dateTo, 'Y-m-d', app_timezone()) : null;
if (!$fromDate instanceof DateTimeImmutable) $dateFrom = '';
if (!$toDate instanceof DateTimeImmutable) $dateTo = '';

$where = ['1=1'];
$params = [];
if ($status !== '') { $where[] = 's.status=?'; $params[] = $status; }
if ($dataset === 'sessions' && $versionId !== null) { $where[] = 's.version_id=?'; $params[] = (int)$versionId; }
if ($dataset === 'sessions' && $fromDate instanceof DateTimeImmutable) { $where[] = 's.started_at_utc>=?'; $params[] = app_site_datetime_to_utc_mysql($fromDate); }
if ($dataset === 'sessions' && $toDate instanceof DateTimeImmutable) { $where[] = 's.started_at_utc<?'; $params[] = app_site_datetime_to_utc_mysql($toDate->modify('+1 day')); }
if ($q !== '') {
    $needle = '%' . $q . '%';
    $where[] = "(s.public_id LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(s.contact_json,'$.name')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(s.contact_json,'$.school')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(s.contact_json,'$.email')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(s.contact_json,'$.phone')) LIKE ?)";
    array_push($params, $needle, $needle, $needle, $needle, $needle);
}

$file = null;
$stream = null;
try {
    study_interest_admin_begin_snapshot($pdo);
    if (!function_exists('authorization_lock_actor_permissions') || !authorization_lock_actor_permissions($pdo, $studyInterestUserId)) throw new RuntimeException('Unable to lock actor permissions.');
    foreach (['workspace.export', 'contacts.view', 'sessions.view', 'results.view'] as $permission) {
        if (!function_exists('user_can') || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.' . $permission)) throw new RuntimeException('Workspace export permission changed.');
    }
    $statement = $pdo->prepare("SELECT s.id,s.public_id,s.status,s.contact_consent_at_utc,s.started_at_utc,s.completed_at_utc,s.last_activity_at_utc,s.contact_json,s.result_revision,v.id AS version_id,v.version_code,v.expected_question_count,v.configuration_json,
        (SELECT COUNT(*) FROM study_interest_answers a WHERE a.session_id=s.id) AS answer_count,
        (SELECT p.program_code FROM study_interest_program_results p WHERE p.session_id=s.id AND p.recommendation_type='DIRECT_ENTRY' ORDER BY p.score DESC,p.program_code ASC LIMIT 1) AS top_program,
        (SELECT p.score FROM study_interest_program_results p WHERE p.session_id=s.id AND p.recommendation_type='DIRECT_ENTRY' ORDER BY p.score DESC,p.program_code ASC LIMIT 1) AS top_score,
        (SELECT COUNT(*) FROM study_interest_result_flags f WHERE f.session_id=s.id) AS flag_count
        FROM study_interest_sessions s JOIN study_interest_test_versions v ON v.id=s.version_id
        WHERE " . implode(' AND ', $where) . ' ORDER BY s.last_activity_at_utc DESC,s.id DESC LIMIT 10001');
    $statement->execute($params);
    $records = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (count($records) > 10000) throw new DomainException('This export contains more than 10,000 rows. Narrow the filters and try again.');

    $programLabels = [];
    foreach ($records as $record) {
        $id = (int)$record['version_id'];
        if (isset($programLabels[$id])) continue;
        $programLabels[$id] = [];
        $configuration = json_decode((string)$record['configuration_json'], true);
        if (!is_array($configuration)) continue;
        foreach ($configuration['programs'] ?? [] as $code => $program) $programLabels[$id][(string)$code] = (string)($program['label'] ?? $code);
    }

    if ($dataset === 'participants') {
        $headers = ['Participant', 'School / institution', 'Class / stage', 'Email', 'Phone', 'Contact consent', 'Session UUID', 'Assessment version', 'Status', 'Answers', 'Questions', 'Closest study direction', 'Interest index', 'Started (UTC)', 'Completed (UTC)'];
        $numericColumns = [9, 10, 12];
        $widths = [24, 26, 18, 28, 18, 16, 38, 20, 14, 12, 12, 30, 14, 22, 22];
    } else {
        $headers = ['Session UUID', 'Participant', 'Assessment version', 'Status', 'Answers', 'Questions', 'Result revision', 'Closest study direction', 'Interest index', 'Quality flags', 'Started (UTC)', 'Completed (UTC)', 'Last activity (UTC)'];
        $numericColumns = [4, 5, 6, 8, 9];
        $widths = [38, 24, 20, 14, 12, 12, 14, 30, 14, 14, 22, 22, 22];
    }
    $rows = [];
    foreach ($records as $record) {
        $contact = study_interest_admin_contact((string)($record['contact_json'] ?? ''));
        $programCode = (string)($record['top_program'] ?? '');
        $program = $programCode !== '' ? ($programLabels[(int)$record['version_id']][$programCode] ?? $programCode) : '';
        if ($dataset === 'participants') {
            $rows[] = [
                (string)($contact['name'] ?? ''), (string)($contact['school'] ?? ''), (string)($contact['class_level'] ?? ''),
                (string)($contact['email'] ?? ''), (string)($contact['phone'] ?? ''), $record['contact_consent_at_utc'] !== null ? 'Yes' : 'No',
                (string)$record['public_id'], (string)$record['version_code'], (string)$record['status'], (int)$record['answer_count'],
                (int)$record['expected_question_count'], $program, $record['top_score'] !== null ? round((float)$record['top_score'], 3) : '',
                (string)$record['started_at_utc'], (string)($record['completed_at_utc'] ?? ''),
            ];
        } else {
            $rows[] = [
                (string)$record['public_id'], (string)($contact['name'] ?? ''), (string)$record['version_code'], (string)$record['status'],
                (int)$record['answer_count'], (int)$record['expected_question_count'], (int)$record['result_revision'], $program,
                $record['top_score'] !== null ? round((float)$record['top_score'], 3) : '', (int)$record['flag_count'],
                (string)$record['started_at_utc'], (string)($record['completed_at_utc'] ?? ''), (string)$record['last_activity_at_utc'],
            ];
        }
    }

    if ($format === 'xlsx') {
        if (!study_interest_xlsx_available()) throw new DomainException('Excel export is unavailable on this server. Use CSV instead.');
        $file = tempnam(sys_get_temp_dir(), 'sie-export-');
        if ($file === false) throw new RuntimeException('Unable to allocate export file.');
        study_interest_xlsx_write($file, $dataset === 'participants' ? 'Participants' : 'Sessions', $headers, $rows, $numericColumns, $widths);
    } else {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new RuntimeException('Unable to allocate export stream.');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $headers, ',', '"', '');
        foreach ($rows as $row) {
            foreach ($row as &$value) if (is_string($value) && preg_match('/\A[=+\-@]/', $value) === 1) $value = "'" . $value;
            unset($value);
            fputcsv($stream, $row, ',', '"', '');
        }
    }
    study_interest_audit($pdo, $studyInterestUserId, 'workspace.exported', $dataset . '_export', null, null, [
        'format' => $format,
        'row_count' => count($rows),
        'filters' => ['status' => $status, 'query' => $q !== '' ? '[filtered]' : '', 'version_id' => $dataset === 'sessions' ? $versionId : null, 'date_from' => $dataset === 'sessions' ? $dateFrom : '', 'date_to' => $dataset === 'sessions' ? $dateTo : ''],
    ]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (is_resource($stream)) fclose($stream);
    if (is_string($file) && is_file($file)) unlink($file);
    error_log('[study-interest] workspace export failed: ' . $error->getMessage());
    study_interest_admin_redirect('error', $error instanceof DomainException ? $error->getMessage() : __('The workspace export could not be created.'), $returnUrl);
}

$filename = 'study-interest-' . $dataset . '-' . gmdate('Ymd-His') . '.' . $format;
header('Content-Type: ' . ($format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8'));
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($format === 'xlsx' && is_string($file)) {
    header('Content-Length: ' . (string)filesize($file));
    readfile($file);
    unlink($file);
} elseif (is_resource($stream)) {
    rewind($stream);
    fpassthru($stream);
    fclose($stream);
}
exit;
