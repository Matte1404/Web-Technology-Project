<?php
/** @var mysqli $conn */
include 'db/db_config.php';
include 'header.php';
?>

    <section class="py-5 text-white" style="background: linear-gradient(135deg, #BB2E29 0%, #8B1E1E 100%);">
        <div class="container text-center py-4">
            <h1 class="display-5 fw-bold mb-3">Find Your Ride</h1>
            <p class="lead opacity-90">Sustainable campus transportation made easy</p>

            <div class="bg-white p-4 rounded-4 shadow-lg text-dark mx-auto mt-5" style="max-width: 800px;">
                <div class="row g-3">
                    <div class="col-md-6 text-start">
                        <label class="form-label small fw-bold">Vehicle Type</label>
                        <select class="form-select border-2">
                            <option>All Vehicles</option>
                            <option>Bike</option>
                            <option>E-Scooter</option>
                        </select>
                    </div>
                    <div class="col-md-6 text-start">
                        <label class="form-label small fw-bold">Availability</label>
                        <select class="form-select border-2">
                            <option>All</option>
                            <option>Available</option>
                            <option>Busy</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5">
        <div class="container">
            <h2 class="fw-bold mb-4">Available Vehicles</h2>
            <div class="row row-cols-1 row-cols-md-3 g-4">

                <?php
                $query = "SELECT * FROM mezzi";
                $result = mysqli_query($conn, $query);

                if (mysqli_num_rows($result) > 0) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $batt = $row['batteria'];
                        $battColor = "bg-success";
                        if ($batt < 20) $battColor = "bg-danger";
                        elseif ($batt < 50) $battColor = "bg-warning";
                        ?>
                        <div class="col">
                            <div class="unibo-card h-100 overflow-hidden shadow-sm border-0">
                                <div class="position-relative">
                                    <img src="<?php echo (!empty($row['immagine'])) ? $row['immagine'] : 'https://placehold.co/600x400?text=No+Image'; ?>" class="card-img-top" alt="Mezzo" style="height: 200px; object-fit: cover;">
                                    <span class="position-absolute top-0 end-0 m-3 badge bg-success rounded-pill px-3">
                                    <?php echo $row['stato']; ?>
                                </span>
                                </div>
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between">
                                        <h5 class="fw-bold text-dark"><?php echo $row['nome']; ?></h5>
                                        <span class="h4 fw-bold text-danger">€<?php echo $row['prezzo_ora']; ?></span>
                                    </div>
                                    <p class="text-muted small"><?php echo $row['posizione']; ?></p>

                                    <div class="my-3 text-dark">
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span>Battery</span>
                                            <span><?php echo $row['batteria']; ?>%</span>
                                        </div>
                                        <div class="progress" style="height: 6px;">
                                            <div class="progress-bar <?php echo $battColor; ?>" style="width: <?php echo $row['batteria']; ?>%"></div>
                                        </div>
                                    </div>
                                    <button class="btn btn-unibo w-100 mt-2">Reserve Now</button>
                                </div>
                            </div>
                        </div>
                        <?php
                    } // <-- QUESTA MANCAVA: Chiude il ciclo While
                } else {
                    echo "<div class='col-12'><p class='text-center'>Nessun mezzo trovato nel database.</p></div>";
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
                                <div class="bg-white p-3 rounded-circle shadow-sm text-danger"><i class="fas fa-map-pin"></i></div>
                                <div>
                                    <h6 class="fw-bold mb-0">Main Campus</h6>
                                    <small class="text-muted">Via Zamboni 33</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php include 'footer.php'; ?>