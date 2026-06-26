<?php include_once './php/verificar_sesion.php'; ?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard - Smartgate POS</title>
  <link rel="icon" type="image/x-icon" href="img/favicon.ico">
  <link rel="stylesheet" href="src/output.css">
  <!-- Bootstrap Icons (local) -->
  <link rel="stylesheet" href="fonts/bootstrap-icons.css">
  <link rel="stylesheet" href="css/scroll.css">
  <style>
    /* ≈ 20px como tu w-5 */
    .icon-20 {
      font-size: 20px;
      line-height: 1;
    }

    .scrollbar-custom::-webkit-scrollbar {
      width: 8px;
    }

    .scrollbar-custom::-webkit-scrollbar-track {
      background: #1e293b;
      /* gris oscuro */
    }

    .scrollbar-custom::-webkit-scrollbar-thumb {
      background-color: #FFB900;
      border-radius: 9999px;
    }

    #modal-pos-rapido {
      animation: modalFadeIn .18s ease-out;
    }

    #modal-pos-rapido>div>div {
      animation: modalScaleIn .18s ease-out;
    }

    @keyframes modalFadeIn {
      from {
        opacity: 0;
      }

      to {
        opacity: 1;
      }
    }

    @keyframes modalScaleIn {
      from {
        opacity: 0;
        transform: scale(.97) translateY(8px);
      }

      to {
        opacity: 1;
        transform: scale(1) translateY(0);
      }
    }

    .accion-flotante-pos {
      border: none;
      background: transparent;
      padding: 0;
      cursor: pointer;
    }

    /* ===============================
   Botones flotantes POS
================================ */
    .acciones-flotantes-pos {
      position: fixed;
      right: 24px;
      top: 50%;
      transform: translateY(-50%);
      z-index: 999999;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .accion-flotante-pos {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 10px;
      border: none;
      background: transparent;
      padding: 0;
      cursor: pointer;
      text-decoration: none;
    }

    .accion-tooltip {
      opacity: 0;
      transform: translateX(8px);
      transition: all 0.2s ease;
      background: rgba(15, 23, 42, 0.92);
      color: #e2e8f0;
      border: 1px solid rgba(255, 255, 255, 0.12);
      padding: 7px 12px;
      border-radius: 999px;
      font-size: 13px;
      white-space: nowrap;
      pointer-events: none;
      box-shadow: 0 10px 25px rgba(0, 0, 0, .25);
    }

    .accion-flotante-pos:hover .accion-tooltip {
      opacity: 1;
      transform: translateX(0);
    }

    .accion-circulo {
      width: 46px;
      height: 46px;
      border-radius: 999px;
      display: flex;
      align-items: center;
      justify-content: center;
      backdrop-filter: blur(8px);
      transition: all 0.2s ease;
      box-shadow: 0 10px 25px rgba(0, 0, 0, .25);
      font-size: 18px;
    }

    .accion-flotante-pos:hover .accion-circulo {
      transform: scale(1.06);
    }

    .accion-venta .accion-circulo {
      color: #a5b4fc;
      background: rgba(99, 102, 241, 0.18);
      border: 1px solid rgba(165, 180, 252, 0.35);
    }

    .accion-lista .accion-circulo {
      color: #7dd3fc;
      background: rgba(14, 165, 233, 0.18);
      border: 1px solid rgba(125, 211, 252, 0.35);
    }

    .accion-productos .accion-circulo {
      color: #6ee7b7;
      background: rgba(16, 185, 129, 0.18);
      border: 1px solid rgba(110, 231, 183, 0.35);
    }

    .accion-financiadas .accion-circulo {
      color: #fcd34d;
      background: rgba(245, 158, 11, 0.18);
      border: 1px solid rgba(252, 211, 77, 0.35);
    }

    @media (max-width: 1024px) {
      .acciones-flotantes-pos {
        right: 16px;
        bottom: 18px;
        top: auto;
        transform: none;
        flex-direction: row;
      }

      .accion-tooltip {
        display: none;
      }

      .accion-circulo {
        width: 44px;
        height: 44px;
      }
    }
  </style>
</head>

<body class="bg-slate-900 font-sans min-h-screen bg-[url('../img/black-paper.png')] bg-fixed bg-auto text-slate-200">

  <!-- LAYOUT -->
  <div class="w-full min-h-screen flex">

    <!-- SIDEBAR -->
    <aside class="aside-scroll aside-scroll--fade w-72 shrink-0 h-[100dvh] sticky top-0
         bg-slate-900/70 backdrop-blur border-r border-slate-700/50 px-4 pr-2 py-6
         hidden md:block flex flex-col">
      <div class="flex items-center gap-3 mb-6">
        <img src="img/logo.webp" class="h-8" alt="logo">
        <span class="font-semibold text-slate-100">Smartgate POS</span>
      </div>

      <?php $rol = $_SESSION['usuario']['rol']; ?>
      <!-- Sección Productos -->
      <div class="mt-6">
        <button onclick="toggleAccordion('productosPanel')"
          class="w-full text-left text-sm font-semibold text-indigo-300 bg-indigo-800/40 px-4 py-3 rounded-lg hover:bg-indigo-700/50 transition">
          🛒 Gestión de Productos
        </button>
        <div id="productosPanel" class="mt-3 space-y-2">
          <a href="vistas/pagos-productos.php"
            class="card-bloqueable block px-3 py-2 rounded-lg border border-slate-700/70 bg-slate-800/70 hover:bg-slate-700/60 flex items-center gap-3">
            <i class="bi bi-cart icon-20 text-indigo-400"></i><span>Venta de Productos</span>
          </a>
          <a href="vistas/admin-productos.php"
            class="card-bloqueable block px-3 py-2 rounded-lg border border-slate-700/70 bg-slate-800/70 hover:bg-slate-700/60 flex items-center gap-3">
            <i class="bi bi-shop icon-20 text-indigo-400"></i><span>Administrar Productos</span>
          </a>
          <a href="vistas/admin-categorias.php"
            class="card-bloqueable block px-3 py-2 rounded-lg border border-slate-700/70 bg-slate-800/70 hover:bg-slate-700/60 flex items-center gap-3">
            <i class="bi bi-tags icon-20 text-indigo-400"></i><span>Administrar Categorías</span>
          </a>
          <a href="vistas/lista-pagos.php"
            class="card-bloqueable block px-3 py-2 rounded-lg border border-slate-700/70 bg-slate-800/70 hover:bg-slate-700/60 flex items-center gap-3">
            <i class="bi bi-receipt icon-20 text-indigo-400"></i><span>Lista de Pagos</span>
          </a>
          <a href="vistas/ventas-financiadas.php"
            class="card-bloqueable block px-3 py-2 rounded-lg border border-slate-700/70 bg-slate-800/70 hover:bg-slate-700/60 flex items-center gap-3">
            <i class="bi bi-credit-card-2-front icon-20 text-indigo-400"></i>
            <span>Ventas Financiadas</span>
          </a>
          <a href="vistas/proveedores.php"
            class="card-bloqueable block px-3 py-2 rounded-lg border border-slate-700/70 bg-slate-800/70 hover:bg-slate-700/60 flex items-center gap-3">
            <i class="bi bi-people-fill text-indigo-400"></i><span>Proveedores</span>
          </a>
        </div>
      </div>
      <!-- Sección Cafetería -->
      <!--  
      <div class="mt-6">
        <button onclick="toggleAccordion('cafeteriaPanel')"
          class="w-full text-left text-sm font-semibold text-rose-300 bg-rose-900/40 px-4 py-3 rounded-lg hover:bg-rose-800/50 transition">
          ☕ Cafetería
        </button>

        <div id="cafeteriaPanel" class="mt-3 space-y-2">
          <a href="vistas/caf_pedido.php"
            class="card-bloqueable block px-3 py-2 rounded-lg border border-slate-700/70 bg-slate-800/70 hover:bg-slate-700/60 flex items-center gap-3">
            <i class="bi bi-cup-hot icon-20 text-rose-400"></i>
            <span>Nuevo pedido (táctil)</span>
          </a>

          <a href="vistas/caf_admin_productos.php"
            class="card-bloqueable block px-3 py-2 rounded-lg border border-slate-700/70 bg-slate-800/70 hover:bg-slate-700/60 flex items-center gap-3">
            <i class="bi bi-shop icon-20 text-rose-400"></i><span>Administrar Productos</span>
          </a>

          <a href="vistas/caf_admin_categorias.php"
            class="card-bloqueable block px-3 py-2 rounded-lg border border-slate-700/70 bg-slate-800/70 hover:bg-slate-700/60 flex items-center gap-3">
            <i class="bi bi-receipt icon-20 text-rose-400"></i>
            <span>Administrar Categorías (Cafeteria)</span>
          </a>

          <a href="vistas/ver_pedidos.php"
            class="card-bloqueable block px-3 py-2 rounded-lg border border-slate-700/70 bg-slate-800/70 hover:bg-slate-700/60 flex items-center gap-3">
            <i class="bi bi-receipt icon-20 text-rose-400"></i>
            <span>Ver pedidos</span>
          </a>
          <a href="vistas/cocina.php"
            class="card-bloqueable block px-3 py-2 rounded-lg border border-slate-700/70 bg-slate-800/70 hover:bg-slate-700/60 flex items-center gap-3">
            <i class="bi bi-receipt icon-20 text-rose-400"></i>
            <span>Pedidos (Cocina)</span>
          </a>
        </div>
        
      </div>
      -->
      <!-- Sección Admin y Reportes -->
      <div class="mt-6">
        <button onclick="toggleAccordion('adminPanel')"
          class="w-full text-left text-sm font-semibold text-amber-300 bg-amber-900/40 px-4 py-3 rounded-lg hover:bg-amber-800/50 transition">
          🧑‍💼 Administración y Reportes
        </button>
        <div id="adminPanel" class="mt-3 space-y-2">
          <?php if ($rol === 'admin' || $rol === 'root'): ?>
            <a href="vistas/usuarios.php"
              class="card-bloqueable block px-3 py-2 rounded-lg border border-slate-700/70 bg-slate-800/70 hover:bg-slate-700/60 flex items-center gap-3">
              <i class="bi bi-people-fill icon-20 text-amber-400"></i><span>Administrar Usuarios</span>
            </a>
            <?php if ($rol === 'root'): ?>
              <a href="vistas/importar.php"
                class="card-bloqueable block px-3 py-2 rounded-lg border border-slate-700/70 bg-slate-800/70 hover:bg-slate-700/60 flex items-center gap-3">
                <i class="bi bi-database icon-20 text-amber-400"></i><span>Importar</span>
              </a>
            <?php endif; ?>
            <a onclick="modalSuscripcion()"
              class="block px-3 py-2 rounded-lg border border-slate-700/70 bg-slate-800/70 hover:bg-slate-700/60 flex items-center gap-3 cursor-pointer">
              <i class="bi bi-key icon-20 text-amber-400"></i><span>Administrar Suscripción</span>
            </a>
          <?php endif; ?>
          <a onclick="modalBranding()"
            class="block px-3 py-2 rounded-lg border border-slate-700/70 bg-slate-800/70 hover:bg-slate-700/60 flex items-center gap-3 cursor-pointer">
            <i class="bi bi-brush icon-20 text-amber-400"></i>
            <span>Configuración de Marca</span>
          </a>
          <a href="vistas/reportes.php"
            class="card-bloqueable block px-3 py-2 rounded-lg border border-slate-700/70 bg-slate-800/70 hover:bg-slate-700/60 flex items-center gap-3">
            <i class="bi bi-file-text icon-20 text-amber-400"></i><span>Ver Reportes</span>
          </a>
        </div>


      </div>

      <!-- Botones inferiores -->
      <div class="mt-auto pt-6 space-y-2">
        <a href="php/logout.php"
          class="flex items-center justify-center gap-2 bg-rose-500 hover:bg-rose-600 text-white px-4 py-3 rounded-xl shadow">
          <i class="bi bi-box-arrow-right text-lg"></i>
          <span class="font-semibold">Salir</span>
        </a>
        <a href="https://wa.me/5214451533504?text=Hola,%20necesito%20soporte%20del%20Gym%20Sport%20Fitness"
          target="_blank"
          class="flex items-center justify-center gap-2 bg-green-500 hover:bg-green-600 text-white px-4 py-3 rounded-xl shadow">
          <i class="bi bi-whatsapp text-lg"></i>
          <span class="font-semibold">Soporte</span>
        </a>
      </div>
    </aside>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="flex-1 min-w-0 px-4 md:px-8 py-20 md:py-10">
      <div class="max-w-7xl mx-auto xl:pr-24">

        <!-- Encabezado -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6 mb-8">

          <div class="flex items-center gap-4">
            <img id="sidebarLogoImg" src="img/logo.webp"
              class="h-24 w-24 rounded-full object-cover border border-slate-700 shadow-lg" alt="logo">

            <div>
              <p class="text-sm text-slate-400">Panel principal</p>
              <h1 id="sidebarAppName" class="font-bold text-slate-100 text-2xl md:text-3xl">
                Smartgate POS
              </h1>
              <p class="text-sm text-slate-400 mt-1">
                Resumen rápido del punto de venta
              </p>
            </div>
          </div>

          <!-- Usuario -->
          <div
            class="bg-slate-800/80 text-white px-4 py-2 rounded-full shadow-xl border border-white/10 backdrop-blur flex items-center gap-2 self-start lg:self-auto">
            <i class="bi bi-person-circle text-xl text-green-400"></i>
            <span class="font-semibold"><?php echo $_SESSION['usuario']['nombre']; ?></span>
          </div>
        </div>

        <!-- Filtro global de usuario -->
        <div class="mb-5 flex items-center gap-3">
          <label for="sel-usuario-global" class="text-slate-300">Usuario:</label>
          <select id="sel-usuario-global" class="bg-slate-800 text-slate-100 border border-slate-600 rounded px-3 py-2">
            <!-- Se llena por JS -->
          </select>
        </div>
        <!-- Accesos rápidos flotantes -->
        <div class="acciones-flotantes-pos">

          <!-- Nueva venta -->
          <button type="button" class="accion-flotante-pos accion-venta" data-pos-modal-title="Nueva venta"
            data-pos-modal-url="vistas/pagos-productos.php">
            <span class="accion-tooltip">Nueva venta</span>
            <span class="accion-circulo">
              <i class="bi bi-cart-plus"></i>
            </span>
          </button>

          <!-- Ver ventas -->
          <button type="button" class="accion-flotante-pos accion-lista" data-pos-modal-title="Lista de ventas"
            data-pos-modal-url="vistas/lista-pagos.php">
            <span class="accion-tooltip">Ver ventas</span>
            <span class="accion-circulo">
              <i class="bi bi-receipt"></i>
            </span>
          </button>

          <!-- Productos -->
          <button type="button" class="accion-flotante-pos accion-productos"
            data-pos-modal-title="Administrar productos" data-pos-modal-url="vistas/admin-productos.php">
            <span class="accion-tooltip">Productos</span>
            <span class="accion-circulo">
              <i class="bi bi-box-seam"></i>
            </span>
          </button>
          <!-- Ventas financiadas -->
          <button type="button" class="accion-flotante-pos accion-financiadas" data-pos-modal-title="Ventas financiadas"
            data-pos-modal-url="vistas/ventas-financiadas.php">
            <span class="accion-tooltip">Financiadas</span>
            <span class="accion-circulo">
              <i class="bi bi-credit-card-2-front"></i>
            </span>
          </button>
        </div>
        <!-- KPIs principales -->
        <section id="kpis" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">

          <!-- Caja -->
          <article id="card-caja" class="rounded-2xl border border-slate-700 bg-slate-800/70 p-5 shadow">
            <div class="flex items-center justify-between">
              <h3 class="font-semibold text-slate-300">Caja actual</h3>
              <i class="bi bi-safe2 icon-20 text-emerald-400"></i>
            </div>

            <p id="kpi-caja-monto" class="mt-3 text-4xl font-extrabold">—</p>
            <small id="kpi-caja-actualizado" class="block text-slate-400">—</small>

            <div class="mt-4">
              <button id="btn-caja-editar"
                class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white shadow disabled:opacity-50 disabled:cursor-not-allowed">
                Editar monto
              </button>
            </div>
          </article>

          <!-- Ventas de hoy -->
          <article class="rounded-2xl border border-slate-700 bg-slate-800/70 p-5 shadow">
            <div class="flex items-center justify-between">
              <h3 class="font-semibold text-slate-300">Ventas de hoy</h3>
              <i class="bi bi-currency-dollar icon-20 text-indigo-400"></i>
            </div>

            <p id="kpi-ventas" class="mt-3 text-4xl font-extrabold">—</p>
            <small id="kpi-ventas-det" class="text-slate-400"></small>
          </article>

          <!-- Stock bajo -->
          <article class="rounded-2xl border border-slate-700 bg-slate-800/70 p-5 shadow flex flex-col">
            <div class="flex items-center justify-between mb-3">
              <div>
                <h3 class="font-semibold text-slate-300">Stock bajo</h3>
                <span class="text-xs text-slate-400">Umbral: ≤ 5</span>
              </div>
              <i class="bi bi-exclamation-triangle icon-20 text-red-400"></i>
            </div>

            <ul id="lista-stock-bajo"
              class="space-y-2 overflow-y-auto pr-1 flex-1 min-h-[100px] max-h-[180px] scrollbar-custom">
              <!-- Items los llena JS -->
            </ul>

            <div id="stock-bajo-footer" class="mt-3 text-xs text-slate-400"></div>

            <a href="vistas/admin-productos.php"
              class="mt-4 inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm transition">
              <i class="bi bi-box-seam"></i>
              Ver inventario
            </a>
          </article>

          <!-- Producto más vendido -->
          <article class="rounded-2xl border border-slate-700 bg-slate-800/70 p-5 shadow">
            <div class="flex items-center justify-between">
              <h3 class="font-semibold text-slate-300">Más vendido hoy</h3>
              <i class="bi bi-trophy icon-20 text-amber-400"></i>
            </div>

            <p id="kpi-producto-top" class="mt-3 text-2xl font-extrabold leading-tight">—</p>
            <small id="kpi-producto-top-det" class="block text-slate-400 mt-2">—</small>
          </article>

        </section>

        <!-- Segunda fila -->
        <section class="mt-8 grid grid-cols-1 xl:grid-cols-3 gap-6">

          <!-- Movimientos de caja -->
          <?php if ($rol !== 'root'): ?>
            <article id="card-caja-mov" class="rounded-2xl border border-slate-700 bg-slate-800/70 p-5 shadow">
              <div class="flex items-center justify-between">
                <h3 class="font-semibold text-slate-300">Movimientos de caja</h3>
                <i class="bi bi-arrow-left-right icon-20 text-sky-400"></i>
              </div>

              <p id="kpi-mov-neto" class="mt-3 text-4xl font-extrabold">—</p>
              <small id="kpi-mov-det" class="block text-slate-400">—</small>

              <div class="mt-4 flex flex-wrap items-center gap-2">
                <button id="btn-mov-nuevo"
                  class="px-4 py-2 rounded-lg bg-sky-600 hover:bg-sky-700 text-white shadow disabled:opacity-50 disabled:cursor-not-allowed">
                  <i class="bi bi-plus-circle mr-1"></i> Nuevo movimiento
                </button>

                <button id="btn-mov-ver"
                  class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white shadow disabled:opacity-50 disabled:cursor-not-allowed">
                  <i class="bi bi-list-ul mr-1"></i> Ver movimientos
                </button>
              </div>
            </article>
          <?php endif; ?>

          <!-- Últimas ventas -->
          <article class="rounded-2xl border border-slate-700 bg-slate-800/70 p-5 shadow">
            <div class="flex items-center justify-between gap-3 mb-4">
              <div>
                <h3 class="font-semibold text-slate-300">Últimas ventas</h3>
                <p class="text-xs text-slate-400">Movimientos recientes del punto de venta</p>
              </div>

              <a href="vistas/lista-pagos.php"
                class="px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm transition">
                Ver todas
              </a>
            </div>

            <div id="ultimas-ventas" class="space-y-3">
              <div class="rounded-xl border border-slate-700 bg-slate-900/50 p-4 text-slate-400 text-sm">
                Cargando últimas ventas...
              </div>
            </div>
          </article>
          <!-- Próximos pagos financiados -->
          <article id="card-pagos-financiados"
            class="rounded-2xl border border-slate-700 bg-slate-800/70 p-5 shadow h-full flex flex-col">

            <div class="flex items-start justify-between gap-3 mb-3">
              <div>
                <h3 class="font-semibold text-slate-300 flex items-center gap-2">
                  Próximos pagos
                  <span id="pagos-financiados-count"
                    class="inline-flex items-center justify-center min-w-6 h-6 px-2 rounded-full bg-amber-500/20 text-amber-300 text-xs font-bold border border-amber-500/30">
                    0
                  </span>
                </h3>
                <p class="text-xs text-slate-400 mt-1">
                  Ventas financiadas dentro de ±5 días
                </p>
              </div>

              <i class="bi bi-calendar2-week icon-20 text-amber-400"></i>
            </div>

            <div class="grid grid-cols-2 gap-3 mb-4">
              <div class="rounded-xl bg-slate-900/60 border border-slate-700 p-3">
                <p class="text-xs text-slate-400">Disponible</p>
                <p id="pagos-financiados-disponible" class="text-lg font-extrabold text-white mt-1">
                  $0.00
                </p>
              </div>

              <div class="rounded-xl bg-slate-900/60 border border-slate-700 p-3">
                <p class="text-xs text-slate-400">Vencidos</p>
                <p id="pagos-financiados-vencidos" class="text-lg font-extrabold text-red-300 mt-1">
                  0
                </p>
              </div>
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto pr-1 scrollbar-custom">
              <ul id="lista-pagos-financiados" class="space-y-2">
                <li class="rounded-xl border border-slate-700 bg-slate-900/50 p-3 text-sm text-slate-400 text-center">
                  Cargando pagos próximos...
                </li>
              </ul>
            </div>

            <div class="mt-4 flex items-center justify-between gap-3">
              <small id="pagos-financiados-footer" class="text-xs text-slate-400">
                —
              </small>

              <button type="button" data-pos-modal-title="Ventas financiadas"
                data-pos-modal-url="vistas/ventas-financiadas.php"
                class="px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-xs transition whitespace-nowrap">
                Ver todos
              </button>
            </div>
          </article>
        </section>

        <!-- Gráfica de ventas -->
        <section class="mt-8 grid grid-cols-1 gap-6">

          <article class="rounded-2xl border border-slate-700 bg-slate-800/70 p-5 shadow">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
              <div>
                <h3 class="font-semibold text-slate-300">Ventas de productos</h3>
                <p class="text-xs text-slate-400">Resumen visual del comportamiento de ventas</p>
              </div>

              <div class="flex items-center gap-2">
                <select id="res-prod" class="bg-slate-700/70 border border-slate-600 rounded-lg px-3 py-2 text-sm">
                  <option value="dia">Día</option>
                  <option value="semana">Semana</option>
                  <option value="mes" selected>Mes</option>
                </select>
              </div>
            </div>

            <canvas id="chart-prod" height="160"></canvas>
          </article>

        </section>

      </div>
    </main>
  </div>
  <!-- Modal: Abono rápido financiado -->
  <div id="modal-abono-financiado-dashboard"
    class="hidden fixed inset-0 z-[100000] bg-black/70 items-center justify-center px-4 py-8">

    <div class="w-full max-w-md rounded-3xl border border-slate-700 bg-slate-800 shadow-2xl overflow-hidden">

      <div class="px-5 py-4 border-b border-slate-700 flex items-center justify-between">
        <div>
          <h2 class="text-xl font-bold text-white">Registrar abono</h2>
          <p id="dash-abono-subtitulo" class="text-sm text-slate-400">
            Pago de venta financiada
          </p>
        </div>

        <button id="btn-cerrar-abono-financiado-dashboard"
          class="w-10 h-10 rounded-xl bg-slate-700 hover:bg-slate-600 text-white">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>

      <div class="p-5 space-y-4">

        <input type="hidden" id="dash-abono-venta-id">
        <input type="hidden" id="dash-abono-cuota-id">
        <input type="hidden" id="dash-abono-saldo-max">

        <div class="rounded-xl bg-slate-900/60 border border-slate-700 p-4">
          <p class="text-xs text-slate-400">Saldo disponible para pagar</p>
          <p id="dash-abono-saldo-texto" class="text-2xl font-extrabold text-amber-300 mt-1">
            $0.00
          </p>
        </div>

        <div>
          <label for="dash-abono-monto" class="block text-sm font-medium text-slate-300 mb-1">
            Monto
          </label>
          <input type="number" id="dash-abono-monto" step="0.01" min="0"
            class="w-full bg-slate-700 text-white border border-slate-600 rounded-xl px-4 py-3 focus:ring-blue-400 focus:border-blue-400">
        </div>

        <div>
          <label for="dash-abono-metodo" class="block text-sm font-medium text-slate-300 mb-1">
            Método de pago
          </label>
          <select id="dash-abono-metodo"
            class="w-full bg-slate-700 text-white border border-slate-600 rounded-xl px-4 py-3 focus:ring-blue-400 focus:border-blue-400">
            <option value="efectivo">Efectivo</option>
            <option value="tarjeta">Tarjeta</option>
            <option value="transferencia">Transferencia</option>
            <option value="otro">Otro</option>
          </select>
        </div>

        <div>
          <label for="dash-abono-referencia" class="block text-sm font-medium text-slate-300 mb-1">
            Referencia
          </label>
          <input type="text" id="dash-abono-referencia" placeholder="Opcional"
            class="w-full bg-slate-700 text-white border border-slate-600 rounded-xl px-4 py-3 focus:ring-blue-400 focus:border-blue-400">
        </div>

        <div>
          <label for="dash-abono-observaciones" class="block text-sm font-medium text-slate-300 mb-1">
            Observaciones
          </label>
          <textarea id="dash-abono-observaciones" rows="2"
            class="w-full bg-slate-700 text-white border border-slate-600 rounded-xl px-4 py-3 focus:ring-blue-400 focus:border-blue-400"></textarea>
        </div>

        <div class="flex gap-3 justify-end pt-2">
          <button id="btn-cancelar-abono-financiado-dashboard"
            class="px-4 py-2 rounded-xl bg-slate-700 hover:bg-slate-600 text-white">
            Cancelar
          </button>

          <button id="btn-guardar-abono-financiado-dashboard"
            class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold">
            Guardar abono
          </button>
        </div>

      </div>
    </div>
  </div>
  <!-- Modal rápido POS -->
  <div id="modal-pos-rapido" class="fixed inset-0 z-[99999] hidden bg-black/70 backdrop-blur-sm">

    <div class="h-full w-full flex items-center justify-center p-3 md:p-6">

      <div
        class="w-full max-w-7xl h-[92vh] bg-slate-950 border border-slate-700 rounded-2xl shadow-2xl overflow-hidden flex flex-col">

        <!-- Header modal -->
        <div class="flex items-center justify-between gap-3 px-4 md:px-6 py-4 border-b border-slate-700 bg-slate-900">

          <div class="min-w-0">
            <h2 id="modal-pos-title" class="text-lg md:text-xl font-bold text-white truncate">
              Ventana rápida
            </h2>
            <p class="text-xs text-slate-400">
              Puedes trabajar aquí sin salir del dashboard
            </p>
          </div>

          <div class="flex items-center gap-2">

            <button type="button" id="btn-modal-pos-recargar"
              class="h-10 w-10 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-600 text-slate-200 flex items-center justify-center transition"
              title="Recargar">
              <i class="bi bi-arrow-clockwise"></i>
            </button>

            <button type="button" id="btn-modal-pos-abrir"
              class="h-10 w-10 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-600 text-slate-200 flex items-center justify-center transition"
              title="Abrir en ventana completa">
              <i class="bi bi-box-arrow-up-right"></i>
            </button>

            <button type="button" id="btn-modal-pos-cerrar"
              class="h-10 w-10 rounded-xl bg-rose-600 hover:bg-rose-700 text-white flex items-center justify-center transition"
              title="Cerrar">
              <i class="bi bi-x-lg"></i>
            </button>

          </div>
        </div>

        <!-- Contenido -->
        <div class="relative flex-1 bg-slate-950">

          <div id="modal-pos-loading" class="absolute inset-0 z-10 flex items-center justify-center bg-slate-950">
            <div class="text-center">
              <div class="h-10 w-10 mx-auto rounded-full border-4 border-slate-700 border-t-indigo-400 animate-spin">
              </div>
              <p class="mt-3 text-sm text-slate-400">Cargando ventana...</p>
            </div>
          </div>

          <iframe id="modal-pos-frame" src="" class="w-full h-full border-0 bg-slate-950" loading="lazy">
          </iframe>

        </div>

      </div>

    </div>
  </div>

  <!-- SCRIPTS -->
  <script src="js/sweetalert2@11.js"></script>
  <script src="js/chart.js@4.4.1"></script>
  <script src="js/swalConfig.js"></script>
  <script src="js/dashboard-pos.js"></script> <!-- KPIs y gráfica -->
  <script src="js/tools-eventservice.js"></script>

</body>

</html>