<?php
require_once 'conexion.php';

header("Content-Type: application/json");

$termino = trim($_GET['termino'] ?? '');

if ($termino === '') {
  echo json_encode([]);
  exit;
}

$like = "%{$termino}%";

$sql = "
  SELECT
    p.id AS producto_id,
    p.codigo,
    p.marca,
    p.modelo,
    TRIM(CONCAT_WS(' ', p.marca, p.modelo)) AS nombre,
    p.descripcion,

    COUNT(iu.id) AS inventarios,
    SUM(iu.stock) AS stock_total,
    MIN(iu.precio_venta) AS precio_min,
    MAX(iu.precio_venta) AS precio_max,

    c.nombre AS categoria

  FROM productos p

  INNER JOIN inventario_usuarios iu
    ON iu.producto_id = p.id
    AND iu.activo = 1
    AND iu.stock > 0

  LEFT JOIN categorias c
    ON c.id = p.categoria_id

  WHERE
    p.codigo LIKE ?
    OR p.marca LIKE ?
    OR p.modelo LIKE ?
    OR TRIM(CONCAT_WS(' ', p.marca, p.modelo)) LIKE ?
    OR p.descripcion LIKE ?

  GROUP BY
    p.id,
    p.codigo,
    p.marca,
    p.modelo,
    p.descripcion,
    c.nombre

  ORDER BY
    CASE WHEN p.codigo = ? THEN 0 ELSE 1 END,
    p.marca ASC,
    p.modelo ASC

  LIMIT 15
";

$stmt = $conexion->prepare($sql);

if (!$stmt) {
  echo json_encode([
    "success" => false,
    "error" => "Error al preparar consulta: " . $conexion->error
  ]);
  exit;
}

$stmt->bind_param(
  "ssssss",
  $like,
  $like,
  $like,
  $like,
  $like,
  $termino
);

$stmt->execute();
$res = $stmt->get_result();

$sugerencias = [];

while ($row = $res->fetch_assoc()) {
  $sugerencias[] = [
    "producto_id" => (int)$row["producto_id"],
    "codigo" => $row["codigo"],
    "marca" => $row["marca"] ?? "",
    "modelo" => $row["modelo"] ?? "",
    "nombre" => $row["nombre"],
    "descripcion" => $row["descripcion"],
    "categoria" => $row["categoria"] ?: "—",

    "inventarios" => (int)$row["inventarios"],
    "stock_total" => (int)$row["stock_total"],
    "precio_min" => (float)$row["precio_min"],
    "precio_max" => (float)$row["precio_max"]
  ];
}

echo json_encode($sugerencias);