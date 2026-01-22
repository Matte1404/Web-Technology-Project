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

// Fetch hubs for the map
$hubsQuery = "SELECT * FROM hubs ORDER BY name ASC";
$hubsResult = mysqli_query($conn, $hubsQuery);
$hubsData = [];
if ($hubsResult) {
    while ($row = mysqli_fetch_assoc($hubsResult)) {
        $hubsData[] = [
            'lat' => (float)$row['lat'], 
            'lng' => (float)$row['lng'], 
            'name' => $row['name'], 
            'desc' => $row['description']
        ];
    }
}

include 'includes/header.php';
?>

<main>

    <section class="py-5" style="background-color: #721c1c; color: #ffffff;" id="main-content">
        <div class="container text-center py-4">
            <h1 class="display-5 fw-bold mb-3" style="color: #ffffff;">Find Your Ride</h1>
            <p class="fs-2" style="color: #ffffff;">Sustainable campus transportation made easy</p>

            <div class="bg-white p-4 rounded-4 shadow-lg text-dark mx-auto mt-5" style="max-width: 520px; background-color: #ffffff;">
                <form class="row g-3" method="get" id="filters">
                    <div class="col-12 text-start">
                        <label class="form-label fw-bold" for="status-filter-select" style="color: #000000;">Status</label>
                        <select class="form-select border-2" name="status" id="status-filter-select">
                            <option value="all" selected="">All vehicles</option>
                            <option value="available">Available</option>
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
                <!-- List (Left) -->
                <div class="col-md-6 px-lg-5 mb-4 mb-md-0 order-2 order-md-1">
                    <div class="list-group list-group-flush bg-transparent" id="hub-list">
                        <!-- JS populated -->
                    </div>
                </div>
                
                <!-- Map (Right) -->
                <div class="col-md-6 order-1 order-md-2">
                    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
                    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
                    
                    <div class="rounded-4 overflow-hidden shadow">
                        <div id="home-map"></div>
                    </div>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            // Cesena coordinates
                            var map = L.map('home-map').setView([44.1485, 12.2346], 14);
                            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
                            
                            var hubs = <?php echo json_encode($hubsData); ?>;
                            var listContainer = document.getElementById('hub-list');

                            hubs.forEach(function(h) {
                                // Add Marker
                                L.marker([h.lat, h.lng]).addTo(map).bindPopup('<strong>' + h.name + '</strong><br>' + h.desc);

                                // Add List Item
                                var item = document.createElement('div');
                                item.className = 'list-group-item bg-transparent border-0 px-0 mb-3 hub-item';
                                item.innerHTML = `
                                    <div class="d-flex gap-3 align-items-center">
                                        <div class="bg-white p-3 rounded-circle shadow-sm text-danger"><span class="fas fa-map-pin"></span></div>
                                        <div>
                                            <h3 class="h6 fw-bold mb-0">${h.name}</h3>
                                            <small class="text-muted">${h.desc}</small>
                                        </div>
                                    </div>
                                `;
                                item.addEventListener('click', function() {
                                    map.flyTo([h.lat, h.lng], 16);
                                });
                                listContainer.appendChild(item);
                            });
                        });
                    </script>
                </div>
            </div>
        </div>
    </section>
</main>

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
