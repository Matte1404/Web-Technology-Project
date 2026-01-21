<?php
/** @var mysqli $conn */
include 'db/db_config.php';
include 'includes/auth.php';

require_login();

$userId = $_SESSION['user_id'];
$isAdmin = is_admin();
$user = null;
$rentals = [];
$totalSpent = 0.0;
$profileFlash = $_SESSION['profile_flash'] ?? null;
unset($_SESSION['profile_flash']);
$issueErrors = [];
$now = date('Y-m-d H:i:s');
$activeRental = null;
$changeLog = [];
$changeLogError = null;
$transactions = [];
$transactionsError = null;
$issueReports = [];
$issueReportsError = null;
$adminIssueErrors = [];

function format_minutes(int $minutes): string
{
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    if ($hours > 0) {
        return $hours . 'h ' . $mins . 'm';
    }
    return $mins . 'm';
}

function type_label(string $type): string
{
    $type = strtolower(trim($type));
    if ($type === 'bike') {
        return 'Bike';
    }
    if ($type === 'scooter') {
        return 'Scooter';
    }
    return ucfirst($type);
}

function transaction_label(string $type): string
{
    $type = strtolower(trim($type));
    if ($type === 'topup') {
        return 'Credit added';
    }
    if ($type === 'rental') {
        return 'Rental charge';
    }
    return ucfirst($type);
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_issue') {
    $issueId = (int) ($_POST['issue_id'] ?? 0);
    $status = strtolower(trim($_POST['status'] ?? 'open'));
    $notes = trim($_POST['admin_notes'] ?? '');
    $allowedStatuses = ['open', 'closed'];

    if ($issueId <= 0) {
        $adminIssueErrors[] = 'Invalid issue.';
    }
    if (!in_array($status, $allowedStatuses, true)) {
        $adminIssueErrors[] = 'Invalid status.';
    }
    if (strlen($notes) > 2000) {
        $adminIssueErrors[] = 'Notes are too long.';
    }

    if (!$adminIssueErrors) {
        $stmt = mysqli_prepare($conn, "UPDATE issues SET status = ?, admin_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ssii", $status, $notes, $userId, $issueId);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            if ($ok) {
                $_SESSION['profile_flash'] = ['type' => 'success', 'message' => 'Issue updated successfully.'];
                header('Location: profile.php#issue-reports');
                exit;
            }
            $adminIssueErrors[] = 'Issue update failed.';
        } else {
            $adminIssueErrors[] = 'Issue update failed.';
        }
    }
}

if (!$isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report_issue') {
    $rentalId = (int) ($_POST['rental_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');

    if ($rentalId <= 0) {
        $issueErrors[] = 'Invalid rental.';
    }
    if (strlen($description) < 10) {
        $issueErrors[] = 'Description is too short (minimum 10 characters).';
    }

    if (!$issueErrors) {
        $stmt = mysqli_prepare($conn, "SELECT id, vehicle_id FROM rentals WHERE id = ? AND user_id = ? AND start_time <= ? AND (end_time IS NULL OR end_time >= ?)");
        mysqli_stmt_bind_param($stmt, "iiss", $rentalId, $userId, $now, $now);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $activeRow = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if (!$activeRow) {
            $issueErrors[] = 'The selected rental is not active.';
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO issues (user_id, vehicle_id, rental_id, description) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "iiis", $userId, $activeRow['vehicle_id'], $rentalId, $description);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($ok) {
                $_SESSION['profile_flash'] = ['type' => 'success', 'message' => 'Issue reported successfully.'];
                header('Location: profile.php#active-rental');
                exit;
            }
            $issueErrors[] = 'Issue report failed.';
        }
    }
}

$stmt = mysqli_prepare($conn, "SELECT name, email, credit FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

if ($isAdmin) {
    $stmt = mysqli_prepare($conn, "SELECT c.action, c.entity, c.entity_id, c.details, c.created_at, u.name AS admin_name FROM change_log c JOIN users u ON c.admin_id = u.id ORDER BY c.created_at DESC LIMIT 50");
    if ($stmt) {
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $changeLog[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    } else {
        $changeLogError = 'Change log is not available. Run the schema update.';
    }

    $stmt = mysqli_prepare($conn, "SELECT i.id, i.description, i.status, i.admin_notes, i.reviewed_at, i.created_at, u.name AS user_name, u.email AS user_email, v.name AS vehicle_name, v.type AS vehicle_type, r.start_time AS rental_start, reviewer.name AS reviewer_name FROM issues i JOIN users u ON i.user_id = u.id JOIN vehicles v ON i.vehicle_id = v.id LEFT JOIN rentals r ON i.rental_id = r.id LEFT JOIN users reviewer ON i.reviewed_by = reviewer.id ORDER BY i.created_at DESC");
    if ($stmt) {
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $issueReports[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    } else {
        $issueReportsError = 'Issue reports are not available. Run the schema update.';
    }
} else {
    $stmt = mysqli_prepare($conn, "SELECT r.id, r.vehicle_id, r.start_time, r.end_time, r.minutes, v.name AS vehicle_name, v.type AS vehicle_type FROM rentals r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.user_id = ? AND r.start_time <= ? AND (r.end_time IS NULL OR r.end_time >= ?) ORDER BY r.start_time DESC LIMIT 1");
    mysqli_stmt_bind_param($stmt, "iss", $userId, $now, $now);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $activeRental = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if ($activeRental) {
        $startTs = strtotime($activeRental['start_time']);
        $endTs = $activeRental['end_time'] ? strtotime($activeRental['end_time']) : null;
        if (!$endTs && isset($activeRental['minutes'])) {
            $endTs = $startTs + ((int) $activeRental['minutes'] * 60);
        }
        $nowTs = time();
        $elapsedMinutes = $startTs ? (int) floor(max(0, $nowTs - $startTs) / 60) : 0;
        $remainingMinutes = null;
        if ($endTs) {
            $remainingMinutes = (int) ceil(max(0, $endTs - $nowTs) / 60);
        }
        $activeRental['elapsed_minutes'] = $elapsedMinutes;
        $activeRental['remaining_minutes'] = $remainingMinutes;
        $activeRental['end_estimated'] = $endTs ? date('Y-m-d H:i:s', $endTs) : null;
    }

    $stmt = mysqli_prepare($conn, "SELECT r.id, r.start_time, r.end_time, r.minutes, r.total_cost, v.name AS vehicle_name, v.type AS vehicle_type FROM rentals r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.user_id = ? ORDER BY r.start_time DESC");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rentals[] = $row;
            if (isset($row['total_cost'])) {
                $totalSpent += (float) $row['total_cost'];
            }
        }
    }
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "SELECT type, amount, balance_after, description, created_at FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $transactions[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    } else {
        $transactionsError = 'Transactions are not available. Run the schema update.';
    }
}

include 'includes/header.php';
?>

    <div class="container py-8 mx-auto" style="max-width: 900px;">
        <h1 class="display-5 fw-bold text-dark mb-4"><?php echo $isAdmin ? 'Admin Dashboard' : 'Profile'; ?></h1>
        <div class="bg-white rounded-4 shadow-sm p-5 mb-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <p class="text-muted mb-1">Name</p>
                    <h4 class="fw-bold mb-0"><?php echo htmlspecialchars($user['name'] ?? $_SESSION['user_name']); ?></h4>
                    <p class="text-muted mb-0"><?php echo htmlspecialchars($user['email'] ?? ''); ?></p>
                </div>
                <div class="text-end">
                    <?php if ($isAdmin): ?>
                        <p class="text-muted mb-1">Role</p>
                        <h4 class="fw-bold mb-0">Administrator</h4>
                    <?php else: ?>
                        <p class="text-muted mb-1">Total spent</p>
                        <h4 class="fw-bold text-danger mb-0">EUR <?php echo number_format($totalSpent, 2); ?></h4>
                        <div class="mt-3">
                            <p class="text-muted mb-1">Wallet Credit</p>
                            <h4 class="fw-bold text-success mb-0">EUR <?php echo number_format((float)($user['credit'] ?? 0), 2); ?></h4>
                            <a href="wallet.php" class="btn btn-outline-secondary btn-sm mt-2">Open wallet</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <hr>
            <p class="small text-muted mb-0">Status: Authenticated with campus credentials.</p>
        </div>

        <?php if ($profileFlash): ?>
            <div class="alert alert-<?php echo htmlspecialchars($profileFlash['type']); ?>">
                <?php echo htmlspecialchars($profileFlash['message']); ?>
            </div>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
            <div class="bg-white rounded-4 shadow-sm p-4 mb-4" id="issue-reports">
                <h5 class="fw-bold mb-3">Issue reports</h5>

                <?php if ($adminIssueErrors): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($adminIssueErrors as $error): ?>
                            <div><?php echo htmlspecialchars($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($issueReportsError): ?>
                    <div class="alert alert-warning"><?php echo htmlspecialchars($issueReportsError); ?></div>
                <?php elseif (!$issueReports): ?>
                    <p class="text-muted mb-0">No reports yet.</p>
                <?php else: ?>
                    <?php foreach ($issueReports as $report): ?>
                        <?php
                        $reportStatus = strtolower(trim($report['status']));
                        $statusClass = $reportStatus === 'closed' ? 'bg-success' : 'bg-warning text-dark';
                        $reviewedLabel = $report['reviewed_at']
                            ? 'Reviewed ' . $report['reviewed_at'] . ($report['reviewer_name'] ? ' by ' . $report['reviewer_name'] : '')
                            : 'Not reviewed yet';
                        ?>
                        <div class="border rounded-4 p-3 mb-3">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <div>
                                    <div class="fw-bold"><?php echo htmlspecialchars($report['vehicle_name']); ?> (<?php echo htmlspecialchars(type_label($report['vehicle_type'])); ?>)</div>
                                    <div class="text-muted small">Reported by <?php echo htmlspecialchars($report['user_name']); ?> (<?php echo htmlspecialchars($report['user_email']); ?>)</div>
                                    <div class="text-muted small">Reported on <?php echo htmlspecialchars($report['created_at']); ?></div>
                                    <?php if (!empty($report['rental_start'])): ?>
                                        <div class="text-muted small">Rental started <?php echo htmlspecialchars($report['rental_start']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <span class="badge <?php echo $statusClass; ?> rounded-pill align-self-start"><?php echo htmlspecialchars(ucfirst($reportStatus)); ?></span>
                            </div>
                            <div class="mt-3">
                                <div class="small text-muted">Report</div>
                                <div class="fw-bold"><?php echo nl2br(htmlspecialchars($report['description'])); ?></div>
                            </div>
                            <form method="post" class="mt-3">
                                <input type="hidden" name="action" value="update_issue">
                                <input type="hidden" name="issue_id" value="<?php echo htmlspecialchars((string) $report['id']); ?>">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Status</label>
                                        <select name="status" class="form-select">
                                            <option value="open" <?php echo $reportStatus === 'open' ? 'selected' : ''; ?>>Open</option>
                                            <option value="closed" <?php echo $reportStatus === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                        </select>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label fw-bold">Admin notes</label>
                                        <textarea name="admin_notes" class="form-control" rows="2" maxlength="2000" placeholder="Add internal notes."><?php echo htmlspecialchars($report['admin_notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2">
                                    <div class="small text-muted"><?php echo htmlspecialchars($reviewedLabel); ?></div>
                                    <button type="submit" class="btn btn-outline-primary btn-sm">Save</button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-4 shadow-sm p-4">
                <h5 class="fw-bold mb-3">Change log</h5>
                <?php if ($changeLogError): ?>
                    <div class="alert alert-warning"><?php echo htmlspecialchars($changeLogError); ?></div>
                <?php elseif (!$changeLog): ?>
                    <p class="text-muted mb-0">No changes recorded yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Admin</th>
                                <th>Action</th>
                                <th>Entity</th>
                                <th>Details</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($changeLog as $entry): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($entry['created_at']); ?></td>
                                    <td><?php echo htmlspecialchars($entry['admin_name']); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($entry['action'])); ?></td>
                                    <td><?php echo htmlspecialchars($entry['entity']); ?> #<?php echo htmlspecialchars((string) $entry['entity_id']); ?></td>
                                    <td><?php echo htmlspecialchars($entry['details']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="bg-white rounded-4 shadow-sm p-4 mb-4" id="active-rental">
                <h5 class="fw-bold mb-3">Active rental</h5>

                <?php if ($issueErrors): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($issueErrors as $error): ?>
                            <div><?php echo htmlspecialchars($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($activeRental): ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="small text-muted">Vehicle</div>
                            <div class="fw-bold"><?php echo htmlspecialchars($activeRental['vehicle_name']); ?></div>
                            <div class="text-muted small">Type: <?php echo htmlspecialchars(type_label($activeRental['vehicle_type'])); ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">Started</div>
                            <div class="fw-bold"><?php echo htmlspecialchars($activeRental['start_time']); ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">Estimated end</div>
                            <div class="fw-bold"><?php echo htmlspecialchars($activeRental['end_estimated'] ?? 'Not available'); ?></div>
                        </div>
                    </div>
                    <div class="row g-3 mt-2">
                        <div class="col-md-3">
                            <div class="small text-muted">Elapsed time</div>
                            <div class="fw-bold"><?php echo format_minutes($activeRental['elapsed_minutes']); ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">Time remaining</div>
                            <div class="fw-bold">
                                <?php echo $activeRental['remaining_minutes'] === null ? 'Not available' : format_minutes($activeRental['remaining_minutes']); ?>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <h6 class="fw-bold mb-2">Report a possible issue</h6>
                    <form method="post">
                        <input type="hidden" name="action" value="report_issue">
                        <input type="hidden" name="rental_id" value="<?php echo htmlspecialchars((string) $activeRental['id']); ?>">
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3" maxlength="500" placeholder="Describe the issue you noticed." required></textarea>
                        </div>
                        <button class="btn btn-outline-danger" type="submit">Send report</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted mb-0">No active rentals right now.</p>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-4 shadow-sm p-4">
                <h5 class="fw-bold mb-3">Rental history</h5>
                <?php if (!$rentals): ?>
                    <p class="text-muted mb-0">No rentals yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Vehicle</th>
                                <th>Type</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Minutes</th>
                                <th>Cost</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rentals as $rental): ?>
                                <tr>
                                    <td class="fw-bold"><?php echo htmlspecialchars($rental['vehicle_name']); ?></td>
                                    <td><?php echo htmlspecialchars(type_label($rental['vehicle_type'])); ?></td>
                                    <td><?php echo htmlspecialchars($rental['start_time']); ?></td>
                                    <td><?php echo htmlspecialchars($rental['end_time'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($rental['minutes'] ?? 0)); ?></td>
                                    <td>EUR <?php echo htmlspecialchars(number_format((float) $rental['total_cost'], 2)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-4 shadow-sm p-4 mt-4">
                <h5 class="fw-bold mb-3">Transactions</h5>
                <?php if ($transactionsError): ?>
                    <div class="alert alert-warning"><?php echo htmlspecialchars($transactionsError); ?></div>
                <?php elseif (!$transactions): ?>
                    <p class="text-muted mb-0">No transactions yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Balance after</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                                <?php
                                $amount = (float) $transaction['amount'];
                                $amountLabel = ($amount > 0 ? '+' : '') . number_format($amount, 2);
                                $amountClass = $amount >= 0 ? 'text-success' : 'text-danger';
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($transaction['created_at']); ?></td>
                                    <td><?php echo htmlspecialchars(transaction_label($transaction['type'])); ?></td>
                                    <td><?php echo htmlspecialchars($transaction['description']); ?></td>
                                    <td class="<?php echo $amountClass; ?>">EUR <?php echo htmlspecialchars($amountLabel); ?></td>
                                    <td>EUR <?php echo htmlspecialchars(number_format((float) $transaction['balance_after'], 2)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

<?php include 'includes/footer.php'; ?>
