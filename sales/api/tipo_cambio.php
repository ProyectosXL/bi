<?php
/**
 * /bi/sales/api/tipo_cambio.php
 * Devuelve el último tipo de cambio dólar oficial BCRA (valor Comprador).
 * Fuente: XL-APPS / sistemas / dolar_oficial_bcra
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

require_once __DIR__ . '/../../class/classEnv.php';

try {
    $vars = (new DotEnv(__DIR__ . '/../../../.env'))->listVars();

    $host = $vars['HOST_APPS'];
    $user = $vars['USER'];
    $pass = $vars['PASS'];
    $char = $vars['CHARACTER'];

    $conn = sqlsrv_connect($host, [
        'Database'     => 'sistemas',
        'UID'          => $user,
        'PWD'          => $pass,
        'CharacterSet' => $char,
    ]);

    if (!$conn) {
        throw new RuntimeException('No se pudo conectar a XL-APPS/sistemas');
    }

    $sql = "
        SELECT TOP 1
            CONVERT(varchar(10), Fecha, 23) AS fecha,
            Comprador
        FROM dolar_oficial_bcra
        WHERE Comprador IS NOT NULL
        ORDER BY Fecha DESC
    ";

    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        throw new RuntimeException('Error al consultar dolar_oficial_bcra');
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);

    if (!$row) {
        throw new RuntimeException('Sin datos en dolar_oficial_bcra');
    }

    echo json_encode([
        'ok'         => true,
        'fecha'      => $row['fecha'],
        'comprador'  => (float)$row['Comprador'],
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
