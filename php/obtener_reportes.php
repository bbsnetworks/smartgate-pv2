<?php
require 'conexion.php';

header('Content-Type: application/json; charset=utf-8');

$usuario = $_GET['usuario'] ?? null;
$tipo    = $_GET['tipo'] ?? null;
$fecha   = $_GET['fecha'] ?? null;
$inicio  = $_GET['inicio'] ?? null;
$fin     = $_GET['fin'] ?? null;

if (!$usuario || !$tipo) {
  echo json_encode([
    "success" => false,
    "error" => "Faltan parámetros"
  ]);
  exit;
}

/* =========================
   CALCULAR RANGO DE FECHAS
========================= */
switch ($tipo) {
  case 'dia':
    if (!$fecha) {
      echo json_encode([
        "success" => false,
        "error" => "Falta la fecha del día"
      ]);
      exit;
    }

    $inicio = $fecha;
    $fin    = $fecha;
    break;

  case 'mes':
    if (!$fecha) {
      echo json_encode([
        "success" => false,
        "error" => "Falta el mes"
      ]);
      exit;
    }

    $inicio = date("Y-m-01", strtotime($fecha));
    $fin    = date("Y-m-t", strtotime($fecha));
    break;

  case 'anio':
    if (!$fecha) {
      echo json_encode([
        "success" => false,
        "error" => "Falta el año"
      ]);
      exit;
    }

    $inicio = "$fecha-01-01";
    $fin    = "$fecha-12-31";
    break;

  case 'rango':
    if (!$inicio || !$fin) {
      echo json_encode([
        "success" => false,
        "error" => "Faltan fechas para el rango"
      ]);
      exit;
    }
    break;

  default:
    echo json_encode([
      "success" => false,
      "error" => "Tipo de búsqueda no válido"
    ]);
    exit;
}

$isTodos = ($usuario === "todos");

try {

  /* =========================
     RESUMEN DE VENTAS POS
  ========================= */

  if (!$isTodos) {
    $stmtVentas = $conexion->prepare("
      SELECT 
        COALESCE(SUM(pp.total), 0) AS total_productos,
        COUNT(DISTINCT pp.venta_id) AS cantidad_ventas,
        COALESCE(SUM(pp.cantidad), 0) AS cantidad_productos
      FROM pagos_productos pp
      WHERE pp.usuario_id = ?
        AND DATE(pp.fecha_pago) BETWEEN ? AND ?
    ");

    $stmtVentas->bind_param("iss", $usuario, $inicio, $fin);
  } else {
    $stmtVentas = $conexion->prepare("
      SELECT 
        COALESCE(SUM(pp.total), 0) AS total_productos,
        COUNT(DISTINCT pp.venta_id) AS cantidad_ventas,
        COALESCE(SUM(pp.cantidad), 0) AS cantidad_productos
      FROM pagos_productos pp
      WHERE DATE(pp.fecha_pago) BETWEEN ? AND ?
    ");

    $stmtVentas->bind_param("ss", $inicio, $fin);
  }

  $stmtVentas->execute();
  $stmtVentas->bind_result(
    $total_productos,
    $cantidad_ventas,
    $cantidad_productos
  );
  $stmtVentas->fetch();
  $stmtVentas->close();

  /* =========================
     VENTAS POR MÉTODO DE PAGO
  ========================= */

  $productos_por_metodo = [
    "efectivo" => 0,
    "tarjeta" => 0,
    "transferencia" => 0
  ];

  if (!$isTodos) {
    $stmtMetodo = $conexion->prepare("
      SELECT 
        LOWER(TRIM(COALESCE(pp.metodo_pago, 'sin especificar'))) AS metodo,
        COALESCE(SUM(pp.total), 0) AS total
      FROM pagos_productos pp
      WHERE pp.usuario_id = ?
        AND DATE(pp.fecha_pago) BETWEEN ? AND ?
      GROUP BY LOWER(TRIM(COALESCE(pp.metodo_pago, 'sin especificar')))
    ");

    $stmtMetodo->bind_param("iss", $usuario, $inicio, $fin);
  } else {
    $stmtMetodo = $conexion->prepare("
      SELECT 
        LOWER(TRIM(COALESCE(pp.metodo_pago, 'sin especificar'))) AS metodo,
        COALESCE(SUM(pp.total), 0) AS total
      FROM pagos_productos pp
      WHERE DATE(pp.fecha_pago) BETWEEN ? AND ?
      GROUP BY LOWER(TRIM(COALESCE(pp.metodo_pago, 'sin especificar')))
    ");

    $stmtMetodo->bind_param("ss", $inicio, $fin);
  }

  $stmtMetodo->execute();
  $resMetodo = $stmtMetodo->get_result();

  while ($row = $resMetodo->fetch_assoc()) {
    $metodo = strtolower(trim((string)($row['metodo'] ?? 'sin especificar')));
    $total  = floatval($row['total'] ?? 0);

    if (!isset($productos_por_metodo[$metodo])) {
      $productos_por_metodo[$metodo] = 0;
    }

    $productos_por_metodo[$metodo] += $total;
  }

  $stmtMetodo->close();

  /* =========================
     MOVIMIENTOS DE CAJA
  ========================= */

  if (!$isTodos) {
    $stmtCaja = $conexion->prepare("
      SELECT 
        COALESCE(SUM(CASE WHEN UPPER(tipo) = 'INGRESO' THEN monto ELSE 0 END), 0) AS ingresos,
        COALESCE(SUM(CASE WHEN UPPER(tipo) = 'EGRESO' THEN monto ELSE 0 END), 0) AS egresos,
        COUNT(*) AS cantidad
      FROM caja_movimientos
      WHERE usuario_id = ?
        AND DATE(fecha) BETWEEN ? AND ?
    ");

    $stmtCaja->bind_param("iss", $usuario, $inicio, $fin);
  } else {
    $stmtCaja = $conexion->prepare("
      SELECT 
        COALESCE(SUM(CASE WHEN UPPER(tipo) = 'INGRESO' THEN monto ELSE 0 END), 0) AS ingresos,
        COALESCE(SUM(CASE WHEN UPPER(tipo) = 'EGRESO' THEN monto ELSE 0 END), 0) AS egresos,
        COUNT(*) AS cantidad
      FROM caja_movimientos
      WHERE DATE(fecha) BETWEEN ? AND ?
    ");

    $stmtCaja->bind_param("ss", $inicio, $fin);
  }

  $stmtCaja->execute();
  $stmtCaja->bind_result(
    $caja_ingresos,
    $caja_egresos,
    $caja_cantidad
  );
  $stmtCaja->fetch();
  $stmtCaja->close();

  $caja_neto = floatval($caja_ingresos ?? 0) - floatval($caja_egresos ?? 0);

  /* =========================
     RESPUESTA FINAL
  ========================= */

  echo json_encode([
    "success" => true,

    "rango" => [
      "inicio" => $inicio,
      "fin" => $fin,
      "tipo" => $tipo,
      "usuario" => $usuario
    ],

    // Ventas POS
    "total_productos" => floatval($total_productos ?? 0),
    "cantidad_productos" => intval($cantidad_ventas ?? 0),
    "cantidad_unidades" => intval($cantidad_productos ?? 0),

    // Total general del POS
    // No se mezclan movimientos de caja para no inflar ventas.
    "total_general" => floatval($total_productos ?? 0),

    // Métodos de pago
    "productos_por_metodo" => $productos_por_metodo,

    // Caja
    "caja_ingresos" => floatval($caja_ingresos ?? 0),
    "caja_egresos" => floatval($caja_egresos ?? 0),
    "caja_neto" => $caja_neto,
    "caja_cantidad" => intval($caja_cantidad ?? 0)
  ]);
} catch (Exception $e) {
  echo json_encode([
    "success" => false,
    "error" => "Error al consultar: " . $e->getMessage()
  ]);
}