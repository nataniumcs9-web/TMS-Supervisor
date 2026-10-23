<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$page = requestText($_GET, 'page', 'dashboard');
$allowedPages = ['dashboard', 'assignments', 'pending', 'finished', 'suspended', 'transcribers', 'accounts', 'reports', 'settings'];
if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePost();
    verifyCsrf();
    $action = requestText($_POST, 'action');

    if ($action === 'login') {
        header('Location: ../index.php');
        exit;
    }

    if ($action === 'logout') {
        unset($_SESSION['username']);
        session_regenerate_id(true);
        header('Location: ../index.php');
        exit;
    }

    try {
        $pdo = database();
        $supervisor = currentSupervisor($pdo);
        if (!$supervisor) {
            unset($_SESSION['username']);
            header('Location: ../index.php');
            exit;
        }

        $accountRoles = ['Recorder', 'Transcriber', 'Supervisor'];
        if (in_array($action, ['account_create', 'account_update', 'account_reset_password'], true)) {
            if ($action === 'account_create') {
                $username = trim(requestText($_POST, 'username'));
                $fullName = trim(requestText($_POST, 'full_name'));
                $fullNameLength = preg_match_all('/./us', $fullName);
                $gender = requestText($_POST, 'gender');
                $role = requestText($_POST, 'role');
                $status = requestText($_POST, 'status');
                $password = requestText($_POST, 'new_password');
                $passwordLength = preg_match_all('/./us', $password);
                if (
                    !preg_match('/^[A-Za-z0-9._-]{2,100}$/', $username)
                    || $fullName === ''
                    || $fullNameLength === false
                    || $fullNameLength > 200
                    || !in_array($gender, ['', 'Male', 'Female', 'Other'], true)
                    || !in_array($role, $accountRoles, true)
                    || !in_array($status, ['Active', 'Pending', 'Inactive'], true)
                    || $passwordLength === false
                    || $passwordLength < 12
                    || strlen($password) > 200
                ) {
                    throw new DomainException('Enter a valid username, full name, role, status, and password of at least 12 characters.');
                }
                $duplicate = $pdo->prepare('SELECT id FROM req_acc WHERE LOWER(username) = LOWER(?) LIMIT 1');
                $duplicate->execute([$username]);
                if ($duplicate->fetchColumn()) {
                    throw new DomainException('That username already exists.');
                }
                $create = $pdo->prepare(
                    'INSERT INTO req_acc (username, full_name, gender, role, prof_pic, id_card, password, req_date, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)'
                );
                $create->execute([
                    $username,
                    $fullName,
                    $gender,
                    $role,
                    '../',
                    '../',
                    password_hash($password, PASSWORD_DEFAULT),
                    $status,
                ]);
                flash('success', 'Account created for ' . $username . '.');
                go('accounts');
            }

            $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$accountId || $accountId < 1) {
                throw new DomainException('Select a valid account.');
            }

            if ($action === 'account_reset_password') {
                $newPassword = requestText($_POST, 'new_password');
                $passwordLength = preg_match_all('/./us', $newPassword);
                if ($passwordLength === false || $passwordLength < 12 || strlen($newPassword) > 200) {
                    throw new DomainException('The new password must be at least 12 characters.');
                }
                $reset = $pdo->prepare('UPDATE req_acc SET password = ? WHERE id = ?');
                $reset->execute([password_hash($newPassword, PASSWORD_DEFAULT), $accountId]);
                if ($reset->rowCount() !== 1) {
                    throw new DomainException('The account was not found or the password was unchanged.');
                }
                flash('success', 'Password reset successfully.');
                go('accounts');
            }

            $fullName = trim(requestText($_POST, 'full_name'));
            $fullNameLength = preg_match_all('/./us', $fullName);
            $gender = requestText($_POST, 'gender');
            $role = requestText($_POST, 'role');
            $status = requestText($_POST, 'status');
            if (
                $fullName === ''
                || $fullNameLength === false
                || $fullNameLength > 200
                || !in_array($gender, ['', 'Male', 'Female', 'Other'], true)
                || !in_array($role, $accountRoles, true)
                || !in_array($status, ['Active', 'Pending', 'Inactive'], true)
            ) {
                throw new DomainException('Enter a valid full name, role, and account status.');
            }

            $pdo->beginTransaction();
            $activeSupervisors = $pdo->query(
                "SELECT id FROM req_acc
                 WHERE LOWER(role) = 'supervisor' AND LOWER(status) = 'active'
                 ORDER BY id FOR UPDATE"
            )->fetchAll(PDO::FETCH_COLUMN);
            $targetStatement = $pdo->prepare(
                'SELECT username, role, status FROM req_acc WHERE id = ? LIMIT 1 FOR UPDATE'
            );
            $targetStatement->execute([$accountId]);
            $targetAccount = $targetStatement->fetch();
            if (!$targetAccount) {
                throw new DomainException('The account no longer exists.');
            }
            if (strcasecmp((string) $targetAccount['username'], (string) $supervisor['username']) === 0
                && (strcasecmp($role, 'Supervisor') !== 0 || strcasecmp($status, 'Active') !== 0)
            ) {
                throw new DomainException('You cannot change your own role or deactivate your signed-in account.');
            }
            $isActiveSupervisor = strcasecmp((string) $targetAccount['role'], 'Supervisor') === 0
                && strcasecmp((string) $targetAccount['status'], 'Active') === 0;
            if (
                $isActiveSupervisor
                && (strcasecmp($role, 'Supervisor') !== 0 || strcasecmp($status, 'Active') !== 0)
                && count($activeSupervisors) <= 1
            ) {
                throw new DomainException('At least one active Supervisor account must remain.');
            }
            $isActiveTranscriber = strcasecmp((string) $targetAccount['role'], 'Transcriber') === 0
                && strcasecmp((string) $targetAccount['status'], 'Active') === 0;
            if (
                $isActiveTranscriber
                && (strcasecmp($role, 'Transcriber') !== 0 || strcasecmp($status, 'Active') !== 0)
            ) {
                $openWork = $pdo->prepare(
                    "SELECT COUNT(*) FROM records
                     WHERE transcriber = ? AND LOWER(TRIM(status)) IN ('assigned', 'suspended', 'pending')"
                );
                $openWork->execute([$targetAccount['username']]);
                if ((int) $openWork->fetchColumn() > 0) {
                    throw new DomainException('Reassign this transcriber’s assigned or suspended recordings before changing their role or deactivating them.');
                }
            }
            $updateAccount = $pdo->prepare(
                'UPDATE req_acc SET full_name = ?, gender = ?, role = ?, status = ? WHERE id = ?'
            );
            $updateAccount->execute([$fullName, $gender, $role, $status, $accountId]);
            $pdo->commit();
            flash('success', 'Account details updated.');
            go('accounts');
        }

        $recordId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$recordId || $recordId < 1) {
            throw new DomainException('Select a valid recording.');
        }

        if ($action === 'assign') {
            $transcriberName = trim(requestText($_POST, 'transcriber'));
            if ($transcriberName === '') {
                throw new DomainException('Choose an active transcriber.');
            }
            $pdo->beginTransaction();
            $account = $pdo->prepare(
                "SELECT id FROM req_acc
                 WHERE username = ? AND LOWER(role) = 'transcriber' AND LOWER(status) = 'active'
                 LIMIT 1 FOR UPDATE"
            );
            $account->execute([$transcriberName]);
            if (!$account->fetch()) {
                throw new DomainException('That transcriber is no longer active. Refresh and choose another.');
            }
            $record = $pdo->prepare('SELECT status FROM records WHERE id = ? LIMIT 1 FOR UPDATE');
            $record->execute([$recordId]);
            $currentStatus = $record->fetchColumn();
            if (!in_array(strtolower(trim((string) $currentStatus)), ['uploaded', 'assigned'], true)) {
                throw new DomainException('This recording is not available for assignment.');
            }
            $update = $pdo->prepare(
                "UPDATE records
                 SET transcriber = ?, status = 'Assigned', distrubuted_on = CURRENT_DATE
                 WHERE id = ?"
            );
            $update->execute([$transcriberName, $recordId]);
            $pdo->commit();
            flash('success', 'Recording assigned to ' . $transcriberName . '.');
            go('assignments');
        }

        if ($action === 'pending_update') {
            $nextStatus = requestText($_POST, 'next_status');
            $requestedTranscriber = requestText($_POST, 'transcriber');
            if (!in_array($nextStatus, ['Pending', 'Uploaded', 'Assigned'], true)) {
                throw new DomainException('Choose Pending, Uploaded, or Assigned as the next status.');
            }

            $pdo->beginTransaction();
            $record = $pdo->prepare(
                'SELECT transcriber, status FROM records WHERE id = ? LIMIT 1 FOR UPDATE'
            );
            $record->execute([$recordId]);
            $currentRecord = $record->fetch();
            if (!$currentRecord || strcasecmp(trim((string) $currentRecord['status']), 'pending') !== 0) {
                throw new DomainException('This recording is no longer in the pending queue.');
            }

            if ($nextStatus === 'Uploaded') {
                $nextTranscriber = 'NOT ASSIGEND';
            } elseif ($requestedTranscriber === '__keep__') {
                $nextTranscriber = (string) $currentRecord['transcriber'];
                if ($nextStatus === 'Assigned') {
                    $account = $pdo->prepare(
                        "SELECT id FROM req_acc
                         WHERE username = ? AND LOWER(role) = 'transcriber' AND LOWER(status) = 'active'
                         LIMIT 1 FOR UPDATE"
                    );
                    $account->execute([$nextTranscriber]);
                    if (!$account->fetch()) {
                        throw new DomainException('Choose an active transcriber before returning this file to Assigned.');
                    }
                }
            } elseif ($requestedTranscriber === '__unassigned__' && $nextStatus === 'Pending') {
                $nextTranscriber = 'NOT ASSIGEND';
            } else {
                $account = $pdo->prepare(
                    "SELECT id FROM req_acc
                     WHERE username = ? AND LOWER(role) = 'transcriber' AND LOWER(status) = 'active'
                     LIMIT 1 FOR UPDATE"
                );
                $account->execute([$requestedTranscriber]);
                if (!$account->fetch()) {
                    throw new DomainException('Choose an active transcriber.');
                }
                $nextTranscriber = $requestedTranscriber;
            }

            $update = $pdo->prepare(
                "UPDATE records
                 SET transcriber = ?, status = ?,
                     distrubuted_on = CASE WHEN ? = 'Assigned' THEN CURRENT_DATE ELSE distrubuted_on END
                 WHERE id = ? AND LOWER(TRIM(status)) = 'pending'"
            );
            $update->execute([$nextTranscriber, $nextStatus, $nextStatus, $recordId]);
            $pdo->commit();
            flash('success', 'Pending recording updated.');
            go('pending');
        }

        $actions = [
            'suspend' => ['Assigned', 'Suspended', 'Recording suspended.'],
            'restore' => ['Suspended', 'Assigned', 'Recording restored to the assigned queue.'],
            'return' => ['Finished', 'Uploaded', 'Finished recording returned to the upload queue.'],
        ];
        if (!isset($actions[$action])) {
            throw new DomainException('That action is not available.');
        }

        [$from, $to, $successMessage] = $actions[$action];
        $pdo->beginTransaction();
        $update = $pdo->prepare('UPDATE records SET status = ? WHERE id = ? AND LOWER(status) = LOWER(?)');
        $update->execute([$to, $recordId, $from]);
        if ($update->rowCount() !== 1) {
            throw new DomainException('The recording changed or is no longer in the expected queue.');
        }
        $pdo->commit();
        flash('success', $successMessage);
        go(match ($action) {
            'suspend' => 'assignments',
            'restore' => 'suspended',
            default => 'finished',
        });
    } catch (DomainException $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $exception->getMessage());
        go($page);
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('TMS supervisor action failed: ' . $exception->getMessage());
        flash('error', 'The request could not be completed. Check the database connection and try again.');
        go($page);
    }
}

$loggedIn = false;
$supervisor = null;
$pdo = null;
$loadError = null;
if (!empty($_SESSION['username'])) {
    try {
        $pdo = database();
        $supervisor = currentSupervisor($pdo);
        if (!$supervisor) {
            unset($_SESSION['username']);
        } else {
            $loggedIn = true;
        }
    } catch (Throwable $exception) {
        error_log('TMS supervisor page load failed: ' . $exception->getMessage());
        $loadError = 'Unable to connect to the TMS database. Check the local database configuration.';
    }
}
if (!$loggedIn) {
    header('Location: ../index.php');
    exit;
} elseif ($loggedIn && $page === 'login') {
    go('dashboard');
}

$titleMap = [
    'dashboard' => 'Supervisor dashboard',
    'assignments' => 'Assignment queue',
    'pending' => 'Pending recordings',
    'finished' => 'Finished recordings',
    'suspended' => 'Suspended recordings',
    'transcribers' => 'Transcriber team',
    'accounts' => 'Account management',
    'reports' => 'Workload reports',
    'settings' => 'Workspace settings',
    'login' => 'Supervisor sign in',
];
$title = $titleMap[$page];
$flashMessage = takeFlash();
$metrics = ['uploaded' => 0, 'assigned' => 0, 'pending' => 0, 'finished' => 0, 'suspended' => 0, 'overdue' => 0, 'due_today' => 0, 'overtime' => 0];
$transcribers = [];
$accounts = [];
$records = [];
$weeklyRecords = [];
$weeklyTranscribers = [];
$weeklyTranscriberOptions = [];
$weeklyRecordsByUser = [];
$reportRows = [];
$reportSummary = ['total' => 0, 'finished' => 0, 'assigned' => 0];
$search = trim(requestText($_GET, 'q'));
$fromDate = requestText($_GET, 'from');
$toDate = requestText($_GET, 'to');
$reportDateError = null;
foreach (['From' => $fromDate, 'To' => $toDate] as $label => $date) {
    if (
        $date !== ''
        && (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
        )
    ) {
        $reportDateError = $label . ' date is not a valid calendar date.';
        break;
    }
}
if ($reportDateError === null && $fromDate !== '' && $toDate !== '' && $fromDate > $toDate) {
    $reportDateError = 'The From date must be on or before the To date.';
}
$weekInput = requestText($_GET, 'week', date('Y-m-d'));
$weekDateError = null;
if (
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekInput)
    || !checkdate((int) substr($weekInput, 5, 2), (int) substr($weekInput, 8, 2), (int) substr($weekInput, 0, 4))
) {
    $weekDateError = 'Choose a valid date for the report week.';
    $weekInput = date('Y-m-d');
}
$weekStart = (new DateTimeImmutable($weekInput))->modify('monday this week');
$weekEnd = $weekStart->modify('+4 days');
$includeFilterApplied = requestText($_GET, 'include_filter') === '1';
$selectedWeeklyTranscribers = [];
if (isset($_GET['include']) && is_array($_GET['include'])) {
    foreach ($_GET['include'] as $includedUsername) {
        if (is_string($includedUsername) && $includedUsername !== '') {
            $selectedWeeklyTranscribers[] = $includedUsername;
        }
    }
}
$statusFilter = requestText($_GET, 'status');
$transcriberFilter = requestText($_GET, 'transcriber');
$overtimeOnly = requestText($_GET, 'ot') === '1';
$reportType = requestText($_GET, 'type', 'transcribers');
$showCharts = requestText($_GET, 'charts', '1') !== '0';
$reportTypes = ['transcribers', 'statuses', 'daily', 'weekly'];
if (!in_array($reportType, $reportTypes, true)) {
    $reportType = 'transcribers';
}
$accountSearch = trim(requestText($_GET, 'account_q'));
$accountRoleFilter = requestText($_GET, 'account_role');
$accountStatusFilter = requestText($_GET, 'account_status');
$validAccountRoles = ['Recorder', 'Transcriber', 'Supervisor'];
$validAccountStatuses = ['Active', 'Pending', 'Inactive'];
if (!in_array($accountRoleFilter, $validAccountRoles, true)) {
    $accountRoleFilter = '';
}
if (!in_array($accountStatusFilter, $validAccountStatuses, true)) {
    $accountStatusFilter = '';
}
$chartData = ['labels' => [], 'values' => []];
$reportColumns = [];
$dashboardStatusChart = ['labels' => [], 'values' => []];
$dashboardWorkChart = ['labels' => [], 'values' => [], 'finished' => []];
$audioConfigurationReady = configuredAudioRoot() !== false
    || audioUrl('Audio/configuration-check.mp3') !== null;

if ($loggedIn && $pdo) {
    try {
        $metricStatement = $pdo->query(
            "SELECT
                COALESCE(SUM(LOWER(TRIM(status)) = 'uploaded'), 0) AS uploaded,
                COALESCE(SUM(LOWER(TRIM(status)) = 'assigned'), 0) AS assigned,
                COALESCE(SUM(LOWER(TRIM(status)) = 'pending'), 0) AS pending,
                COALESCE(SUM(LOWER(TRIM(status)) = 'finished'), 0) AS finished,
                COALESCE(SUM(LOWER(TRIM(status)) = 'suspended'), 0) AS suspended,
                COALESCE(SUM(LOWER(TRIM(status)) = 'assigned' AND appointed_on = CURRENT_DATE), 0) AS due_today,
                COALESCE(SUM(LOWER(TRIM(status)) = 'assigned' AND appointed_on < CURRENT_DATE), 0) AS overdue,
                COALESCE(SUM(LOWER(TRIM(status)) = 'finished' AND UPPER(TRIM(doc)) = 'OT'), 0) AS overtime
             FROM records"
        );
        $metrics = array_map('intval', $metricStatement->fetch() ?: $metrics);
        if (in_array($page, ['dashboard', 'assignments', 'pending', 'transcribers'], true)) {
            $transcribers = $pdo->query(
                "SELECT a.username, a.full_name,
                        COALESCE(SUM(LOWER(TRIM(r.status)) = 'assigned'), 0) AS assigned_count,
                        COALESCE(SUM(LOWER(TRIM(r.status)) = 'finished'), 0) AS finished_count
                 FROM req_acc a
                 LEFT JOIN records r ON r.transcriber = a.username
                 WHERE LOWER(a.role) = 'transcriber' AND LOWER(a.status) = 'active'
                 GROUP BY a.id, a.username, a.full_name
                 ORDER BY assigned_count DESC, finished_count DESC, a.full_name, a.username"
            )->fetchAll();
        }
        if ($page === 'accounts') {
            $accountConditions = [];
            $accountParameters = [];
            if ($accountSearch !== '') {
                $accountConditions[] = '(username LIKE ? OR full_name LIKE ?)';
                $accountParameters[] = '%' . $accountSearch . '%';
                $accountParameters[] = '%' . $accountSearch . '%';
            }
            if ($accountRoleFilter !== '') {
                $accountConditions[] = 'role = ?';
                $accountParameters[] = $accountRoleFilter;
            }
            if ($accountStatusFilter !== '') {
                $accountConditions[] = 'status = ?';
                $accountParameters[] = $accountStatusFilter;
            }
            $accountWhere = $accountConditions ? 'WHERE ' . implode(' AND ', $accountConditions) : '';
            $accountStatement = $pdo->prepare(
                "SELECT id, username, full_name, gender, role, status, req_date
                 FROM req_acc $accountWhere
                 ORDER BY CASE WHEN LOWER(status) = 'active' THEN 0 ELSE 1 END,
                          role, full_name, username"
            );
            $accountStatement->execute($accountParameters);
            $accounts = $accountStatement->fetchAll();
        }
        if ($page === 'dashboard') {
            $dashboardStatusChart = [
                'labels' => ['Uploaded', 'Assigned', 'Pending', 'Finished', 'Suspended'],
                'values' => [$metrics['uploaded'], $metrics['assigned'], $metrics['pending'], $metrics['finished'], $metrics['suspended']],
            ];
            $dashboardWorkChart = [
                'labels' => array_map(static fn(array $person): string => (string) ($person['full_name'] ?: $person['username']), array_slice($transcribers, 0, 8)),
                'values' => array_map(static fn(array $person): int => (int) $person['assigned_count'], array_slice($transcribers, 0, 8)),
                'finished' => array_map(static fn(array $person): int => (int) $person['finished_count'], array_slice($transcribers, 0, 8)),
            ];
        }

        if ($page === 'dashboard') {
            $records = $pdo->query(
                "SELECT id, rec_date, file_num, machine_num, Apllicant, Defendent, audio_status, audio,
                        appointed_on, transcriber, status
                 FROM records
                 WHERE LOWER(TRIM(status)) IN ('uploaded', 'assigned')
                 ORDER BY appointed_on IS NULL, appointed_on, rec_date DESC, id DESC
                 LIMIT 8"
            )->fetchAll();
        } elseif (in_array($page, ['assignments', 'pending', 'finished', 'suspended'], true)) {
            $statusClause = match ($page) {
                'assignments' => match ($statusFilter) {
                    'uploaded' => "LOWER(TRIM(status)) = 'uploaded'",
                    'assigned' => "LOWER(TRIM(status)) = 'assigned'",
                    'due_today', 'overdue' => "LOWER(TRIM(status)) = 'assigned'",
                    default => "LOWER(TRIM(status)) IN ('uploaded', 'assigned')",
                },
                'pending' => "LOWER(TRIM(status)) = 'pending'",
                'finished' => "LOWER(TRIM(status)) = 'finished'",
                default => "LOWER(TRIM(status)) = 'suspended'",
            };
            $finishedDuration = $page === 'finished'
                ? ', DATEDIFF(r.finished_date, r.distrubuted_on) AS turnaround_days'
                : '';
            $sql = "SELECT r.id, r.rec_date, r.file_num, r.machine_num, r.Apllicant, r.Defendent, r.audio_status,
                           r.audio, r.appointed_on, r.transcriber, r.status, r.finished_date, r.distrubuted_on,
                           r.doc AS overtime $finishedDuration
                    FROM records r WHERE $statusClause";
            $parameters = [];
            if ($search !== '') {
                $sql .= ' AND (CAST(r.machine_num AS CHAR) LIKE ? OR CAST(r.file_num AS CHAR) LIKE ?
                          OR r.Apllicant LIKE ? OR r.Defendent LIKE ? OR r.transcriber LIKE ?)';
                $needle = '%' . $search . '%';
                $parameters = array_fill(0, 5, $needle);
            }
            if ($page === 'assignments' && $transcriberFilter !== '') {
                $sql .= ' AND r.transcriber = ?';
                $parameters[] = $transcriberFilter;
            }
            if ($page === 'finished' && $overtimeOnly) {
                $sql .= " AND UPPER(TRIM(r.doc)) = 'OT'";
            }
            if ($page === 'assignments' && in_array($statusFilter, ['due_today', 'overdue'], true)) {
                $sql .= $statusFilter === 'due_today'
                    ? ' AND r.appointed_on = CURRENT_DATE'
                    : ' AND r.appointed_on < CURRENT_DATE';
            }
            $order = $page === 'assignments'
                ? " ORDER BY CASE WHEN LOWER(TRIM(r.status)) = 'uploaded' THEN 0 ELSE 1 END,
                           r.appointed_on IS NULL, r.appointed_on, r.rec_date DESC, r.id DESC"
                : ($page === 'pending'
                    ? ' ORDER BY r.rec_date DESC, r.id DESC'
                    : ' ORDER BY r.finished_date DESC, r.rec_date DESC, r.id DESC');
            $sql .= $order . ' LIMIT 250';
            $statement = $pdo->prepare($sql);
            $statement->execute($parameters);
            $records = $statement->fetchAll();
        } elseif ($page === 'reports' && $reportType === 'weekly' && $weekDateError === null) {
            $weeklyRecordsStatement = $pdo->prepare(
                "SELECT id, file_num, machine_num, audio, transcriber, finished_date, remark
                 FROM records
                 WHERE LOWER(TRIM(status)) = 'finished'
                   AND finished_date BETWEEN ? AND ?
                 ORDER BY transcriber, finished_date, file_num, machine_num, id"
            );
            $weeklyRecordsStatement->execute([$weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')]);
            $weeklyRecords = $weeklyRecordsStatement->fetchAll();
            $weeklyTranscriberStatement = $pdo->query(
                "SELECT username, full_name
                 FROM req_acc
                 WHERE LOWER(TRIM(role)) = 'transcriber'
                 ORDER BY full_name, username"
            );
            foreach ($weeklyTranscriberStatement->fetchAll() as $person) {
                $username = (string) $person['username'];
                $weeklyTranscribers['user:' . strtolower($username)] = [
                    'username' => (string) $person['username'],
                    'name' => trim((string) $person['full_name']) !== ''
                        ? (string) $person['full_name']
                        : (string) $person['username'],
                ];
            }
            $weeklyRecordKeys = [];
            foreach ($weeklyRecords as $weeklyRecord) {
                $username = trim((string) $weeklyRecord['transcriber']);
                if ($username === '' || in_array(strtolower($username), ['not assigend', 'not assigned'], true)) {
                    $usernameKey = '@unassigned';
                    $username = '@unassigned';
                } else {
                    $usernameKey = 'user:' . strtolower($username);
                    foreach ($weeklyTranscribers as $knownKey => $knownPerson) {
                        if (strcasecmp($knownPerson['username'], $username) === 0) {
                            $usernameKey = $knownKey;
                            $username = $knownPerson['username'];
                            break;
                        }
                    }
                }
                if (!isset($weeklyTranscribers[$usernameKey])) {
                    $weeklyTranscribers[$usernameKey] = [
                        'username' => $username,
                        'name' => $username === '@unassigned' ? 'Not assigned' : $username,
                    ];
                }
                $weeklyRecordKeys[(int) $weeklyRecord['id']] = $usernameKey;
            }
            $weeklyTranscriberOptions = $weeklyTranscribers;
            $selectedWeeklyTranscribers = $includeFilterApplied
                ? array_values(array_unique($selectedWeeklyTranscribers))
                : array_map(
                    static fn(array $person): string => $person['username'],
                    array_values($weeklyTranscribers)
                );
            $selectedSet = array_fill_keys(array_map('strtolower', $selectedWeeklyTranscribers), true);
            $weeklyTranscribers = array_filter(
                $weeklyTranscribers,
                static function (array $person, string $_key) use ($selectedSet): bool {
                    return isset($selectedSet[strtolower($person['username'])]);
                },
                ARRAY_FILTER_USE_BOTH
            );
            foreach (array_keys($weeklyTranscribers) as $username) {
                $weeklyRecordsByUser[$username] = [];
            }
            foreach ($weeklyRecords as $weeklyRecord) {
                $username = $weeklyRecordKeys[(int) $weeklyRecord['id']];
                if (isset($weeklyRecordsByUser[$username])) {
                    $weeklyRecordsByUser[$username][] = $weeklyRecord;
                }
            }
        } elseif ($page === 'reports' && $reportType !== 'weekly' && $reportDateError === null) {
            $conditions = [];
            $parameters = [];
            if ($fromDate !== '') {
                $conditions[] = 'rec_date >= ?';
                $parameters[] = $fromDate;
            }
            if ($toDate !== '') {
                $conditions[] = 'rec_date <= ?';
                $parameters[] = $toDate;
            }
            $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
            $summaryStatement = $pdo->prepare(
                "SELECT COUNT(*) AS total,
                        COALESCE(SUM(LOWER(TRIM(status)) = 'finished'), 0) AS finished_count,
                        COALESCE(SUM(LOWER(TRIM(status)) = 'assigned'), 0) AS assigned_count
                 FROM records$where"
            );
            $summaryStatement->execute($parameters);
            $summary = $summaryStatement->fetch() ?: [];
            $reportSummary = [
                'total' => (int) ($summary['total'] ?? 0),
                'finished' => (int) ($summary['finished_count'] ?? 0),
                'assigned' => (int) ($summary['assigned_count'] ?? 0),
            ];
            if ($reportType === 'statuses') {
                $statement = $pdo->prepare(
                    "SELECT CASE
                                WHEN LOWER(TRIM(status)) IN ('uploaded', 'assigned', 'pending', 'finished', 'suspended')
                                    THEN CONCAT(UPPER(LEFT(LOWER(TRIM(status)), 1)), SUBSTRING(LOWER(TRIM(status)), 2))
                                WHEN TRIM(status) = '' THEN 'Unknown'
                                ELSE TRIM(status)
                            END AS label,
                            COUNT(*) AS total
                     FROM records$where
                     GROUP BY label ORDER BY total DESC, label"
                );
                $reportColumns = ['Status', 'Recordings'];
            } elseif ($reportType === 'daily') {
                $statement = $pdo->prepare(
                    "SELECT rec_date AS label, COUNT(*) AS total,
                            SUM(LOWER(TRIM(status)) = 'assigned') AS assigned_count,
                            SUM(LOWER(TRIM(status)) = 'finished') AS finished_count
                     FROM records$where
                     GROUP BY rec_date ORDER BY rec_date DESC"
                );
                $reportColumns = ['Recording date', 'Total', 'Assigned', 'Finished'];
            } else {
                $statement = $pdo->prepare(
                    "SELECT CASE
                                WHEN LOWER(TRIM(transcriber)) IN ('', 'not assigend', 'not assigned')
                                    THEN 'Not assigned'
                                ELSE TRIM(transcriber)
                            END AS label,
                            COUNT(*) AS total,
                            SUM(LOWER(TRIM(status)) = 'assigned') AS assigned_count,
                            SUM(LOWER(TRIM(status)) = 'finished') AS finished_count,
                            SUM(LOWER(TRIM(status)) = 'suspended') AS suspended_count
                     FROM records$where
                     GROUP BY label
                     ORDER BY finished_count DESC, total DESC, label"
                );
                $reportColumns = ['Transcriber', 'Total', 'Assigned', 'Finished', 'Suspended'];
            }
            $statement->execute($parameters);
            $reportRows = $statement->fetchAll();
            $chartRows = $reportType === 'statuses' ? $reportRows : array_reverse($reportRows);
            $chartData = [
                'labels' => array_map(static fn(array $row): string => (string) $row['label'], $chartRows),
                'values' => array_map(static fn(array $row): int => (int) $row['total'], $chartRows),
            ];

            if (requestText($_GET, 'export') === 'csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="tms-supervisor-report.csv"');
                $output = fopen('php://output', 'w');
                if ($output === false) {
                    throw new RuntimeException('Unable to open report output.');
                }
                fputcsv($output, $reportColumns);
                foreach ($reportRows as $row) {
                    $csvRow = [];
                    foreach ($row as $value) {
                        $csvRow[] = csvText($value);
                    }
                    fputcsv($output, $csvRow);
                }
                fclose($output);
                exit;
            }
        }
    } catch (Throwable $exception) {
        error_log('TMS supervisor data query failed: ' . $exception->getMessage());
        $loadError = 'Dashboard data could not be loaded. Verify the database schema and connection settings.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173b63">
    <title><?= e($title) ?> · GZHC TMS</title>
    <link rel="stylesheet" href="assets/supervisor.css?v=<?= (int) filemtime(__DIR__ . '/assets/supervisor.css') ?>">
    <script src="assets/supervisor.js?v=<?= (int) filemtime(__DIR__ . '/assets/supervisor.js') ?>" defer></script>
</head>
<body class="<?= $loggedIn ? 'app-body' : 'login-body' ?>">
<?php if (!$loggedIn): ?>
    <main class="login-shell">
        <section class="login-brand">
            <div class="brand-mark" aria-hidden="true">GZ</div>
            <p class="eyebrow">GEDEO ZONE HIGH COURT</p>
            <h1>Transcription<br>management system</h1>
            <p class="login-intro">Supervisor workspace</p>
            <div class="login-rule"></div>
            <p class="login-note">Coordinate recordings, assignments, and transcription progress from one secure workspace.</p>
        </section>
        <section class="login-card">
            <div class="mobile-brand"><span class="brand-mark small">GZ</span><span>GEDEO ZONE HIGH COURT</span></div>
            <p class="eyebrow accent-text">SUPERVISOR ACCESS</p>
            <h2>Welcome back</h2>
            <p class="muted">Sign in with your active Supervisor account.</p>
            <?php if ($loadError): ?><div class="notice error"><?= e($loadError) ?></div><?php endif; ?>
            <?php if ($flashMessage): ?><div class="notice <?= e($flashMessage['type']) ?>"><?= e($flashMessage['message']) ?></div><?php endif; ?>
            <form method="post" action="index.php?page=login" class="login-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="login">
                <label for="username">Username</label>
                <input id="username" name="username" autocomplete="username" required maxlength="100" autofocus>
                <label for="password">Password</label>
                <input id="password" type="password" name="password" autocomplete="current-password" required>
                <button class="button primary full-button" type="submit">Sign in to dashboard <span aria-hidden="true">→</span></button>
            </form>
            <p class="login-footnote">Your account must be active and assigned the Supervisor role.</p>
        </section>
    </main>
<?php else: ?>
    <div class="app-shell">
        <aside class="sidebar" id="sidebar">
            <a class="brand-lockup" href="index.php" aria-label="GZHC TMS dashboard">
                <span class="brand-mark">GZ</span>
                <span class="brand-name"><strong>GZHC</strong><small>TRANSCRIPTION SYSTEM</small></span>
            </a>
            <div class="side-caption">WORKSPACE</div>
            <nav class="side-nav" aria-label="Main navigation">
                <?php
                $nav = [
                    'dashboard' => ['Overview', '⌂'],
                    'assignments' => ['Assignment queue', '⇄'],
                    'pending' => ['Pending', '◷'],
                    'finished' => ['Finished work', '✓'],
                    'suspended' => ['Suspended', 'Ⅱ'],
                    'transcribers' => ['Transcriber team', '♙'],
                    'accounts' => ['Accounts', '♙'],
                    'reports' => ['Reports', '▤'],
                    'settings' => ['Settings', '⚙'],
                ];
                foreach ($nav as $key => [$label, $icon]):
                ?>
                    <a class="nav-link <?= $page === $key ? 'active' : '' ?>" href="index.php?page=<?= e($key) ?>" <?= $page === $key ? 'aria-current="page"' : '' ?>>
                        <span class="nav-icon" aria-hidden="true"><?= e($icon) ?></span><span><?= e($label) ?></span>
                        <?php if ($key === 'assignments' && $metrics['uploaded'] > 0): ?><span class="nav-count"><?= (int) $metrics['uploaded'] ?></span><?php endif; ?>
                        <?php if ($key === 'pending' && $metrics['pending'] > 0): ?><span class="nav-count"><?= (int) $metrics['pending'] ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-help"><span class="help-dot"></span><div><strong>Supervisor console</strong><small>Connected workspace</small></div></div>
                <form method="post" action="index.php">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="nav-link signout-button" type="submit"><span class="nav-icon" aria-hidden="true">↪</span><span>Sign out</span></button>
                </form>
                <div class="sidebar-version">GZHC · Internal operations</div>
            </div>
        </aside>
        <div class="sidebar-scrim" data-sidebar-close></div>
        <main class="main-area">
            <header class="topbar">
                <button class="menu-toggle" type="button" aria-label="Toggle navigation" aria-expanded="true" data-sidebar-toggle data-sidebar-collapse>☰</button>
                <div class="breadcrumb"><span>Workspace</span><span class="crumb-separator">/</span><strong><?= e($title) ?></strong></div>
                <div class="topbar-user"><time class="topbar-date" datetime="<?= e(date('Y-m-d')) ?>" data-calendar-date-top><?= e(date('D, M j')) ?></time><span class="avatar"><?= e(strtoupper(substr((string) ($supervisor['full_name'] ?: $supervisor['username']), 0, 1))) ?></span><span class="user-name"><?= e($supervisor['full_name'] ?: $supervisor['username']) ?><small>Supervisor</small></span></div>
            </header>
            <div class="page-content">
                <?php if ($flashMessage): ?><div class="notice <?= e($flashMessage['type']) ?>" role="status"><?= e($flashMessage['message']) ?></div><?php endif; ?>
                <?php if ($loadError): ?><div class="notice error" role="alert"><?= e($loadError) ?></div><?php endif; ?>

                <?php if ($page === 'dashboard'): ?>
                    <section class="page-heading">
                        <div><p class="eyebrow accent-text">OPERATIONS OVERVIEW</p><h1>Good <?= (int) date('G') < 12 ? 'morning' : ((int) date('G') < 17 ? 'afternoon' : 'evening') ?>, <?= e(strtok((string) ($supervisor['full_name'] ?: $supervisor['username']), ' ')) ?></h1><p class="muted">Here is what is happening across your transcription team.</p></div>
                        <a class="button primary" href="index.php?page=assignments">Manage assignments <span aria-hidden="true">→</span></a>
                    </section>
                    <section class="metric-grid dashboard-metrics" aria-label="Workload summary">
                        <?php foreach ([['uploaded', 'Needs assignment', 'New recordings in queue', 'metric-blue', 'assignments', 'uploaded', []], ['assigned', 'Assigned', 'Currently with transcribers', 'metric-teal', 'assignments', 'assigned', []], ['pending', 'Pending', 'Needs supervisor review', 'metric-amber', 'pending', '', []], ['due_today', 'Due today', 'Appointments scheduled today', 'metric-teal', 'assignments', 'due_today', []], ['overdue', 'Past appointment', 'Assigned cases past due', 'metric-amber', 'assignments', 'overdue', []], ['finished', 'Finished', 'Transcriptions completed', 'metric-green', 'finished', '', []], ['overtime', 'OT cases', 'Finished records marked OT in TMS', 'metric-amber', 'finished', '', ['ot' => '1']]] as [$key, $label, $caption, $color, $destination, $filter, $extra]): ?>
                            <a class="metric-card metric-card-link" href="index.php?<?= e(http_build_query(['page' => $destination, 'status' => $filter] + $extra)) ?>" aria-label="<?= e($label) ?>: <?= (int) $metrics[$key] ?>. View matching recordings."><div class="metric-top"><span><?= e($label) ?></span><span class="metric-symbol <?= e($color) ?>"><?= $key === 'uploaded' ? '↓' : ($key === 'assigned' ? '⇄' : ($key === 'finished' ? '✓' : ($key === 'due_today' ? '◷' : ($key === 'overtime' ? 'OT' : '!')))) ?></span></div><strong class="metric-value"><?= number_format((int) $metrics[$key]) ?></strong><span class="metric-caption"><?= e($caption) ?> · View list →</span></a>
                        <?php endforeach; ?>
                    </section>
                    <div class="dashboard-grid">
                        <section class="panel">
                            <div class="panel-heading"><div><h2>Work requiring attention</h2><p>Upcoming assignments and new recordings</p></div><a class="text-link" href="index.php?page=assignments">View queue <span aria-hidden="true">→</span></a></div>
                            <?php if (!$records): ?><div class="empty-state"><span class="empty-icon">✓</span><strong>Queue is clear</strong><p>There are no uploaded or assigned recordings to show.</p></div><?php else: ?>
                            <div class="table-wrap"><table><thead><tr><th>CASE</th><th>PARTIES</th><th>APPOINTMENT</th><th>TRANSCRIBER</th><th>STATUS</th></tr></thead><tbody>
                                <?php foreach ($records as $record): ?><tr><td><strong>#<?= e($record['file_num'] ?: $record['machine_num']) ?></strong><small>Machine <?= e($record['machine_num']) ?></small></td><td><?= e($record['Apllicant'] ?: '—') ?><small>vs <?= e($record['Defendent'] ?: '—') ?></small></td><td><?= dateValue($record['appointed_on']) ?></td><td><?= e($record['transcriber'] ?: 'Not assigned') ?></td><td><span class="status-pill <?= e(statusClass((string) $record['status'])) ?>"><?= e(statusLabel((string) $record['status'])) ?></span></td></tr><?php endforeach; ?>
                            </tbody></table></div><?php endif; ?>
                        </section>
                        <section class="panel team-panel">
                            <div class="panel-heading"><div><h2>Team workload</h2><p>Active transcribers</p></div><a class="icon-link" href="index.php?page=transcribers" aria-label="View transcriber team">→</a></div>
                            <?php if (!$transcribers): ?><div class="empty-state compact"><p>No active transcribers found.</p></div><?php else: ?>
                                <div class="team-list"><?php foreach (array_slice($transcribers, 0, 6) as $person): ?><a class="team-row" href="index.php?<?= e(http_build_query(['page' => 'assignments', 'status' => 'assigned', 'transcriber' => $person['username']])) ?>"><span class="avatar team-avatar"><?= e(strtoupper(substr((string) ($person['full_name'] ?: $person['username']), 0, 1))) ?></span><span class="team-person"><strong><?= e($person['full_name'] ?: $person['username']) ?></strong><small>@<?= e($person['username']) ?></small></span><span class="team-work"><strong><?= (int) $person['assigned_count'] ?></strong><small>active · view</small></span></a><?php endforeach; ?></div>
                            <?php endif; ?>
                            <a class="team-footer" href="index.php?page=transcribers">View team overview <span>→</span></a>
                        </section>
                    </div>
                    <section class="chart-grid">
                        <article class="panel chart-panel"><div class="panel-heading"><div><h2>Recording distribution</h2><p>All recordings by current workflow status</p></div><a class="text-link" href="index.php?page=reports&amp;type=statuses">Open status report →</a></div><div class="chart-content"><canvas id="status-chart" class="chart-canvas" role="img" aria-label="Donut chart showing recording counts by status"></canvas><div id="status-chart-legend" class="chart-legend"></div></div></article>
                        <article class="panel chart-panel"><div class="panel-heading"><div><h2>Team output comparison</h2><p>Active assignments and finished work by transcriber</p></div><a class="text-link" href="index.php?page=reports">Detailed report →</a></div><div class="chart-key"><span><i class="legend-swatch assigned-key"></i>Assigned</span><span><i class="legend-swatch finished-key"></i>Finished</span></div><div class="chart-content chart-wide"><canvas id="workload-chart" class="chart-canvas" role="img" aria-label="Bar chart comparing active and finished work by transcriber"></canvas></div></article>
                    </section>
                <?php elseif ($page === 'transcribers'): ?>
                    <section class="page-heading"><div><p class="eyebrow accent-text">TEAM MANAGEMENT</p><h1>Transcriber team</h1><p class="muted">Active accounts and their current workload.</p></div></section>
                    <section class="panel"><div class="panel-heading"><div><h2>Active transcribers</h2><p><?= count($transcribers) ?> team members</p></div></div>
                        <?php if (!$transcribers): ?><div class="empty-state"><strong>No active team members</strong><p>Active Transcriber accounts will appear here.</p></div><?php else: ?>
                        <div class="table-wrap"><table><thead><tr><th>TRANSCRIBER</th><th>USERNAME</th><th>ACTIVE ASSIGNMENTS</th><th>FINISHED</th><th>WORKLOAD</th></tr></thead><tbody><?php foreach ($transcribers as $person): $active = (int) $person['assigned_count']; ?><tr><td><strong><?= e($person['full_name'] ?: $person['username']) ?></strong></td><td>@<?= e($person['username']) ?></td><td><a class="count-chip" href="index.php?<?= e(http_build_query(['page' => 'assignments', 'status' => 'assigned', 'transcriber' => $person['username']])) ?>"><?= $active ?> · view</a></td><td><?= (int) $person['finished_count'] ?></td><td><div class="workload-track"><span style="width:<?= min(100, $active * 10) ?>%"></span></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
                    </section>
                <?php elseif ($page === 'accounts'): ?>
                    <section class="page-heading"><div><p class="eyebrow accent-text">ADMINISTRATION</p><h1>Account management</h1><p class="muted">Create accounts, manage access, and set replacement passwords for the TMS team.</p></div></section>
                    <section class="panel account-list-panel">
                        <div class="panel-heading account-list-heading"><div><h2>Accounts</h2><p><?= count($accounts) ?> matching accounts · username is fixed to preserve assignments</p></div><button class="button primary account-create-trigger" type="button" data-account-create-toggle aria-expanded="false" aria-controls="account-create-panel">+ Create account</button></div>
                        <form method="get" action="index.php" class="account-filter-form">
                            <input type="hidden" name="page" value="accounts">
                            <label class="account-search-field"><span class="visually-hidden">Search accounts</span><input type="search" name="account_q" value="<?= e($accountSearch) ?>" placeholder="Search name or username"></label>
                            <label><span class="visually-hidden">Filter by role</span><select name="account_role"><option value="">All roles</option><?php foreach ($validAccountRoles as $roleOption): ?><option value="<?= e($roleOption) ?>" <?= $accountRoleFilter === $roleOption ? 'selected' : '' ?>><?= e($roleOption) ?></option><?php endforeach; ?></select></label>
                            <label><span class="visually-hidden">Filter by status</span><select name="account_status"><option value="">All statuses</option><?php foreach ($validAccountStatuses as $statusOption): ?><option value="<?= e($statusOption) ?>" <?= $accountStatusFilter === $statusOption ? 'selected' : '' ?>><?= e($statusOption) ?></option><?php endforeach; ?></select></label>
                            <button class="button secondary" type="submit">Filter</button>
                            <?php if ($accountSearch !== '' || $accountRoleFilter !== '' || $accountStatusFilter !== ''): ?><a class="button quiet account-clear-filter" href="index.php?page=accounts">Clear filters</a><?php endif; ?>
                        </form>
                        <div id="account-create-panel" class="account-create-drawer" hidden>
                            <div class="account-create-intro"><div><strong>Create a team account</strong><p>Choose the role and set a temporary password for the new user.</p></div><button type="button" class="icon-link account-create-close" data-account-create-toggle aria-label="Close create account form">×</button></div>
                            <form method="post" action="index.php?page=accounts" class="account-create-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="account_create">
                                <label>Username<input name="username" required maxlength="100" pattern="[A-Za-z0-9._-]{2,100}" autocomplete="off"></label>
                                <label>Full name<input name="full_name" required maxlength="200"></label>
                                <label>Gender<select name="gender"><option value="">Not specified</option><option>Female</option><option>Male</option><option>Other</option></select></label>
                                <label>Role<select name="role" required><option value="Recorder">Recorder</option><option value="Transcriber">Transcriber</option><option value="Supervisor">Supervisor</option></select></label>
                                <label>Initial status<select name="status" required><option value="Active">Active</option><option value="Pending">Pending</option><option value="Inactive">Inactive</option></select></label>
                                <label>Temporary password<input type="password" name="new_password" required minlength="12" maxlength="200" autocomplete="new-password"></label>
                                <div class="account-create-submit"><span>At least 12 characters · Password is securely hashed</span><button class="button primary" type="submit">Create account</button></div>
                            </form>
                        </div>
                        <?php if (!$accounts): ?><div class="empty-state"><strong>No accounts match</strong><p>Try a different search or filter, or create a new account.</p></div><?php else: ?>
                            <div class="account-list">
                                <?php foreach ($accounts as $account): $accountId = (int) $account['id']; $isCurrentAccount = strcasecmp((string) $account['username'], (string) $supervisor['username']) === 0; ?>
                                    <article class="account-card">
                                        <div class="account-identity"><span class="avatar"><?= e(strtoupper(substr((string) ($account['full_name'] ?: $account['username'] ?: '?'), 0, 1))) ?></span><div><strong><?= e($account['full_name'] ?: 'Name not set') ?><?= $isCurrentAccount ? ' · You' : '' ?></strong><small>@<?= e($account['username'] ?: 'username not set') ?> · <?= e($account['req_date']) ?></small></div><span class="account-role-badge"><?= e($account['role']) ?></span><span class="status-pill <?= strtolower((string) $account['status']) === 'active' ? 'status-finished' : 'status-neutral' ?>"><?= e($account['status'] ?: 'Unknown') ?></span><button class="button small secondary account-edit-toggle" type="button" data-account-edit-toggle aria-expanded="false">Manage</button></div>
                                        <div class="account-edit-drawer" hidden>
                                        <form method="post" action="index.php?page=accounts" class="account-edit-form">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="account_update">
                                            <input type="hidden" name="account_id" value="<?= $accountId ?>">
                                            <label>Full name<input name="full_name" value="<?= e($account['full_name']) ?>" required maxlength="200"></label>
                                            <label>Gender<select name="gender"><option value="" <?= $account['gender'] === '' ? 'selected' : '' ?>>Not specified</option><?php foreach (['Female', 'Male', 'Other'] as $genderOption): ?><option value="<?= e($genderOption) ?>" <?= $account['gender'] === $genderOption ? 'selected' : '' ?>><?= e($genderOption) ?></option><?php endforeach; ?></select></label>
                                            <label>Role<select name="role" <?= $isCurrentAccount ? 'disabled' : '' ?>><?php foreach (['Recorder', 'Transcriber', 'Supervisor'] as $roleOption): ?><option value="<?= e($roleOption) ?>" <?= $account['role'] === $roleOption ? 'selected' : '' ?>><?= e($roleOption) ?></option><?php endforeach; ?></select><?php if ($isCurrentAccount): ?><input type="hidden" name="role" value="Supervisor"><?php endif; ?></label>
                                            <label>Status<select name="status" <?= $isCurrentAccount ? 'disabled' : '' ?>><?php foreach (['Active', 'Pending', 'Inactive'] as $statusOption): ?><option value="<?= e($statusOption) ?>" <?= strcasecmp((string) $account['status'], $statusOption) === 0 ? 'selected' : '' ?>><?= e($statusOption) ?></option><?php endforeach; ?></select><?php if ($isCurrentAccount): ?><input type="hidden" name="status" value="Active"><?php endif; ?></label>
                                            <button class="button small secondary account-save-button" type="submit">Save account</button>
                                        </form>
                                        <form method="post" action="index.php?page=accounts" class="account-reset-form">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="account_reset_password">
                                            <input type="hidden" name="account_id" value="<?= $accountId ?>">
                                            <label>Set new password<input type="password" name="new_password" required minlength="12" maxlength="200" autocomplete="new-password" placeholder="At least 12 characters"></label>
                                            <button class="button small primary" type="submit">Reset password</button>
                                        </form>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php elseif ($page === 'reports'): ?>
                    <?php if ($reportType === 'weekly'): ?>
                    <section class="page-heading"><div><p class="eyebrow accent-text">WEEKLY PERFORMANCE</p><h1>Weekly transcriber report</h1><p class="muted">Data range (Monday–Friday): <?= e($weekStart->format('M j, Y')) ?> – <?= e($weekEnd->format('M j, Y')) ?> · based on completion date</p></div><button class="button primary no-print" type="button" data-print data-weekly-print disabled>Preparing report…</button></section>
                    <?php if ($weekDateError !== null): ?><div class="notice error" role="alert"><?= e($weekDateError) ?></div><?php endif; ?>
                    <section class="panel weekly-controls no-print">
                        <form method="get" class="weekly-filter-form">
                            <input type="hidden" name="page" value="reports"><input type="hidden" name="type" value="weekly">
                            <label>Choose a date in the week<input type="date" name="week" value="<?= e($weekStart->format('Y-m-d')) ?>"></label>
                            <button class="button secondary" type="submit">Generate week</button>
                            <a class="button quiet" href="index.php?<?= e(http_build_query(['page' => 'reports', 'type' => 'weekly', 'week' => $weekStart->modify('-7 days')->format('Y-m-d')])) ?>">← Previous week</a>
                            <a class="button quiet" href="index.php?<?= e(http_build_query(['page' => 'reports', 'type' => 'weekly', 'week' => $weekStart->modify('+7 days')->format('Y-m-d')])) ?>">Next week →</a>
                        </form>
                        <form method="get" class="weekly-transcriber-form">
                            <input type="hidden" name="page" value="reports"><input type="hidden" name="type" value="weekly"><input type="hidden" name="week" value="<?= e($weekStart->format('Y-m-d')) ?>"><input type="hidden" name="include_filter" value="1">
                            <strong>Transcriber tables</strong><span class="muted-small">Select who to include in this report.</span>
                            <div class="weekly-checkboxes"><?php foreach ($weeklyTranscriberOptions as $option): ?><label><input type="checkbox" name="include[]" value="<?= e($option['username']) ?>" <?= isset($selectedSet[strtolower($option['username'])]) ? 'checked' : '' ?>> <?= e($option['name']) ?></label><?php endforeach; ?></div>
                            <button class="button secondary small" type="submit">Update tables</button>
                        </form>
                    </section>
                    <?php if ($weekDateError === null && !$weeklyTranscribers): ?><section class="panel empty-state"><strong>No transcriber tables selected</strong><p>Select one or more transcribers above to include them in the report.</p></section><?php endif; ?>
                    <div class="weekly-report" data-weekly-report>
                        <?php foreach ($weeklyTranscribers as $username => $person):
                            $personRecords = $weeklyRecordsByUser[$username] ?? [];
                            $dayDates = [];
                            for ($dayOffset = 0; $dayOffset < 5; $dayOffset++) {
                                $dayDates[] = $weekStart->modify('+' . $dayOffset . ' days');
                            }
                        ?>
                        <section class="weekly-person" data-weekly-person data-transcriber="<?= e($person['username']) ?>" data-name="<?= e($person['name']) ?>" data-expected-seconds="18000">
                            <div class="weekly-person-heading"><div><p class="eyebrow accent-text">WEEKLY TRANSCRIBER SUMMARY</p><h2><?= e($person['name']) ?></h2><span class="muted-small"><?= e($person['username'] === '@unassigned' ? 'No transcriber recorded' : $person['username']) ?></span></div><span class="weekly-rank" data-weekly-rank>Calculating rank…</span></div>
                            <div class="weekly-summary-grid">
                                <article class="weekly-summary-card"><span>Files completed</span><strong data-weekly-files><?= number_format(count($personRecords)) ?></strong></article>
                                <article class="weekly-summary-card"><span>Audio completed</span><strong data-weekly-total>Loading audio…</strong></article>
                                <article class="weekly-summary-card"><span>Expected / week</span><strong>5 hours</strong><small>1 hour per weekday</small></article>
                                <article class="weekly-summary-card"><span>Remaining to target</span><strong data-weekly-remaining>Calculating…</strong></article>
                            </div>
                            <section class="weekly-subsection"><h3>Daily progress · Monday–Friday</h3><div class="table-wrap"><table class="weekly-table"><thead><tr><th>WEEKDAY</th><th>DATE</th><th>AUDIO COMPLETED</th></tr></thead><tbody>
                                <?php foreach ($dayDates as $dayDate): ?><tr data-weekly-day data-date="<?= e($dayDate->format('Y-m-d')) ?>"><td><?= e($dayDate->format('l')) ?></td><td><?= e($dayDate->format('M j')) ?></td><td data-weekly-day-total>Calculating…</td></tr><?php endforeach; ?>
                            </tbody><tfoot><tr><th colspan="2">Average audio per weekday</th><th data-weekly-average>Calculating…</th></tr></tfoot></table></div></section>
                            <section class="weekly-subsection"><h3>Completed files <span class="muted-small">(<?= count($personRecords) ?>)</span></h3>
                                <?php if (!$personRecords): ?><div class="empty-state">No files completed by this transcriber during this week.</div><?php else: ?>
                                <div class="table-wrap"><table class="weekly-table"><thead><tr><th>R.N.</th><th>FILE NUMBER</th><th>MACHINE NUMBER</th><th>DURATION</th><th>AUDIO</th><th>COMPLETED</th><th>REMARK</th></tr></thead><tbody>
                                    <?php $weeklyRowNumber = 0; foreach ($personRecords as $record): $weeklyRowNumber++; $weeklyAudioSource = audioUrl($record['audio'] ?? '', (int) $record['id']); ?>
                                        <tr data-weekly-record data-finished-date="<?= e($record['finished_date']) ?>" data-audio-src="<?= e($weeklyAudioSource ?? '') ?>">
                                            <td><?= $weeklyRowNumber ?></td>
                                            <td><?= e($record['file_num']) ?></td>
                                            <td><?= e($record['machine_num']) ?></td>
                                            <td data-weekly-record-duration><?= $weeklyAudioSource ? 'Loading…' : 'Unavailable' ?></td>
                                            <td><?php if ($weeklyAudioSource): ?><button type="button" class="audio-launch" data-audio-src="<?= e($weeklyAudioSource) ?>" data-audio-title="Case #<?= e($record['file_num'] ?: $record['machine_num']) ?> · <?= e($record['transcriber'] ?: 'Unassigned') ?>">▶ Listen</button><?php else: ?><span class="muted-small"><?= e($record['audio'] ? 'Configure the audio directory' : 'No audio file') ?></span><?php endif; ?></td>
                                            <td><?= e($record['finished_date']) ?></td>
                                            <td class="weekly-remark"><?= e($record['remark'] ?: '—') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody></table></div><?php endif; ?>
                            </section>
                        </section>
                        <?php endforeach; ?>
                    </div>
                    <p class="weekly-duration-note">Audio duration is read from each recording. Rows marked unavailable are excluded from duration totals and can affect duration-based rankings.</p>
                    <?php else: ?>
                    <section class="page-heading"><div><p class="eyebrow accent-text">PERFORMANCE & OUTPUT</p><h1>Workload reports</h1><p class="muted">Review recording volume and completion by transcriber.</p></div></section>
                    <section class="panel report-filter"><form method="get" class="filter-form"><input type="hidden" name="page" value="reports"><input type="hidden" name="charts" value="0"><label>Report type<select name="type"><option value="transcribers" <?= $reportType === 'transcribers' ? 'selected' : '' ?>>Work by transcriber</option><option value="statuses" <?= $reportType === 'statuses' ? 'selected' : '' ?>>Status distribution</option><option value="daily" <?= $reportType === 'daily' ? 'selected' : '' ?>>Daily volume</option></select></label><label>From<input type="date" name="from" value="<?= e($fromDate) ?>"></label><label>To<input type="date" name="to" value="<?= e($toDate) ?>"></label><label class="checkbox-label"><input type="checkbox" name="charts" value="1" <?= $showCharts ? 'checked' : '' ?>> Include charts</label><button class="button primary" type="submit">Apply</button><a class="button secondary" href="index.php?page=reports">Clear</a></form><div class="report-actions"><a class="button secondary" href="index.php?<?= e(http_build_query(['page' => 'reports', 'type' => 'weekly', 'week' => date('Y-m-d')])) ?>">▦ Weekly report</a><button class="button secondary" type="button" data-print>Print</button><?php if ($reportDateError === null): ?><a class="button secondary export-button" href="index.php?<?= e(http_build_query(['page' => 'reports', 'type' => $reportType, 'from' => $fromDate, 'to' => $toDate, 'charts' => $showCharts ? '1' : '0', 'export' => 'csv'])) ?>">Export CSV</a><?php endif; ?></div></section>
                    <?php if ($reportDateError !== null): ?><div class="notice error" role="alert"><?= e($reportDateError) ?></div><?php endif; ?>
                    <?php if ($reportDateError === null): ?>
                    <section class="metric-grid report-metrics"><article class="metric-card"><div class="metric-top"><span>Recordings</span></div><strong class="metric-value"><?= number_format($reportSummary['total']) ?></strong><span class="metric-caption">In selected date range</span></article><article class="metric-card"><div class="metric-top"><span>Finished</span></div><strong class="metric-value"><?= number_format($reportSummary['finished']) ?></strong><span class="metric-caption">Completed transcription</span></article><article class="metric-card"><div class="metric-top"><span>Assigned</span></div><strong class="metric-value"><?= number_format($reportSummary['assigned']) ?></strong><span class="metric-caption">Currently in progress</span></article></section>
                    <?php if ($showCharts && $reportRows): ?><section class="panel chart-panel report-chart"><div class="panel-heading"><div><h2><?= e(match ($reportType) {'statuses' => 'Status breakdown', 'daily' => 'Daily recording volume', default => 'Team output comparison'}) ?></h2><p><?= e(match ($reportType) {'statuses' => 'Share of recordings in each workflow state', 'daily' => 'Recorded case volume by day', default => 'Total recording count by transcriber'}) ?></p></div></div><div class="chart-content chart-wide"><canvas id="report-chart" class="chart-canvas" role="img" aria-label="<?= e($title) ?> chart"></canvas></div></section><?php endif; ?>
                    <section class="panel"><div class="panel-heading"><div><h2><?= e(match ($reportType) {'statuses' => 'Status distribution', 'daily' => 'Daily recording volume', default => 'Transcriber output'}) ?></h2><p>Totals for the selected recording dates</p></div></div><?php if (!$reportRows): ?><div class="empty-state"><p>No report data for this date range.</p></div><?php else: ?><div class="table-wrap"><table><thead><tr><?php foreach ($reportColumns as $column): ?><th><?= e(strtoupper($column)) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($reportRows as $row): ?><tr><?php foreach ($row as $value): ?><td><?= e($value) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
                    <?php endif; ?>
                    <?php endif; ?>
                <?php elseif ($page === 'settings'): ?>
                    <section class="page-heading"><div><p class="eyebrow accent-text">PERSONAL PREFERENCES</p><h1>Workspace settings</h1><p class="muted">Adjust display and navigation preferences for this browser.</p></div></section>
                    <section class="panel settings-panel"><div class="panel-heading"><div><h2>Calendar and language</h2><p>Choose how dates are displayed in the Supervisor workspace.</p></div></div><div class="settings-row"><div><strong>Date calendar</strong><p>Use the Ethiopian calendar and Amharic month names for displayed dates.</p></div><label class="settings-select-label" for="calendar-mode">Display calendar<select id="calendar-mode" data-calendar-setting><option value="gregorian">Gregorian</option><option value="ethiopian">Ethiopian (Amharic)</option></select></label></div><div class="settings-row"><div><strong>Navigation sidebar</strong><p>Collapse the sidebar to icon-only mode on wide screens.</p></div><button class="button secondary" type="button" data-sidebar-preference>Toggle collapsed sidebar</button></div><div class="settings-row"><div><strong>Audio playback</strong><p>Prefers the protected Recorder storage directory configured by TMS_AUDIO_ROOT; the existing Recorder URL is used only when no root is configured.</p></div><span class="settings-badge <?= $audioConfigurationReady ? 'ready' : 'not-ready' ?>"><?= $audioConfigurationReady ? 'Configured' : 'Needs configuration' ?></span></div></section>
                <?php else:
                    $isQueue = $page === 'assignments';
                    $isPending = $page === 'pending';
                    $heading = $isQueue ? 'Assignment queue' : ($isPending ? 'Pending recordings' : ($page === 'finished' ? 'Finished recordings' : 'Suspended recordings'));
                    $kicker = $isQueue ? 'DISTRIBUTION WORKFLOW' : ($isPending ? 'PENDING REVIEW' : ($page === 'finished' ? 'COMPLETED WORK' : 'ON HOLD'));
                ?>
                    <section class="page-heading"><div><p class="eyebrow accent-text"><?= e($kicker) ?></p><h1><?= e($heading) ?></h1><p class="muted"><?= $isQueue ? 'Unassigned recordings are prioritized first; review existing assignments separately or filter by transcriber.' : ($isPending ? 'Review pending recordings, change their transcriber, or return them to Uploaded or Assigned.' : ($page === 'finished' ? 'Review completed work, audio duration, and overtime classification from the TMS record.' : 'Review paused assignments and restore work to the active queue.')) ?></p></div></section>
                    <section class="panel">
                        <?php if ($isQueue): ?><nav class="queue-tabs" aria-label="Assignment status filters"><?php foreach (['' => ['All recordings', $metrics['uploaded'] + $metrics['assigned']], 'uploaded' => ['Unassigned', $metrics['uploaded']], 'assigned' => ['Assigned', $metrics['assigned']]] as $filter => [$filterLabel, $filterCount]): ?><a class="<?= $statusFilter === $filter ? 'selected' : '' ?>" href="index.php?<?= e(http_build_query(['page' => 'assignments', 'status' => $filter, 'transcriber' => $transcriberFilter])) ?>"><?= e($filterLabel) ?><span><?= (int) $filterCount ?></span></a><?php endforeach; ?></nav><?php endif; ?>
                        <?php if ($page === 'finished'): ?><nav class="queue-tabs" aria-label="Finished work filters"><a class="<?= !$overtimeOnly ? 'selected' : '' ?>" href="index.php?page=finished">All finished <span><?= (int) $metrics['finished'] ?></span></a><a class="<?= $overtimeOnly ? 'selected' : '' ?>" href="index.php?page=finished&amp;ot=1">OT cases <span><?= (int) $metrics['overtime'] ?></span></a></nav><?php endif; ?>
                        <div class="panel-heading"><div><h2><?= e($heading) ?></h2><p><?= count($records) ?> records<?= count($records) === 250 ? ' · showing first 250' : '' ?><?= $isQueue && $statusFilter === '' ? ' · unassigned first' : '' ?></p></div><form method="get" class="search-form"><input type="hidden" name="page" value="<?= e($page) ?>"><?php if ($isQueue): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><label class="visually-hidden" for="transcriber-filter">Filter by transcriber</label><select id="transcriber-filter" name="transcriber"><option value="">All transcribers</option><?php foreach ($transcribers as $person): ?><option value="<?= e($person['username']) ?>" <?= $transcriberFilter === $person['username'] ? 'selected' : '' ?>><?= e($person['full_name'] ?: $person['username']) ?></option><?php endforeach; ?></select><?php endif; ?><label class="visually-hidden" for="record-search">Search records</label><input id="record-search" type="search" name="q" value="<?= e($search) ?>" placeholder="Search case, party, transcriber…"><button class="button secondary" type="submit">Filter</button></form></div>
                        <?php if (!$records): ?><div class="empty-state"><span class="empty-icon"><?= $isQueue ? '✓' : '⌕' ?></span><strong><?= $search ? 'No matching recordings' : 'Nothing in this queue' ?></strong><p><?= $search ? 'Try changing your search terms.' : 'Records will appear here as their status changes.' ?></p></div><?php else: ?>
                        <div class="table-wrap records-scroll"><table class="records-table"><thead><tr><th>CASE</th><th>CASE PARTIES</th><?php if ($isQueue): ?><th>APPOINTMENT</th><?php else: ?><th>TRANSCRIBER</th><?php endif; ?><th>STATUS</th><th>AUDIO</th><?php if ($page === 'finished'): ?><th>FINISHED / TURNAROUND</th><th>OT / WORK TYPE</th><th>ACTIONS</th><?php else: ?><th>ACTIONS</th><?php endif; ?></tr></thead><tbody>
                            <?php foreach ($records as $record): $audioSource = audioUrl($record['audio'] ?? '', (int) $record['id']); ?><tr><td><strong>#<?= e($record['file_num'] ?: $record['machine_num']) ?></strong><small>Machine <?= e($record['machine_num']) ?> · <?= dateValue($record['rec_date']) ?></small></td><td><?= e($record['Apllicant'] ?: '—') ?><small>vs <?= e($record['Defendent'] ?: '—') ?></small></td><?php if ($isQueue): ?><td><?= dateValue($record['appointed_on']) ?></td><?php else: ?><td><?= e($record['transcriber'] ?: 'Not assigned') ?></td><?php endif; ?><td><span class="status-pill <?= e(statusClass((string) $record['status'])) ?>"><?= e(statusLabel((string) $record['status'])) ?></span></td><td class="audio-cell"><?php if ($audioSource): ?><button type="button" class="audio-launch" data-audio-src="<?= e($audioSource) ?>" data-audio-title="Case #<?= e($record['file_num'] ?: $record['machine_num']) ?> · <?= e($record['transcriber'] ?: 'Unassigned') ?>">▶ Listen</button><span class="audio-duration" data-audio-duration data-audio-src="<?= e($audioSource) ?>">Checking length…</span><?php else: ?><span class="muted-small"><?= e($record['audio'] ? 'Configure the audio directory' : 'No audio file') ?></span><?php endif; ?></td><?php if ($page === 'finished'): ?><td><?= dateValue($record['finished_date']) ?><small><?= $record['turnaround_days'] !== null ? e((string) $record['turnaround_days']) . ' days from assignment' : 'Turnaround unavailable' ?></small></td><td>
                                <?= strtoupper(trim((string) $record['overtime'])) === 'OT' ? '<span class="status-pill status-suspended">OT</span>' : e($record['overtime'] ?: '—') ?></td><td class="action-cell">
                            <?php else: ?><td class="action-cell"><?php endif; ?>
                                <?php if ($isQueue && in_array(strtolower(trim((string) $record['status'])), ['uploaded', 'assigned'], true)): ?>
                                    <div class="queue-actions"><form method="post" class="assign-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="assign"><input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>"><label class="visually-hidden" for="transcriber-<?= (int) $record['id'] ?>">Assign transcriber</label><select id="transcriber-<?= (int) $record['id'] ?>" name="transcriber" required><option value="">Choose transcriber</option><?php foreach ($transcribers as $person): ?><option value="<?= e($person['username']) ?>" <?= $person['username'] === $record['transcriber'] ? 'selected' : '' ?>><?= e($person['full_name'] ?: $person['username']) ?></option><?php endforeach; ?></select><button class="button small primary" type="submit"><?= strtolower((string) $record['status']) === 'assigned' ? 'Reassign' : 'Assign' ?></button></form>
                                    <?php if (strtolower(trim((string) $record['status'])) === 'assigned'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="suspend"><input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>"><button class="button small quiet" type="submit" data-confirm="Suspend this assignment?">Hold</button></form><?php endif; ?></div>
                                <?php elseif ($isPending): ?><form method="post" class="pending-manage-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="pending_update"><input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>"><label>Transcriber<select name="transcriber"><option value="__keep__">Keep current (<?= e($record['transcriber'] ?: 'unassigned') ?>)</option><option value="__unassigned__">Unassigned</option><?php foreach ($transcribers as $person): ?><option value="<?= e($person['username']) ?>"><?= e($person['full_name'] ?: $person['username']) ?></option><?php endforeach; ?></select></label><label>New status<select name="next_status"><option value="Pending">Keep Pending</option><option value="Assigned">Assigned</option><option value="Uploaded">Uploaded / unassigned</option></select></label><button class="button small primary" type="submit">Update</button></form>
                                <?php elseif ($page === 'suspended'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>"><button class="button small secondary" type="submit">Restore</button></form>
                                <?php elseif ($page === 'finished'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="return"><input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>"><button class="button small secondary" type="submit" data-confirm="Return this finished recording to the upload queue?">Return to queue</button></form><?php endif; ?>
                            </td></tr><?php endforeach; ?>
                        </tbody></table></div><?php endif; ?>
                    </section>
                <?php endif; ?>
                <footer class="page-footer"><span>Gedeo Zone High Court · Transcription Management</span><span>Supervisor workspace</span></footer>
            </div>
            <section id="audio-dock" class="audio-dock" aria-label="Recording player" hidden><div class="audio-dock-info"><strong id="audio-dock-title">Recording</strong><span id="audio-dock-duration">Audio duration: loading</span></div><audio id="mini-audio" controls preload="none"></audio><button class="audio-dock-close" type="button" data-audio-close aria-label="Close audio player">×</button></section>
        </main>
    </div>
<?php endif; ?>
<?php if ($loggedIn && $page === 'dashboard'): ?>
<script type="application/json" id="dashboard-chart-data"><?= json_encode(['status' => $dashboardStatusChart, 'workload' => $dashboardWorkChart], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<?php elseif ($loggedIn && $page === 'reports' && $showCharts && $reportRows): ?>
<script type="application/json" id="report-chart-data"><?= json_encode(['type' => $reportType, 'data' => $chartData], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<?php endif; ?>
</body>
</html>
