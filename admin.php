<?php
/** @var mysqli $conn */
include 'db/db_config.php';
include 'includes/auth.php';

require_admin();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$allowedTypes = ['bike', 'scooter'];
$allowedStatuses = ['available', 'rented', 'maintenance', 'broken'];

$defaultImages = [
    'bike' => 'images/bike.png',
    'scooter' => 'images/scooter.png'
];

$statusFilter = $_GET['status'] ?? 'all';
$allowedFilters = array_merge(['all'], $allowedStatuses);
if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'all';
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

function log_change(mysqli $conn, int $adminId, string $action, string $entity, int $entityId, string $details): void
{
    $stmt = mysqli_prepare($conn, "INSERT INTO change_log (admin_id, action, entity, entity_id, details) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, "issis", $adminId, $action, $entity, $entityId, $details);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $vehicleName = null;
    $stmt = mysqli_prepare($conn, "SELECT name FROM vehicles WHERE id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        $vehicleName = $row['name'] ?? null;
        mysqli_stmt_close($stmt);
    }
    mysqli_begin_transaction($conn);

    $deleteOk = true;
    $errorText = '';

    $stmt = mysqli_prepare($conn, "DELETE FROM issues WHERE vehicle_id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        $deleteOk = mysqli_stmt_execute($stmt);
        $errorText = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $deleteOk = false;
        $errorText = mysqli_error($conn);
    }

    if ($deleteOk) {
        $stmt = mysqli_prepare($conn, "DELETE FROM rentals WHERE vehicle_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $id);
            $deleteOk = mysqli_stmt_execute($stmt);
            $errorText = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
        } else {
            $deleteOk = false;
            $errorText = mysqli_error($conn);
        }
    }

    if ($deleteOk) {
        $stmt = mysqli_prepare($conn, "DELETE FROM vehicles WHERE id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $id);
            $deleteOk = mysqli_stmt_execute($stmt);
            $errorText = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
        } else {
            $deleteOk = false;
            $errorText = mysqli_error($conn);
        }
    }

    if ($deleteOk) {
        mysqli_commit($conn);
        $details = $vehicleName ? "Deleted vehicle {$vehicleName}." : "Deleted vehicle #{$id}.";
        log_change($conn, $_SESSION['user_id'], 'delete', 'vehicle', $id, $details);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Vehicle deleted.'];
    } else {
        mysqli_rollback($conn);
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Delete failed: ' . $errorText];
    }

    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_all') {
        $countResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM vehicles");
        $countRow = $countResult ? mysqli_fetch_assoc($countResult) : null;
        $vehicleCount = $countRow ? (int) $countRow['total'] : 0;
        if ($countResult) {
            mysqli_free_result($countResult);
        }

        mysqli_begin_transaction($conn);
        $deleteOk = true;
        $errorText = '';

        if (!mysqli_query($conn, "DELETE FROM issues")) {
            $deleteOk = false;
            $errorText = mysqli_error($conn);
        }
        if ($deleteOk && !mysqli_query($conn, "DELETE FROM rentals")) {
            $deleteOk = false;
            $errorText = mysqli_error($conn);
        }
        if ($deleteOk && !mysqli_query($conn, "DELETE FROM vehicles")) {
            $deleteOk = false;
            $errorText = mysqli_error($conn);
        }

        if ($deleteOk) {
            mysqli_commit($conn);
            $details = $vehicleCount > 0
                ? "Deleted all vehicles ({$vehicleCount})."
                : "Delete all vehicles requested (none found).";
            log_change($conn, $_SESSION['user_id'], 'delete', 'vehicle', 0, $details);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'All vehicles deleted.'];
        } else {
            mysqli_rollback($conn);
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Delete failed: ' . $errorText];
        }

        header('Location: admin.php');
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? '';
    $status = $_POST['status'] ?? '';
    $location = trim($_POST['location'] ?? '');
    $battery = (int) ($_POST['battery'] ?? 0);
    $hourlyPrice = (float) ($_POST['hourly_price'] ?? 0);
    
    $useCustomImage = isset($_POST['use_custom_image']);
    $customImageUrl = trim($_POST['image_url'] ?? '');

    if ($useCustomImage) {
        $imageUrl = $customImageUrl === '' ? null : $customImageUrl;
    } else {
        $imageUrl = $defaultImages[$type] ?? null;
    }

    if ($name === '') {
        $errors[] = 'Name is required.';
    }
    if (!in_array($type, $allowedTypes, true)) {
        $errors[] = 'Invalid type.';
    }
    if (!in_array($status, $allowedStatuses, true)) {
        $errors[] = 'Invalid status.';
    }
    if ($location === '') {
        $errors[] = 'Location is required.';
    }
    if ($battery < 0 || $battery > 100) {
        $errors[] = 'Battery must be between 0 and 100.';
    }
    if ($hourlyPrice <= 0) {
        $errors[] = 'Invalid hourly price.';
    }

    if (!$errors) {
        if ($action === 'create') {
            $stmt = mysqli_prepare($conn, "INSERT INTO vehicles (name, type, status, location, battery, hourly_price, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "ssssids", $name, $type, $status, $location, $battery, $hourlyPrice, $imageUrl);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $newId = mysqli_insert_id($conn);
            log_change($conn, $_SESSION['user_id'], 'create', 'vehicle', $newId, "Created vehicle {$name}.");
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Vehicle created.'];
            header('Location: admin.php');
            exit;
        }
        if ($action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = mysqli_prepare($conn, "UPDATE vehicles SET name = ?, type = ?, status = ?, location = ?, battery = ?, hourly_price = ?, image_url = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "ssssidsi", $name, $type, $status, $location, $battery, $hourlyPrice, $imageUrl, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $statusLabel = status_label($status);
            log_change($conn, $_SESSION['user_id'], 'update', 'vehicle', $id, "Updated vehicle {$name} (status: {$statusLabel}).");
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Vehicle updated.'];
            header('Location: admin.php');
            exit;
        }
    }
}

$editVehicle = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = mysqli_prepare($conn, "SELECT * FROM vehicles WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $editId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $editVehicle = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
}

$formMode = $editVehicle ? 'update' : 'create';
$formData = [
    'id' => $editVehicle['id'] ?? '',
    'name' => $editVehicle['name'] ?? '',
    'type' => $editVehicle['type'] ?? 'bike',
    'status' => $editVehicle['status'] ?? 'available',
    'location' => $editVehicle['location'] ?? '',
    'battery' => $editVehicle['battery'] ?? 100,
    'hourly_price' => $editVehicle['hourly_price'] ?? 2.50,
    'image_url' => $editVehicle['image_url'] ?? ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
    $formMode = $action === 'update' ? 'update' : 'create';
    $formData = [
        'id' => $_POST['id'] ?? '',
        'name' => $name ?? '',
        'type' => $type ?? 'bike',
        'status' => $status ?? 'available',
        'location' => $location ?? '',
        'battery' => $battery ?? 100,
        'hourly_price' => $hourlyPrice ?? 2.50,
        'image_url' => $imageUrl ?? ''
    ];
}

$vehicles = [];
$listQuery = "SELECT * FROM vehicles";
$listParams = [];
$listTypes = '';
if ($statusFilter !== 'all') {
    $listQuery .= " WHERE status = ?";
    $listParams[] = $statusFilter;
    $listTypes .= 's';
}
$listQuery .= " ORDER BY id DESC";
$listStmt = mysqli_prepare($conn, $listQuery);
if ($listStmt && $listParams) {
    mysqli_stmt_bind_param($listStmt, $listTypes, ...$listParams);
}
if ($listStmt) {
    mysqli_stmt_execute($listStmt);
    $listResult = mysqli_stmt_get_result($listStmt);
    if ($listResult) {
        while ($row = mysqli_fetch_assoc($listResult)) {
            $vehicles[] = $row;
        }
    }
    mysqli_stmt_close($listStmt);
}

include 'includes/header.php';
?>

    <div class="container py-5" id="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold">Fleet Management</h2>
            <a class="btn btn-unibo" href="#vehicle-form"><i class="fas fa-plus me-2"></i>Add Vehicle</a>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
                <?php echo htmlspecialchars($flash['message']); ?>
            </div>
        <?php endif; ?>

        <div class="form-section p-4 shadow-sm mb-4" id="vehicle-form">
            <h5 class="fw-bold mb-3"><?php echo $formMode === 'update' ? 'Edit Vehicle' : 'New Vehicle'; ?></h5>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="action" value="<?php echo $formMode; ?>">
                <?php if ($formMode === 'update'): ?>
                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($formData['id']); ?>">
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Name</label>
                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($formData['name']); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Type</label>
                        <select name="type" class="form-select">
                            <option value="bike" <?php echo $formData['type'] === 'bike' ? 'selected' : ''; ?>>Bike</option>
                            <option value="scooter" <?php echo $formData['type'] === 'scooter' ? 'selected' : ''; ?>>Scooter</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Status</label>
                        <select name="status" class="form-select">
                            <option value="available" <?php echo $formData['status'] === 'available' ? 'selected' : ''; ?>>Available</option>
                            <option value="rented" <?php echo $formData['status'] === 'rented' ? 'selected' : ''; ?>>Rented</option>
                            <option value="maintenance" <?php echo $formData['status'] === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                            <option value="broken" <?php echo $formData['status'] === 'broken' ? 'selected' : ''; ?>>Broken</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Location</label>
                        <input type="text" name="location" class="form-control" value="<?php echo htmlspecialchars($formData['location']); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Battery (%)</label>
                        <input type="number" min="0" max="100" name="battery" class="form-control" value="<?php echo htmlspecialchars((string) $formData['battery']); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Price per hour (EUR)</label>
                        <input type="number" step="0.01" name="hourly_price" class="form-control" value="<?php echo htmlspecialchars((string) $formData['hourly_price']); ?>" required>
                    </div>
                    <div class="col-md-12">
                        <?php
                            $currentImage = $formData['image_url'] ?? '';
                            $isDefault = in_array($currentImage, $defaultImages);
                            // If it's a new vehicle (create mode), default to NOT using custom image (so checkbox unchecked)
                            // If update mode, check if current image is NOT one of the defaults
                            $useCustomChecked = $formMode === 'update' && !$isDefault && $currentImage !== '';
                        ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="use_custom_image" name="use_custom_image" 
                                <?php echo $useCustomChecked ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="use_custom_image">
                                Use custom image
                            </label>
                        </div>
                        <div id="image_url_container" style="<?php echo $useCustomChecked ? '' : 'display: none;'; ?>">
                            <label class="form-label fw-bold">Image (URL)</label>
                            <input type="text" name="image_url" id="image_url" class="form-control" value="<?php echo htmlspecialchars((string) $formData['image_url']); ?>">
                        </div>
                    </div>
                </div>
                
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var checkbox = document.getElementById('use_custom_image');
                    var container = document.getElementById('image_url_container');
                    
                    function toggleImageInput() {
                        container.style.display = checkbox.checked ? 'block' : 'none';
                    }
                    
                    checkbox.addEventListener('change', toggleImageInput);
                    // Initial check handled by PHP style output, but good to have sync if needed
                });
                </script>
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-unibo"><?php echo $formMode === 'update' ? 'Update' : 'Create'; ?></button>
                    <?php if ($formMode === 'update'): ?>
                        <a href="admin.php" class="btn btn-outline-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="bg-white shadow-sm rounded-4 overflow-hidden mx-auto" style="max-width: 980px;">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 p-3 border-bottom">
                <h6 class="fw-bold mb-0">Vehicle list</h6>
                <div class="d-flex flex-column flex-md-row align-items-stretch align-items-md-center gap-2">
                    <form method="get" class="d-flex flex-wrap align-items-center gap-2">
                        <label class="small text-muted" for="status-filter">Status</label>
                        <select id="status-filter" name="status" class="form-select form-select-sm">
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="available" <?php echo $statusFilter === 'available' ? 'selected' : ''; ?>>Available</option>
                            <option value="rented" <?php echo $statusFilter === 'rented' ? 'selected' : ''; ?>>Rented</option>
                            <option value="maintenance" <?php echo $statusFilter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                            <option value="broken" <?php echo $statusFilter === 'broken' ? 'selected' : ''; ?>>Broken</option>
                        </select>
                        <button class="btn btn-outline-secondary btn-sm" type="submit">Filter</button>
                    </form>
                    <form method="post" class="d-flex align-items-center gap-2" onsubmit="return confirm('Delete all vehicles? This will remove rentals and issue reports.');">
                        <input type="hidden" name="action" value="delete_all">
                        <button class="btn btn-outline-danger btn-sm" type="submit">Delete All Vehicles</button>
                    </form>
                </div>
            </div>
            <div class="d-block d-md-none p-3">
                <?php if (!$vehicles): ?>
                    <div class="text-center text-muted py-4">No vehicles found.</div>
                <?php else: ?>
                    <?php foreach ($vehicles as $row): ?>
                        <?php
                        $statusLabel = status_label($row['status']);
                        $badgeClass = status_badge_class($row['status']);
                        ?>
                        <div class="border rounded-4 p-3 mb-3">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="fw-bold"><?php echo htmlspecialchars($row['name']); ?></div>
                                    <div class="text-muted small">Type: <?php echo htmlspecialchars(type_label($row['type'])); ?></div>
                                </div>
                                <span class="badge <?php echo $badgeClass; ?> rounded-pill"><?php echo htmlspecialchars($statusLabel); ?></span>
                            </div>
                            <div class="d-flex justify-content-between text-muted small mt-2">
                                <span>Battery</span>
                                <span><?php echo htmlspecialchars((string) $row['battery']); ?>%</span>
                            </div>
                            <div class="d-flex justify-content-between text-muted small">
                                <span>Price per hour</span>
                                <span>EUR <?php echo htmlspecialchars((string) $row['hourly_price']); ?></span>
                            </div>
                            <div class="d-flex flex-wrap gap-2 mt-3">
                                <a href="admin.php?edit=<?php echo $row['id']; ?>#vehicle-form" class="btn btn-sm btn-outline-primary">Edit</a>
                                <a href="admin.php?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure?')">Delete</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover mb-0">
                    <thead class="bg-light">
                    <tr>
                        <th scope="col" class="px-4">Vehicle</th>
                        <th scope="col">Type</th>
                        <th scope="col">Status</th>
                        <th scope="col">Battery</th>
                        <th scope="col">Price per hour</th>
                        <th scope="col" class="text-end px-4">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($vehicles): ?>
                        <?php foreach ($vehicles as $row): ?>
                            <?php $statusLabel = status_label($row['status']); ?>
                            <tr class="align-middle">
                                <td class="px-4 fw-bold"><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars(type_label($row['type'])); ?></span></td>
                                <td>
                                <span class="badge <?php echo status_badge_class($row['status']); ?>">
                                    <?php echo htmlspecialchars($statusLabel); ?>
                                </span>
                                </td>
                                <td><?php echo htmlspecialchars((string) $row['battery']); ?>%</td>
                                <td>EUR <?php echo htmlspecialchars((string) $row['hourly_price']); ?></td>
                                <td class="text-end px-4">
                                    <div class="d-flex flex-column flex-sm-row justify-content-end gap-2">
                                        <a href="admin.php?edit=<?php echo $row['id']; ?>#vehicle-form" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                                        <a href="admin.php?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure?')"><i class="fas fa-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No vehicles found.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php include 'includes/footer.php'; ?>
