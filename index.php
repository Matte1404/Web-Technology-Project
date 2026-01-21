<?php
/** @var mysqli $conn */
include 'db/db_config.php';
include 'includes/auth.php';

$isAdmin = is_admin();

$allowedStatuses = ['all', 'available'];
$filterStatus = $_GET['status'] ?? 'all';
if (!in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = 'all';
}



function status_label(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'available') {
        return 'Available';
    }
    if ($status === 'rented') {
        return 'Rented';
    }
    if ($status === 'maintenance') {
        return 'Maintenance';
    }
    if ($status === 'broken') {
        return 'Broken';
    }
    return ucfirst($status);
}

function status_badge_class(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'available') {
        return 'bg-success';
    }
    if ($status === 'rented') {
        return 'bg-warning text-dark';
    }
    if ($status === 'maintenance') {
        return 'bg-secondary';
    }
    if ($status === 'broken') {
        return 'bg-danger';
    }
    return 'bg-light text-dark border';
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

$query = "SELECT * FROM vehicles";
$params = [];
$types = '';

if ($filterStatus === 'available') {
    $query .= " WHERE status = ?";
    $params[] = 'available';
    $types .= 's';
}
$query .= " ORDER BY id DESC";

$stmt = mysqli_prepare($conn, $query);
if ($stmt && $params) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
if ($stmt) {
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = false;
}

include 'includes/header.php';
?>

    <section class="py-5 text-white" style="background-color: #8B1E1E; background: linear-gradient(135deg, #BB2E29 0%, #8B1E1E 100%);" id="main-content">
        <div class="container text-center py-4">
            <h1 class="display-5 fw-bold mb-3">Find Your Ride</h1>
            <p class="lead text-dark">Sustainable campus transportation made easy</p>

            <div class="bg-white p-4 rounded-4 shadow-lg text-dark mx-auto mt-5" style="max-width: 520px;">
                <form class="row g-3" method="get" id="filters">
                    <div class="col-12 text-start">
                        <label class="form-label fw-bold text-black" for="status-filter-select">Status</label>
                        <select class="form-select border-2" name="status" id="status-filter-select">
                            <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All vehicles</option>
                            <option value="available" <?php echo $filterStatus === 'available' ? 'selected' : ''; ?>>Available</option>
                        </select>
                    </div>
                    <div class="col-12 text-end">
                        <button class="btn btn-unibo" type="submit">Apply filter</button>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <section class="py-5">
        <div class="container">
            <h2 class="fw-bold mb-4">Vehicles</h2>
            <div class="row row-cols-1 row-cols-md-3 g-4">

                <?php
                if ($result && mysqli_num_rows($result) > 0) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $battery = (int) $row['battery'];
                        $batteryColor = 'bg-success';
                        if ($battery < 20) {
                            $batteryColor = 'bg-danger';
                        } elseif ($battery < 50) {
                            $batteryColor = 'bg-warning';
                        }

                        $statusLabel = status_label($row['status']);
                        $badgeClass = status_badge_class($row['status']);
                        $isAvailable = strtolower(trim($row['status'])) === 'available';
                        $imageUrl = trim((string) ($row['image_url'] ?? ''));
                        $showImage = $imageUrl !== '';
                        ?>
                        <div class="col">
                            <div class="unibo-card h-100 overflow-hidden shadow-sm border-0">
                                <div class="position-relative">
                                    <?php if ($showImage): ?>
                                        <img src="<?php echo htmlspecialchars($imageUrl); ?>" class="card-img-top" alt="Vehicle" style="height: 200px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="image-placeholder d-flex align-items-center justify-content-center">
                                            <div class="text-muted small">No image</div>
                                        </div>
                                    <?php endif; ?>
                                    <span class="position-absolute top-0 end-0 m-3 badge <?php echo $badgeClass; ?> rounded-pill px-3">
                                    <?php echo htmlspecialchars($statusLabel); ?>
                                </span>
                                </div>
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between">
                                        <h3 class="h5 fw-bold text-dark"><?php echo htmlspecialchars($row['name']); ?></h3>
                                        <span class="h4 fw-bold text-danger">EUR <?php echo htmlspecialchars((string) $row['hourly_price']); ?></span>
                                    </div>
                                    <p class="text-muted small mb-1"><?php echo htmlspecialchars($row['location']); ?></p>
                                    <p class="text-muted small">Type: <?php echo htmlspecialchars(type_label($row['type'])); ?></p>

                                    <div class="my-3 text-dark">
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span>Battery</span>
                                            <span><?php echo htmlspecialchars((string) $battery); ?>%</span>
                                        </div>
                                        <div class="progress" style="height: 6px;">
                                            <div class="progress-bar <?php echo $batteryColor; ?>" style="width: <?php echo htmlspecialchars((string) $battery); ?>%"></div>
                                        </div>
                                    </div>
                                    <?php if ($isAdmin): ?>
                                        <button class="btn btn-outline-secondary w-100 mt-2" disabled>Admins cannot book</button>
                                    <?php elseif ($isAvailable): ?>
                                        <a href="booking.php?vehicle_id=<?php echo $row['id']; ?>" class="btn btn-unibo w-100 mt-2">Reserve Now</a>
                                    <?php else: ?>
                                        <button class="btn btn-outline-secondary w-100 mt-2" disabled>Unavailable</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php
                    }
                } else {
                    echo "<div class='col-12'><p class='text-center'>No vehicles found.</p></div>";
                }
                ?>

            </div>
        </div>
    </section>

    <section class="py-5 bg-light">
        <div class="container">
            <h2 class="fw-bold mb-4 text-center">Nearby Pick-up Points</h2>
            <div class="row align-items-center">
                <div class="col-md-6 mb-4 mb-md-0">
                    <div class="rounded-4 overflow-hidden shadow">
                        <img src="https://images.unsplash.com/photo-1619468129361-605ebea04b44?w=800" class="img-fluid" alt="Map">
                    </div>
                </div>
                <div class="col-md-6 px-lg-5">
                    <div class="list-group list-group-flush bg-transparent">
                        <div class="list-group-item bg-transparent border-0 px-0 mb-3">
                            <div class="d-flex gap-3 align-items-center">
                                <div class="bg-white p-3 rounded-circle shadow-sm text-danger"><span class="fas fa-map-pin"></span></div>
                                <div>
                                    <h3 class="h6 fw-bold mb-0">Main Campus</h3>
                                    <small class="text-muted">Main Campus Gate</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

<script>
(function () {
    var form = document.getElementById('filters');
    if (!form) {
        return;
    }
    var select = form.querySelector('select');
    if (!select) {
        return;
    }
    select.addEventListener('change', function () {
        form.submit();
    });
})();
</script>

<?php include 'includes/footer.php'; ?>
