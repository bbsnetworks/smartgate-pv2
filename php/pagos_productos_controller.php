<?php
require_once 'conexion.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/Mexico_City');

header('Content-Type: application/json');

$accion = $_GET['accion'] ?? '';

if ($accion === 'obtener') {
    obtenerPagos($conexion);
} elseif ($accion === 'eliminar') {
    eliminarPago($conexion);
}elseif ($accion === 'validar_codigo') {
    include 'validar_codigo_admin.php';
    exit;
}else {
    echo json_encode(["success" => false, "error" => "Acción no válida"]);
}

function obtenerPagos($conexion) {
    $input = json_decode(file_get_contents("php://input"), true);

    $mes    = $input['mes'] ?? date('m');
    $year   = $input['year'] ?? date('Y');
    $search = trim($input['busqueda'] ?? '');
    $offset = intval($input['offset'] ?? 0);
    $limit  = intval($input['limit'] ?? 20);

    if ($limit <= 0) $limit = 20;
    if ($offset < 0) $offset = 0;

    $where = " WHERE MONTH(p.fecha_pago) = ? AND YEAR(p.fecha_pago) = ? ";
    $params = [$mes, $year];
    $types = "ss";

    if ($search !== '') {
        $like = "%{$search}%";

        $where .= " AND (
    p.venta_id LIKE ?
    OR IFNULL(vendedor.nombre, 'Usuario Eliminado') LIKE ?
    OR IFNULL(prod.marca, '') LIKE ?
    OR IFNULL(prod.modelo, '') LIKE ?
    OR TRIM(CONCAT_WS(' ', prod.marca, prod.modelo)) LIKE ?
    OR IFNULL(prod.codigo, '') LIKE ?
    OR IFNULL(propietario.nombre, 'Sin propietario') LIKE ?
    OR IFNULL(p.metodo_pago, '') LIKE ?
)";

$params[] = $like; // venta_id
$params[] = $like; // vendedor
$params[] = $like; // marca
$params[] = $like; // modelo
$params[] = $like; // marca + modelo
$params[] = $like; // codigo
$params[] = $like; // propietario
$params[] = $like; // metodo_pago
$types .= "ssssssss";
    }

    /*
      1. Contar ventas únicas, no productos.
    */
    $countSql = "
        SELECT COUNT(DISTINCT p.venta_id) AS total
        FROM pagos_productos p
        LEFT JOIN productos prod 
            ON p.producto_id = prod.id
        LEFT JOIN usuarios vendedor 
            ON p.usuario_id = vendedor.id
        LEFT JOIN usuarios propietario
            ON propietario.id = p.usuario_propietario_id
        $where
    ";

    $stmtCount = $conexion->prepare($countSql);
    $stmtCount->bind_param($types, ...$params);
    $stmtCount->execute();
    $countRes = $stmtCount->get_result();
    $totalRegistros = (int)($countRes->fetch_assoc()['total'] ?? 0);
    $stmtCount->close();

    /*
      2. Obtener solo los folios de venta de la página actual.
      Esto evita que una venta con muchos productos rompa la paginación.
    */
    $foliosSql = "
        SELECT 
            p.venta_id,
            MAX(p.fecha_pago) AS fecha_orden
        FROM pagos_productos p
        LEFT JOIN productos prod 
            ON p.producto_id = prod.id
        LEFT JOIN usuarios vendedor 
            ON p.usuario_id = vendedor.id
        LEFT JOIN usuarios propietario
            ON propietario.id = p.usuario_propietario_id
        $where
        GROUP BY p.venta_id
        ORDER BY fecha_orden DESC, p.venta_id DESC
        LIMIT ? OFFSET ?
    ";

    $paramsFolios = $params;
    $typesFolios = $types . "ii";
    $paramsFolios[] = $limit;
    $paramsFolios[] = $offset;

    $stmtFolios = $conexion->prepare($foliosSql);
    $stmtFolios->bind_param($typesFolios, ...$paramsFolios);
    $stmtFolios->execute();
    $resFolios = $stmtFolios->get_result();

    $folios = [];

    while ($row = $resFolios->fetch_assoc()) {
        $folios[] = $row['venta_id'];
    }

    $stmtFolios->close();

    if (empty($folios)) {
        echo json_encode([
            "success" => true,
            "pagos" => [],
            "total" => $totalRegistros
        ]);
        return;
    }

    /*
      3. Obtener el detalle de productos de esos folios.
    */
    $placeholders = implode(",", array_fill(0, count($folios), "?"));
    $typesDetalle = str_repeat("s", count($folios));

    $detalleSql = "
        SELECT
            p.id,
            p.venta_id,
            p.fecha_pago,
            p.metodo_pago,

            p.producto_id,
            p.inventario_usuario_id,
            p.usuario_propietario_id,

            p.cantidad,
            p.precio_unitario,
            p.costo_unitario,
            p.total,
            p.utilidad_total,

            IFNULL(vendedor.nombre, 'Usuario Eliminado') AS usuario,

            IFNULL(prod.codigo, '') AS producto_codigo,
            IFNULL(prod.marca, '') AS producto_marca,
            IFNULL(prod.modelo, '') AS producto_modelo,
            COALESCE(
                NULLIF(TRIM(CONCAT_WS(' ', prod.marca, prod.modelo)), ''),
                'Producto eliminado'
            ) AS producto_nombre,

            IFNULL(propietario.nombre, 'Sin propietario') AS propietario

        FROM pagos_productos p

        LEFT JOIN productos prod 
            ON p.producto_id = prod.id

        LEFT JOIN usuarios vendedor 
            ON p.usuario_id = vendedor.id

        LEFT JOIN usuarios propietario
            ON propietario.id = p.usuario_propietario_id

        WHERE p.venta_id IN ($placeholders)

        ORDER BY p.fecha_pago DESC, p.venta_id DESC, p.id ASC
    ";

    $stmtDetalle = $conexion->prepare($detalleSql);
    $stmtDetalle->bind_param($typesDetalle, ...$folios);
    $stmtDetalle->execute();
    $res = $stmtDetalle->get_result();

    $ventas = [];

    while ($row = $res->fetch_assoc()) {
        $venta_id = $row['venta_id'];

        if (!isset($ventas[$venta_id])) {
            $ventas[$venta_id] = [
                "venta_id"    => $venta_id,
                "fecha_pago"  => $row['fecha_pago'],
                "metodo_pago" => $row['metodo_pago'],
                "usuario"     => $row['usuario'],
                "total"       => 0,
                "productos"   => []
            ];
        }

        $ventas[$venta_id]['productos'][] = [
    "id" => (int)$row['id'],
    "producto_id" => (int)$row['producto_id'],
    "inventario_usuario_id" => (int)$row['inventario_usuario_id'],
    "usuario_propietario_id" => (int)$row['usuario_propietario_id'],

    "codigo" => $row['producto_codigo'],
    "marca" => $row['producto_marca'] ?? "",
    "modelo" => $row['producto_modelo'] ?? "",
    "nombre" => $row['producto_nombre'],
    "propietario" => $row['propietario'],

    "cantidad" => (int)$row['cantidad'],
    "precio_unitario" => (float)$row['precio_unitario'],
    "costo_unitario" => (float)$row['costo_unitario'],
    "total" => (float)$row['total'],
    "utilidad_total" => (float)$row['utilidad_total']
];

        $ventas[$venta_id]['total'] += (float)$row['total'];
    }

    $stmtDetalle->close();

    /*
      4. Respetar el orden original de folios.
    */
    $pagosOrdenados = [];

    foreach ($folios as $folio) {
        if (isset($ventas[$folio])) {
            $pagosOrdenados[] = $ventas[$folio];
        }
    }

    echo json_encode([
        "success" => true,
        "pagos" => $pagosOrdenados,
        "total" => $totalRegistros
    ]);
}
function eliminarPago($conexion) {
    $venta_id = $_GET['venta_id'] ?? '';

    if (!$venta_id) {
        echo json_encode(["success" => false, "error" => "Folio inválido"]);
        return;
    }

    $usuario_accion_id = $_SESSION['usuario']['id'] ?? null;

    if (!$usuario_accion_id) {
        echo json_encode(["success" => false, "error" => "Sesión no válida"]);
        return;
    }

    $conexion->begin_transaction();

    try {
        /*
          1. Recuperar todos los productos de la venta.
          Bloqueamos para evitar inconsistencias.
        */
        $stmt = $conexion->prepare("
            SELECT
                pp.id AS pago_producto_id,
                pp.venta_id,
                pp.producto_id,
                pp.inventario_usuario_id,
                pp.usuario_propietario_id,
                pp.cantidad,
                pp.costo_unitario,

                IFNULL(prod.codigo, '') AS producto_codigo,
                COALESCE(
                    NULLIF(TRIM(CONCAT_WS(' ', prod.marca, prod.modelo)), ''),
                    'Producto eliminado'
                ) AS producto_nombre,

                iu.stock AS stock_actual

            FROM pagos_productos pp

            LEFT JOIN productos prod
                ON prod.id = pp.producto_id

            LEFT JOIN inventario_usuarios iu
                ON iu.id = pp.inventario_usuario_id

            WHERE pp.venta_id = ?

            FOR UPDATE
        ");

        $stmt->bind_param("s", $venta_id);
        $stmt->execute();
        $resultado = $stmt->get_result();

        if ($resultado->num_rows === 0) {
            throw new Exception("Venta no encontrada");
        }

        $productos = [];

        while ($row = $resultado->fetch_assoc()) {
            $productos[] = $row;
        }

        $stmt->close();

        /*
          2. Restaurar stock por inventario.
        */
        foreach ($productos as $p) {
            $pago_producto_id = (int)$p['pago_producto_id'];
            $producto_id = (int)$p['producto_id'];
            $inventario_usuario_id = (int)$p['inventario_usuario_id'];
            $usuario_propietario_id = (int)$p['usuario_propietario_id'];
            $cantidad = (int)$p['cantidad'];
            $costo_unitario = (float)$p['costo_unitario'];
            $producto_codigo = $p['producto_codigo'];
            $producto_nombre = $p['producto_nombre'];

            if ($inventario_usuario_id <= 0) {
                /*
                  Respaldo para ventas antiguas que no tengan inventario.
                  Solo restauramos productos.stock.
                */
                $stmtOld = $conexion->prepare("
                    UPDATE productos
                    SET stock = stock + ?
                    WHERE id = ?
                ");
                $stmtOld->bind_param("ii", $cantidad, $producto_id);

                if (!$stmtOld->execute()) {
                    throw new Exception("No se pudo restaurar stock antiguo de '{$producto_nombre}'");
                }

                $stmtOld->close();
                continue;
            }

            /*
              Bloqueamos inventario actual.
            */
            $stmtInv = $conexion->prepare("
                SELECT stock
                FROM inventario_usuarios
                WHERE id = ?
                FOR UPDATE
            ");
            $stmtInv->bind_param("i", $inventario_usuario_id);
            $stmtInv->execute();
            $inv = $stmtInv->get_result()->fetch_assoc();
            $stmtInv->close();

            if (!$inv) {
                throw new Exception("Inventario no encontrado para '{$producto_nombre}'");
            }

            $stock_actual = (float)$inv['stock'];
            $nuevo_stock = $stock_actual + $cantidad;

            $stmtUpdate = $conexion->prepare("
                UPDATE inventario_usuarios
                SET stock = ?
                WHERE id = ?
            ");
            $stmtUpdate->bind_param("di", $nuevo_stock, $inventario_usuario_id);

            if (!$stmtUpdate->execute()) {
                throw new Exception("No se pudo restaurar stock de '{$producto_nombre}'");
            }

            $stmtUpdate->close();

            /*
              Registrar movimiento de devolución.
              No insertamos total porque es columna generada.
            */
            $nota = "Eliminación de venta POS {$venta_id}";

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
                ) VALUES (?, ?, ?, ?, 'devolucion+', ?, ?, ?, 'pagos_productos', ?, ?, ?, NULL, ?, NOW())
            ");

            $stmtMov->bind_param(
                "iissdddiiis",
                $producto_id,
                $inventario_usuario_id,
                $producto_codigo,
                $producto_nombre,
                $cantidad,
                $costo_unitario,
                $nuevo_stock,
                $pago_producto_id,
                $usuario_accion_id,
                $usuario_propietario_id,
                $nota
            );

            if (!$stmtMov->execute()) {
                throw new Exception("No se pudo registrar devolución de '{$producto_nombre}': " . $stmtMov->error);
            }

            $stmtMov->close();

            /*
              Sincronizar productos.stock.
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
                throw new Exception("No se pudo sincronizar stock general de '{$producto_nombre}'");
            }

            $stmtSync->close();
        }

        /*
          3. Eliminar registros de la venta.
        */
        $stmtDelete = $conexion->prepare("
            DELETE FROM pagos_productos
            WHERE venta_id = ?
        ");
        $stmtDelete->bind_param("s", $venta_id);

        if (!$stmtDelete->execute()) {
            throw new Exception("No se pudo eliminar la venta");
        }

        if ($stmtDelete->affected_rows <= 0) {
            throw new Exception("No se eliminó ningún registro");
        }

        $stmtDelete->close();

        $conexion->commit();

        echo json_encode([
            "success" => true,
            "msg" => "Venta eliminada y stock restaurado correctamente"
        ]);

    } catch (Exception $e) {
        $conexion->rollback();

        echo json_encode([
            "success" => false,
            "error" => $e->getMessage()
        ]);
    }
}
function validarCodigoAdmin($conexion) {
    $input = json_decode(file_get_contents("php://input"), true);
    $codigo = $input["codigo"] ?? '';

    if (!$codigo || strlen($codigo) !== 10) {
        echo json_encode(["success" => false, "error" => "Código inválido"]);
        return;
    }

    $stmt = $conexion->prepare("SELECT id FROM usuarios WHERE codigo = ? AND rol = 'admin' LIMIT 1");
    $stmt->bind_param("s", $codigo);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "error" => "Código no autorizado"]);
    }

    $stmt->close();
}

