<?php
/** @var mysqli $conn */
include 'db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$shouldRun = isset($_GET['run']) && $_GET['run'] === '1';
$alerts = [];
$report = [
    'users_added' => 0,
    'users_skipped' => 0,
    'vehicles_added' => 0,
    'vehicles_skipped' => 0,
    'rentals_added' => 0,
    'rentals_skipped' => 0,
    'rentals_missing' => 0
];
$requiredTables = ['users', 'vehicles', 'rentals', 'transactions', 'issues', 'change_log'];
$missingTables = [];

function table_exists(mysqli $conn, string $table): bool
{
    $safeTable = mysqli_real_escape_string($conn, $table);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safeTable}'");
    return $result && mysqli_num_rows($result) > 0;
}

foreach ($requiredTables as $table) {
    if (!table_exists($conn, $table)) {
        $missingTables[] = $table;
    }
}

$schemaReady = empty($missingTables);
$schemaLoaded = false;
$schemaErrors = [];
$schemaPath = __DIR__ . '/schema.sql';

if ($shouldRun) {
    if (!$schemaReady) {
        if (!is_readable($schemaPath)) {
            $schemaErrors[] = 'Schema not found in db/schema.sql.';
        } else {
            $schemaSql = file_get_contents($schemaPath);
            if ($schemaSql === false || trim($schemaSql) === '') {
                $schemaErrors[] = 'Schema is empty or not readable.';
            } elseif (!mysqli_multi_query($conn, $schemaSql)) {
                $schemaErrors[] = 'Table creation error: ' . mysqli_error($conn);
            } else {
                do {
                    $result = mysqli_store_result($conn);
                    if ($result) {
                        mysqli_free_result($result);
                    }
                } while (mysqli_more_results($conn) && mysqli_next_result($conn));

                if (mysqli_errno($conn)) {
                    $schemaErrors[] = 'Table creation error: ' . mysqli_error($conn);
                } else {
                    $schemaLoaded = true;
                }
            }
        }

        $missingTables = [];
        foreach ($requiredTables as $table) {
            if (!table_exists($conn, $table)) {
                $missingTables[] = $table;
            }
        }
        $schemaReady = empty($missingTables);
    }

    if ($schemaLoaded) {
        $alerts[] = ['type' => 'success', 'message' => 'Schema created successfully.'];
    }
    foreach ($schemaErrors as $error) {
        $alerts[] = ['type' => 'danger', 'message' => $error];
    }
}

if (!$schemaReady) {
    $alerts[] = ['type' => 'warning', 'message' => 'Missing tables: ' . implode(', ', $missingTables) . '.'];
}

if ($shouldRun && $schemaReady) {
    function get_auth_data($password) {
        $salt = hash('sha512', uniqid(mt_rand(1, mt_getrandmax()), true));
        $password = hash('sha512', $password . $salt);
        return ['password' => $password, 'salt' => $salt];
    }

    $adminAuth = get_auth_data('admin123');
    $userAuth = get_auth_data('student123');

    $users = [
        [
            'name' => 'Admin User',
            'email' => 'admin@unibo.it',
            'password' => $adminAuth['password'], 
            'salt' => $adminAuth['salt'],
            'role' => 'admin',
            'credit' => 100.00
        ],
        [
            'name' => 'Alex Taylor',
            'email' => 'alex.taylor@students.unibo.it',
            'password' => $userAuth['password'],
            'salt' => $userAuth['salt'],
            'role' => 'user',
            'credit' => 50.00
        ],
        [
            'name' => 'Maya Lee',
            'email' => 'maya.lee@students.unibo.it',
            'password' => $userAuth['password'],
            'salt' => $userAuth['salt'],
            'role' => 'user',
            'status' => 'active',
            'credit' => 0.00
        ]
    ];



    $vehicles = [
        [
            'name' => 'Bike 01',
            'type' => 'bike',
            'status' => 'available',
            'location' => 'Main Campus Gate',
            'battery' => 78,
            'hourly_price' => 2.50,
            'image_url' => 'images/bike.png'
        ],
        [
            'name' => 'Bike 02',
            'type' => 'bike',
            'status' => 'maintenance',
            'location' => 'Central Square',
            'battery' => 32,
            'hourly_price' => 2.10,
            'image_url' => 'images/bike.png'
        ],
        [
            'name' => 'Scooter 01',
            'type' => 'scooter',
            'status' => 'available',
            'location' => 'East Campus Hub',
            'battery' => 90,
            'hourly_price' => 3.00,
            'image_url' => 'images/scooter.png'
        ],
        [
            'name' => 'Scooter 02',
            'type' => 'scooter',
            'status' => 'broken',
            'location' => 'Engineering Park',
            'battery' => 15,
            'hourly_price' => 2.80,
            'image_url' => 'images/scooter.png'
        ],
        [
            'name' => 'Bike 03',
            'type' => 'bike',
            'status' => 'rented',
            'location' => 'Library Plaza',
            'battery' => 55,
            'hourly_price' => 2.20,
            'image_url' => 'images/bike.png'
        ]
    ];

    $rentals = [
        [
            'email' => 'alex.taylor@students.unibo.it',
            'vehicle' => 'Bike 01',
            'start' => '2026-01-15 09:15:00',
            'minutes' => 35
        ],
        [
            'email' => 'maya.lee@students.unibo.it',
            'vehicle' => 'Scooter 01',
            'start' => '2026-01-16 18:30:00',
            'minutes' => 22
        ]
    ];

    $selectUserStmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
    $insertUserStmt = mysqli_prepare($conn, "INSERT INTO users (name, email, password, salt, role, status, credit) VALUES (?, ?, ?, ?, ?, ?, ?)");

    foreach ($users as $user) {
        mysqli_stmt_bind_param($selectUserStmt, "s", $user['email']);
        mysqli_stmt_execute($selectUserStmt);
        $result = mysqli_stmt_get_result($selectUserStmt);
        $exists = $result ? mysqli_fetch_assoc($result) : null;

        if ($exists) {
            $report['users_skipped']++;
            continue;
        }

        $status = $user['status'] ?? 'active';
        mysqli_stmt_bind_param($insertUserStmt, "ssssssd", $user['name'], $user['email'], $user['password'], $user['salt'], $user['role'], $status, $user['credit']);
        if (mysqli_stmt_execute($insertUserStmt)) {
            $report['users_added']++;
        }
    }

    mysqli_stmt_close($selectUserStmt);
    mysqli_stmt_close($insertUserStmt);

    $selectVehicleStmt = mysqli_prepare($conn, "SELECT id FROM vehicles WHERE name = ?");
    $insertVehicleStmt = mysqli_prepare($conn, "INSERT INTO vehicles (name, type, status, location, battery, hourly_price, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)");

    foreach ($vehicles as $vehicle) {
        mysqli_stmt_bind_param($selectVehicleStmt, "s", $vehicle['name']);
        mysqli_stmt_execute($selectVehicleStmt);
        $result = mysqli_stmt_get_result($selectVehicleStmt);
        $exists = $result ? mysqli_fetch_assoc($result) : null;

        if ($exists) {
            $report['vehicles_skipped']++;
            continue;
        }

        mysqli_stmt_bind_param(
            $insertVehicleStmt,
            "ssssids",
            $vehicle['name'],
            $vehicle['type'],
            $vehicle['status'],
            $vehicle['location'],
            $vehicle['battery'],
            $vehicle['hourly_price'],
            $vehicle['image_url']
        );
        if (mysqli_stmt_execute($insertVehicleStmt)) {
            $report['vehicles_added']++;
        }
    }

    mysqli_stmt_close($selectVehicleStmt);
    mysqli_stmt_close($insertVehicleStmt);

    $lookupRentalStmt = mysqli_prepare($conn, "SELECT u.id AS user_id, v.id AS vehicle_id, v.hourly_price FROM users u CROSS JOIN vehicles v WHERE u.email = ? AND v.name = ? LIMIT 1");
    $checkRentalStmt = mysqli_prepare($conn, "SELECT id FROM rentals WHERE user_id = ? AND vehicle_id = ? AND start_time = ? LIMIT 1");
    $insertRentalStmt = mysqli_prepare($conn, "INSERT INTO rentals (user_id, vehicle_id, start_time, end_time, minutes, total_cost) VALUES (?, ?, ?, ?, ?, ?)");

    foreach ($rentals as $rental) {
        mysqli_stmt_bind_param($lookupRentalStmt, "ss", $rental['email'], $rental['vehicle']);
        mysqli_stmt_execute($lookupRentalStmt);
        $result = mysqli_stmt_get_result($lookupRentalStmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;

        if (!$row) {
            $report['rentals_missing']++;
            continue;
        }

        $userId = (int) $row['user_id'];
        $vehicleId = (int) $row['vehicle_id'];
        $start = $rental['start'];
        $minutes = (int) $rental['minutes'];

        mysqli_stmt_bind_param($checkRentalStmt, "iis", $userId, $vehicleId, $start);
        mysqli_stmt_execute($checkRentalStmt);
        $exists = mysqli_stmt_get_result($checkRentalStmt);

        if ($exists && mysqli_fetch_assoc($exists)) {
            $report['rentals_skipped']++;
            continue;
        }

        $startTs = strtotime($start);
        if ($startTs === false) {
            $startTs = time();
            $start = date('Y-m-d H:i:s', $startTs);
        }
        $end = date('Y-m-d H:i:s', $startTs + ($minutes * 60));
        $cost = round(((float) $row['hourly_price'] / 60) * $minutes, 2);

        mysqli_stmt_bind_param($insertRentalStmt, "iissid", $userId, $vehicleId, $start, $end, $minutes, $cost);
        if (mysqli_stmt_execute($insertRentalStmt)) {
            $report['rentals_added']++;
        }
    }

    mysqli_stmt_close($lookupRentalStmt);
    mysqli_stmt_close($checkRentalStmt);
    mysqli_stmt_close($insertRentalStmt);

    $alerts[] = ['type' => 'success', 'message' => 'Seed completed.'];
} elseif ($shouldRun && !$schemaReady) {
    $alerts[] = ['type' => 'danger', 'message' => 'Seed cannot run without tables. Import db/schema.sql or reopen db/seed.php?run=1.'];
}

include '../includes/header.php';
?>
<main>
<div class="container py-5" style="max-width: 720px;">
    <h1 class="fw-bold mb-3">Demo Data Seed</h1>
    <p class="text-muted">Script to insert sample data into the local database.</p>

    <?php if ($alerts): ?>
        <?php foreach ($alerts as $alert): ?>
            <div class="alert alert-<?php echo htmlspecialchars($alert['type']); ?>">
                <?php echo htmlspecialchars($alert['message']); ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!$shouldRun): ?>
        <div class="form-section p-4 shadow-sm">
            <h2 class="fw-bold mb-3">Demo credentials</h2>
            <ul class="mb-4">
                <li>Admin: admin@unibo.it / admin123</li>
                <li>Student: alex.taylor@students.unibo.it / student123</li>
                <li>Student: maya.lee@students.unibo.it / student123</li>
            </ul>
            <a class="btn btn-unibo" href="seed.php?run=1">Run seed</a>
        </div>
    <?php else: ?>
        <div class="form-section p-4 shadow-sm">
            <h2 class="fw-bold mb-3">Result</h2>
            <ul class="mb-0">
                <li>Users added: <?php echo (int) $report['users_added']; ?></li>
                <li>Users already present: <?php echo (int) $report['users_skipped']; ?></li>
                <li>Vehicles added: <?php echo (int) $report['vehicles_added']; ?></li>
                <li>Vehicles already present: <?php echo (int) $report['vehicles_skipped']; ?></li>
                <li>Rentals added: <?php echo (int) $report['rentals_added']; ?></li>
                <li>Rentals already present: <?php echo (int) $report['rentals_skipped']; ?></li>
                <li>Rentals skipped (missing data): <?php echo (int) $report['rentals_missing']; ?></li>
            </ul>
        </div>
    <?php endif; ?>
</div>
</main>
<?php include '../includes/footer.php'; ?>
