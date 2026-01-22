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

    if (in_array($action, ['create', 'update'])) {
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

$currentTab = $_GET['tab'] ?? 'vehicles';
$allowedTabs = ['vehicles', 'users', 'hubs'];
if (!in_array($currentTab, $allowedTabs, true)) {
    $currentTab = 'vehicles';
}

// --- HUB ACTIONS ---
if (isset($_GET['delete_hub'])) {
    $id = (int) $_GET['delete_hub'];
    $stmt = mysqli_prepare($conn, "DELETE FROM hubs WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    if (mysqli_stmt_execute($stmt)) {
        log_change($conn, $_SESSION['user_id'], 'delete', 'hub', $id, "Deleted hub #{$id}.");
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Hub deleted.'];
    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Delete failed.'];
    }
    header('Location: admin.php?tab=hubs');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($action, 'hub_') === 0) {
    $hubName = trim($_POST['name'] ?? '');
    $hubDesc = trim($_POST['description'] ?? '');
    $hubLat = (float) ($_POST['lat'] ?? 0);
    $hubLng = (float) ($_POST['lng'] ?? 0);
    
    if ($hubName === '' || $hubDesc === '') {
        $errors[] = 'Name and description are required.';
    }
    
    if (!$errors) {
        if ($action === 'hub_create') {
            $stmt = mysqli_prepare($conn, "INSERT INTO hubs (name, description, lat, lng) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "ssdd", $hubName, $hubDesc, $hubLat, $hubLng);
            if (mysqli_stmt_execute($stmt)) {
                log_change($conn, $_SESSION['user_id'], 'create', 'hub', mysqli_insert_id($conn), "Created hub {$hubName}.");
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Hub created.'];
                header('Location: admin.php?tab=hubs');
                exit;
            }
        } elseif ($action === 'hub_update') {
            $hubId = (int) ($_POST['id'] ?? 0);
            $stmt = mysqli_prepare($conn, "UPDATE hubs SET name = ?, description = ?, lat = ?, lng = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "ssddi", $hubName, $hubDesc, $hubLat, $hubLng, $hubId);
            if (mysqli_stmt_execute($stmt)) {
                log_change($conn, $_SESSION['user_id'], 'update', 'hub', $hubId, "Updated hub {$hubName}.");
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Hub updated.'];
                header('Location: admin.php?tab=hubs');
                exit;
            }
        }
    }
}



if (isset($_GET['delete_user'])) {
    $id = (int) $_GET['delete_user'];
    if ($id === $_SESSION['user_id']) {
         $_SESSION['flash'] = ['type' => 'danger', 'message' => 'You cannot delete yourself.'];
    } else {
        mysqli_begin_transaction($conn);
        $deleteOk = true;

        $tables = ['issues', 'rentals', 'transactions', 'change_log'];
        foreach ($tables as $table) {
            $col = (($table === 'change_log') ? 'admin_id' : 'user_id');
            if ($deleteOk && !mysqli_query($conn, "DELETE FROM $table WHERE $col = $id")) {
                $deleteOk = false;
            }
        }
        
        if ($deleteOk && !mysqli_query($conn, "DELETE FROM users WHERE id = $id")) {
             $deleteOk = false;
        }

        if ($deleteOk) {
            mysqli_commit($conn);
            log_change($conn, $_SESSION['user_id'], 'delete', 'user', $id, "Deleted user #{$id}.");
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'User deleted.'];
        } else {
            mysqli_rollback($conn);
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Delete failed.'];
        }
    }
    header('Location: admin.php?tab=users');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($action, 'user_') === 0) {

    $userName = trim($_POST['name'] ?? '');
    $userEmail = trim($_POST['email'] ?? '');
    $userPassword = $_POST['password'] ?? '';
    $userRole = $_POST['role'] ?? 'user';
    $userStatus = $_POST['status'] ?? 'active';

    if ($userName === '' || $userEmail === '') {
        $errors[] = 'Name and email are required.';
    }
    
    if (!$errors) {
        if ($action === 'user_create') {
             if ($userPassword === '') {
                 $errors[] = 'Password is required for new users.';
             } else {
                 $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
                 mysqli_stmt_bind_param($stmt, "s", $userEmail);
                 mysqli_stmt_execute($stmt);
                 if (mysqli_stmt_fetch($stmt)) {
                     $errors[] = 'Email already exists.';
                 }
                 mysqli_stmt_close($stmt);

                 if (!$errors) {
                     $salt = hash('sha512', uniqid(mt_rand(1, mt_getrandmax()), true));
                     $passwordHash = hash('sha512', $userPassword . $salt);
                     
                     $stmt = mysqli_prepare($conn, "INSERT INTO users (name, email, password, salt, role, status) VALUES (?, ?, ?, ?, ?, ?)");
                     mysqli_stmt_bind_param($stmt, "ssssss", $userName, $userEmail, $passwordHash, $salt, $userRole, $userStatus);
                     if (mysqli_stmt_execute($stmt)) {
                         log_change($conn, $_SESSION['user_id'], 'create', 'user', mysqli_insert_id($conn), "Created user {$userName}.");
                         $_SESSION['flash'] = ['type' => 'success', 'message' => 'User created.'];
                         header('Location: admin.php?tab=users');
                         exit;
                     } else {
                         $errors[] = 'Database error: ' . mysqli_error($conn);
                     }
                 }
             }
        } elseif ($action === 'user_update') {
             $editUserId = (int) ($_POST['id'] ?? 0);
             if ($editUserId <= 0) {
                 $errors[] = 'Invalid user ID.';
             } else {

                 if ($userPassword !== '') {
                     $salt = hash('sha512', uniqid(mt_rand(1, mt_getrandmax()), true));
                     $passwordHash = hash('sha512', $userPassword . $salt);
                     $stmt = mysqli_prepare($conn, "UPDATE users SET name=?, email=?, password=?, salt=?, role=?, status=? WHERE id=?");
                     mysqli_stmt_bind_param($stmt, "ssssssi", $userName, $userEmail, $passwordHash, $salt, $userRole, $userStatus, $editUserId);
                 } else {
                     $stmt = mysqli_prepare($conn, "UPDATE users SET name=?, email=?, role=?, status=? WHERE id=?");
                     mysqli_stmt_bind_param($stmt, "ssssi", $userName, $userEmail, $userRole, $userStatus, $editUserId);
                 }
                 
                 if (mysqli_stmt_execute($stmt)) {
                     log_change($conn, $_SESSION['user_id'], 'update', 'user', $editUserId, "Updated user {$userName}.");
                      $_SESSION['flash'] = ['type' => 'success', 'message' => 'User updated.'];
                     header('Location: admin.php?tab=users');
                     exit;
                 } else {
                      $errors[] = 'Update failed.';
                 }
             }
        }
    }
}



$vehicles = [];
if ($currentTab === 'vehicles') {
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
        while ($row = mysqli_fetch_assoc($listResult)) {
            $vehicles[] = $row;
        }
        mysqli_stmt_close($listStmt);
    }
}

$usersList = [];
$editUser = null;
if ($currentTab === 'users') {
    if (isset($_GET['edit_user'])) {
        $editUserId = (int) $_GET['edit_user'];
        $stmt = mysqli_prepare($conn, "SELECT name, email, role, status FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $editUserId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $editUser = $res ? mysqli_fetch_assoc($res) : null;
        if ($editUser) $editUser['id'] = $editUserId;
    }
    
    $res = mysqli_query($conn, "SELECT id, name, email, role, status, created_at FROM users ORDER BY id DESC");
    while ($row = mysqli_fetch_assoc($res)) {
        $usersList[] = $row;
    }
}

$hubsList = [];
$editHub = null;
if ($currentTab === 'hubs') {
    if (isset($_GET['edit_hub'])) {
        $editHubId = (int) $_GET['edit_hub'];
        $stmt = mysqli_prepare($conn, "SELECT * FROM hubs WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $editHubId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $editHub = $res ? mysqli_fetch_assoc($res) : null;
    }

    $res = mysqli_query($conn, "SELECT * FROM hubs ORDER BY id DESC");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $hubsList[] = $row;
        }
    }
}

include 'includes/header.php';
?>
<main>
    <div class="container py-5" id="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold">Admin Panel</h2>
        </div>

        <!-- Navigation Tabs -->
        <ul class="nav nav-tabs mb-4">
          <li class="nav-item">
            <a class="nav-link <?php echo $currentTab === 'vehicles' ? 'active' : ''; ?>" aria-current="page" href="admin.php?tab=vehicles">Fleet Management</a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php echo $currentTab === 'users' ? 'active' : ''; ?>" href="admin.php?tab=users">User Management</a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php echo $currentTab === 'hubs' ? 'active' : ''; ?>" href="admin.php?tab=hubs">Hub Management</a>
          </li>
        </ul>

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
                <?php echo htmlspecialchars($flash['message']); ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($currentTab === 'vehicles'): ?>
            <div class="d-flex justify-content-end mb-3">
                 <a class="btn btn-unibo fas fa-plus me-2" href="#vehicle-form" aria-label="Add vehicle"> Add Vehicle</a>
            </div>

            <div class="form-section p-4 shadow-sm mb-4" id="vehicle-form" >
                <h3 class="h5 fw-bold mb-3" aria-label="Edit or add vehicle"><?php echo $formMode === 'update' ? 'Edit Vehicle' : 'New Vehicle'; ?></h3>

                <form method="post">
                    <input type="hidden" name="action" value="<?php echo $formMode; ?>">
                    <?php if ($formMode === 'update'): ?>
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($formData['id']); ?>">
                    <?php endif; ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label fw-bold">Name </label>
                            <input type="text" name="name" id="name" class="form-control" value="<?php echo htmlspecialchars($formData['name']); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label for="type" class="form-label fw-bold">Type</label>
                            <select name="type" id="type" class="form-select">
                                <option value="bike" <?php echo $formData['type'] === 'bike' ? 'selected' : ''; ?>>Bike</option>
                                <option value="scooter" <?php echo $formData['type'] === 'scooter' ? 'selected' : ''; ?>>Scooter</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="status" class="form-label fw-bold">Status</label>
                            <select name="status" id="status" class="form-select">
                                <option value="available" <?php echo $formData['status'] === 'available' ? 'selected' : ''; ?>>Available</option>
                                <option value="rented" <?php echo $formData['status'] === 'rented' ? 'selected' : ''; ?>>Rented</option>
                                <option value="maintenance" <?php echo $formData['status'] === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                <option value="broken" <?php echo $formData['status'] === 'broken' ? 'selected' : ''; ?>>Broken</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="location" class="form-label fw-bold">Location </label>
                            <input type="text" name="location" id="location" class="form-control" value="<?php echo htmlspecialchars($formData['location']); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label for="battery" class="form-label fw-bold">Battery (%)</label>
                            <input type="number" min="0" max="100" name="battery" id="battery" class="form-control" value="<?php echo htmlspecialchars((string) $formData['battery']); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label for="hourly_price" class="form-label fw-bold">Price per hour (EUR)</label>
                            <input type="number" step="0.01" name="hourly_price" id="hourly_price" class="form-control" value="<?php echo htmlspecialchars((string) $formData['hourly_price']); ?>" required>
                        </div>
                        <div class="col-md-12">
                            <?php
                                $currentImage = $formData['image_url'] ?? '';
                                $isDefault = in_array($currentImage, $defaultImages);
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
                                <label for="image_url" class="form-label fw-bold">Image (URL)</label>
                                <input type="text" name="image_url" id="image_url" class="form-control" value="<?php echo htmlspecialchars((string) $formData['image_url']); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        var checkbox = document.getElementById('use_custom_image');
                        var container = document.getElementById('image_url_container');
                        function toggleImageInput() { container.style.display = checkbox.checked ? 'block' : 'none'; }
                        checkbox.addEventListener('change', toggleImageInput);
                    });
                    </script>
                    
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
                    <h3 class="h6 fw-bold mb-0">Vehicle list</h3>
                    <div class="d-flex flex-column flex-md-row align-items-stretch align-items-md-center gap-2">
                        <form method="get" class="d-flex flex-wrap align-items-center gap-2">
                            <input type="hidden" name="tab" value="vehicles">
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
                <div class="table-responsive">
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
                                            <a href="admin.php?tab=vehicles&edit=<?php echo $row['id']; ?>#vehicle-form" class="btn btn-sm btn-outline-primary fas fa-edit" aria-label="Edit vehicle"></a>
                                            <a href="admin.php?tab=vehicles&delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger fas fa-trash" onclick="return confirm('Are you sure?')" aria-label="Delete vehicle"></a>
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

        <?php elseif ($currentTab === 'users'): ?>
            <!-- USERS TAB CONTENT -->
            <div class="form-section p-4 shadow-sm mb-4" id="user-form">
                <h3 class="h5 fw-bold mb-3"><?php echo $editUser ? 'Edit User' : 'Create User'; ?></h3>
                <form method="post">
                    <input type="hidden" name="action" value="<?php echo $editUser ? 'user_update' : 'user_create'; ?>">
                    <?php if ($editUser): ?>
                        <input type="hidden" name="id" value="<?php echo $editUser['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label fw-bold">Name</label>
                            <input type="text" name="name" id="name" class="form-control" value="<?php echo htmlspecialchars($editUser['name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label fw-bold">Email</label>
                            <input type="email" name="email" id="email" class="form-control" value="<?php echo htmlspecialchars($editUser['email'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label for="role" class="form-label fw-bold">Role</label>
                            <select name="role" id="role" class="form-select">
                                <option value="user" <?php echo ($editUser['role'] ?? '') === 'user' ? 'selected' : ''; ?>>User</option>
                                <option value="admin" <?php echo ($editUser['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="status" class="form-label fw-bold">Status</label>
                            <select name="status" id="status" class="form-select">
                                <option value="active" <?php echo ($editUser['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="blocked" <?php echo ($editUser['status'] ?? '') === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="password" class="form-label fw-bold">Password</label>
                            <input type="password" name="password" id="password" class="form-control" placeholder="<?php echo $editUser ? 'Leave blank to keep current' : 'Required for new user'; ?>" <?php echo $editUser ? '' : 'required'; ?>>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-unibo"><?php echo $editUser ? 'Update User' : 'Create User'; ?></button>
                        <?php if ($editUser): ?>
                           <a href="admin.php?tab=users" class="btn btn-outline-secondary">Cancel Edit</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="bg-white shadow-sm rounded-4 overflow-hidden">
                <div class="p-3 border-bottom">
                    <h3 class="h6 fw-bold mb-0">Registered Users</h3>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="px-4">ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-end px-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usersList as $u): ?>
                            <tr class="align-middle">
                                <td class="px-4">#<?php echo $u['id']; ?></td>
                                <td class="fw-bold"><?php echo htmlspecialchars($u['name']); ?></td>
                                <td><?php echo htmlspecialchars($u['email']); ?></td>
                                <td>
                                    <?php if ($u['role'] === 'admin'): ?>
                                        <span class="badge bg-primary">Admin</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark border">User</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['status'] === 'blocked'): ?>
                                        <span class="badge bg-danger">Blocked</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted"><?php echo htmlspecialchars($u['created_at']); ?></td>
                                <td class="text-end px-4">
                                     <a href="admin.php?tab=users&edit_user=<?php echo $u['id']; ?>#user-form" class="btn btn-sm btn-outline-primary fas fa-edit" aria-label="Edit user"></a>
                                     <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                     <a href="admin.php?tab=users&delete_user=<?php echo $u['id']; ?>" class="btn btn-sm btn-outline-danger fas fa-trash" onclick="return confirm('Delete this user? This will remove all their data.')" aria-label="Delete user"></a>
                                     <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($currentTab === 'hubs'): ?>
            <div class="form-section p-4 shadow-sm mb-4" id="hub-form">
                <h3 class="h5 fw-bold mb-3"><?php echo $editHub ? 'Edit Hub' : 'Create Hub'; ?></h3>
                <form method="post">
                    <input type="hidden" name="action" value="<?php echo $editHub ? 'hub_update' : 'hub_create'; ?>">
                    <?php if ($editHub): ?>
                        <input type="hidden" name="id" value="<?php echo $editHub['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label fw-bold">Name</label>
                            <input type="text" name="name" id="name" class="form-control" value="<?php echo htmlspecialchars($editHub['name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="description" class="form-label fw-bold">Description</label>
                            <input type="text" name="description" id="description" class="form-control" value="<?php echo htmlspecialchars($editHub['description'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label for="lat" class="form-label fw-bold">Latitude</label>
                            <input type="number" step="any" name="lat" id="lat" class="form-control" value="<?php echo htmlspecialchars((string)($editHub['lat'] ?? '')); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label for="lng" class="form-label fw-bold">Longitude</label>
                            <input type="number" step="any" name="lng" id="lng" class="form-control" value="<?php echo htmlspecialchars((string)($editHub['lng'] ?? '')); ?>" required>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-unibo"><?php echo $editHub ? 'Update Hub' : 'Create Hub'; ?></button>
                         <?php if ($editHub): ?>
                           <a href="admin.php?tab=hubs" class="btn btn-outline-secondary">Cancel Edit</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="bg-white shadow-sm rounded-4 overflow-hidden">
                <div class="p-3 border-bottom">
                    <h3 class="h6 fw-bold mb-0">Hubs List</h3>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="px-4">ID</th>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Coordinates</th>
                                <th class="text-end px-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($hubsList): ?>
                                <?php foreach ($hubsList as $h): ?>
                                <tr class="align-middle">
                                    <td class="px-4">#<?php echo $h['id']; ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($h['name']); ?></td>
                                    <td><?php echo htmlspecialchars($h['description']); ?></td>
                                    <td class="small text-muted"><?php echo htmlspecialchars($h['lat'] . ', ' . $h['lng']); ?></td>
                                    <td class="text-end px-4">
                                         <a href="admin.php?tab=hubs&edit_hub=<?php echo $h['id']; ?>#hub-form" class="btn btn-sm btn-outline-primary fas fa-edit" aria-label="Edit hub"></a>
                                         <a href="admin.php?tab=hubs&delete_hub=<?php echo $h['id']; ?>" class="btn btn-sm btn-outline-danger fas fa-trash" onclick="return confirm('Delete this hub?')" aria-label="Delete hub"></a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No hubs found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php include 'includes/footer.php'; ?>
