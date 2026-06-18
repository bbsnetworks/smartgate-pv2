<?php
require_once './verificar_sesion.php';
require_once './conexion.php';

header('Content-Type: application/json; charset=utf-8');

$uidSes = intval($_SESSION['usuario']['id'] ?? 0);
$rolSes = $_SESSION['usuario']['rol'] ?? 'worker';

if ($uidSes <= 0) {
  echo json_encode([
    "success" => false,
    "error" => "Sesión no válida"
  ]);
  exit;
}

$period = $_GET['period'] ?? 'hoy';

$userParam = $_GET['user'] ?? 'me'; // me | all | id
$selectedAll = ($userParam === 'all');
$selectedUid = ($userParam === 'me') ? $uidSes : intval($userParam);

// Seguridad: worker solo ve sus propias ventas
if ($rolSes === 'worker') {
  $selectedAll = false;
  $selectedUid = $uidSes;
}

if (!$selectedAll && $selectedUid <= 0) {
  $selectedUid = $uidSes;
}

/* =========================
   HELPERS
========================= */
function monedaMX($n) {
  return '$' . number_format(floatval($n), 2);
}

function rangoPeriodo($period) {
  $hoy = date('Y-m-d');

  switch ($period) {
    case 'semana':
      $ini = date('Y-m-d', strtotime('monday this week'));
      $fin = date('Y-m-d', strtotime('sunday this week'));
      break;

    case 'mes':
      $ini = date('Y-m-01');
      $fin = date('Y-m-t');
      break;

    case 'hoy':
    default:
      $ini = $hoy;
      $fin = $hoy;
      break;
  }

  return [
    $ini . ' 00:00:00',
    $fin . ' 23:59:59',
    $ini,
    $fin
  ];
}

/* =========================
   GRÁFICA DE VENTAS POS
========================= */
if (isset($_GET['serie'])) {
  $serie = $_GET['serie'] ?? 'prod';
  $resolucion = $_GET['res'] ?? 'mes';

  $labels = [];
  $data = [];

  $obtenerTotalRango = function ($ini, $finExcl) use ($conexion, $selectedAll, $selectedUid) {
    if ($selectedAll) {
      $stmt = $conexion->prepare("
        SELECT COALESCE(SUM(total), 0) AS total
        FROM pagos_productos
        WHERE fecha_pago >= ?
          AND fecha_pago < ?
      ");

      $stmt->bind_param("ss", $ini, $finExcl);
    } else {
      $stmt = $conexion->prepare("
        SELECT COALESCE(SUM(total), 0) AS total
        FROM pagos_productos
        WHERE fecha_pago >= ?
          AND fecha_pago < ?
          AND usuario_id = ?
      ");

      $stmt->bind_param("ssi", $ini, $finExcl, $selectedUid);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return floatval($row['total'] ?? 0);
  };

  if ($resolucion === 'dia') {
    for ($i = 29; $i >= 0; $i--) {
      $ini = date('Y-m-d 00:00:00', strtotime("-{$i} days"));
      $finExcl = date('Y-m-d 00:00:00', strtotime("-{$i} days +1 day"));

      $labels[] = date('d M', strtotime($ini));
      $data[] = $obtenerTotalRango($ini, $finExcl);
    }
  } elseif ($resolucion === 'semana') {
    for ($i = 11; $i >= 0; $i--) {
      $ini = date('Y-m-d 00:00:00', strtotime("monday -{$i} week"));
      $finExcl = date('Y-m-d 00:00:00', strtotime("monday -{$i} week +7 days"));
      $finShow = date('Y-m-d', strtotime($finExcl . ' -1 day'));

      $labels[] = date('d M', strtotime($ini)) . ' - ' . date('d M', strtotime($finShow));
      $data[] = $obtenerTotalRango($ini, $finExcl);
    }
  } else {
    for ($i = 11; $i >= 0; $i--) {
      $ini = date('Y-m-01 00:00:00', strtotime("-{$i} months"));
      $finExcl = date('Y-m-01 00:00:00', strtotime($ini . " +1 month"));

      $labels[] = date('M Y', strtotime($ini));
      $data[] = $obtenerTotalRango($ini, $finExcl);
    }
  }

  echo json_encode([
    "labels" => $labels,
    "data" => $data
  ], JSON_UNESCAPED_UNICODE);

  exit;
}

/* =========================
   RANGO KPI
========================= */
[$iniDt, $finDt, $iniFecha, $finFecha] = rangoPeriodo($period);

/* =========================
   RESPUESTA BASE
========================= */
$out = [
  "success" => true,

  "ventas_monto" => 0,
  "ventas_monto_fmt" => "$0.00",
  "ventas_detalle" => "",

  "ventas_cantidad" => 0,
  "ventas_cantidad_detalle" => "",

  "productos_vendidos" => 0,

  "utilidad_total" => 0,
  "utilidad_total_fmt" => "$0.00",

  "producto_top" => null,

  "stock_bajo" => [],
  "stock_bajo_total" => 0,

  "ultimas_ventas" => []
];

/* =========================
   TOTAL VENTAS DEL PERIODO
========================= */
if ($selectedAll) {
  $stmtVentas = $conexion->prepare("
    SELECT
      COALESCE(SUM(total), 0) AS monto,
      COUNT(DISTINCT venta_id) AS ventas,
      COALESCE(SUM(cantidad), 0) AS productos,
      COALESCE(SUM(utilidad_total), 0) AS utilidad
    FROM pagos_productos
    WHERE fecha_pago BETWEEN ? AND ?
  ");

  $stmtVentas->bind_param("ss", $iniDt, $finDt);
  $rolDetalle = "todos";
} else {
  $stmtVentas = $conexion->prepare("
    SELECT
      COALESCE(SUM(total), 0) AS monto,
      COUNT(DISTINCT venta_id) AS ventas,
      COALESCE(SUM(cantidad), 0) AS productos,
      COALESCE(SUM(utilidad_total), 0) AS utilidad
    FROM pagos_productos
    WHERE fecha_pago BETWEEN ? AND ?
      AND usuario_id = ?
  ");

  $stmtVentas->bind_param("ssi", $iniDt, $finDt, $selectedUid);
  $rolDetalle = "usuario seleccionado";
}

$stmtVentas->execute();
$rowVentas = $stmtVentas->get_result()->fetch_assoc();
$stmtVentas->close();

$ventasMonto = floatval($rowVentas['monto'] ?? 0);
$ventasCantidad = intval($rowVentas['ventas'] ?? 0);
$productosVendidos = intval($rowVentas['productos'] ?? 0);
$utilidadTotal = floatval($rowVentas['utilidad'] ?? 0);

$out["ventas_monto"] = $ventasMonto;
$out["ventas_monto_fmt"] = monedaMX($ventasMonto);
$out["ventas_detalle"] = "Periodo: {$period} ({$rolDetalle})";

$out["ventas_cantidad"] = $ventasCantidad;
$out["ventas_cantidad_detalle"] = "{$ventasCantidad} venta(s) · {$productosVendidos} producto(s)";

$out["productos_vendidos"] = $productosVendidos;

$out["utilidad_total"] = $utilidadTotal;
$out["utilidad_total_fmt"] = monedaMX($utilidadTotal);

/* =========================
   PRODUCTO MÁS VENDIDO
========================= */
if ($selectedAll) {
  $stmtTop = $conexion->prepare("
    SELECT
      pp.producto_id,
      COALESCE(
        NULLIF(TRIM(CONCAT_WS(' ', p.marca, p.modelo)), ''),
        'Producto eliminado'
      ) AS nombre,
      COALESCE(SUM(pp.cantidad), 0) AS cantidad,
      COALESCE(SUM(pp.total), 0) AS total
    FROM pagos_productos pp
    LEFT JOIN productos p
      ON p.id = pp.producto_id
    WHERE pp.fecha_pago BETWEEN ? AND ?
    GROUP BY pp.producto_id, p.marca, p.modelo
    ORDER BY cantidad DESC, total DESC
    LIMIT 1
  ");

  $stmtTop->bind_param("ss", $iniDt, $finDt);
} else {
  $stmtTop = $conexion->prepare("
    SELECT
      pp.producto_id,
      COALESCE(
        NULLIF(TRIM(CONCAT_WS(' ', p.marca, p.modelo)), ''),
        'Producto eliminado'
      ) AS nombre,
      COALESCE(SUM(pp.cantidad), 0) AS cantidad,
      COALESCE(SUM(pp.total), 0) AS total
    FROM pagos_productos pp
    LEFT JOIN productos p
      ON p.id = pp.producto_id
    WHERE pp.fecha_pago BETWEEN ? AND ?
      AND pp.usuario_id = ?
    GROUP BY pp.producto_id, p.marca, p.modelo
    ORDER BY cantidad DESC, total DESC
    LIMIT 1
  ");

  $stmtTop->bind_param("ssi", $iniDt, $finDt, $selectedUid);
}

$stmtTop->execute();
$rowTop = $stmtTop->get_result()->fetch_assoc();
$stmtTop->close();

if ($rowTop) {
  $out["producto_top"] = [
    "producto_id" => intval($rowTop["producto_id"] ?? 0),
    "nombre" => $rowTop["nombre"] ?? "Producto eliminado",
    "cantidad" => intval($rowTop["cantidad"] ?? 0),
    "total" => floatval($rowTop["total"] ?? 0),
    "total_fmt" => monedaMX($rowTop["total"] ?? 0)
  ];
}

/* =========================
   STOCK BAJO
   Ahora usa inventario_usuarios, no productos.nombre
========================= */
$umbral = isset($_GET['umbral']) ? intval($_GET['umbral']) : 5;

if ($selectedAll) {
  $stmtStock = $conexion->prepare("
    SELECT
      p.id AS producto_id,
      COALESCE(
        NULLIF(TRIM(CONCAT_WS(' ', p.marca, p.modelo)), ''),
        'Producto sin nombre'
      ) AS nombre,
      COALESCE(SUM(iu.stock), 0) AS stock
    FROM productos p
    LEFT JOIN inventario_usuarios iu
      ON iu.producto_id = p.id
      AND iu.activo = 1
    GROUP BY p.id, p.marca, p.modelo
    HAVING stock <= ?
    ORDER BY stock ASC, nombre ASC
    LIMIT 30
  ");

  $stmtStock->bind_param("i", $umbral);
} else {
  $stmtStock = $conexion->prepare("
    SELECT
      p.id AS producto_id,
      COALESCE(
        NULLIF(TRIM(CONCAT_WS(' ', p.marca, p.modelo)), ''),
        'Producto sin nombre'
      ) AS nombre,
      COALESCE(iu.stock, 0) AS stock
    FROM inventario_usuarios iu
    INNER JOIN productos p
      ON p.id = iu.producto_id
    WHERE iu.activo = 1
      AND iu.usuario_id = ?
      AND COALESCE(iu.stock, 0) <= ?
    ORDER BY iu.stock ASC, nombre ASC
    LIMIT 30
  ");

  $stmtStock->bind_param("ii", $selectedUid, $umbral);
}

$stmtStock->execute();
$resStock = $stmtStock->get_result();

$stockBajo = [];

while ($r = $resStock->fetch_assoc()) {
  $stockBajo[] = [
    "producto_id" => intval($r["producto_id"] ?? 0),
    "nombre" => $r["nombre"] ?? "Producto sin nombre",
    "stock" => intval($r["stock"] ?? 0),
    "min" => $umbral
  ];
}

$stmtStock->close();

$out["stock_bajo"] = $stockBajo;
$out["stock_bajo_total"] = count($stockBajo);

/* =========================
   ÚLTIMAS VENTAS
========================= */
if ($selectedAll) {
  $stmtUltimas = $conexion->prepare("
    SELECT
      pp.venta_id,
      MAX(pp.fecha_pago) AS fecha_pago,
      MAX(pp.metodo_pago) AS metodo_pago,
      MAX(u.nombre) AS usuario,
      COALESCE(SUM(pp.total), 0) AS total,
      COALESCE(SUM(pp.cantidad), 0) AS cantidad
    FROM pagos_productos pp
    LEFT JOIN usuarios u
      ON u.id = pp.usuario_id
    GROUP BY pp.venta_id
    ORDER BY fecha_pago DESC
    LIMIT 5
  ");
} else {
  $stmtUltimas = $conexion->prepare("
    SELECT
      pp.venta_id,
      MAX(pp.fecha_pago) AS fecha_pago,
      MAX(pp.metodo_pago) AS metodo_pago,
      MAX(u.nombre) AS usuario,
      COALESCE(SUM(pp.total), 0) AS total,
      COALESCE(SUM(pp.cantidad), 0) AS cantidad
    FROM pagos_productos pp
    LEFT JOIN usuarios u
      ON u.id = pp.usuario_id
    WHERE pp.usuario_id = ?
    GROUP BY pp.venta_id
    ORDER BY fecha_pago DESC
    LIMIT 5
  ");

  $stmtUltimas->bind_param("i", $selectedUid);
}

$stmtUltimas->execute();
$resUltimas = $stmtUltimas->get_result();

$ultimas = [];

while ($v = $resUltimas->fetch_assoc()) {
  $ultimas[] = [
    "venta_id" => $v["venta_id"] ?? "SIN-FOLIO",
    "fecha_pago" => $v["fecha_pago"] ?? "",
    "metodo_pago" => $v["metodo_pago"] ?? "Sin especificar",
    "usuario" => $v["usuario"] ?? "Usuario eliminado",
    "total" => floatval($v["total"] ?? 0),
    "total_fmt" => monedaMX($v["total"] ?? 0),
    "cantidad" => intval($v["cantidad"] ?? 0)
  ];
}

$stmtUltimas->close();

$out["ultimas_ventas"] = $ultimas;

echo json_encode($out, JSON_UNESCAPED_UNICODE);
exit;