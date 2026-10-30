<?php
/**
 * Database connection. Configure these environment variables in production:
 * BOARDING_HAUZ_DB_HOST, BOARDING_HAUZ_DB_PORT, BOARDING_HAUZ_DB_NAME,
 * BOARDING_HAUZ_DB_USER, BOARDING_HAUZ_DB_PASSWORD.
 */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('BOARDING_HAUZ_DB_HOST') ?: '127.0.0.1';
$portValue = getenv('BOARDING_HAUZ_DB_PORT') ?: '3306';
$port = ctype_digit((string) $portValue) ? (int) $portValue : 3306;
$username = getenv('BOARDING_HAUZ_DB_USER') ?: 'root';
$password = getenv('BOARDING_HAUZ_DB_PASSWORD');
$password = $password === false ? '' : $password;
$database = getenv('BOARDING_HAUZ_DB_NAME') ?: 'boarding_hauz';

try {
    $conn = new mysqli($host, $username, $password, $database, $port);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $exception) {
    error_log('Boarding Hauz database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The application is temporarily unavailable. Please try again later.');
}
