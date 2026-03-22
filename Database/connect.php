<?php
date_default_timezone_set('Asia/Manila');

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "ccs_sitin";

$conn = new mysqli($servername, $username, $password, $dbname);

if($conn->connect_error){
    die("Database connection failed: " . $conn->connect_error);
}
?>