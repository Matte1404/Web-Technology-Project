<?php
/** @var mysqli $conn */
include 'db/db_config.php';
include 'includes/auth.php';

require_login();

if (is_admin()) {
    include 'includes/header.php';
    ?>
    <div class="container py-5" style="max-width: 720px;">
        <div class="alert alert-warning">
            Admin accounts cannot book vehicles.
        </div>
        <a class="btn btn-outline-secondary" href="admin.php">Go to admin panel</a>
    </div>
    <?php
    include 'includes/footer.php';
    exit;
}

$errors = [];
$success = null;
$vehicle = null;

function normalize_status(string $status): string
{
    $status = strtolower(trim($status));
    if (in_array($status, ['available', 'rented', 'maintenance', 'broken'], true)) {
        return $status;
    }
    return 'available';
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

$vehicleId = isset($_GET['vehicle_id']) ? (int) $_GET['vehicle_id'] : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
}

if ($vehicleId > 0) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM vehicles WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $vehicleId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $vehicle = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
}

if (!$vehicle) {
    $errors[] = 'Vehicle not found.';
}

$userCredit = 0.00;
if (isset($_SESSION['user_id'])) {
    $stmt = mysqli_prepare($conn, "SELECT credit FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($result)) {
        $userCredit = (float) $row['credit'];
    }
    mysqli_stmt_close($stmt);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    $minutes = (int) ($_POST['minutes'] ?? 0);
    $status = normalize_status($vehicle['status']);
    $estimatedCost = 0.0;

    if ($status !== 'available') {
        $errors[] = 'Vehicle not available.';
    }
    if ($minutes <= 0) {
        $errors[] = 'Enter usage minutes.';
    }
    if ($vehicle) {
        $estimatedCost = round(((float) $vehicle['hourly_price'] / 60) * $minutes, 2);
        if ($userCredit < $estimatedCost) {
            $errors[] = 'Insufficient credit. Please add funds to your wallet.';
        }
    }

    if (!$errors) {
        $start = date('Y-m-d H:i:s');
        $end = date('Y-m-d H:i:s', time() + ($minutes * 60));
        $cost = $estimatedCost;
        $newBalance = $userCredit - $cost;

        mysqli_begin_transaction($conn);

        $stmt = mysqli_prepare($conn, "INSERT INTO rentals (user_id, vehicle_id, start_time, end_time, minutes, total_cost) VALUES (?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "iissid", $_SESSION['user_id'], $vehicleId, $start, $end, $minutes, $cost);
        $insertOk = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($conn, "UPDATE users SET credit = credit - ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "di", $cost, $_SESSION['user_id']);
        $walletUpdateOk = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $transactionOk = true;
        $transactionStmt = mysqli_prepare($conn, "INSERT INTO transactions (user_id, type, amount, balance_after, description) VALUES (?, 'rental', ?, ?, ?)");
        if ($transactionStmt) {
            $amount = 0 - $cost;
            $description = "Rental for {$vehicle['name']}.";
            mysqli_stmt_bind_param($transactionStmt, "idds", $_SESSION['user_id'], $amount, $newBalance, $description);
            $transactionOk = mysqli_stmt_execute($transactionStmt);
            mysqli_stmt_close($transactionStmt);
        }

        $updateOk = false;
        if ($insertOk) {
            // Deduct battery: 0.5% per minute
            $batteryConsumption = ceil($minutes * 0.5);
            $stmt = mysqli_prepare($conn, "UPDATE vehicles SET status = 'rented', battery = GREATEST(0, battery - ?) WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $batteryConsumption, $vehicleId);
            $updateOk = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        if ($insertOk && $updateOk && $walletUpdateOk && $transactionOk) {
            mysqli_commit($conn);
            $success = 'Booking confirmed! You can see the summary in your profile.';
            $vehicle['status'] = 'rented';
            $userCredit -= $cost;
        } else {
            mysqli_rollback($conn);
            $errors[] = 'Booking failed.';
        }
    }
}

include 'includes/header.php';
?>
<main>
<div class="container py-5" id="main-content">
    <div class="row g-4">
        <div class="col-lg-7">
            <h1 class="fw-bold mb-3">Book your vehicle</h1>
            <p class="text-muted">Estimate the cost based on usage minutes.</p>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($vehicle): ?>
                <div class="form-section p-4 shadow-sm mb-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h2 class="h5 fw-bold mb-1"><?php echo htmlspecialchars($vehicle['name']); ?></h2>
                            <p class="text-muted mb-0">Type: <?php echo htmlspecialchars(type_label($vehicle['type'])); ?></p>
                        </div>
                        <span class="badge bg-light text-dark border">
                            <?php echo htmlspecialchars(ucfirst(normalize_status($vehicle['status']))); ?>
                        </span>
                    </div>
                    <hr>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="small text-muted">Location</div>
                            <div class="fw-bold"><?php echo htmlspecialchars($vehicle['location']); ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">Battery</div>
                            <div class="fw-bold"><?php echo htmlspecialchars((string) $vehicle['battery']); ?>%</div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">Price per hour</div>
                            <div class="fw-bold">EUR <?php echo htmlspecialchars((string) $vehicle['hourly_price']); ?></div>
                        </div>
                    </div>
                </div>

                <div class="form-section p-4 shadow-sm">
                    <form method="post" class="booking-form">
                        <input type="hidden" name="vehicle_id" value="<?php echo htmlspecialchars((string) $vehicleId); ?>">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label fw-bold" for="minutes-input">Duration (minutes)</label>
                                <input id="minutes-input" type="number" min="1" name="minutes" class="form-control" value="30" required>
                            </div>
                            <div class="col-md-4">
                                <div class="small text-muted">Estimated cost</div>
                                <div class="display-6 fw-bold text-danger" id="price-estimate" data-price-per-minute="<?php echo htmlspecialchars((string) ((float) $vehicle['hourly_price'] / 60)); ?>">EUR 0.00</div>
                            </div>
                            <div class="col-md-4">
                                <div class="small text-muted">Estimated end</div>
                                <div class="fw-bold" id="end-estimate">--</div>
                            </div>
                        </div>
                        <div class="mb-4">
                            <div class="small text-muted">Wallet balance</div>
                            <div class="fw-bold">EUR <?php echo number_format($userCredit, 2); ?></div>
                            <div class="small text-muted mt-1">Bookings are charged to your wallet credit.</div>
                            <div id="credit-warning" class="alert alert-warning mt-3 d-none">
                                Not enough credit to confirm this booking.
                                <a class="alert-link" href="wallet.php">Open wallet</a>
                            </div>
                        </div>
                        <button id="confirm-booking" type="submit" class="btn btn-unibo w-100 mt-2">Confirm booking</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <div class="col-lg-5">
            <div class="form-section p-4 shadow-sm h-100">
                <h2 class="h5 fw-bold mb-3">Pickup point</h2>
                <div class="rounded-4 overflow-hidden shadow-sm">
                    <img src="https://images.unsplash.com/photo-1619468129361-605ebea04b44?w=800" class="img-fluid" alt="Map">
                </div>
                <p class="text-muted small mt-3 mb-0">Static map to highlight the main pickup area.</p>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="creditModal" tabindex="-1" role="dialog" aria-labelledby="creditModalLabel" aria-hidden="true" aria-modal="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="h5 modal-title" id="creditModalLabel">Insufficient credit</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Your wallet balance is not enough to cover this booking. Please add funds to continue.
            </div>
            <div class="modal-footer">
                <a href="wallet.php" class="btn btn-unibo">Open wallet</a>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
</main>
<script>
(function () {
    var minutesInput = document.getElementById('minutes-input');
    var estimate = document.getElementById('price-estimate');
    var endEstimate = document.getElementById('end-estimate');
    var form = document.querySelector('.booking-form');
    var creditWarning = document.getElementById('credit-warning');
    var creditModal = document.getElementById('creditModal');
    if (!minutesInput || !estimate || !endEstimate) {
        return;
    }
    var pricePerMinute = parseFloat(estimate.dataset.pricePerMinute || '0');
    var userCredit = <?php echo json_encode((float) $userCredit); ?>;

    var updateCreditState = function (total) {
        var insufficient = total > userCredit;
        if (creditWarning) {
            creditWarning.classList.toggle('d-none', !insufficient);
        }
        return insufficient;
    };

    var updateEstimate = function () {
        var minutes = parseInt(minutesInput.value, 10);
        if (isNaN(minutes) || minutes <= 0) {
            estimate.textContent = 'EUR 0.00';
            endEstimate.textContent = '--';
            updateCreditState(0);
            return;
        }
        var total = minutes * pricePerMinute;
        estimate.textContent = 'EUR ' + total.toFixed(2);

        var now = new Date();
        var end = new Date(now.getTime() + (minutes * 60000));
        var endText = end.toLocaleString('en-GB', {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
        endEstimate.textContent = endText;
        updateCreditState(total);
    };
    minutesInput.addEventListener('input', updateEstimate);
    if (form) {
        form.addEventListener('submit', function (event) {
            var minutes = parseInt(minutesInput.value, 10);
            var total = 0;
            if (!isNaN(minutes) && minutes > 0) {
                total = minutes * pricePerMinute;
            }
            if (total > userCredit) {
                event.preventDefault();
                if (creditModal && window.bootstrap && window.bootstrap.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(creditModal).show();
                }
            }
        });
    }
    updateEstimate();
})();
</script>

<?php include 'includes/footer.php'; ?>
