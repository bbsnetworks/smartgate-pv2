<?php
require_once 'conexion.php';

header("Content-Type: application/json");

$codigo = trim($_GET['codigo'] ?? '');
$producto_id = isset($_GET['producto_id']) ? (int)$_GET['producto_id'] : 0;

if ($codigo === '' && $producto_id <= 0) {
  echo json_encode([
    "success" => false,
    "error" => "Código o producto no proporcionado"
  ]);
  exit;
}

$params = [];
$types = "";

$whereExtra = "";

if ($producto_id > 0) {
  $whereExtra = "p.id = ?";
  $params[] = $producto_id;
  $types .= "i";
} else {
  $like = "%{$codigo}%";
  $whereExtra = "(
  p.codigo = ?
  OR p.codigo LIKE ?
  OR p.marca LIKE ?
  OR p.modelo LIKE ?
  OR p.descripcion LIKE ?
  OR TRIM(CONCAT_WS(' ', p.marca, p.modelo)) LIKE ?
)";

$params[] = $codigo;
$params[] = $like;
$params[] = $like;
$params[] = $like;
$params[] = $like;
$params[] = $like;
$types .= "ssssss";
}

$sql = "
  SELECT
    iu.id AS id,
    iu.id AS inventario_usuario_id,
    iu.producto_id,
    iu.usuario_id AS usuario_propietario_id,

    p.codigo,
    p.marca,
    p.modelo,
    TRIM(CONCAT_WS(' ', p.marca, p.modelo)) AS nombre,
    p.descripcion,

    iu.precio_venta AS precio,
    iu.precio_proveedor,
    iu.stock,

    u.nombre AS propietario,
    c.nombre AS categoria,
    pr.nombre AS proveedor_nombre

  FROM inventario_usuarios iu

  INNER JOIN productos p
    ON p.id = iu.producto_id

  INNER JOIN usuarios u
    ON u.id = iu.usuario_id

  LEFT JOIN categorias c
    ON c.id = p.categoria_id

  LEFT JOIN proveedores pr
    ON pr.id = iu.proveedor_id

  WHERE iu.activo = 1
    AND iu.stock > 0
    AND $whereExtra

  ORDER BY
    u.nombre ASC,
    iu.precio_venta ASC
";

$stmt = $conexion->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();

$res = $stmt->get_result();

$productos = [];

while ($row = $res->fetch_assoc()) {
  $productos[] = [
    "id" => (int)$row["inventario_usuario_id"],
    "inventario_usuario_id" => (int)$row["inventario_usuario_id"],
    "producto_id" => (int)$row["producto_id"],
    "usuario_propietario_id" => (int)$row["usuario_propietario_id"],

    "codigo" => $row["codigo"],
    "marca" => $row["marca"],
    "modelo" => $row["modelo"],
    "nombre" => $row["nombre"],
    "descripcion" => $row["descripcion"],

    "precio" => (float)$row["precio"],
    "precio_proveedor" => (float)$row["precio_proveedor"],
    "stock" => (int)$row["stock"],

    "propietario" => $row["propietario"] ?: "—",
    "categoria" => $row["categoria"] ?: "—",
    "proveedor_nombre" => $row["proveedor_nombre"] ?: "—"
  ];
}

echo json_encode([
  "success" => true,
  "productos" => $productos
]);