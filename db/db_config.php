<?php
$host = "localhost";
$user = "root";
$password = "";
$database = "unibo_mobility";

$conn = mysqli_connect($host, $user, $password, $database);

if (!$conn) {
    die("Connessione fallita: " . mysqli_connect_error());
}
?>