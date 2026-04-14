<?php
session_start();
if(!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true){
    header("Location: ../login.php"); exit;
}

include 'connect.php';
$results = $conn->query("SELECT * FROM students");
echo "<h2>Registered Students</h2>";
echo "<table border='1'>";
echo "<tr><th>ID</th><th>Last Name</th><th>First Name</th><th>Course</th><th>Year</th><th>Email</th></tr>";
while($row = $results->fetch_assoc()){
    echo "<tr>
        <td>" . htmlspecialchars($row['id_number'])  . "</td>
        <td>" . htmlspecialchars($row['last_name'])   . "</td>
        <td>" . htmlspecialchars($row['first_name'])  . "</td>
        <td>" . htmlspecialchars($row['course'])      . "</td>
        <td>" . htmlspecialchars($row['year_level'])  . "</td>
        <td>" . htmlspecialchars($row['email'])       . "</td>
    </tr>";
}
echo "</table>";
$conn->close();
?>