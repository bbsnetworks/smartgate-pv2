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

/* =========================
   VENTAS DE PRODUCTOS POS
========================= */
$ventasAgrupadas = [];

$total_productos = 0;
$cantidad_productos = 0;

$productos_por_metodo = [
  "efectivo" => 0,
  "tarjeta" => 0,
  "transferencia" => 0
];

if (!$isTodos) {
  $stmt = $conexion->prepare("
    SELECT 
      pp.venta_id,
      pp.fecha_pago,
      pp.usuario_id,
      u.nombre AS usuario,

      pp.producto_id,
      pp.inventario_usuario_id,
      pp.usuario_propietario_id,

      COALESCE(prod.nombre, 'Producto eliminado') AS producto,
      COALESCE(prod.codigo, '') AS codigo,

      pp.cantidad,
      pp.precio_unitario,
      pp.costo_unitario,
      pp.utilidad_total,
      pp.metodo_pago,
      pp.total,

      COALESCE(propietario.nombre, 'Sin propietario') AS propietario

    FROM pagos_productos pp
    LEFT JOIN productos prod 
      ON pp.producto_id = prod.id
    LEFT JOIN usuarios u 
      ON pp.usuario_id = u.id
    LEFT JOIN usuarios propietario 
      ON pp.usuario_propietario_id = propietario.id

    WHERE pp.usuario_id = ?
      AND DATE(pp.fecha_pago) BETWEEN ? AND ?

    ORDER BY pp.fecha_pago ASC, pp.venta_id ASC
  ");

  $stmt->bind_param("iss", $usuario, $inicio, $fin);
} else {
  $stmt = $conexion->prepare("
    SELECT 
      pp.venta_id,
      pp.fecha_pago,
      pp.usuario_id,
      u.nombre AS usuario,

      pp.producto_id,
      pp.inventario_usuario_id,
      pp.usuario_propietario_id,

      COALESCE(prod.nombre, 'Producto eliminado') AS producto,
      COALESCE(prod.codigo, '') AS codigo,

      pp.cantidad,
      pp.precio_unitario,
      pp.costo_unitario,
      pp.utilidad_total,
      pp.metodo_pago,
      pp.total,

      COALESCE(propietario.nombre, 'Sin propietario') AS propietario

    FROM pagos_productos pp
    LEFT JOIN productos prod 
      ON pp.producto_id = prod.id
    LEFT JOIN usuarios u 
      ON pp.usuario_id = u.id
    LEFT JOIN usuarios propietario 
      ON pp.usuario_propietario_id = propietario.id

    WHERE DATE(pp.fecha_pago) BETWEEN ? AND ?

    ORDER BY pp.fecha_pago ASC, pp.venta_id ASC
  ");

  $stmt->bind_param("ss", $inicio, $fin);
}

$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
  $venta_id = $row['venta_id'] ?? 'SIN-FOLIO';

  $metodo = strtolower(trim((string)($row["metodo_pago"] ?? "sin especificar")));
  $cantidad = intval($row["cantidad"] ?? 0);
  $total = floatval($row["total"] ?? 0);

  $total_productos += $total;
  $cantidad_productos += $cantidad;

  if (!isset($productos_por_metodo[$metodo])) {
    $productos_por_metodo[$metodo] = 0;
  }

  $productos_por_metodo[$metodo] += $total;

  if (!isset($ventasAgrupadas[$venta_id])) {
    $ventasAgrupadas[$venta_id] = [
      "venta_id" => $venta_id,
      "usuario_id" => intval($row['usuario_id'] ?? 0),
      "usuario" => $row['usuario'] ?? "Usuario eliminado",
      "fecha" => date("Y-m-d", strtotime($row['fecha_pago'])),
      "hora" => date("H:i:s", strtotime($row['fecha_pago'])),
      "fecha_pago" => $row['fecha_pago'],
      "metodo_pago" => $row['metodo_pago'] ?? "Sin especificar",
      "total_venta" => 0,
      "cantidad_productos" => 0,
      "productos" => []
    ];
  }

  $ventasAgrupadas[$venta_id]["total_venta"] += $total;
  $ventasAgrupadas[$venta_id]["cantidad_productos"] += $cantidad;

  $ventasAgrupadas[$venta_id]["productos"][] = [
    "producto_id" => intval($row["producto_id"] ?? 0),
    "inventario_usuario_id" => intval($row["inventario_usuario_id"] ?? 0),
    "usuario_propietario_id" => intval($row["usuario_propietario_id"] ?? 0),

    "codigo" => $row["codigo"] ?? "",
    "nombre" => $row["producto"] ?? "Producto eliminado",
    "propietario" => $row["propietario"] ?? "Sin propietario",

    "cantidad" => $cantidad,
    "precio_unitario" => floatval($row["precio_unitario"] ?? 0),
    "costo_unitario" => floatval($row["costo_unitario"] ?? 0),
    "utilidad_total" => floatval($row["utilidad_total"] ?? 0),
    "total" => $total
  ];
}

$stmt->close();

/* =========================
   MOVIMIENTOS DE CAJA
========================= */
$movimientos_caja = [];
$caja_ingresos = 0;
$caja_egresos = 0;

if (!$isTodos) {
  $stCaja = $conexion->prepare("
    SELECT 
      cm.id,
      cm.tipo,
      cm.monto,
      DATE_FORMAT(cm.fecha, '%Y-%m-%d %H:%i:%s') AS fecha,
      cm.concepto,
      cm.observaciones,
      cm.usuario_id,
      u.nombre AS usuario
    FROM caja_movimientos cm
    LEFT JOIN usuarios u ON cm.usuario_id = u.id
    WHERE cm.usuario_id = ?
      AND DATE(cm.fecha) BETWEEN ? AND ?
    ORDER BY cm.fecha ASC
  ");

  $stCaja->bind_param("iss", $usuario, $inicio, $fin);
} else {
  $stCaja = $conexion->prepare("
    SELECT 
      cm.id,
      cm.tipo,
      cm.monto,
      DATE_FORMAT(cm.fecha, '%Y-%m-%d %H:%i:%s') AS fecha,
      cm.concepto,
      cm.observaciones,
      cm.usuario_id,
      u.nombre AS usuario
    FROM caja_movimientos cm
    LEFT JOIN usuarios u ON cm.usuario_id = u.id
    WHERE DATE(cm.fecha) BETWEEN ? AND ?
    ORDER BY cm.fecha ASC
  ");

  $stCaja->bind_param("ss", $inicio, $fin);
}

$stCaja->execute();
$resCaja = $stCaja->get_result();

while ($mov = $resCaja->fetch_assoc()) {
  $tipoMov = strtoupper(trim((string)($mov['tipo'] ?? '')));
  $montoMov = floatval($mov['monto'] ?? 0);

  if ($tipoMov === 'INGRESO') {
    $caja_ingresos += $montoMov;
  }

  if ($tipoMov === 'EGRESO') {
    $caja_egresos += $montoMov;
  }

  $movimientos_caja[] = [
    "id" => intval($mov["id"]),
    "tipo" => $tipoMov,
    "monto" => $montoMov,
    "fecha" => $mov["fecha"],
    "concepto" => $mov["concepto"] ?? "",
    "observaciones" => $mov["observaciones"] ?? "",
    "usuario_id" => intval($mov["usuario_id"] ?? 0),
    "usuario" => $mov["usuario"] ?? "Usuario eliminado"
  ];
}

$stCaja->close();

$caja_neto = $caja_ingresos - $caja_egresos;

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

  "ventas" => array_values($ventasAgrupadas),

  "total_productos" => $total_productos,
  "cantidad_productos" => $cantidad_productos,
  "productos_por_metodo" => $productos_por_metodo,

  "movimientos_caja" => $movimientos_caja,
  "caja_ingresos" => $caja_ingresos,
  "caja_egresos" => $caja_egresos,
  "caja_neto" => $caja_neto,

  "total_general" => $total_productos
]);

exit;