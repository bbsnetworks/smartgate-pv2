<?php
ob_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function () {
    $error = error_get_last();

    if ($error !== null) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'error' => 'Error fatal en ventas_financiadas_controller.php',
            'detalle' => $error['message'],
            'archivo' => $error['file'],
            'linea' => $error['line']
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
});

require_once __DIR__ . '/conexion.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conexion->set_charset('utf8mb4');

/* =========================================================
   RESPUESTAS
========================================================= */

function responder(bool $success, array $data = [], int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================================================
   HELPERS REQUEST
========================================================= */

function input_str(string $key, string $default = ''): string
{
    if (isset($_POST[$key])) return trim((string) $_POST[$key]);
    if (isset($_GET[$key])) return trim((string) $_GET[$key]);
    return $default;
}

function input_int(string $key, int $default = 0): int
{
    $value = input_str($key, '');

    if ($value === '') return $default;

    $value = preg_replace('/[^\d\-]/', '', $value);

    if ($value === '' || !is_numeric($value)) return $default;

    return (int) $value;
}

function input_float(string $key, float $default = 0): float
{
    $value = input_str($key, '');

    if ($value === '') return $default;

    $value = str_replace([',', '$', ' '], '', $value);

    if (!is_numeric($value)) return $default;

    return round((float) $value, 2);
}

function usuario_id_actual(): ?int
{
    if (isset($_SESSION['usuario']['id'])) return (int) $_SESSION['usuario']['id'];
    if (isset($_SESSION['iduser'])) return (int) $_SESSION['iduser'];
    if (isset($_SESSION['id'])) return (int) $_SESSION['id'];

    return null;
}

function normalizar_metodo_pago(string $metodo): string
{
    $metodo = strtolower(trim($metodo));
    $permitidos = ['efectivo', 'tarjeta', 'transferencia', 'otro'];

    return in_array($metodo, $permitidos, true) ? $metodo : 'efectivo';
}

function fecha_mas_meses(string $fechaBase, int $meses): string
{
    $fecha = new DateTime($fechaBase);
    $fecha->modify("+$meses month");

    return $fecha->format('Y-m-d');
}

function generar_folio_financiado(mysqli $conexion): string
{
    $fecha = date('Ymd');
    $base = "VF-$fecha-";

    $sql = "
        SELECT folio
        FROM ventas_financiadas
        WHERE folio LIKE CONCAT(?, '%')
        ORDER BY id DESC
        LIMIT 1
    ";

    $stmt = $conexion->prepare($sql);
    $stmt->bind_param('s', $base);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        return $base . '001';
    }

    $ultimo = $row['folio'];
    $numero = (int) substr($ultimo, -3);
    $nuevo = $numero + 1;

    return $base . str_pad((string) $nuevo, 3, '0', STR_PAD_LEFT);
}

/* =========================================================
   ESTADOS
========================================================= */

function recalcular_estado_venta(mysqli $conexion, int $ventaId): void
{
    $hoy = date('Y-m-d');

    $sqlVencidas = "
        UPDATE ventas_financiadas_cuotas
        SET estado = 'vencida'
        WHERE venta_financiada_id = ?
          AND estado IN ('pendiente', 'parcial')
          AND fecha_vencimiento < ?
          AND saldo_cuota > 0
    ";

    $stmt = $conexion->prepare($sqlVencidas);
    $stmt->bind_param('is', $ventaId, $hoy);
    $stmt->execute();

    $sqlSaldo = "
        SELECT 
            COALESCE(SUM(saldo_cuota), 0) AS saldo_actual,
            SUM(CASE WHEN estado = 'vencida' THEN 1 ELSE 0 END) AS vencidas
        FROM ventas_financiadas_cuotas
        WHERE venta_financiada_id = ?
    ";

    $stmt = $conexion->prepare($sqlSaldo);
    $stmt->bind_param('i', $ventaId);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    $saldoActual = round((float) ($row['saldo_actual'] ?? 0), 2);
    $vencidas = (int) ($row['vencidas'] ?? 0);

    if ($saldoActual <= 0) {
        $estado = 'liquidada';
        $saldoActual = 0;
    } elseif ($vencidas > 0) {
        $estado = 'vencida';
    } else {
        $estado = 'activa';
    }

    $sqlUpdate = "
        UPDATE ventas_financiadas
        SET saldo_actual = ?, estado = ?
        WHERE id = ?
    ";

    $stmt = $conexion->prepare($sqlUpdate);
    $stmt->bind_param('dsi', $saldoActual, $estado, $ventaId);
    $stmt->execute();
}

function obtener_cuotas_liberadas(mysqli $conexion, int $ventaId): array
{
    $hoy = date('Y-m-d');

    $sql = "
        SELECT *
        FROM ventas_financiadas_cuotas
        WHERE venta_financiada_id = ?
          AND saldo_cuota > 0
          AND (
                fecha_vencimiento <= ?
                OR numero_cuota = (
                    SELECT MIN(numero_cuota)
                    FROM ventas_financiadas_cuotas
                    WHERE venta_financiada_id = ?
                      AND saldo_cuota > 0
                )
          )
        ORDER BY numero_cuota ASC
        FOR UPDATE
    ";

    $stmt = $conexion->prepare($sql);
    $stmt->bind_param('isi', $ventaId, $hoy, $ventaId);
    $stmt->execute();

    $res = $stmt->get_result();
    $cuotas = [];

    while ($row = $res->fetch_assoc()) {
        $cuotas[] = $row;
    }

    return $cuotas;
}

function cuota_esta_liberada(array $cuotasLiberadas, int $cuotaId): bool
{
    foreach ($cuotasLiberadas as $cuota) {
        if ((int) $cuota['id'] === $cuotaId) {
            return true;
        }
    }

    return false;
}

function saldo_cuotas_liberadas(array $cuotasLiberadas): float
{
    $total = 0;

    foreach ($cuotasLiberadas as $cuota) {
        $total += (float) $cuota['saldo_cuota'];
    }

    return round($total, 2);
}

/* =========================================================
   BUSCAR PRODUCTOS POS
   Busca productos disponibles por dueño en inventario_usuarios
========================================================= */

function buscar_productos(mysqli $conexion): void
{
    $q = input_str('q');

    if ($q === '') {
        responder(false, ['error' => 'Escribe algo para buscar.'], 400);
    }

    $like = "%$q%";

    $sql = "
        SELECT
            iu.id AS inventario_usuario_id,
            iu.producto_id,
            iu.usuario_id AS usuario_propietario_id,
            iu.proveedor_id,
            iu.precio_proveedor,
            iu.precio_venta,
            iu.stock,
            iu.activo,

            p.codigo,
            p.marca,
            p.modelo,
            p.descripcion,
            p.categoria_id,

            u.nombre AS propietario_nombre
        FROM inventario_usuarios iu
        INNER JOIN productos p
            ON p.id = iu.producto_id
        INNER JOIN usuarios u
            ON u.id = iu.usuario_id
        WHERE iu.activo = 1
          AND iu.stock > 0
          AND (
                p.codigo LIKE ?
             OR p.marca LIKE ?
             OR p.modelo LIKE ?
             OR p.descripcion LIKE ?
             OR u.nombre LIKE ?
          )
        ORDER BY p.marca ASC, p.modelo ASC, u.nombre ASC
        LIMIT 30
    ";

    $stmt = $conexion->prepare($sql);
    $stmt->bind_param('sssss', $like, $like, $like, $like, $like);
    $stmt->execute();

    $res = $stmt->get_result();
    $productos = [];

    while ($row = $res->fetch_assoc()) {
        $nombreProducto = trim(($row['marca'] ?? '') . ' ' . ($row['modelo'] ?? ''));

        if ($nombreProducto === '') {
            $nombreProducto = $row['descripcion'] ?: 'Producto sin nombre';
        }

        $productos[] = [
            'inventario_usuario_id' => (int) $row['inventario_usuario_id'],
            'producto_id' => (int) $row['producto_id'],
            'usuario_propietario_id' => (int) $row['usuario_propietario_id'],
            'propietario_nombre' => $row['propietario_nombre'],

            'codigo' => $row['codigo'],
            'marca' => $row['marca'],
            'modelo' => $row['modelo'],
            'nombre' => $nombreProducto,
            'descripcion' => $row['descripcion'],

            'precio_venta' => (float) $row['precio_venta'],
            'precio' => (float) $row['precio_venta'],
            'precio_proveedor' => (float) $row['precio_proveedor'],
            'costo_unitario' => (float) $row['precio_proveedor'],
            'stock' => (int) $row['stock'],
            'categoria_id' => $row['categoria_id'] !== null ? (int) $row['categoria_id'] : null,
        ];
    }

    responder(true, ['productos' => $productos]);
}

/* =========================================================
   BUSCAR CLIENTES
   POS no tiene clientes: siempre devuelve vacío
========================================================= */

function buscar_clientes(mysqli $conexion): void
{
    responder(true, [
        'clientes' => [],
        'mensaje' => 'Smartgate POS no tiene tabla clientes. Captura el cliente manualmente.'
    ]);
}

/* =========================================================
   CREAR VENTA FINANCIADA POS
========================================================= */

function crear_venta_financiada(mysqli $conexion): void
{
    $usuarioId = usuario_id_actual();

    $clienteNombre = input_str('cliente_nombre');
    $clienteTelefono = input_str('cliente_telefono');
    $clienteEmail = input_str('cliente_email');
    $clienteDireccion = input_str('cliente_direccion');

    $comisionPorcentaje = input_float('comision_porcentaje', 0);
    $enganche = input_float('enganche', 0);
    $meses = input_int('meses', 1);

    $fechaPrimerPago = input_str('fecha_primer_pago');
    $metodoEnganche = normalizar_metodo_pago(input_str('metodo_enganche', 'efectivo'));
    $observaciones = input_str('observaciones');

    $productosJson = input_str('productos');

    if ($clienteNombre === '') {
        responder(false, ['error' => 'El nombre del cliente/persona es obligatorio.'], 400);
    }

    if ($meses <= 0) {
        responder(false, ['error' => 'Los meses deben ser mayores a 0.'], 400);
    }

    if ($fechaPrimerPago === '') {
        responder(false, ['error' => 'La fecha del primer pago es obligatoria.'], 400);
    }

    if ($productosJson === '') {
        responder(false, ['error' => 'No se recibieron productos.'], 400);
    }

    $productos = json_decode($productosJson, true);

    if (!is_array($productos) || count($productos) === 0) {
        responder(false, ['error' => 'El formato de productos no es válido.'], 400);
    }

    try {
        $conexion->begin_transaction();

        $folio = generar_folio_financiado($conexion);

        $detalleProductos = [];
        $subtotalGeneral = 0;

        foreach ($productos as $item) {
            $inventarioUsuarioId = isset($item['inventario_usuario_id']) ? (int) $item['inventario_usuario_id'] : 0;
            $cantidad = isset($item['cantidad']) ? (int) $item['cantidad'] : 0;
            $precioUnitario = isset($item['precio_unitario']) ? round((float) $item['precio_unitario'], 2) : 0;

            if ($inventarioUsuarioId <= 0) {
                throw new Exception('Hay un producto inválido. Vuelve a seleccionarlo desde el buscador.');
            }

            if ($cantidad <= 0) {
                throw new Exception('La cantidad del producto debe ser mayor a 0.');
            }

            if ($precioUnitario <= 0) {
                throw new Exception('El precio del producto debe ser mayor a 0.');
            }

            $sqlInventario = "
                SELECT
                    iu.id AS inventario_usuario_id,
                    iu.producto_id,
                    iu.usuario_id AS usuario_propietario_id,
                    iu.proveedor_id,
                    iu.precio_proveedor,
                    iu.precio_venta,
                    iu.stock,
                    iu.activo,

                    p.codigo,
                    p.marca,
                    p.modelo,
                    p.descripcion
                FROM inventario_usuarios iu
                INNER JOIN productos p
                    ON p.id = iu.producto_id
                WHERE iu.id = ?
                  AND iu.activo = 1
                FOR UPDATE
            ";

            $stmt = $conexion->prepare($sqlInventario);
            $stmt->bind_param('i', $inventarioUsuarioId);
            $stmt->execute();

            $inventario = $stmt->get_result()->fetch_assoc();

            if (!$inventario) {
                throw new Exception('No se encontró el inventario seleccionado.');
            }

            $stockActual = (int) $inventario['stock'];

            if ($stockActual <= 0) {
                throw new Exception('El producto seleccionado no tiene stock disponible.');
            }

            if ($stockActual < $cantidad) {
                $nombreTmp = trim($inventario['marca'] . ' ' . $inventario['modelo']);
                throw new Exception("Stock insuficiente para {$nombreTmp}. Disponible: $stockActual.");
            }

            $productoNombre = trim(($inventario['marca'] ?? '') . ' ' . ($inventario['modelo'] ?? ''));

            if ($productoNombre === '') {
                $productoNombre = $inventario['descripcion'] ?: 'Producto sin nombre';
            }

            $costoUnitario = round((float) $inventario['precio_proveedor'], 2);

            $subtotalItem = round($cantidad * $precioUnitario, 2);
            $costoTotal = round($cantidad * $costoUnitario, 2);
            $utilidadTotal = round($subtotalItem - $costoTotal, 2);

            $subtotalGeneral += $subtotalItem;

            $detalleProductos[] = [
                'inventario_usuario_id' => (int) $inventario['inventario_usuario_id'],
                'producto_id' => (int) $inventario['producto_id'],
                'usuario_propietario_id' => (int) $inventario['usuario_propietario_id'],

                'codigo' => $inventario['codigo'],
                'marca' => $inventario['marca'],
                'modelo' => $inventario['modelo'],
                'nombre' => $productoNombre,
                'descripcion' => $inventario['descripcion'],

                'cantidad' => $cantidad,
                'precio_unitario' => $precioUnitario,
                'costo_unitario' => $costoUnitario,

                'subtotal' => $subtotalItem,
                'costo_total' => $costoTotal,
                'utilidad_total' => $utilidadTotal
            ];
        }

        $subtotalGeneral = round($subtotalGeneral, 2);
        $comisionMonto = round($subtotalGeneral * ($comisionPorcentaje / 100), 2);
        $totalFinanciado = round($subtotalGeneral + $comisionMonto, 2);

        if ($enganche < 0) {
            $enganche = 0;
        }

        if ($enganche > $totalFinanciado) {
            throw new Exception('El enganche no puede ser mayor al total financiado.');
        }

        $saldoInicial = round($totalFinanciado - $enganche, 2);
        $saldoActual = $saldoInicial;
        $montoMensual = $meses > 0 ? round($saldoInicial / $meses, 2) : 0;

        $clienteOrigen = 'manual';
        $clienteReferencia = null;

        $sqlVenta = "
            INSERT INTO ventas_financiadas (
                folio,
                cliente_nombre,
                cliente_telefono,
                cliente_email,
                cliente_direccion,
                cliente_origen,
                cliente_referencia,
                subtotal,
                comision_porcentaje,
                comision_monto,
                total_financiado,
                enganche,
                saldo_inicial,
                saldo_actual,
                meses,
                monto_mensual,
                fecha_primer_pago,
                estado,
                observaciones,
                creado_por
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'activa', ?, ?
            )
        ";

        $stmt = $conexion->prepare($sqlVenta);
        $stmt->bind_param(
            'sssssssdddddddidssi',
            $folio,
            $clienteNombre,
            $clienteTelefono,
            $clienteEmail,
            $clienteDireccion,
            $clienteOrigen,
            $clienteReferencia,
            $subtotalGeneral,
            $comisionPorcentaje,
            $comisionMonto,
            $totalFinanciado,
            $enganche,
            $saldoInicial,
            $saldoActual,
            $meses,
            $montoMensual,
            $fechaPrimerPago,
            $observaciones,
            $usuarioId
        );
        $stmt->execute();

        $ventaId = $conexion->insert_id;

        foreach ($detalleProductos as $item) {
            $sqlDetalle = "
                INSERT INTO ventas_financiadas_detalle (
                    venta_financiada_id,
                    producto_id,
                    inventario_usuario_id,
                    usuario_propietario_id,
                    producto_codigo,
                    producto_marca,
                    producto_modelo,
                    producto_nombre,
                    producto_descripcion,
                    cantidad,
                    precio_unitario,
                    costo_unitario,
                    subtotal,
                    costo_total,
                    utilidad_total
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $stmt = $conexion->prepare($sqlDetalle);
            $stmt->bind_param(
                'iiiisssssdddddd',
                $ventaId,
                $item['producto_id'],
                $item['inventario_usuario_id'],
                $item['usuario_propietario_id'],
                $item['codigo'],
                $item['marca'],
                $item['modelo'],
                $item['nombre'],
                $item['descripcion'],
                $item['cantidad'],
                $item['precio_unitario'],
                $item['costo_unitario'],
                $item['subtotal'],
                $item['costo_total'],
                $item['utilidad_total']
            );
            $stmt->execute();

            $sqlStock = "
                UPDATE inventario_usuarios
                SET stock = stock - ?
                WHERE id = ?
                  AND producto_id = ?
                  AND stock >= ?
                  AND activo = 1
            ";

            $stmt = $conexion->prepare($sqlStock);
            $stmt->bind_param(
                'iiii',
                $item['cantidad'],
                $item['inventario_usuario_id'],
                $item['producto_id'],
                $item['cantidad']
            );
            $stmt->execute();

            if ($stmt->affected_rows <= 0) {
                throw new Exception("No se pudo descontar stock de {$item['nombre']}.");
            }
        }

        if ($saldoInicial > 0) {
            $acumulado = 0;

            for ($i = 1; $i <= $meses; $i++) {
                $fechaVencimiento = fecha_mas_meses($fechaPrimerPago, $i - 1);

                if ($i === $meses) {
                    $montoCuota = round($saldoInicial - $acumulado, 2);
                } else {
                    $montoCuota = $montoMensual;
                    $acumulado += $montoCuota;
                }

                $sqlCuota = "
                    INSERT INTO ventas_financiadas_cuotas (
                        venta_financiada_id,
                        numero_cuota,
                        fecha_vencimiento,
                        monto_programado,
                        monto_pagado,
                        saldo_cuota,
                        estado
                    ) VALUES (?, ?, ?, ?, 0.00, ?, 'pendiente')
                ";

                $stmt = $conexion->prepare($sqlCuota);
                $stmt->bind_param(
                    'iisdd',
                    $ventaId,
                    $i,
                    $fechaVencimiento,
                    $montoCuota,
                    $montoCuota
                );
                $stmt->execute();
            }
        } else {
            $sqlLiquidar = "
                UPDATE ventas_financiadas
                SET estado = 'liquidada', saldo_actual = 0
                WHERE id = ?
            ";

            $stmt = $conexion->prepare($sqlLiquidar);
            $stmt->bind_param('i', $ventaId);
            $stmt->execute();
        }

        if ($enganche > 0) {
            $referenciaEnganche = 'ENGANCHE';
            $obsEnganche = 'Pago inicial de la venta financiada';

            $sqlPagoEnganche = "
                INSERT INTO ventas_financiadas_pagos (
                    venta_financiada_id,
                    cuota_id,
                    monto,
                    metodo_pago,
                    referencia,
                    observaciones,
                    recibido_por
                ) VALUES (?, NULL, ?, ?, ?, ?, ?)
            ";

            $stmt = $conexion->prepare($sqlPagoEnganche);
            $stmt->bind_param(
                'idsssi',
                $ventaId,
                $enganche,
                $metodoEnganche,
                $referenciaEnganche,
                $obsEnganche,
                $usuarioId
            );
            $stmt->execute();
        }

        recalcular_estado_venta($conexion, $ventaId);

        $conexion->commit();

        responder(true, [
            'mensaje' => 'Venta financiada creada correctamente.',
            'venta_id' => $ventaId,
            'folio' => $folio,
            'subtotal' => $subtotalGeneral,
            'comision_monto' => $comisionMonto,
            'total_financiado' => $totalFinanciado,
            'enganche' => $enganche,
            'saldo_inicial' => $saldoInicial,
            'saldo_actual' => $saldoActual,
            'meses' => $meses,
            'monto_mensual' => $montoMensual
        ]);

    } catch (Throwable $e) {
        $conexion->rollback();

        responder(false, [
            'error' => 'No se pudo crear la venta financiada.',
            'detalle' => $e->getMessage()
        ], 500);
    }
}
/* =========================================================
   LISTAR VENTAS FINANCIADAS
========================================================= */

function listar_ventas_financiadas(mysqli $conexion): void
{
    $q = input_str('q');
    $estado = input_str('estado');
    $pagina = input_int('pagina', 1);
    $limite = input_int('limite', 20);

    if ($pagina <= 0) {
        $pagina = 1;
    }

    if ($limite <= 0 || $limite > 100) {
        $limite = 20;
    }

    $offset = ($pagina - 1) * $limite;

    $where = [];
    $params = [];
    $types = '';

    if ($q !== '') {
        $like = "%$q%";
        $where[] = "(folio LIKE ? OR cliente_nombre LIKE ? OR cliente_telefono LIKE ? OR cliente_email LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'ssss';
    }

    if ($estado !== '' && in_array($estado, ['activa', 'liquidada', 'vencida', 'cancelada'], true)) {
        $where[] = "estado = ?";
        $params[] = $estado;
        $types .= 's';
    }

    $whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT 
            id,
            folio,
            cliente_nombre,
            cliente_telefono,
            subtotal,
            comision_porcentaje,
            comision_monto,
            total_financiado,
            enganche,
            saldo_actual,
            meses,
            monto_mensual,
            fecha_venta,
            fecha_primer_pago,
            estado
        FROM ventas_financiadas
        $whereSql
        ORDER BY id DESC
        LIMIT ? OFFSET ?
    ";

    $params[] = $limite;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $conexion->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $ventas = [];
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        recalcular_estado_venta($conexion, (int) $row['id']);

        $ventas[] = [
            'id' => (int) $row['id'],
            'folio' => $row['folio'],
            'cliente_nombre' => $row['cliente_nombre'],
            'cliente_telefono' => $row['cliente_telefono'],
            'subtotal' => (float) $row['subtotal'],
            'comision_porcentaje' => (float) $row['comision_porcentaje'],
            'comision_monto' => (float) $row['comision_monto'],
            'total_financiado' => (float) $row['total_financiado'],
            'enganche' => (float) $row['enganche'],
            'saldo_actual' => (float) $row['saldo_actual'],
            'meses' => (int) $row['meses'],
            'monto_mensual' => (float) $row['monto_mensual'],
            'fecha_venta' => $row['fecha_venta'],
            'fecha_primer_pago' => $row['fecha_primer_pago'],
            'estado' => $row['estado'],
        ];
    }

    responder(true, [
        'ventas' => $ventas,
        'pagina' => $pagina,
        'limite' => $limite
    ]);
}

/* =========================================================
   DETALLE DE VENTA FINANCIADA
========================================================= */

function obtener_detalle_financiamiento(mysqli $conexion): void
{
    $ventaId = input_int('id');

    if ($ventaId <= 0) {
        responder(false, ['error' => 'ID inválido.'], 400);
    }

    recalcular_estado_venta($conexion, $ventaId);

    $sqlVenta = "
        SELECT *
        FROM ventas_financiadas
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $conexion->prepare($sqlVenta);
    $stmt->bind_param('i', $ventaId);
    $stmt->execute();

    $venta = $stmt->get_result()->fetch_assoc();

    if (!$venta) {
        responder(false, ['error' => 'No se encontró la venta financiada.'], 404);
    }

    $sqlDetalle = "
        SELECT 
            d.*,
            u.nombre AS propietario_nombre
        FROM ventas_financiadas_detalle d
        LEFT JOIN usuarios u
            ON u.id = d.usuario_propietario_id
        WHERE d.venta_financiada_id = ?
        ORDER BY d.id ASC
    ";

    $stmt = $conexion->prepare($sqlDetalle);
    $stmt->bind_param('i', $ventaId);
    $stmt->execute();

    $detalle = [];
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $detalle[] = $row;
    }

    $sqlCuotas = "
        SELECT *
        FROM ventas_financiadas_cuotas
        WHERE venta_financiada_id = ?
        ORDER BY numero_cuota ASC
    ";

    $stmt = $conexion->prepare($sqlCuotas);
    $stmt->bind_param('i', $ventaId);
    $stmt->execute();

    $cuotas = [];
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $cuotas[] = $row;
    }

    $sqlPagos = "
        SELECT *
        FROM ventas_financiadas_pagos
        WHERE venta_financiada_id = ?
        ORDER BY fecha_pago DESC, id DESC
    ";

    $stmt = $conexion->prepare($sqlPagos);
    $stmt->bind_param('i', $ventaId);
    $stmt->execute();

    $pagos = [];
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $pagos[] = $row;
    }

    responder(true, [
        'venta' => $venta,
        'detalle' => $detalle,
        'cuotas' => $cuotas,
        'pagos' => $pagos
    ]);
}

/* =========================================================
   REGISTRAR ABONO
========================================================= */

function registrar_abono(mysqli $conexion): void
{
    $usuarioId = usuario_id_actual();

    $ventaId = input_int('venta_id');
    $cuotaId = input_int('cuota_id', 0);
    $monto = input_float('monto', 0);
    $metodoPago = normalizar_metodo_pago(input_str('metodo_pago', 'efectivo'));
    $referencia = input_str('referencia');
    $observaciones = input_str('observaciones');

    if ($ventaId <= 0) {
        responder(false, ['error' => 'Venta inválida.'], 400);
    }

    if ($monto <= 0) {
        responder(false, ['error' => 'El monto debe ser mayor a 0.'], 400);
    }

    try {
        $conexion->begin_transaction();

        $sqlVenta = "
            SELECT id, estado, saldo_actual
            FROM ventas_financiadas
            WHERE id = ?
            FOR UPDATE
        ";

        $stmt = $conexion->prepare($sqlVenta);
        $stmt->bind_param('i', $ventaId);
        $stmt->execute();

        $venta = $stmt->get_result()->fetch_assoc();

        if (!$venta) {
            throw new Exception('No se encontró la venta financiada.');
        }

        if ($venta['estado'] === 'cancelada') {
            throw new Exception('No se puede abonar a una venta cancelada.');
        }

        $saldoActual = round((float) $venta['saldo_actual'], 2);

        if ($saldoActual <= 0) {
            throw new Exception('Esta venta ya está liquidada.');
        }

        $cuotasLiberadas = obtener_cuotas_liberadas($conexion, $ventaId);
        $saldoLiberado = saldo_cuotas_liberadas($cuotasLiberadas);

        if ($cuotaId > 0) {
            if ($saldoLiberado <= 0) {
                throw new Exception('No hay cuotas disponibles para pagar en este momento.');
            }

            if ($monto > $saldoLiberado) {
                throw new Exception(
                    'El abono no puede ser mayor al saldo disponible para pagar en este momento. Disponible: $' .
                    number_format($saldoLiberado, 2)
                );
            }
        } else {
            // Abono general: permite abonar hasta el saldo total de la venta.
            if ($monto > $saldoActual) {
                throw new Exception(
                    'El abono no puede ser mayor al saldo total pendiente. Saldo actual: $' .
                    number_format($saldoActual, 2)
                );
            }
        }

        $montoPendienteAplicar = $monto;
        $cuotasAplicadas = [];

        if ($cuotaId > 0) {
            $sqlCuota = "
                SELECT *
                FROM ventas_financiadas_cuotas
                WHERE id = ?
                  AND venta_financiada_id = ?
                FOR UPDATE
            ";

            $stmt = $conexion->prepare($sqlCuota);
            $stmt->bind_param('ii', $cuotaId, $ventaId);
            $stmt->execute();

            $cuota = $stmt->get_result()->fetch_assoc();

            if (!$cuota) {
                throw new Exception('No se encontró la cuota seleccionada.');
            }

            if (!cuota_esta_liberada($cuotasLiberadas, $cuotaId)) {
                throw new Exception('Esta cuota todavía no está disponible para pago. Primero debe pagarse la cuota más próxima o las vencidas.');
            }

            $saldoCuota = round((float) $cuota['saldo_cuota'], 2);

            if ($saldoCuota <= 0) {
                throw new Exception('La cuota seleccionada ya está pagada.');
            }

            if ($monto > $saldoCuota) {
                throw new Exception('El abono no puede ser mayor al saldo de la cuota seleccionada. Saldo de cuota: $' . number_format($saldoCuota, 2));
            }

            $aplicar = $montoPendienteAplicar;

            $nuevoPagado = round((float) $cuota['monto_pagado'] + $aplicar, 2);
            $nuevoSaldo = round($saldoCuota - $aplicar, 2);
            $nuevoEstado = $nuevoSaldo <= 0 ? 'pagada' : 'parcial';

            $sqlUpdateCuota = "
                UPDATE ventas_financiadas_cuotas
                SET monto_pagado = ?, saldo_cuota = ?, estado = ?
                WHERE id = ?
            ";

            $stmt = $conexion->prepare($sqlUpdateCuota);
            $stmt->bind_param('ddsi', $nuevoPagado, $nuevoSaldo, $nuevoEstado, $cuotaId);
            $stmt->execute();

            $montoPendienteAplicar = round($montoPendienteAplicar - $aplicar, 2);

            $cuotasAplicadas[] = [
                'cuota_id' => $cuotaId,
                'monto' => $aplicar
            ];
        }

        // Si no se seleccionó cuota, es abono general:
        // se aplica a todas las cuotas pendientes en orden.
        if ($montoPendienteAplicar > 0) {
            if ($cuotaId > 0) {
                $cuotasParaAplicar = $cuotasLiberadas;
            } else {
                $sqlTodasCuotasPendientes = "
                    SELECT *
                    FROM ventas_financiadas_cuotas
                    WHERE venta_financiada_id = ?
                      AND saldo_cuota > 0
                    ORDER BY numero_cuota ASC
                    FOR UPDATE
                ";

                $stmtTodas = $conexion->prepare($sqlTodasCuotasPendientes);
                $stmtTodas->bind_param('i', $ventaId);
                $stmtTodas->execute();

                $resTodas = $stmtTodas->get_result();

                $cuotasParaAplicar = [];

                while ($rowCuota = $resTodas->fetch_assoc()) {
                    $cuotasParaAplicar[] = $rowCuota;
                }
            }

            foreach ($cuotasParaAplicar as $cuota) {
                if ($montoPendienteAplicar <= 0) {
                    break;
                }

                $idCuotaActual = (int) $cuota['id'];
                $saldoCuota = round((float) $cuota['saldo_cuota'], 2);

                if ($saldoCuota <= 0) {
                    continue;
                }

                $aplicar = min($montoPendienteAplicar, $saldoCuota);

                $nuevoPagado = round((float) $cuota['monto_pagado'] + $aplicar, 2);
                $nuevoSaldo = round($saldoCuota - $aplicar, 2);
                $nuevoEstado = $nuevoSaldo <= 0 ? 'pagada' : 'parcial';

                $sqlUpdateCuota = "
                    UPDATE ventas_financiadas_cuotas
                    SET monto_pagado = ?, saldo_cuota = ?, estado = ?
                    WHERE id = ?
                ";

                $stmtUpdate = $conexion->prepare($sqlUpdateCuota);
                $stmtUpdate->bind_param('ddsi', $nuevoPagado, $nuevoSaldo, $nuevoEstado, $idCuotaActual);
                $stmtUpdate->execute();

                $montoPendienteAplicar = round($montoPendienteAplicar - $aplicar, 2);

                $cuotasAplicadas[] = [
                    'cuota_id' => $idCuotaActual,
                    'monto' => $aplicar
                ];
            }
        }

        $cuotaPagoId = $cuotaId > 0 ? $cuotaId : null;

        $sqlPago = "
            INSERT INTO ventas_financiadas_pagos (
                venta_financiada_id,
                cuota_id,
                monto,
                metodo_pago,
                referencia,
                observaciones,
                recibido_por
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ";

        $stmt = $conexion->prepare($sqlPago);
        $stmt->bind_param(
            'iidsssi',
            $ventaId,
            $cuotaPagoId,
            $monto,
            $metodoPago,
            $referencia,
            $observaciones,
            $usuarioId
        );
        $stmt->execute();

        $pagoId = $conexion->insert_id;

        foreach ($cuotasAplicadas as $aplicacion) {
            $cuotaAplicadaId = (int) $aplicacion['cuota_id'];
            $montoAplicado = round((float) $aplicacion['monto'], 2);

            if ($cuotaAplicadaId <= 0 || $montoAplicado <= 0) {
                continue;
            }

            $sqlAplicacion = "
                INSERT INTO ventas_financiadas_pagos_aplicaciones (
                    pago_id,
                    venta_financiada_id,
                    cuota_id,
                    monto_aplicado
                ) VALUES (?, ?, ?, ?)
            ";

            $stmtAplicacion = $conexion->prepare($sqlAplicacion);
            $stmtAplicacion->bind_param(
                'iiid',
                $pagoId,
                $ventaId,
                $cuotaAplicadaId,
                $montoAplicado
            );
            $stmtAplicacion->execute();
        }

        recalcular_estado_venta($conexion, $ventaId);

        $conexion->commit();

        responder(true, [
            'mensaje' => 'Abono registrado correctamente.',
            'venta_id' => $ventaId,
            'pago_id' => $pagoId,
            'monto' => $monto,
            'cuotas_aplicadas' => $cuotasAplicadas
        ]);

    } catch (Throwable $e) {
        $conexion->rollback();

        responder(false, [
            'error' => 'No se pudo registrar el abono.',
            'detalle' => $e->getMessage()
        ], 500);
    }
}
/* =========================================================
   PAGOS PRÓXIMOS DASHBOARD
   Solo muestra cuotas entre hoy -5 días y hoy +5 días.
========================================================= */

function listar_pagos_proximos_dashboard(mysqli $conexion): void
{
    $hoy = date('Y-m-d');

    $fechaInicio = date('Y-m-d', strtotime('-5 days'));
    $fechaFin = date('Y-m-d', strtotime('+5 days'));

    $limite = 12;

    $sql = "
        SELECT
            v.id AS venta_id,
            v.folio,
            v.cliente_nombre,
            v.cliente_telefono,
            v.estado AS estado_venta,

            c.id AS cuota_id,
            c.numero_cuota,
            c.fecha_vencimiento,
            c.monto_programado,
            c.monto_pagado,
            c.saldo_cuota,
            c.estado AS estado_cuota
        FROM ventas_financiadas_cuotas c
        INNER JOIN ventas_financiadas v
            ON v.id = c.venta_financiada_id
        WHERE v.estado IN ('activa', 'vencida')
          AND c.saldo_cuota > 0
          AND c.fecha_vencimiento BETWEEN ? AND ?
        ORDER BY
            CASE 
                WHEN c.fecha_vencimiento < ? THEN 0
                WHEN c.fecha_vencimiento = ? THEN 1
                ELSE 2
            END,
            c.fecha_vencimiento ASC,
            v.id DESC
        LIMIT ?
    ";

    $stmt = $conexion->prepare($sql);
    $stmt->bind_param('ssssi', $fechaInicio, $fechaFin, $hoy, $hoy, $limite);
    $stmt->execute();

    $res = $stmt->get_result();

    $pagos = [];
    $totalDisponible = 0;
    $vencidos = 0;

    while ($row = $res->fetch_assoc()) {
        $saldo = round((float) $row['saldo_cuota'], 2);
        $totalDisponible += $saldo;

        if ($row['fecha_vencimiento'] < $hoy) {
            $vencidos++;
            $tipo = 'vencida';
        } elseif ($row['fecha_vencimiento'] === $hoy) {
            $tipo = 'hoy';
        } else {
            $tipo = 'proxima';
        }

        $pagos[] = [
            'venta_id' => (int) $row['venta_id'],
            'folio' => $row['folio'],
            'cliente_nombre' => $row['cliente_nombre'],
            'cliente_telefono' => $row['cliente_telefono'],

            'cuota_id' => (int) $row['cuota_id'],
            'numero_cuota' => (int) $row['numero_cuota'],
            'fecha_vencimiento' => $row['fecha_vencimiento'],
            'monto_programado' => (float) $row['monto_programado'],
            'monto_pagado' => (float) $row['monto_pagado'],
            'saldo_cuota' => $saldo,

            'estado_venta' => $row['estado_venta'],
            'estado_cuota' => $row['estado_cuota'],
            'tipo' => $tipo
        ];
    }

    responder(true, [
        'resumen' => [
            'total_disponible' => round($totalDisponible, 2),
            'vencidos' => $vencidos,
            'total_items' => count($pagos),
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin
        ],
        'pagos' => $pagos
    ]);
}
/* =========================================================
   ROUTER
========================================================= */

$accion = input_str('accion');

try {
    switch ($accion) {
        case 'buscar_productos':
            buscar_productos($conexion);
            break;

        case 'buscar_clientes':
            buscar_clientes($conexion);
            break;

        case 'crear_venta_financiada':
            crear_venta_financiada($conexion);
            break;

        case 'listar_ventas_financiadas':
            listar_ventas_financiadas($conexion);
            break;

        case 'obtener_detalle_financiamiento':
            obtener_detalle_financiamiento($conexion);
            break;

        case 'registrar_abono':
            registrar_abono($conexion);
            break;

        case 'listar_pagos_proximos_dashboard':
            listar_pagos_proximos_dashboard($conexion);
            break;

        default:
            responder(false, [
                'error' => 'Acción no válida.',
                'accion_recibida' => $accion,
                'acciones_disponibles' => [
                    'buscar_productos',
                    'buscar_clientes',
                    'crear_venta_financiada',
                    'listar_ventas_financiadas',
                    'obtener_detalle_financiamiento',
                    'registrar_abono',
                    'listar_pagos_proximos_dashboard'
                ]
            ], 400);
    }
} catch (Throwable $e) {
    responder(false, [
        'error' => 'Error interno del controlador.',
        'detalle' => $e->getMessage()
    ], 500);
}