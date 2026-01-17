<?php
/** @var mysqli $conn */
include 'db/db_config.php';
include 'header.php';

if(isset($_GET['delete'])){
    $id = $_GET['delete'];
    mysqli_query($conn, "DELETE FROM mezzi WHERE id=$id");
    header('location:admin.php');
}
?>

    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold">Gestione Flotta</h2>
            <button class="btn btn-unibo"><i class="fas fa-plus me-2"></i>Aggiungi Mezzo</button>
        </div>

        <div class="bg-white shadow-sm rounded-4 overflow-hidden">
            <table class="table table-hover mb-0">
                <thead class="bg-light">
                <tr>
                    <th class="px-4">Mezzo</th>
                    <th>Tipo</th>
                    <th>Stato</th>
                    <th>Batteria</th>
                    <th class="text-end px-4">Azioni</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $result = mysqli_query($conn, "SELECT * FROM mezzi");
                while($row = mysqli_fetch_assoc($result)):
                    ?>
                    <tr class="align-middle">
                        <td class="px-4 fw-bold"><?php echo $row['nome']; ?></td>
                        <td><span class="badge bg-light text-dark border"><?php echo $row['tipo']; ?></span></td>
                        <td>
                        <span class="badge <?php echo $row['stato'] == 'available' ? 'bg-success' : 'bg-secondary'; ?>">
                            <?php echo $row['stato']; ?>
                        </span>
                        </td>
                        <td><?php echo $row['batteria']; ?>%</td>
                        <td class="text-end px-4">
                            <button class="btn btn-sm btn-outline-primary me-2"><i class="fas fa-edit"></i></button>
                            <a href="admin.php?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Sicuro?')"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php include 'footer.php'; ?>