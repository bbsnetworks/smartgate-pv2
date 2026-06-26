<?php
require_once '../php/conexion.php';
require_once '../php/verificar_sesion.php'; // para tomar usuario_id si está
header("Content-Type: application/json");

// Utilidad: id de usuario
function current_user_id()
{
  return isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : null;
}

// === Utilidad: aplicar movimiento atómico (MySQLi) ===
function aplicarMovimientoInventario($conexion, $inventario_usuario_id, $tipo, $cantidad, $nota = '', $usuario_id = null)
{
  $signo = in_array($tipo, ['ingreso', 'ajuste+', 'devolucion+']) ? +1 : -1;

  $rol = $_SESSION['usuario']['rol'] ?? '';

  $conexion->begin_transaction();

  try {
  
    $sql = "SELECT
              iu.id AS inventario_usuario_id,
              iu.producto_id,
              iu.usuario_id AS usuario_propietario_id,
              iu.stock,
              iu.precio_proveedor,
              p.codigo,
              p.marca,
              p.modelo,
              TRIM(CONCAT_WS(' ', p.marca, p.modelo)) AS nombre
            FROM inventario_usuarios iu
            INNER JOIN productos p ON p.id = iu.producto_id
            WHERE iu.id = ?
              AND iu.activo = 1";

    /*
      Si es worker, solo puede mover su propio inventario.
      Admin/root pueden mover todos.
    */
    if ($rol === 'worker') {
      $sql .= " AND iu.usuario_id = ?";
    }

    $sql .= " FOR UPDATE";

    $stmt = $conexion->prepare($sql);

    if ($rol === 'worker') {
      $stmt->bind_param("ii", $inventario_usuario_id, $usuario_id);
    } else {
      $stmt->bind_param("i", $inventario_usuario_id);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $p = $res->fetch_assoc();
    $stmt->close();

    if (!$p) {
      throw new Exception("Inventario no encontrado o sin permisos");
    }

    $producto_id = (int)$p['producto_id'];
    $usuario_propietario_id = (int)$p['usuario_propietario_id'];
    $codigo = $p['codigo'];
    $nombre = $p['nombre'];
    $stockActual = (float)$p['stock'];
    $costo_unitario = (float)$p['precio_proveedor'];

    $nuevoStock = $stockActual + ($signo * (float)$cantidad);

    if ($nuevoStock < 0) {
      throw new Exception("Stock insuficiente");
    }

    /*
      Registrar movimiento con inventario_usuario_id.
      Esto permite reportar por dueño real del producto.
    */
    $sqlMov = "INSERT INTO inventario_movimientos
      (
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
        nota
      )
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'admin_productos', NULL, ?, ?, NULL, ?)";

    $stmtI = $conexion->prepare($sqlMov);
    $stmtI->bind_param(
      "iisssdddiis",
      $producto_id,
      $inventario_usuario_id,
      $codigo,
      $nombre,
      $tipo,
      $cantidad,
      $costo_unitario,
      $nuevoStock,
      $usuario_id,
      $usuario_propietario_id,
      $nota
    );

    if (!$stmtI->execute()) {
      throw new Exception("No se pudo registrar el movimiento: " . $stmtI->error);
    }

    $stmtI->close();

    /*
      Actualizar stock del inventario exacto.
    */
    $stmtU = $conexion->prepare("
      UPDATE inventario_usuarios
      SET stock = ?
      WHERE id = ?
    ");
    $stmtU->bind_param("di", $nuevoStock, $inventario_usuario_id);

    if (!$stmtU->execute()) {
      throw new Exception("No se pudo actualizar el stock del inventario: " . $stmtU->error);
    }

    $stmtU->close();

    /*
      Mantener productos.stock sincronizado temporalmente.
      Esto evita romper partes viejas del sistema.
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
      throw new Exception("No se pudo sincronizar el stock general: " . $stmtSync->error);
    }

    $stmtSync->close();

    $conexion->commit();

    return [
      'ok' => true,
      'stock_despues' => $nuevoStock
    ];

  } catch (Exception $e) {
    $conexion->rollback();

    return [
      'ok' => false,
      'error' => $e->getMessage()
    ];
  }
}

// ==== Router ====
$method = $_SERVER['REQUEST_METHOD'];
$accion = $_GET['accion'] ?? '';

switch ($method) {
  case 'GET':
    if (isset($_GET['accion']) && $_GET['accion'] === 'validar_codigo') {
      include 'validar_codigo_admin.php';
      exit;
    }
    if (isset($_GET['accion']) && $_GET['accion'] === 'reporte_movimientos') {
      reporteMovimientos($conexion);
      exit;
    }
    obtenerProductos($conexion);
    break;

  case 'POST':
    if ($accion === 'ajustar_stock') {
      ajustarStock($conexion);
    } else {
      agregarProducto($conexion);
    }
    break;

  case 'PUT':
    parse_str(file_get_contents("php://input"), $_PUT);
    $_POST = $_PUT;
    editarProducto($conexion);
    break;

  case 'DELETE':
    parse_str(file_get_contents("php://input"), $_DELETE);
    $_POST = $_DELETE;
    eliminarProducto($conexion);
    break;

  default:
    echo json_encode(["success" => false, "error" => "Método no soportado"]);
}


// === EXISTENTES (ajustados donde corresponde) ===

function obtenerProductos($conexion)
{
  $usuario_id = current_user_id();
  $rol = $_SESSION['usuario']['rol'] ?? '';

  if (!$usuario_id) {
    echo json_encode([
      "success" => false,
      "error" => "No se pudo obtener el usuario de la sesión"
    ]);
    return;
  }


  if (isset($_GET['id'])) {
    $id = intval($_GET['id']);

    $sql = "SELECT
              iu.id AS id,
              iu.id AS inventario_usuario_id,
              iu.producto_id,
              iu.usuario_id AS usuario_propietario_id,
              u.nombre AS propietario,

              p.codigo,
              p.marca,
              p.modelo,
              TRIM(CONCAT_WS(' ', p.marca, p.modelo)) AS nombre,
              p.descripcion,
              p.categoria_id,

              iu.precio_venta AS precio,
              iu.precio_proveedor,
              iu.stock,
              iu.proveedor_id,

              c.nombre AS categoria,
              pr.nombre AS proveedor_nombre,
              (iu.precio_venta - iu.precio_proveedor) AS ganancia_unitaria
            FROM inventario_usuarios iu
            INNER JOIN productos p ON p.id = iu.producto_id
            INNER JOIN usuarios u ON u.id = iu.usuario_id
            LEFT JOIN categorias c ON c.id = p.categoria_id
            LEFT JOIN proveedores pr ON pr.id = iu.proveedor_id
            WHERE iu.id = ?";


    if ($rol === 'worker') {
      $sql .= " AND iu.usuario_id = ?";
    }

    $sql .= " LIMIT 1";

    $stmt = $conexion->prepare($sql);

    if ($rol === 'worker') {
      $stmt->bind_param("ii", $id, $usuario_id);
    } else {
      $stmt->bind_param("i", $id);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();

    echo json_encode($row ?: ["error" => "Producto no encontrado"]);
    return;
  }

  $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
  $offset = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;
  $busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';

  $productos = [];

  $base = "FROM inventario_usuarios iu
           INNER JOIN productos p ON p.id = iu.producto_id
           INNER JOIN usuarios u ON u.id = iu.usuario_id
           LEFT JOIN categorias c ON c.id = p.categoria_id
           LEFT JOIN proveedores pr ON pr.id = iu.proveedor_id";

  $whereParts = ["iu.activo = 1"];
  $params = [];
  $types = "";

  /*
    Regla recomendada:
    - admin/root ven inventario de todos.
    - worker solo ve su propio inventario.
  */
  if ($rol === 'worker') {
    $whereParts[] = "iu.usuario_id = ?";
    $params[] = $usuario_id;
    $types .= "i";
  }

  if ($busqueda !== '') {
  $whereParts[] = "(
    p.marca LIKE ?
    OR p.modelo LIKE ?
    OR p.descripcion LIKE ?
    OR p.codigo LIKE ?
    OR c.nombre LIKE ?
    OR pr.nombre LIKE ?
    OR u.nombre LIKE ?
  )";

  $like = "%$busqueda%";

  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;

  $types .= "sssssss";
}

  $where = "WHERE " . implode(" AND ", $whereParts);

  $sql = "SELECT
            iu.id AS id,
            iu.id AS inventario_usuario_id,
            iu.producto_id,
            iu.usuario_id AS usuario_propietario_id,
            u.nombre AS propietario,

            p.codigo,
            p.marca,
            p.modelo,
            TRIM(CONCAT_WS(' ', p.marca, p.modelo)) AS nombre,
            p.descripcion,
            p.categoria_id,

            iu.precio_venta AS precio,
            iu.precio_proveedor,
            iu.stock,
            iu.proveedor_id,

            c.nombre AS categoria,
            pr.nombre AS proveedor_nombre,
            (iu.precio_venta - iu.precio_proveedor) AS ganancia_unitaria
          $base
          $where
          ORDER BY p.marca ASC, p.modelo ASC, u.nombre ASC
          LIMIT ? OFFSET ?";

  $paramsDatos = $params;
  $typesDatos = $types . "ii";
  $paramsDatos[] = $limit;
  $paramsDatos[] = $offset;

  $stmt = $conexion->prepare($sql);

  if (!empty($paramsDatos)) {
    $stmt->bind_param($typesDatos, ...$paramsDatos);
  }

  $stmt->execute();
  $res = $stmt->get_result();

  while ($row = $res->fetch_assoc()) {
    $productos[] = $row;
  }

  $sqlTot = "SELECT COUNT(*) AS total $base $where";
  $stmtT = $conexion->prepare($sqlTot);

  if (!empty($params)) {
    $stmtT->bind_param($types, ...$params);
  }

  $stmtT->execute();
  $total = (int) $stmtT->get_result()->fetch_assoc()['total'];

  echo json_encode([
    "success" => true,
    "productos" => $productos,
    "total" => $total
  ]);
}

function agregarProducto($conexion) {
  $data = json_decode(file_get_contents("php://input"), true);

  $usuario_id = current_user_id();

  if (!$usuario_id) {
    echo json_encode([
      "success" => false,
      "error" => "No se pudo obtener el usuario de la sesión"
    ]);
    return;
  }

  $codigo            = trim($data['codigo'] ?? '');
  $marca             = trim($data['marca'] ?? '');
  $modelo            = trim($data['modelo'] ?? '');
  $nombre            = trim($marca . ' ' . $modelo);
  $descripcion       = trim($data['descripcion'] ?? '');
  $precio            = (float)($data['precio'] ?? -1); 
  $precio_proveedor  = (float)($data['precio_proveedor'] ?? -1); 
  $stock_inicial     = (int)($data['stock'] ?? -1);
  $categoria_id      = (int)($data['categoria_id'] ?? 0);

  $proveedor_id = (
    isset($data['proveedor_id']) &&
    $data['proveedor_id'] !== '' &&
    $data['proveedor_id'] !== null
  ) ? (int)$data['proveedor_id'] : null;

  if (
  !$codigo ||
  !$marca ||
  !$modelo ||
  !$descripcion ||
  $precio < 0 ||
  $precio_proveedor < 0 ||
  $stock_inicial < 0 ||
  $categoria_id <= 0
) {
    echo json_encode([
      "success" => false,
      "error" => "Todos los campos son obligatorios y deben ser válidos"
    ]);
    return;
  }

  // Validar categoría
  $stmt = $conexion->prepare("SELECT id FROM categorias WHERE id = ?");
  $stmt->bind_param("i", $categoria_id);
  $stmt->execute();

  if ($stmt->get_result()->num_rows === 0) {
    echo json_encode([
      "success" => false,
      "error" => "Categoría inválida"
    ]);
    return;
  }

  $stmt->close();

  // Validar proveedor si viene seleccionado
  if (!is_null($proveedor_id)) {
    $stmt = $conexion->prepare("SELECT id FROM proveedores WHERE id = ?");
    $stmt->bind_param("i", $proveedor_id);
    $stmt->execute();

    if ($stmt->get_result()->num_rows === 0) {
      echo json_encode([
        "success" => false,
        "error" => "Proveedor no válido"
      ]);
      return;
    }

    $stmt->close();
  }

  $conexion->begin_transaction();

  try {
    $producto_id = 0;
    $producto_existente = false;

    $stmt = $conexion->prepare("
  SELECT id, marca, modelo
  FROM productos
  WHERE codigo = ?
  LIMIT 1
  FOR UPDATE
");
    $stmt->bind_param("s", $codigo);
    $stmt->execute();
    $resProducto = $stmt->get_result();
    $producto = $resProducto->fetch_assoc();
    $stmt->close();

    if ($producto) {
      // Si ya existe, reutilizamos el producto
      $producto_id = (int)$producto['id'];
      $producto_existente = true;
    } else {
      // Si no existe, lo creamos en productos
      if (is_null($proveedor_id)) {
        $sql = "INSERT INTO productos
  (marca, modelo, codigo, descripcion, precio, precio_proveedor, proveedor_id, stock, categoria_id)
  VALUES (?, ?, ?, ?, ?, ?, NULL, 0, ?)";

$stmt = $conexion->prepare($sql);
$stmt->bind_param(
  "ssssddi",
  $marca,
  $modelo,
  $codigo,
  $descripcion,
  $precio,
  $precio_proveedor,
  $categoria_id
);
      } else {
  $sql = "INSERT INTO productos
    (marca, modelo, codigo, descripcion, precio, precio_proveedor, proveedor_id, stock, categoria_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)";

  $stmt = $conexion->prepare($sql);
  $stmt->bind_param(
    "ssssddii",
    $marca,
    $modelo,
    $codigo,
    $descripcion,
    $precio,
    $precio_proveedor,
    $proveedor_id,
    $categoria_id
  );
}

      if (!$stmt->execute()) {
        throw new Exception("No se pudo crear el producto: " . $stmt->error);
      }

      $producto_id = $conexion->insert_id;
      $stmt->close();
    }

    // 2. Revisar si este usuario ya tiene inventario de ese producto
    $stmt = $conexion->prepare("
      SELECT id, stock
      FROM inventario_usuarios
      WHERE producto_id = ?
        AND usuario_id = ?
      LIMIT 1
      FOR UPDATE
    ");
    $stmt->bind_param("ii", $producto_id, $usuario_id);
    $stmt->execute();
    $resInv = $stmt->get_result();
    $inventario = $resInv->fetch_assoc();
    $stmt->close();

    if ($inventario) {
      // Si ya tiene inventario, sumamos stock
      $inventario_usuario_id = (int)$inventario['id'];
      $stock_anterior = (int)$inventario['stock'];
      $nuevo_stock_usuario = $stock_anterior + $stock_inicial;

      if (is_null($proveedor_id)) {
        $sql = "UPDATE inventario_usuarios
                SET precio_venta = ?,
                    precio_proveedor = ?,
                    proveedor_id = NULL,
                    stock = ?,
                    activo = 1
                WHERE id = ?";

        $stmt = $conexion->prepare($sql);
        $stmt->bind_param(
          "ddii",
          $precio,
          $precio_proveedor,
          $nuevo_stock_usuario,
          $inventario_usuario_id
        );
      } else {
        $sql = "UPDATE inventario_usuarios
                SET precio_venta = ?,
                    precio_proveedor = ?,
                    proveedor_id = ?,
                    stock = ?,
                    activo = 1
                WHERE id = ?";

        $stmt = $conexion->prepare($sql);
        $stmt->bind_param(
          "ddiii",
          $precio,
          $precio_proveedor,
          $proveedor_id,
          $nuevo_stock_usuario,
          $inventario_usuario_id
        );
      }

      if (!$stmt->execute()) {
        throw new Exception("No se pudo actualizar el inventario del usuario: " . $stmt->error);
      }

      $stmt->close();

    } else {
      // Si no tiene inventario, creamos el inventario para este usuario
      if (is_null($proveedor_id)) {
        $sql = "INSERT INTO inventario_usuarios
          (producto_id, usuario_id, proveedor_id, precio_proveedor, precio_venta, stock, activo)
          VALUES (?, ?, NULL, ?, ?, ?, 1)";

        $stmt = $conexion->prepare($sql);
        $stmt->bind_param(
          "iiddi",
          $producto_id,
          $usuario_id,
          $precio_proveedor,
          $precio,
          $stock_inicial
        );
      } else {
        $sql = "INSERT INTO inventario_usuarios
          (producto_id, usuario_id, proveedor_id, precio_proveedor, precio_venta, stock, activo)
          VALUES (?, ?, ?, ?, ?, ?, 1)";

        $stmt = $conexion->prepare($sql);
        $stmt->bind_param(
          "iiiddi",
          $producto_id,
          $usuario_id,
          $proveedor_id,
          $precio_proveedor,
          $precio,
          $stock_inicial
        );
      }

      if (!$stmt->execute()) {
        throw new Exception("No se pudo crear el inventario del usuario: " . $stmt->error);
      }

      $inventario_usuario_id = $conexion->insert_id;
      $stmt->close();
    }

    // 3. Sincronizar productos.stock como suma general temporal
    $stmt = $conexion->prepare("
      UPDATE productos p
      SET p.stock = (
        SELECT COALESCE(SUM(iu.stock), 0)
        FROM inventario_usuarios iu
        WHERE iu.producto_id = p.id
          AND iu.activo = 1
      )
      WHERE p.id = ?
    ");
    $stmt->bind_param("i", $producto_id);

    if (!$stmt->execute()) {
      throw new Exception("No se pudo sincronizar el stock general: " . $stmt->error);
    }

    $stmt->close();

    // 4. Registrar movimiento inicial si hubo stock
    if ($stock_inicial > 0) {
      $stmt = $conexion->prepare("
        SELECT stock
        FROM inventario_usuarios
        WHERE id = ?
        LIMIT 1
      ");
      $stmt->bind_param("i", $inventario_usuario_id);
      $stmt->execute();
      $stockDespuesUsuario = (float)$stmt->get_result()->fetch_assoc()['stock'];
      $stmt->close();

      $sqlMov = "INSERT INTO inventario_movimientos
        (producto_id, producto_codigo, producto_nombre, tipo, cantidad, costo_unitario, stock_despues, ref_tabla, ref_id, usuario_id, almacen_id, nota)
        VALUES (?, ?, ?, 'ingreso', ?, ?, ?, 'admin_productos', NULL, ?, NULL, ?)";

      $nota = $producto_existente
        ? "Ingreso de inventario a producto existente"
        : "Alta de producto";

      $stmtI = $conexion->prepare($sqlMov);
      $stmtI->bind_param(
        "issdddis",
        $producto_id,
        $codigo,
        $nombre,
        $stock_inicial,
        $precio_proveedor,
        $stockDespuesUsuario,
        $usuario_id,
        $nota
      );

      if (!$stmtI->execute()) {
        throw new Exception("No se pudo registrar el movimiento: " . $stmtI->error);
      }

      $stmtI->close();
    }

    $conexion->commit();

    echo json_encode([
      "success" => true,
      "msg" => $producto_existente
        ? "El producto ya existía. Se agregó inventario para tu usuario correctamente."
        : "Producto agregado correctamente con inventario de usuario.",
      "producto_id" => $producto_id,
      "inventario_usuario_id" => $inventario_usuario_id
    ]);

  } catch (Exception $e) {
    $conexion->rollback();

    echo json_encode([
      "success" => false,
      "error" => "Error al guardar el producto: " . $e->getMessage()
    ]);
  }
}

function ajustarStock($conexion)
{
  $in = json_decode(file_get_contents("php://input"), true);

  /*
    Por compatibilidad con tu JS actual, recibimos producto_id,
    pero realmente ahora representa inventario_usuarios.id.
  */
  $inventario_usuario_id = (int)($in['producto_id'] ?? 0);
  $tipo = $in['tipo'] ?? '';
  $cantidad = (float)($in['cantidad'] ?? 0);
  $nota = trim($in['nota'] ?? '');

  if (
    $inventario_usuario_id <= 0 ||
    $cantidad <= 0 ||
    !in_array($tipo, ['ingreso', 'ajuste-'])
  ) {
    echo json_encode([
      'ok' => false,
      'error' => 'Parámetros inválidos'
    ]);
    return;
  }

  $res = aplicarMovimientoInventario(
    $conexion,
    $inventario_usuario_id,
    $tipo,
    $cantidad,
    $nota,
    current_user_id()
  );

  echo json_encode($res);
}
function editarProducto($conexion)
{
  $data = json_decode(file_get_contents("php://input"), true);

  $inventario_usuario_id = intval($data['inventario_usuario_id'] ?? $data['id'] ?? 0);
  $producto_id = intval($data['producto_id'] ?? 0);

  $codigo = trim($data['codigo'] ?? '');
  $marca = trim($data['marca'] ?? '');
  $modelo = trim($data['modelo'] ?? '');
  $nombre = trim($marca . ' ' . $modelo);
  $descripcion = trim($data['descripcion'] ?? '');
  $precio = (float)($data['precio'] ?? -1);
  $precio_proveedor = (float)($data['precio_proveedor'] ?? 0);
  $categoria_id = intval($data['categoria_id'] ?? 0);

  $usuario_id = current_user_id();
  $rol = $_SESSION['usuario']['rol'] ?? '';

  $proveedor_id = array_key_exists('proveedor_id', $data) && $data['proveedor_id'] !== '' && $data['proveedor_id'] !== null
    ? (int)$data['proveedor_id']
    : null;

  if ($inventario_usuario_id <= 0) {
    echo json_encode(["success" => false, "error" => "ID de inventario inválido"]);
    return;
  }

  if ($producto_id <= 0) {
    echo json_encode(["success" => false, "error" => "ID de producto inválido"]);
    return;
  }

  if ($codigo === '' || !preg_match('/^\d+$/', $codigo)) {
    echo json_encode(["success" => false, "error" => "Código inválido"]);
    return;
  }

  if ($marca === '' || strlen($marca) > 100) {
  echo json_encode(["success" => false, "error" => "Marca inválida"]);
  return;
}

if ($modelo === '' || strlen($modelo) > 100) {
  echo json_encode(["success" => false, "error" => "Modelo inválido"]);
  return;
}

  if ($descripcion === '' || strlen($descripcion) > 1000) {
    echo json_encode(["success" => false, "error" => "Descripción inválida"]);
    return;
  }

  if ($precio < 0) {
    echo json_encode(["success" => false, "error" => "Precio inválido"]);
    return;
  }

  if ($precio_proveedor < 0) {
    echo json_encode(["success" => false, "error" => "Costo proveedor inválido"]);
    return;
  }

  if ($categoria_id <= 0) {
    echo json_encode(["success" => false, "error" => "Categoría no válida"]);
    return;
  }

  // Validar categoría
  $stmtCheck = $conexion->prepare("SELECT id FROM categorias WHERE id = ?");
  $stmtCheck->bind_param("i", $categoria_id);
  $stmtCheck->execute();

  if ($stmtCheck->get_result()->num_rows === 0) {
    echo json_encode(["success" => false, "error" => "Categoría no válida"]);
    return;
  }

  $stmtCheck->close();

  // Validar proveedor si viene
  if (!is_null($proveedor_id)) {
    $stmtProv = $conexion->prepare("SELECT id FROM proveedores WHERE id = ?");
    $stmtProv->bind_param("i", $proveedor_id);
    $stmtProv->execute();

    if ($stmtProv->get_result()->num_rows === 0) {
      echo json_encode(["success" => false, "error" => "Proveedor no válido"]);
      return;
    }

    $stmtProv->close();
  }

  $conexion->begin_transaction();

  try {
    /*
      Bloqueamos el inventario para confirmar que existe
      y que el usuario tiene permiso.
    */
    $sqlInv = "SELECT
                 iu.id,
                 iu.producto_id,
                 iu.usuario_id
               FROM inventario_usuarios iu
               WHERE iu.id = ?
                 AND iu.producto_id = ?
                 AND iu.activo = 1";

    if ($rol === 'worker') {
      $sqlInv .= " AND iu.usuario_id = ?";
    }

    $sqlInv .= " FOR UPDATE";

    $stmtInv = $conexion->prepare($sqlInv);

    if ($rol === 'worker') {
      $stmtInv->bind_param("iii", $inventario_usuario_id, $producto_id, $usuario_id);
    } else {
      $stmtInv->bind_param("ii", $inventario_usuario_id, $producto_id);
    }

    $stmtInv->execute();
    $inv = $stmtInv->get_result()->fetch_assoc();
    $stmtInv->close();

    if (!$inv) {
      throw new Exception("Inventario no encontrado o sin permisos");
    }

    /*
      Validar código único contra otro producto.
      Sí puede ser el mismo código si es el mismo producto_id.
    */
    $stmtCodigo = $conexion->prepare("
      SELECT id
      FROM productos
      WHERE codigo = ?
        AND id != ?
      LIMIT 1
    ");
    $stmtCodigo->bind_param("si", $codigo, $producto_id);
    $stmtCodigo->execute();

    if ($stmtCodigo->get_result()->num_rows > 0) {
      throw new Exception("Este código ya está en uso por otro producto");
    }

    $stmtCodigo->close();

    $stmtP = $conexion->prepare("
  UPDATE productos
  SET codigo = ?,
      marca = ?,
      modelo = ?,
      descripcion = ?,
      categoria_id = ?
  WHERE id = ?
");
$stmtP->bind_param(
  "ssssii",
  $codigo,
  $marca,
  $modelo,
  $descripcion,
  $categoria_id,
  $producto_id
);

    if (!$stmtP->execute()) {
      throw new Exception("No se pudo actualizar el producto: " . $stmtP->error);
    }

    $stmtP->close();

    /*
      Actualizar datos del inventario del usuario.
      No actualizamos stock aquí.
    */
    if (is_null($proveedor_id)) {
      $stmtIU = $conexion->prepare("
        UPDATE inventario_usuarios
        SET precio_venta = ?,
            precio_proveedor = ?,
            proveedor_id = NULL
        WHERE id = ?
      ");

      $stmtIU->bind_param(
        "ddi",
        $precio,
        $precio_proveedor,
        $inventario_usuario_id
      );
    } else {
      $stmtIU = $conexion->prepare("
        UPDATE inventario_usuarios
        SET precio_venta = ?,
            precio_proveedor = ?,
            proveedor_id = ?
        WHERE id = ?
      ");

      $stmtIU->bind_param(
        "ddii",
        $precio,
        $precio_proveedor,
        $proveedor_id,
        $inventario_usuario_id
      );
    }

    if (!$stmtIU->execute()) {
      throw new Exception("No se pudo actualizar el inventario: " . $stmtIU->error);
    }

    $stmtIU->close();

    /*
      Mantener productos.precio y productos.precio_proveedor sincronizados
      como referencia temporal.
    */
    if (is_null($proveedor_id)) {
      $stmtSync = $conexion->prepare("
        UPDATE productos
        SET precio = ?,
            precio_proveedor = ?,
            proveedor_id = NULL
        WHERE id = ?
      ");
      $stmtSync->bind_param("ddi", $precio, $precio_proveedor, $producto_id);
    } else {
      $stmtSync = $conexion->prepare("
        UPDATE productos
        SET precio = ?,
            precio_proveedor = ?,
            proveedor_id = ?
        WHERE id = ?
      ");
      $stmtSync->bind_param("ddii", $precio, $precio_proveedor, $proveedor_id, $producto_id);
    }

    if (!$stmtSync->execute()) {
      throw new Exception("No se pudo sincronizar datos generales: " . $stmtSync->error);
    }

    $stmtSync->close();

    $conexion->commit();

    echo json_encode([
      "success" => true,
      "msg" => "Producto e inventario actualizados correctamente"
    ]);

  } catch (Exception $e) {
    $conexion->rollback();

    echo json_encode([
      "success" => false,
      "error" => "Error al actualizar: " . $e->getMessage()
    ]);
  }
}



function eliminarProducto($conexion)
{
  $inventario_usuario_id = intval($_GET['id'] ?? 0);
  $usuario_id = current_user_id();
  $rol = $_SESSION['usuario']['rol'] ?? '';

  if ($inventario_usuario_id <= 0) {
    echo json_encode(["success" => false, "error" => "ID no válido"]);
    return;
  }

  $conexion->begin_transaction();

  try {
    $sql = "SELECT
              iu.id AS inventario_usuario_id,
              iu.producto_id,
              iu.usuario_id AS usuario_propietario_id,
              iu.stock,
              iu.precio_proveedor,
              p.codigo,
              p.marca,
              p.modelo,
              TRIM(CONCAT_WS(' ', p.marca, p.modelo)) AS nombre
            FROM inventario_usuarios iu
            INNER JOIN productos p ON p.id = iu.producto_id
            WHERE iu.id = ?
              AND iu.activo = 1";

    if ($rol === 'worker') {
      $sql .= " AND iu.usuario_id = ?";
    }

    $sql .= " FOR UPDATE";

    $stmt = $conexion->prepare($sql);

    if ($rol === 'worker') {
      $stmt->bind_param("ii", $inventario_usuario_id, $usuario_id);
    } else {
      $stmt->bind_param("i", $inventario_usuario_id);
    }

    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$p) {
      throw new Exception("Inventario no encontrado o sin permisos");
    }

    $producto_id = (int)$p['producto_id'];
    $stock = (float)$p['stock'];
    $usuario_propietario_id = (int)$p['usuario_propietario_id'];
    $costo_unitario = (float)$p['precio_proveedor'];

    /*
      Si tenía stock, registramos salida total.
    */
    if ($stock > 0) {
      $nuevo = 0.0;

      $sqlMov = "INSERT INTO inventario_movimientos
        (
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
          nota
        )
        VALUES (?, ?, ?, ?, 'ajuste-', ?, ?, ?, 'admin_productos', NULL, ?, ?, NULL, 'Eliminación de inventario')";

      $stmtI = $conexion->prepare($sqlMov);
      $stmtI->bind_param(
        "iissdddii",
        $producto_id,
        $inventario_usuario_id,
        $p['codigo'],
        $p['nombre'],
        $stock,
        $costo_unitario,
        $nuevo,
        $usuario_id,
        $usuario_propietario_id
      );

      if (!$stmtI->execute()) {
        throw new Exception("No se pudo registrar el movimiento de eliminación: " . $stmtI->error);
      }

      $stmtI->close();
    }

    /*
      No borramos el producto general.
      Solo desactivamos este inventario.
    */
    $stmtD = $conexion->prepare("
      UPDATE inventario_usuarios
      SET activo = 0,
          stock = 0
      WHERE id = ?
    ");
    $stmtD->bind_param("i", $inventario_usuario_id);

    if (!$stmtD->execute()) {
      throw new Exception("No se pudo desactivar el inventario: " . $stmtD->error);
    }

    $stmtD->close();

    /*
      Sincronizar stock general del producto.
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
      throw new Exception("No se pudo sincronizar stock general: " . $stmtSync->error);
    }

    $stmtSync->close();

    $conexion->commit();

    echo json_encode([
      "success" => true,
      "message" => "Inventario eliminado correctamente"
    ]);

  } catch (Exception $e) {
    $conexion->rollback();

    echo json_encode([
      "success" => false,
      "error" => "No se pudo eliminar: " . $e->getMessage()
    ]);
  }
}
// Helper: Y-m-d[ H:i:s] -> d/m/Y
function toDMY($str)
{
  $ts = strtotime($str);
  return $ts ? date('d/m/Y', $ts) : $str;
}

function reporteMovimientos(mysqli $conexion)
{
  $tipo = $_GET['tipo'] ?? 'dia';

  try {
    if ($tipo === 'dia') {
      $f = $_GET['fecha'] ?? '';
      if (!$f) {
        throw new Exception('Fecha requerida');
      }

      $desde = $f . ' 00:00:00';
      $hasta = $f . ' 23:59:59';

    } elseif ($tipo === 'mes') {
      $m = $_GET['fecha'] ?? ''; // YYYY-MM
      if (!$m) {
        throw new Exception('Mes requerido');
      }

      $dt = DateTime::createFromFormat('Y-m-d', $m . '-01');
      if (!$dt) {
        throw new Exception('Mes inválido');
      }

      $desde = $dt->format('Y-m-01') . ' 00:00:00';
      $hasta = $dt->format('Y-m-t') . ' 23:59:59';

    } elseif ($tipo === 'anio') {
      $a = (int)($_GET['fecha'] ?? 0);

      if ($a < 2000 || $a > 2100) {
        throw new Exception('Año inválido');
      }

      $desde = $a . '-01-01 00:00:00';
      $hasta = $a . '-12-31 23:59:59';

    } else {
      $i = $_GET['inicio'] ?? '';
      $f = $_GET['fin'] ?? '';

      if (!$i || !$f) {
        throw new Exception('Rango incompleto');
      }

      $desde = $i . ' 00:00:00';
      $hasta = $f . ' 23:59:59';
    }

  } catch (Exception $e) {
    echo json_encode([
      'ok' => false,
      'error' => $e->getMessage()
    ]);
    return;
  }

  $sql = "SELECT
            im.id,
            im.producto_id,
            im.inventario_usuario_id,
            im.producto_codigo,
            COALESCE(NULLIF(im.producto_nombre, ''), TRIM(CONCAT_WS(' ', p.marca, p.modelo))) AS producto_nombre,
            p.marca,
            p.modelo,
            im.tipo,
            im.cantidad,
            im.costo_unitario,
            im.total,
            im.stock_despues,
            im.ref_tabla,
            im.ref_id,
            im.usuario_id,
            im.usuario_propietario_id,
            im.almacen_id,
            im.nota,
            im.creado_en,

            COALESCE(iu.stock, p.stock) AS stock_actual,

            usuario_movimiento.nombre AS usuario,
            propietario.nombre AS propietario

          FROM inventario_movimientos im

          LEFT JOIN productos p 
            ON p.id = im.producto_id

          LEFT JOIN inventario_usuarios iu 
            ON iu.id = im.inventario_usuario_id

          LEFT JOIN usuarios usuario_movimiento 
            ON usuario_movimiento.id = im.usuario_id

          LEFT JOIN usuarios propietario
            ON propietario.id = COALESCE(im.usuario_propietario_id, iu.usuario_id)

          WHERE im.creado_en >= ? 
            AND im.creado_en <= ?

          ORDER BY 
            im.producto_nombre ASC,
            propietario.nombre ASC,
            im.inventario_usuario_id ASC,
            im.creado_en ASC,
            im.id ASC";

  $stmt = $conexion->prepare($sql);
  $stmt->bind_param("ss", $desde, $hasta);
  $stmt->execute();
  $res = $stmt->get_result();

  $grupos = [];
  $tot_entradas = 0.0;
  $tot_salidas = 0.0;

  while ($row = $res->fetch_assoc()) {
    
    if (!empty($row['inventario_usuario_id'])) {
      $key = 'inv_' . $row['inventario_usuario_id'];
    } else {
      // Respaldo para movimientos antiguos sin inventario_usuario_id
      $key = 'prod_' . $row['producto_id'] . '_' . ($row['propietario'] ?? 'sin_propietario');
    }

    if (!isset($grupos[$key])) {
      $grupos[$key] = [
        'producto_id' => $row['producto_id'],
        'inventario_usuario_id' => $row['inventario_usuario_id'],
        'codigo' => $row['producto_codigo'],
        'nombre' => $row['producto_nombre'],
        'marca' => $row['marca'] ?? '',
        'modelo' => $row['modelo'] ?? '',
        'propietario' => $row['propietario'] ?: '—',
        'stock_actual' => isset($row['stock_actual']) ? (float)$row['stock_actual'] : null,
        'entradas' => 0.0,
        'salidas' => 0.0,
        'movimientos' => []
      ];
    }

    $tipoMov = $row['tipo'];
    $cant = (float)$row['cantidad'];

    if (in_array($tipoMov, ['ingreso', 'ajuste+', 'devolucion+'])) {
      $grupos[$key]['entradas'] += $cant;
      $tot_entradas += $cant;
    } else {
      $grupos[$key]['salidas'] += $cant;
      $tot_salidas += $cant;
    }

    $grupos[$key]['movimientos'][] = [
      'id' => $row['id'],
      'fecha' => toDMY($row['creado_en']),
      'fecha_hora' => $row['creado_en'],
      'tipo' => $tipoMov,
      'cantidad' => $cant,
      'costo_unitario' => (float)$row['costo_unitario'],
      'total' => (float)$row['total'],
      'stock_despues' => (float)$row['stock_despues'],
      'nota' => $row['nota'],
      'usuario' => $row['usuario'] ?: '—',
      'propietario' => $row['propietario'] ?: '—'
    ];
  }

  $resumen = array_values($grupos);

  usort($resumen, function ($a, $b) {
    return strcmp(
      ($a['nombre'] . $a['codigo'] . $a['propietario']),
      ($b['nombre'] . $b['codigo'] . $b['propietario'])
    );
  });

  echo json_encode([
    'ok' => true,
    'desde' => toDMY($desde),
    'hasta' => toDMY($hasta),
    'resumen' => $resumen,
    'totales' => [
      'entradas' => $tot_entradas,
      'salidas' => $tot_salidas
    ]
  ]);
}

