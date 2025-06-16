<?php

$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "kopi-ler";


mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    
    $koneksi = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    
    $koneksi->set_charset("utf8mb4");

} catch (mysqli_sql_exception $e) {
   
    die("Koneksi ke database GAGAL: " . $e->getMessage());
}

?>