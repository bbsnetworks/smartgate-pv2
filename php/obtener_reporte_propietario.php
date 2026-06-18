<?php
require 'conexion.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario']['id'])) {
  echo json_encode([
    "success" => false,
    "error" => "Sesión no válida"
  ]);
  exit;
}

if (($_SESSION['usuario']['rol'] ?? '') !== 'admin') {
  echo json_encode([
    "success" => false,
    "error" => "Este reporte solo está disponible para usuarios admin"
  ]);
  exit;
}

$usuarioPropietarioId = intval($_SESSION['usuario']['id']);

$tipo   = $_GET['tipo'] ?? null;
$fecha  = $_GET['fecha'] ?? null;
$inicio = $_GET['inicio'] ?? null;
$fin    = $_GET['fin'] ?? null;

if (!$tipo) {
  echo json_encode([
    "success" => false,
    "error" => "Falta tipo de reporte"
  ]);
  exit;
}

/* =========================
   CALCULAR RANGO DE FECHAS
========================= */
switch ($tipo) {
  case 'dia':
    if (!$fecha) {
      echo json_encode(["success" => false, "error" => "Falta la fecha"]);
      exit;
    }
    $inicio = $fecha;
    $fin = $fecha;
    break;

  case 'mes':
    if (!$fecha) {
      echo json_encode(["success" => false, "error" => "Falta el mes"]);
      exit;
    }
    $inicio = date("Y-m-01", strtotime($fecha));
    $fin = date("Y-m-t", strtotime($fecha));
    break;

  case 'anio':
    if (!$fecha) {
      echo json_encode(["success" => false, "error" => "Falta el año"]);
      exit;
    }
    $inicio = "$fecha-01-01";
    $fin = "$fecha-12-31";
    break;

  case 'rango':
    if (!$inicio || !$fin) {
      echo json_encode(["success" => false, "error" => "Faltan fechas para el rango"]);
      exit;
    }
    break;

  default:
    echo json_encode(["success" => false, "error" => "Tipo no válido"]);
    exit;
}

$stmt = $conexion->prepare("
  SELECT
    pp.producto_id,
    pp.inventario_usuario_id,

    COALESCE(prod.codigo, '') AS codigo,
    COALESCE(
    NULLIF(TRIM(CONCAT_WS(' ', prod.marca, prod.modelo)), ''),
    'Producto eliminado'
    ) AS producto,

    SUM(pp.cantidad) AS cantidad,
    SUM(pp.total) AS total_vendido,
    SUM(pp.costo_unitario * pp.cantidad) AS costo_total,
    SUM(pp.utilidad_total) AS ganancia_total,
    COUNT(DISTINCT pp.venta_id) AS ventas

  FROM pagos_productos pp

  LEFT JOIN productos prod
    ON prod.id = pp.producto_id

  WHERE pp.usuario_propietario_id = ?
    AND DATE(pp.fecha_pago) BETWEEN ? AND ?

  GROUP BY
    pp.producto_id,
    pp.inventario_usuario_id,
    prod.codigo,
    prod.marca,
    prod.modelo

  ORDER BY ganancia_total DESC, total_vendido DESC
");

$stmt->bind_param("iss", $usuarioPropietarioId, $inicio, $fin);
$stmt->execute();
$res = $stmt->get_result();

$productos = [];

$totalCantidad = 0;
$totalVendido = 0;
$totalCosto = 0;
$totalGanancia = 0;
$totalVentas = 0;

while ($row = $res->fetch_assoc()) {
  $cantidad = intval($row['cantidad'] ?? 0);
  $vendido = floatval($row['total_vendido'] ?? 0);
  $costo = floatval($row['costo_total'] ?? 0);
  $ganancia = floatval($row['ganancia_total'] ?? 0);
  $ventas = intval($row['ventas'] ?? 0);

  $productos[] = [
    "producto_id" => intval($row["producto_id"] ?? 0),
    "inventario_usuario_id" => intval($row["inventario_usuario_id"] ?? 0),
    "codigo" => $row["codigo"] ?? "",
    "producto" => $row["producto"] ?? "Producto eliminado",
    "cantidad" => $cantidad,
    "total_vendido" => $vendido,
    "costo_total" => $costo,
    "ganancia_total" => $ganancia,
    "ventas" => $ventas
  ];

  $totalCantidad += $cantidad;
  $totalVendido += $vendido;
  $totalCosto += $costo;
  $totalGanancia += $ganancia;
  $totalVentas += $ventas;
}

$stmt->close();

echo json_encode([
  "success" => true,
  "propietario" => $_SESSION['usuario']['nombre'] ?? 'Usuario',
  "rango" => [
    "inicio" => $inicio,
    "fin" => $fin,
    "tipo" => $tipo
  ],
  "productos" => $productos,
  "totales" => [
    "cantidad" => $totalCantidad,
    "total_vendido" => $totalVendido,
    "costo_total" => $totalCosto,
    "ganancia_total" => $totalGanancia,
    "ventas" => $totalVentas
  ]
]);