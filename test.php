<?php
echo "PHP version: " . PHP_VERSION . "<br>";
echo "PDO MySQL driver: " .
    (extension_loaded("pdo_mysql") ? "Available" : "Not available");
?>
