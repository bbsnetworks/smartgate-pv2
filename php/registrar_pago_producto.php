<?php
require_once 'conexion.php';

date_default_timezone_set('America/Mexico_City');
session_start();

header("Content-Type: application/json");

// Validar sesión
if (!isset($_SESSION['usuario']['id'])) {
  echo json_encode([
    "success" => false,
    "error" => "Sesión no válida."
  ]);
  exit;
}

$usuario_id = intval($_SESSION['usuario']['id']);
$nombre_usuario = $_SESSION['usuario']['nombre'] ?? 'Usuario';
$fecha_pago = date("Y-m-d H:i:s");
$venta_id = uniqid("v_");

$data = json_decode(file_get_contents("php://input"), true);

$productos = $data['productos'] ?? [];
$metodo_pago = $data['metodo_pago'] ?? 'Efectivo';

if (!$productos || !is_array($productos)) {
  echo json_encode([
    "success" => false,
    "error" => "Datos de productos inválidos."
  ]);
  exit;
}

if (!in_array($metodo_pago, ['Efectivo', 'Tarjeta', 'Transferencia'])) {
  echo json_encode([
    "success" => false,
    "error" => "Método de pago no válido."
  ]);
  exit;
}

$conexion->begin_transaction();

try {
  $total_venta = 0;
  $productos_ticket = [];

  foreach ($productos as $producto) {
    /*
      En tu JS puedes mandar:
      - inventario_usuario_id
      o
      - id

      En ambos casos, ahora ese ID representa inventario_usuarios.id.
    */
    $inventario_usuario_id = intval(
      $producto['inventario_usuario_id'] ?? $producto['id'] ?? 0
    );

    $cantidad = intval($producto['cantidad'] ?? 0);

    if ($inventario_usuario_id <= 0 || $cantidad <= 0) {
      throw new Exception("Datos incompletos para uno de los productos.");
    }

    /*
      Bloqueamos el inventario exacto para evitar vender de más
      si dos usuarios venden al mismo tiempo.
    */
    $sql = "SELECT
              iu.id AS inventario_usuario_id,
              iu.producto_id,
              iu.usuario_id AS usuario_propietario_id,
              iu.stock,
              iu.precio_venta,
              iu.precio_proveedor,
              p.codigo,
              p.nombre,
              p.descripcion,
              propietario.nombre AS propietario
            FROM inventario_usuarios iu
            INNER JOIN productos p 
              ON p.id = iu.producto_id
            INNER JOIN usuarios propietario
              ON propietario.id = iu.usuario_id
            WHERE iu.id = ?
              AND iu.activo = 1
            FOR UPDATE";

    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("i", $inventario_usuario_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $inv = $res->fetch_assoc();
    $stmt->close();

    if (!$inv) {
      throw new Exception("Inventario no encontrado o inactivo.");
    }

    $producto_id = intval($inv['producto_id']);
    $usuario_propietario_id = intval($inv['usuario_propietario_id']);
    $stock_actual = intval($inv['stock']);
    $precio_unitario = floatval($inv['precio_venta']);
    $costo_unitario = floatval($inv['precio_proveedor']);

    $codigo_producto = $inv['codigo'];
    $nombre_producto = $inv['nombre'];
    $propietario = $inv['propietario'] ?: '—';

    if ($stock_actual < $cantidad) {
      throw new Exception(
        "Stock insuficiente para '{$nombre_producto}' de {$propietario}. Disponible: {$stock_actual}, solicitado: {$cantidad}."
      );
    }

    $total = $precio_unitario * $cantidad;
    $utilidad_total = ($precio_unitario - $costo_unitario) * $cantidad;
    $nuevo_stock = $stock_actual - $cantidad;

    /*
      Insertar venta.
      Requiere que tu tabla pagos_productos tenga estas columnas:
      inventario_usuario_id, usuario_propietario_id,
      precio_unitario, costo_unitario, utilidad_total.
    */
    $stmtVenta = $conexion->prepare("
      INSERT INTO pagos_productos (
        venta_id,
        producto_id,
        inventario_usuario_id,
        cantidad,
        precio_unitario,
        costo_unitario,
        total,
        utilidad_total,
        metodo_pago,
        fecha_pago,
        usuario_id,
        usuario_propietario_id,
        observaciones
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
    ");

    $stmtVenta->bind_param(
      "siiiddddssii",
      $venta_id,
      $producto_id,
      $inventario_usuario_id,
      $cantidad,
      $precio_unitario,
      $costo_unitario,
      $total,
      $utilidad_total,
      $metodo_pago,
      $fecha_pago,
      $usuario_id,
      $usuario_propietario_id
    );

    if (!$stmtVenta->execute()) {
      throw new Exception("Error al guardar '{$nombre_producto}' en la venta: " . $stmtVenta->error);
    }

    $pago_producto_id = $conexion->insert_id;
    $stmtVenta->close();

    /*
      Descontar stock del inventario exacto.
    */
    $stmtStock = $conexion->prepare("
      UPDATE inventario_usuarios
      SET stock = stock - ?
      WHERE id = ?
        AND stock >= ?
    ");

    $stmtStock->bind_param(
      "iii",
      $cantidad,
      $inventario_usuario_id,
      $cantidad
    );

    if (!$stmtStock->execute()) {
      throw new Exception("Error al actualizar stock de '{$nombre_producto}'.");
    }

    if ($stmtStock->affected_rows <= 0) {
      throw new Exception("Stock insuficiente para '{$nombre_producto}' al actualizar.");
    }

    $stmtStock->close();

   $stmtMov = $conexion->prepare("
  INSERT INTO inventario_movimientos (
    producto_id,
    inventario_usuario_id,
    producto_codigo,
    producto_nombre,
    tipo,
    cantidad,
    costo_unitario,
    stock_despues,
    ref_tabla,
    ref_id,
    usuario_id,
    usuario_propietario_id,
    almacen_id,
    nota,
    creado_en
  ) VALUES (?, ?, ?, ?, 'venta', ?, ?, ?, 'pagos_productos', ?, ?, ?, NULL, ?, ?)
");

$nota = "Venta POS {$venta_id}";

$stmtMov->bind_param(
  "iissdddiiiss",
  $producto_id,
  $inventario_usuario_id,
  $codigo_producto,
  $nombre_producto,
  $cantidad,
  $costo_unitario,
  $nuevo_stock,
  $pago_producto_id,
  $usuario_id,
  $usuario_propietario_id,
  $nota,
  $fecha_pago
);

if (!$stmtMov->execute()) {
  throw new Exception("Error al registrar movimiento de '{$nombre_producto}': " . $stmtMov->error);
}

$stmtMov->close();

    /*
      Sincronizar productos.stock como suma de todos los inventarios activos.
      Esto es temporal para que no se rompan otras partes que aún lean productos.stock.
    */
    $stmtSync = $conexion->prepare("
      UPDATE productos p
      SET p.stock = (
        SELECT COALESCE(SUM(iu.stock), 0)
        FROM inventario_usuarios iu
        WHERE iu.producto_id = p.id
          AND iu.activo = 1
      )
      WHERE p.id = ?
    ");

    $stmtSync->bind_param("i", $producto_id);

    if (!$stmtSync->execute()) {
      throw new Exception("Error al sincronizar stock general de '{$nombre_producto}'.");
    }

    $stmtSync->close();

    $total_venta += $total;

    $productos_ticket[] = [
      "producto_id" => $producto_id,
      "inventario_usuario_id" => $inventario_usuario_id,
      "usuario_propietario_id" => $usuario_propietario_id,
      "codigo" => $codigo_producto,
      "nombre" => $nombre_producto,
      "propietario" => $propietario,
      "cantidad" => $cantidad,
      "precio_unitario" => $precio_unitario,
      "costo_unitario" => $costo_unitario,
      "total" => $total,
      "utilidad_total" => $utilidad_total,
      "stock_despues" => $nuevo_stock
    ];
  }

  $conexion->commit();

  echo json_encode([
    "success" => true,
    "venta_id" => $venta_id,
    "usuario" => $nombre_usuario,
    "fecha_pago" => $fecha_pago,
    "metodo_pago" => $metodo_pago,
    "total_venta" => $total_venta,
    "productos" => $productos_ticket
  ]);

} catch (Exception $e) {
  $conexion->rollback();

  echo json_encode([
    "success" => false,
    "error" => $e->getMessage()
  ]);
}