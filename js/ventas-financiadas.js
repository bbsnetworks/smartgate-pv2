/* =========================================================
   VENTAS FINANCIADAS - SMARTGATE POS
========================================================= */

const API_VENTAS_FINANCIADAS = '../php/ventas_financiadas_controller.php';

let productosFinanciados = [];
let productoSeleccionado = null;

let paginaActual = 1;
let limitePagina = 20;
let ultimaVentaDetalle = null;

/* =========================================================
   HELPERS
========================================================= */

const $ = (id) => document.getElementById(id);

function money(value) {
  const number = Number(value || 0);

  return number.toLocaleString('es-MX', {
    style: 'currency',
    currency: 'MXN'
  });
}

function numberValue(id) {
  const el = $(id);
  if (!el) return 0;

  const value = String(el.value || '').replace(/[$,\s]/g, '');
  const number = Number(value);

  return Number.isFinite(number) ? number : 0;
}

function intValue(id) {
  const el = $(id);
  if (!el) return 0;

  const value = parseInt(el.value, 10);

  return Number.isFinite(value) ? value : 0;
}

function textValue(id) {
  const el = $(id);
  return el ? String(el.value || '').trim() : '';
}

function setValue(id, value) {
  const el = $(id);
  if (el) el.value = value;
}

function setText(id, value) {
  const el = $(id);
  if (el) el.textContent = value;
}

function escapeHTML(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function formatDate(dateString) {
  if (!dateString) return '-';

  const date = new Date(String(dateString) + 'T00:00:00');

  if (Number.isNaN(date.getTime())) return dateString;

  return date.toLocaleDateString('es-MX', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit'
  });
}

function formatDateTime(dateString) {
  if (!dateString) return '-';

  const date = new Date(String(dateString).replace(' ', 'T'));

  if (Number.isNaN(date.getTime())) return dateString;

  return date.toLocaleString('es-MX', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit'
  });
}

function estadoBadge(estado) {
  const map = {
    activa: 'bg-blue-500/20 text-blue-300 border-blue-500/30',
    vencida: 'bg-red-500/20 text-red-300 border-red-500/30',
    liquidada: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
    cancelada: 'bg-slate-500/20 text-slate-300 border-slate-500/30',
    pendiente: 'bg-amber-500/20 text-amber-300 border-amber-500/30',
    parcial: 'bg-purple-500/20 text-purple-300 border-purple-500/30',
    pagada: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30'
  };

  const cls = map[estado] || 'bg-slate-500/20 text-slate-300 border-slate-500/30';

  return `
    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold border ${cls}">
      ${escapeHTML(estado || '-')}
    </span>
  `;
}

function openModal(id) {
  const modal = $(id);
  if (!modal) return;

  modal.classList.remove('hidden');
  modal.classList.add('flex');

  document.body.classList.add('overflow-hidden');

  if (window.lucide) {
    lucide.createIcons();
  }
}

function closeModal(id) {
  const modal = $(id);
  if (!modal) return;

  modal.classList.add('hidden');
  modal.classList.remove('flex');

  const abiertos = document.querySelectorAll('.vf-modal-overlay.flex');

  if (!abiertos.length) {
    document.body.classList.remove('overflow-hidden');
  }
}

async function apiRequest(params = {}) {
  const formData = new FormData();

  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === null) return;
    formData.append(key, value);
  });

  const response = await fetch(API_VENTAS_FINANCIADAS, {
    method: 'POST',
    body: formData
  });

  const text = await response.text();

  let data;

  try {
    data = JSON.parse(text);
  } catch (error) {
    throw new Error('Respuesta inválida del servidor: ' + text);
  }

  if (!response.ok || !data.success) {
    throw new Error(data.detalle || data.error || 'Ocurrió un error.');
  }

  return data;
}

/* =========================================================
   INIT
========================================================= */

document.addEventListener('DOMContentLoaded', () => {
  inicializarEventos();
  inicializarFechaPrimerPago();
  calcularResumenFinanciamiento();
  listarVentasFinanciadas();

  if (window.lucide) {
    lucide.createIcons();
  }
});

function inicializarEventos() {
  $('btnAbrirNuevaVenta')?.addEventListener('click', abrirNuevaVenta);
  $('btnCerrarNuevaVenta')?.addEventListener('click', () => closeModal('modalNuevaVenta'));
  $('btnCancelarNuevaVenta')?.addEventListener('click', () => closeModal('modalNuevaVenta'));

  $('btnRecargarVentas')?.addEventListener('click', () => {
    paginaActual = 1;
    listarVentasFinanciadas();
  });

  $('btnBuscarVentas')?.addEventListener('click', () => {
    paginaActual = 1;
    listarVentasFinanciadas();
  });

  $('filtroBusqueda')?.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter') {
      paginaActual = 1;
      listarVentasFinanciadas();
    }
  });

  $('filtroEstado')?.addEventListener('change', () => {
    paginaActual = 1;
    listarVentasFinanciadas();
  });

  $('btnPaginaAnterior')?.addEventListener('click', () => {
    if (paginaActual > 1) {
      paginaActual--;
      listarVentasFinanciadas();
    }
  });

  $('btnPaginaSiguiente')?.addEventListener('click', () => {
    paginaActual++;
    listarVentasFinanciadas();
  });

  $('buscarProductoFinanciado')?.addEventListener('input', debounce(buscarProductos, 350));
  $('btnAgregarProductoFinanciado')?.addEventListener('click', agregarProductoFinanciado);

  $('productoCantidad')?.addEventListener('input', calcularResumenFinanciamiento);
  $('productoPrecio')?.addEventListener('input', calcularResumenFinanciamiento);
  $('mesesFinanciamiento')?.addEventListener('input', calcularResumenFinanciamiento);
  $('comisionPorcentaje')?.addEventListener('input', calcularResumenFinanciamiento);
  $('engancheFinanciamiento')?.addEventListener('input', calcularResumenFinanciamiento);

  $('btnGuardarVentaFinanciada')?.addEventListener('click', guardarVentaFinanciada);

  $('btnCerrarDetalle')?.addEventListener('click', () => closeModal('modalDetalleVenta'));

  $('btnAbrirAbonoDesdeDetalle')?.addEventListener('click', () => {
    const ventaId = intValue('detalleVentaId');
    const saldoActual = Number(ultimaVentaDetalle?.venta?.saldo_actual || 0);

    abrirModalAbono(ventaId, 0, saldoActual);
  });

  $('btnCerrarAbono')?.addEventListener('click', () => closeModal('modalAbono'));
  $('btnCancelarAbono')?.addEventListener('click', () => closeModal('modalAbono'));
  $('btnGuardarAbono')?.addEventListener('click', guardarAbono);

  $('abonoMonto')?.addEventListener('input', validarMontoAbonoVisual);

  document.addEventListener('click', (ev) => {
    const resultadosProductos = $('resultadosProductos');

    if (
      resultadosProductos &&
      !resultadosProductos.contains(ev.target) &&
      ev.target !== $('buscarProductoFinanciado')
    ) {
      resultadosProductos.classList.add('hidden');
    }
  });
}

function debounce(fn, delay = 300) {
  let timer = null;

  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), delay);
  };
}

function inicializarFechaPrimerPago() {
  const input = $('fechaPrimerPago');
  if (!input) return;

  const hoy = new Date();
  hoy.setMonth(hoy.getMonth() + 1);

  input.value = hoy.toISOString().slice(0, 10);
}

/* =========================================================
   NUEVA VENTA
========================================================= */

function abrirNuevaVenta() {
  limpiarFormularioNuevaVenta();
  openModal('modalNuevaVenta');
}

function limpiarFormularioNuevaVenta() {
  productosFinanciados = [];
  productoSeleccionado = null;

  setValue('clienteNombre', '');
  setValue('clienteTelefono', '');
  setValue('clienteEmail', '');
  setValue('clienteDireccion', '');
  setValue('clienteOrigen', 'manual');
  setValue('clienteReferencia', '');

  setValue('buscarProductoFinanciado', '');
  setValue('productoCantidad', 1);
  setValue('productoPrecio', '');

  limpiarSeleccionProducto();

  setValue('mesesFinanciamiento', 1);
  setValue('comisionPorcentaje', 0);
  setValue('engancheFinanciamiento', 0);
  setValue('metodoEnganche', 'efectivo');
  setValue('observacionesFinanciamiento', '');

  inicializarFechaPrimerPago();

  renderProductosFinanciados();
  calcularResumenFinanciamiento();
}

/* =========================================================
   PRODUCTOS POS
========================================================= */

async function buscarProductos() {
  const input = $('buscarProductoFinanciado');
  const contenedor = $('resultadosProductos');

  if (!input || !contenedor) return;

  const q = input.value.trim();

  productoSeleccionado = null;
  limpiarSeleccionProducto(false);

  if (q.length < 2) {
    contenedor.classList.add('hidden');
    contenedor.innerHTML = '';
    return;
  }

  try {
    const data = await apiRequest({
      accion: 'buscar_productos',
      q
    });

    renderResultadosProductos(data.productos || []);
  } catch (error) {
    contenedor.classList.remove('hidden');
    contenedor.innerHTML = `
      <div class="px-4 py-3 text-sm text-red-300">
        ${escapeHTML(error.message)}
      </div>
    `;
  }
}

function renderResultadosProductos(productos) {
  const contenedor = $('resultadosProductos');

  if (!contenedor) return;

  if (!productos.length) {
    contenedor.classList.remove('hidden');
    contenedor.innerHTML = `
      <div class="px-4 py-3 text-sm text-slate-400">
        No se encontraron productos disponibles.
      </div>
    `;
    return;
  }

  contenedor.classList.remove('hidden');

  contenedor.innerHTML = productos.map((producto) => {
    const stock = Number(producto.stock || 0);
    const precio = Number(producto.precio_venta || producto.precio || 0);
    const costo = Number(producto.costo_unitario || producto.precio_proveedor || 0);

    return `
      <button type="button"
        class="w-full text-left px-4 py-3 hover:bg-slate-800 border-b border-slate-800 last:border-b-0"
        onclick='seleccionarProductoFinanciado(${JSON.stringify(producto).replaceAll("'", '&#039;')})'>
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="font-bold text-white">
              ${escapeHTML(producto.nombre || '-')}
            </p>
            <p class="text-xs text-slate-400 mt-0.5">
              Código: ${escapeHTML(producto.codigo || '-')}
            </p>
            <p class="text-xs text-blue-300 mt-0.5">
              Propietario: ${escapeHTML(producto.propietario_nombre || '-')}
            </p>
          </div>

          <div class="text-right shrink-0">
            <p class="font-bold text-emerald-300">${money(precio)}</p>
            <p class="text-xs text-slate-400">Costo: ${money(costo)}</p>
            <p class="text-xs ${stock > 0 ? 'text-amber-300' : 'text-red-300'}">
              Stock: ${stock}
            </p>
          </div>
        </div>
      </button>
    `;
  }).join('');
}

function seleccionarProductoFinanciado(producto) {
  productoSeleccionado = producto;

  const precio = Number(producto.precio_venta || producto.precio || 0);
  const stock = Number(producto.stock || 0);

  setValue('productoSeleccionadoId', producto.producto_id || '');
  setValue('productoSeleccionadoInventarioId', producto.inventario_usuario_id || '');
  setValue('productoSeleccionadoPropietarioId', producto.usuario_propietario_id || '');
  setValue('productoSeleccionadoCodigo', producto.codigo || '');
  setValue('productoSeleccionadoNombre', producto.nombre || '');
  setValue('productoSeleccionadoStock', stock);
  setValue('productoPrecio', precio.toFixed(2));
  setValue('productoCantidad', 1);
  setValue('buscarProductoFinanciado', producto.nombre || '');

  const contenedor = $('resultadosProductos');
  if (contenedor) {
    contenedor.classList.add('hidden');
    contenedor.innerHTML = '';
  }

  const info = $('productoSeleccionadoInfo');

  if (info) {
    info.classList.remove('hidden');
    info.innerHTML = `
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
        <div>
          <p class="font-semibold text-white">${escapeHTML(producto.nombre || '-')}</p>
          <p class="text-xs text-slate-400">
            Código: ${escapeHTML(producto.codigo || '-')}
            · Propietario: <span class="text-blue-300">${escapeHTML(producto.propietario_nombre || '-')}</span>
          </p>
        </div>
        <div class="text-sm">
          <span class="text-emerald-300 font-bold">${money(precio)}</span>
          <span class="text-slate-500 mx-1">|</span>
          <span class="text-amber-300">Stock: ${stock}</span>
        </div>
      </div>
    `;
  }

  calcularResumenFinanciamiento();
}

function limpiarSeleccionProducto(limpiarInput = true) {
  productoSeleccionado = null;

  setValue('productoSeleccionadoId', '');
  setValue('productoSeleccionadoInventarioId', '');
  setValue('productoSeleccionadoPropietarioId', '');
  setValue('productoSeleccionadoCodigo', '');
  setValue('productoSeleccionadoNombre', '');
  setValue('productoSeleccionadoStock', 0);

  if (limpiarInput) {
    setValue('buscarProductoFinanciado', '');
    setValue('productoCantidad', 1);
    setValue('productoPrecio', '');
  }

  const info = $('productoSeleccionadoInfo');

  if (info) {
    info.classList.add('hidden');
    info.innerHTML = '';
  }
}

function agregarProductoFinanciado() {
  if (!productoSeleccionado) {
    Swal.fire({
      icon: 'warning',
      title: 'Selecciona un producto',
      text: 'Busca y selecciona un producto del listado.',
      background: '#1e293b',
      color: '#f8fafc'
    });
    return;
  }

  const productoId = Number(productoSeleccionado.producto_id || 0);
  const inventarioUsuarioId = Number(productoSeleccionado.inventario_usuario_id || 0);
  const usuarioPropietarioId = Number(productoSeleccionado.usuario_propietario_id || 0);
  const cantidad = intValue('productoCantidad');
  const precioUnitario = numberValue('productoPrecio');
  const stock = Number(productoSeleccionado.stock || 0);

  if (productoId <= 0 || inventarioUsuarioId <= 0 || usuarioPropietarioId <= 0) {
    Swal.fire({
      icon: 'error',
      title: 'Producto inválido',
      text: 'Vuelve a seleccionar el producto desde el buscador.',
      background: '#1e293b',
      color: '#f8fafc'
    });
    return;
  }

  if (stock <= 0) {
    Swal.fire({
      icon: 'warning',
      title: 'Sin stock',
      text: 'Este producto no tiene stock disponible.',
      background: '#1e293b',
      color: '#f8fafc'
    });
    return;
  }

  if (cantidad <= 0) {
    Swal.fire({
      icon: 'warning',
      title: 'Cantidad inválida',
      text: 'La cantidad debe ser mayor a 0.',
      background: '#1e293b',
      color: '#f8fafc'
    });
    return;
  }

  if (cantidad > stock) {
    Swal.fire({
      icon: 'warning',
      title: 'Stock insuficiente',
      text: `Solo hay ${stock} pieza(s) disponibles de este propietario.`,
      background: '#1e293b',
      color: '#f8fafc'
    });
    return;
  }

  if (precioUnitario <= 0) {
    Swal.fire({
      icon: 'warning',
      title: 'Precio inválido',
      text: 'El precio debe ser mayor a 0.',
      background: '#1e293b',
      color: '#f8fafc'
    });
    return;
  }

  const existente = productosFinanciados.find(
    item => Number(item.inventario_usuario_id) === inventarioUsuarioId
  );

  if (existente) {
    const nuevaCantidad = Number(existente.cantidad) + cantidad;

    if (nuevaCantidad > stock) {
      Swal.fire({
        icon: 'warning',
        title: 'Stock insuficiente',
        text: `Ya tienes agregado este producto. La cantidad total no puede superar ${stock}.`,
        background: '#1e293b',
        color: '#f8fafc'
      });
      return;
    }

    existente.cantidad = nuevaCantidad;
    existente.precio_unitario = precioUnitario;
    existente.subtotal = Number((nuevaCantidad * precioUnitario).toFixed(2));
  } else {
    productosFinanciados.push({
      producto_id: productoId,
      inventario_usuario_id: inventarioUsuarioId,
      usuario_propietario_id: usuarioPropietarioId,

      codigo: productoSeleccionado.codigo || '',
      marca: productoSeleccionado.marca || '',
      modelo: productoSeleccionado.modelo || '',
      nombre: productoSeleccionado.nombre || '',
      descripcion: productoSeleccionado.descripcion || '',
      propietario_nombre: productoSeleccionado.propietario_nombre || '',

      stock,
      cantidad,
      precio_unitario: precioUnitario,
      costo_unitario: Number(productoSeleccionado.costo_unitario || productoSeleccionado.precio_proveedor || 0),
      subtotal: Number((cantidad * precioUnitario).toFixed(2))
    });
  }

  limpiarSeleccionProducto();
  renderProductosFinanciados();
  calcularResumenFinanciamiento();
}

function renderProductosFinanciados() {
  const tbody = $('tbodyProductosFinanciados');

  if (!tbody) return;

  if (!productosFinanciados.length) {
    tbody.innerHTML = `
      <tr>
        <td colspan="7" class="px-4 py-6 text-center text-slate-400">
          No has agregado productos.
        </td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = productosFinanciados.map((item, index) => {
    const subtotal = Number(item.cantidad || 0) * Number(item.precio_unitario || 0);

    return `
      <tr class="hover:bg-slate-800/50">
        <td class="px-4 py-3">
          <p class="font-semibold text-white">${escapeHTML(item.nombre || '-')}</p>
          <p class="text-xs text-slate-400">Código: ${escapeHTML(item.codigo || '-')}</p>
        </td>

        <td class="px-4 py-3 text-blue-300">
          ${escapeHTML(item.propietario_nombre || '-')}
        </td>

        <td class="px-4 py-3 text-center text-amber-300">
          ${Number(item.stock || 0)}
        </td>

        <td class="px-4 py-3 text-center">
          <input type="number"
            min="1"
            max="${Number(item.stock || 0)}"
            value="${Number(item.cantidad || 1)}"
            class="w-24 text-center"
            onchange="actualizarCantidadProducto(${index}, this.value)">
        </td>

        <td class="px-4 py-3 text-right">
          <input type="number"
            step="0.01"
            min="0"
            value="${Number(item.precio_unitario || 0).toFixed(2)}"
            class="w-32 text-right"
            onchange="actualizarPrecioProducto(${index}, this.value)">
        </td>

        <td class="px-4 py-3 text-right font-bold text-emerald-300">
          ${money(subtotal)}
        </td>

        <td class="px-4 py-3 text-center">
          <button type="button"
            onclick="quitarProductoFinanciado(${index})"
            class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-red-500/20 text-red-300 hover:bg-red-500/30">
            <i data-lucide="trash-2" class="w-4 h-4"></i>
          </button>
        </td>
      </tr>
    `;
  }).join('');

  if (window.lucide) {
    lucide.createIcons();
  }
}

function actualizarCantidadProducto(index, value) {
  const item = productosFinanciados[index];
  if (!item) return;

  let cantidad = parseInt(value, 10);

  if (!Number.isFinite(cantidad) || cantidad <= 0) {
    cantidad = 1;
  }

  const stock = Number(item.stock || 0);

  if (cantidad > stock) {
    cantidad = stock;

    Swal.fire({
      icon: 'warning',
      title: 'Stock insuficiente',
      text: `Solo hay ${stock} pieza(s) disponibles.`,
      background: '#1e293b',
      color: '#f8fafc'
    });
  }

  item.cantidad = cantidad;
  item.subtotal = Number((cantidad * Number(item.precio_unitario || 0)).toFixed(2));

  renderProductosFinanciados();
  calcularResumenFinanciamiento();
}

function actualizarPrecioProducto(index, value) {
  const item = productosFinanciados[index];
  if (!item) return;

  let precio = Number(value);

  if (!Number.isFinite(precio) || precio <= 0) {
    precio = 0;
  }

  item.precio_unitario = precio;
  item.subtotal = Number((Number(item.cantidad || 0) * precio).toFixed(2));

  renderProductosFinanciados();
  calcularResumenFinanciamiento();
}

function quitarProductoFinanciado(index) {
  productosFinanciados.splice(index, 1);
  renderProductosFinanciados();
  calcularResumenFinanciamiento();
}

/* =========================================================
   RESUMEN
========================================================= */

function calcularResumenFinanciamiento() {
  const subtotal = productosFinanciados.reduce((acc, item) => {
    return acc + (Number(item.cantidad || 0) * Number(item.precio_unitario || 0));
  }, 0);

  const comisionPorcentaje = numberValue('comisionPorcentaje');
  const enganche = numberValue('engancheFinanciamiento');
  const meses = Math.max(1, intValue('mesesFinanciamiento'));

  const comision = subtotal * (comisionPorcentaje / 100);
  const total = subtotal + comision;
  const saldo = Math.max(0, total - enganche);
  const mensualidad = meses > 0 ? saldo / meses : 0;

  setText('resumenSubtotal', money(subtotal));
  setText('resumenComision', money(comision));
  setText('resumenTotal', money(total));
  setText('resumenEnganche', money(enganche));
  setText('resumenSaldo', money(saldo));
  setText('resumenMensualidad', money(mensualidad));
}

/* =========================================================
   GUARDAR VENTA
========================================================= */

async function guardarVentaFinanciada() {
  const clienteNombre = textValue('clienteNombre');
  const fechaPrimerPago = textValue('fechaPrimerPago');

  if (!clienteNombre) {
    Swal.fire({
      icon: 'warning',
      title: 'Falta cliente',
      text: 'Captura el nombre del cliente/persona.',
      background: '#1e293b',
      color: '#f8fafc'
    });
    return;
  }

  if (!productosFinanciados.length) {
    Swal.fire({
      icon: 'warning',
      title: 'Faltan productos',
      text: 'Agrega por lo menos un producto.',
      background: '#1e293b',
      color: '#f8fafc'
    });
    return;
  }

  for (const item of productosFinanciados) {
    if (!item.inventario_usuario_id || !item.producto_id) {
      Swal.fire({
        icon: 'error',
        title: 'Producto inválido',
        text: 'Hay un producto sin inventario seleccionado.',
        background: '#1e293b',
        color: '#f8fafc'
      });
      return;
    }

    if (Number(item.cantidad || 0) <= 0) {
      Swal.fire({
        icon: 'warning',
        title: 'Cantidad inválida',
        text: `Revisa la cantidad de ${item.nombre}.`,
        background: '#1e293b',
        color: '#f8fafc'
      });
      return;
    }

    if (Number(item.cantidad || 0) > Number(item.stock || 0)) {
      Swal.fire({
        icon: 'warning',
        title: 'Stock insuficiente',
        text: `La cantidad de ${item.nombre} supera el stock disponible.`,
        background: '#1e293b',
        color: '#f8fafc'
      });
      return;
    }

    if (Number(item.precio_unitario || 0) <= 0) {
      Swal.fire({
        icon: 'warning',
        title: 'Precio inválido',
        text: `Revisa el precio de ${item.nombre}.`,
        background: '#1e293b',
        color: '#f8fafc'
      });
      return;
    }
  }

  const meses = intValue('mesesFinanciamiento');

  if (meses <= 0) {
    Swal.fire({
      icon: 'warning',
      title: 'Meses inválidos',
      text: 'Los meses deben ser mayores a 0.',
      background: '#1e293b',
      color: '#f8fafc'
    });
    return;
  }

  if (!fechaPrimerPago) {
    Swal.fire({
      icon: 'warning',
      title: 'Falta fecha',
      text: 'Selecciona la fecha del primer pago.',
      background: '#1e293b',
      color: '#f8fafc'
    });
    return;
  }

  const subtotal = productosFinanciados.reduce((acc, item) => {
    return acc + (Number(item.cantidad || 0) * Number(item.precio_unitario || 0));
  }, 0);

  const comisionPorcentaje = numberValue('comisionPorcentaje');
  const enganche = numberValue('engancheFinanciamiento');
  const total = subtotal + (subtotal * (comisionPorcentaje / 100));

  if (enganche > total) {
    Swal.fire({
      icon: 'warning',
      title: 'Enganche inválido',
      text: 'El enganche no puede ser mayor al total financiado.',
      background: '#1e293b',
      color: '#f8fafc'
    });
    return;
  }

  const confirmacion = await Swal.fire({
    icon: 'question',
    title: 'Generar venta financiada',
    html: `
      <div class="text-left">
        <p>Cliente: <b>${escapeHTML(clienteNombre)}</b></p>
        <p>Total financiado: <b>${money(total)}</b></p>
        <p>Enganche: <b>${money(enganche)}</b></p>
        <p>Saldo: <b>${money(total - enganche)}</b></p>
      </div>
    `,
    showCancelButton: true,
    confirmButtonText: 'Sí, generar',
    cancelButtonText: 'Cancelar',
    background: '#1e293b',
    color: '#f8fafc',
    confirmButtonColor: '#2563eb',
    cancelButtonColor: '#475569'
  });

  if (!confirmacion.isConfirmed) return;

  const productosPayload = productosFinanciados.map(item => ({
    producto_id: Number(item.producto_id),
    inventario_usuario_id: Number(item.inventario_usuario_id),
    usuario_propietario_id: Number(item.usuario_propietario_id),
    cantidad: Number(item.cantidad),
    precio_unitario: Number(item.precio_unitario)
  }));

  try {
    const btn = $('btnGuardarVentaFinanciada');

    if (btn) {
      btn.disabled = true;
      btn.classList.add('opacity-60', 'cursor-not-allowed');
    }

    const data = await apiRequest({
      accion: 'crear_venta_financiada',
      cliente_nombre: clienteNombre,
      cliente_telefono: textValue('clienteTelefono'),
      cliente_email: textValue('clienteEmail'),
      cliente_direccion: textValue('clienteDireccion'),
      cliente_origen: 'manual',
      cliente_referencia: '',
      productos: JSON.stringify(productosPayload),
      meses,
      comision_porcentaje: comisionPorcentaje,
      enganche,
      metodo_enganche: textValue('metodoEnganche') || 'efectivo',
      fecha_primer_pago: fechaPrimerPago,
      observaciones: textValue('observacionesFinanciamiento')
    });

    await Swal.fire({
      icon: 'success',
      title: 'Venta creada',
      html: `
        <p>${escapeHTML(data.mensaje || 'Venta financiada creada correctamente.')}</p>
        <p class="mt-2 text-blue-300 font-bold">${escapeHTML(data.folio || '')}</p>
      `,
      background: '#1e293b',
      color: '#f8fafc'
    });

    closeModal('modalNuevaVenta');
    listarVentasFinanciadas();

    if (data.venta_id) {
      verDetalleVentaFinanciada(Number(data.venta_id));
    }

  } catch (error) {
    Swal.fire({
      icon: 'error',
      title: 'No se pudo guardar',
      text: error.message,
      background: '#1e293b',
      color: '#f8fafc'
    });
  } finally {
    const btn = $('btnGuardarVentaFinanciada');

    if (btn) {
      btn.disabled = false;
      btn.classList.remove('opacity-60', 'cursor-not-allowed');
    }
  }
}

/* =========================================================
   LISTAR VENTAS
========================================================= */

async function listarVentasFinanciadas() {
  const tbody = $('tbodyVentasFinanciadas');

  if (tbody) {
    tbody.innerHTML = `
      <tr>
        <td colspan="8" class="px-4 py-8 text-center text-slate-400">
          Cargando ventas...
        </td>
      </tr>
    `;
  }

  try {
    const data = await apiRequest({
      accion: 'listar_ventas_financiadas',
      q: textValue('filtroBusqueda'),
      estado: textValue('filtroEstado'),
      pagina: paginaActual,
      limite: limitePagina
    });

    renderVentasFinanciadas(data.ventas || []);
    actualizarCardsResumen(data.ventas || []);

    setText('textoPaginacion', `Página ${paginaActual}`);

    const btnAnterior = $('btnPaginaAnterior');
    const btnSiguiente = $('btnPaginaSiguiente');

    if (btnAnterior) btnAnterior.disabled = paginaActual <= 1;
    if (btnSiguiente) btnSiguiente.disabled = (data.ventas || []).length < limitePagina;

  } catch (error) {
    if (tbody) {
      tbody.innerHTML = `
        <tr>
          <td colspan="8" class="px-4 py-8 text-center text-red-300">
            ${escapeHTML(error.message)}
          </td>
        </tr>
      `;
    }
  }
}

function renderVentasFinanciadas(ventas) {
  const tbody = $('tbodyVentasFinanciadas');

  if (!tbody) return;

  if (!ventas.length) {
    tbody.innerHTML = `
      <tr>
        <td colspan="8" class="px-4 py-8 text-center text-slate-400">
          No hay ventas financiadas registradas.
        </td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = ventas.map((venta) => {
    return `
      <tr class="hover:bg-slate-800/50">
        <td class="px-4 py-3">
          <p class="font-bold text-white">${escapeHTML(venta.folio || '-')}</p>
        </td>

        <td class="px-4 py-3">
          <p class="font-semibold text-white">${escapeHTML(venta.cliente_nombre || '-')}</p>
          <p class="text-xs text-slate-400">${escapeHTML(venta.cliente_telefono || '')}</p>
        </td>

        <td class="px-4 py-3 text-right font-bold text-emerald-300">
          ${money(venta.total_financiado)}
        </td>

        <td class="px-4 py-3 text-right font-bold text-amber-300">
          ${money(venta.saldo_actual)}
        </td>

        <td class="px-4 py-3 text-center text-slate-300">
          ${Number(venta.meses || 0)}
        </td>

        <td class="px-4 py-3 text-center">
          ${estadoBadge(venta.estado)}
        </td>

        <td class="px-4 py-3 text-center text-slate-300">
          ${formatDateTime(venta.fecha_venta)}
        </td>

        <td class="px-4 py-3 text-center">
          <div class="flex items-center justify-center gap-2">
            <button type="button"
              onclick="verDetalleVentaFinanciada(${Number(venta.id)})"
              class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-blue-500/20 text-blue-300 hover:bg-blue-500/30"
              title="Ver detalle">
              <i data-lucide="eye" class="w-4 h-4"></i>
            </button>

            ${
              venta.estado !== 'cancelada' && venta.estado !== 'liquidada'
                ? `
                  <button type="button"
                    onclick="confirmarCancelarVentaFinanciada(${Number(venta.id)})"
                    class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-red-500/20 text-red-300 hover:bg-red-500/30"
                    title="Cancelar venta">
                    <i data-lucide="ban" class="w-4 h-4"></i>
                  </button>
                `
                : ''
            }
          </div>
        </td>
      </tr>
    `;
  }).join('');

  if (window.lucide) {
    lucide.createIcons();
  }
}

function actualizarCardsResumen(ventas) {
  let activas = 0;
  let vencidas = 0;
  let liquidadas = 0;
  let saldoPendiente = 0;

  ventas.forEach((venta) => {
    if (venta.estado === 'activa') activas++;
    if (venta.estado === 'vencida') vencidas++;
    if (venta.estado === 'liquidada') liquidadas++;

    if (venta.estado === 'activa' || venta.estado === 'vencida') {
      saldoPendiente += Number(venta.saldo_actual || 0);
    }
  });

  setText('cardActivas', activas);
  setText('cardVencidas', vencidas);
  setText('cardLiquidadas', liquidadas);
  setText('cardSaldoPendiente', money(saldoPendiente));
}

/* =========================================================
   DETALLE
========================================================= */

async function verDetalleVentaFinanciada(ventaId) {
  if (!ventaId) return;

  try {
    const data = await apiRequest({
      accion: 'obtener_detalle_financiamiento',
      id: ventaId
    });

    ultimaVentaDetalle = data;

    renderDetalleVenta(data.venta, data.detalle || [], data.cuotas || [], data.pagos || []);
    openModal('modalDetalleVenta');

  } catch (error) {
    Swal.fire({
      icon: 'error',
      title: 'No se pudo cargar detalle',
      text: error.message,
      background: '#1e293b',
      color: '#f8fafc'
    });
  }
}

function renderDetalleVenta(venta, detalle, cuotas, pagos) {
  setValue('detalleVentaId', venta.id || '');

  setText('detalleTitulo', `Financiamiento ${venta.folio || ''}`);
  setText(
    'detalleSubtitulo',
    `${venta.cliente_nombre || '-'} · Saldo actual ${money(venta.saldo_actual)}`
  );

  renderDetalleInfoGeneral(venta);
  renderDetalleProductos(detalle);
  renderDetalleCuotas(cuotas, venta);
  renderDetallePagos(pagos);

  if (window.lucide) {
    lucide.createIcons();
  }
}

function renderDetalleInfoGeneral(venta) {
  const contenedor = $('detalleInfoGeneral');

  if (!contenedor) return;

  contenedor.innerHTML = `
    <div class="rounded-2xl bg-slate-900/60 border border-slate-700 p-4">
      <p class="text-xs text-slate-400">Cliente</p>
      <h4 class="text-lg font-bold text-white mt-1">${escapeHTML(venta.cliente_nombre || '-')}</h4>
      <p class="text-xs text-slate-400 mt-1">${escapeHTML(venta.cliente_telefono || '')}</p>
    </div>

    <div class="rounded-2xl bg-slate-900/60 border border-slate-700 p-4">
      <p class="text-xs text-slate-400">Total financiado</p>
      <h4 class="text-lg font-bold text-emerald-300 mt-1">${money(venta.total_financiado)}</h4>
      <p class="text-xs text-slate-400 mt-1">Enganche: ${money(venta.enganche)}</p>
    </div>

    <div class="rounded-2xl bg-slate-900/60 border border-slate-700 p-4">
      <p class="text-xs text-slate-400">Saldo actual</p>
      <h4 class="text-lg font-bold text-amber-300 mt-1">${money(venta.saldo_actual)}</h4>
      <p class="text-xs text-slate-400 mt-1">${Number(venta.meses || 0)} meses · ${money(venta.monto_mensual)}</p>
    </div>

    <div class="rounded-2xl bg-slate-900/60 border border-slate-700 p-4">
      <p class="text-xs text-slate-400">Estado</p>
      <div class="mt-2">${estadoBadge(venta.estado)}</div>
      <p class="text-xs text-slate-400 mt-2">Primer pago: ${formatDate(venta.fecha_primer_pago)}</p>
    </div>
  `;
}

function renderDetalleProductos(detalle) {
  const tbody = $('tbodyDetalleProductos');

  if (!tbody) return;

  if (!detalle.length) {
    tbody.innerHTML = `
      <tr>
        <td colspan="5" class="px-4 py-6 text-center text-slate-400">
          Sin productos.
        </td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = detalle.map((item) => {
    return `
      <tr class="hover:bg-slate-800/50">
        <td class="px-4 py-3">
          <p class="font-semibold text-white">
            ${escapeHTML(item.producto_nombre || '-')}
          </p>
          <p class="text-xs text-slate-400">
            Código: ${escapeHTML(item.producto_codigo || '-')}
          </p>
        </td>

        <td class="px-4 py-3 text-blue-300">
          ${escapeHTML(item.propietario_nombre || '-')}
        </td>

        <td class="px-4 py-3 text-center text-slate-300">
          ${Number(item.cantidad || 0)}
        </td>

        <td class="px-4 py-3 text-right text-slate-300">
          ${money(item.precio_unitario)}
        </td>

        <td class="px-4 py-3 text-right font-bold text-emerald-300">
          ${money(item.subtotal)}
        </td>
      </tr>
    `;
  }).join('');
}

function renderDetalleCuotas(cuotas, venta) {
  const tbody = $('tbodyDetalleCuotas');

  if (!tbody) return;

  if (!cuotas.length) {
    tbody.innerHTML = `
      <tr>
        <td colspan="7" class="px-4 py-6 text-center text-slate-400">
          Sin cuotas generadas.
        </td>
      </tr>
    `;
    return;
  }

  const hoy = new Date();
  hoy.setHours(0, 0, 0, 0);

  const cuotasPendientes = cuotas
    .filter(c => Number(c.saldo_cuota || 0) > 0)
    .sort((a, b) => Number(a.numero_cuota) - Number(b.numero_cuota));

  const primeraPendiente = cuotasPendientes.length
    ? Number(cuotasPendientes[0].numero_cuota)
    : null;

  const cuotasLiberadasIds = cuotasPendientes
    .filter(c => {
      const fecha = new Date(String(c.fecha_vencimiento) + 'T00:00:00');
      const vencidaOActual = fecha <= hoy;
      const esPrimeraPendiente = Number(c.numero_cuota) === primeraPendiente;

      return vencidaOActual || esPrimeraPendiente;
    })
    .map(c => Number(c.id));

  const saldoLiberado = cuotas
    .filter(c => cuotasLiberadasIds.includes(Number(c.id)))
    .reduce((acc, c) => acc + Number(c.saldo_cuota || 0), 0);

  const btnAbonoGeneral = $('btnAbrirAbonoDesdeDetalle');

  if (btnAbonoGeneral) {
    btnAbonoGeneral.dataset.saldoLiberado = saldoLiberado.toFixed(2);

    if (
      Number(venta.saldo_actual || 0) <= 0 ||
      venta.estado === 'cancelada' ||
      venta.estado === 'liquidada'
    ) {
      btnAbonoGeneral.disabled = true;
      btnAbonoGeneral.classList.add('opacity-50', 'cursor-not-allowed');
    } else {
      btnAbonoGeneral.disabled = false;
      btnAbonoGeneral.classList.remove('opacity-50', 'cursor-not-allowed');
    }
  }

  tbody.innerHTML = cuotas.map((cuota) => {
    const saldo = Number(cuota.saldo_cuota || 0);
    const cuotaLiberada = cuotasLiberadasIds.includes(Number(cuota.id));

    const puedeAbonar =
      saldo > 0 &&
      cuotaLiberada &&
      venta.estado !== 'cancelada' &&
      venta.estado !== 'liquidada';

    return `
      <tr class="hover:bg-slate-800/50">
        <td class="px-4 py-3 text-center font-bold text-white">
          ${Number(cuota.numero_cuota || 0)}
        </td>

        <td class="px-4 py-3 text-center text-slate-300">
          ${formatDate(cuota.fecha_vencimiento)}
        </td>

        <td class="px-4 py-3 text-right text-slate-300">
          ${money(cuota.monto_programado)}
        </td>

        <td class="px-4 py-3 text-right text-emerald-300">
          ${money(cuota.monto_pagado)}
        </td>

        <td class="px-4 py-3 text-right font-bold text-amber-300">
          ${money(cuota.saldo_cuota)}
        </td>

        <td class="px-4 py-3 text-center">
          ${estadoBadge(cuota.estado)}
        </td>

        <td class="px-4 py-3 text-center">
          ${
            puedeAbonar
              ? `
                <button type="button"
                  onclick="abrirModalAbono(${Number(venta.id)}, ${Number(cuota.id)}, ${saldo})"
                  class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-500/20 text-emerald-300 hover:bg-emerald-500/30"
                  title="Abonar esta cuota">
                  <i data-lucide="badge-dollar-sign" class="w-4 h-4"></i>
                </button>
              `
              : `
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-slate-700/60 text-slate-500"
                  title="${saldo <= 0 ? 'Cuota pagada' : 'Cuota bloqueada'}">
                  <i data-lucide="${saldo <= 0 ? 'check' : 'lock'}" class="w-4 h-4"></i>
                </span>
              `
          }
        </td>
      </tr>
    `;
  }).join('');

  if (window.lucide) {
    lucide.createIcons();
  }
}

function renderDetallePagos(pagos) {
  const tbody = $('tbodyDetallePagos');

  if (!tbody) return;

  if (!pagos.length) {
    tbody.innerHTML = `
      <tr>
        <td colspan="6" class="px-4 py-6 text-center text-slate-400">
          Sin pagos registrados.
        </td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = pagos.map((pago, index) => {
    const referencia = String(pago.referencia || '').toUpperCase();
    const esEnganche = referencia === 'ENGANCHE';
    const esUltimoPago = index === 0;

    const puedeEliminar = !esEnganche && esUltimoPago;

    return `
      <tr class="hover:bg-slate-800/50">
        <td class="px-4 py-3 text-center text-slate-300">
          ${formatDateTime(pago.fecha_pago)}
        </td>

        <td class="px-4 py-3 text-right font-bold text-emerald-300">
          ${money(pago.monto)}
        </td>

        <td class="px-4 py-3 text-center text-slate-300">
          ${escapeHTML(pago.metodo_pago || '-')}
        </td>

        <td class="px-4 py-3 text-slate-300">
          ${escapeHTML(pago.referencia || '-')}
        </td>

        <td class="px-4 py-3 text-slate-300">
          ${escapeHTML(pago.observaciones || '-')}
        </td>

        <td class="px-4 py-3 text-center">
          ${
            puedeEliminar
              ? `
                <button type="button"
                  onclick="eliminarPagoFinanciado(${Number(pago.id)})"
                  class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-red-500/20 text-red-300 hover:bg-red-500/30"
                  title="Eliminar pago y restaurar saldo">
                  <i data-lucide="trash-2" class="w-4 h-4"></i>
                </button>
              `
              : `
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-slate-700/60 text-slate-500"
                  title="${esEnganche ? 'El enganche no se elimina desde aquí' : 'Solo se puede eliminar el último pago'}">
                  <i data-lucide="lock" class="w-4 h-4"></i>
                </span>
              `
          }
        </td>
      </tr>
    `;
  }).join('');

  if (window.lucide) {
    lucide.createIcons();
  }
}

/* =========================================================
   ABONOS
========================================================= */

function abrirModalAbono(ventaId, cuotaId = 0, saldoMax = 0) {
  const saldo = Number(saldoMax || 0);

  if (!ventaId || saldo <= 0) {
    Swal.fire({
      icon: 'warning',
      title: 'Sin saldo disponible',
      text: 'No hay saldo disponible para registrar abono.',
      background: '#1e293b',
      color: '#f8fafc'
    });
    return;
  }

  setValue('abonoVentaId', ventaId);
  setValue('abonoCuotaId', cuotaId);
  setValue('abonoSaldoMax', saldo.toFixed(2));

  setValue('abonoMonto', saldo.toFixed(2));
  setValue('abonoMetodoPago', 'efectivo');
  setValue('abonoReferencia', '');
  setValue('abonoObservaciones', '');

  const inputMonto = $('abonoMonto');
  if (inputMonto) {
    inputMonto.max = saldo.toFixed(2);
  }

  setText(
    'abonoSubtitulo',
    cuotaId > 0
      ? `Máximo permitido para esta cuota: ${money(saldo)}`
      : `Abono general. Máximo permitido: ${money(saldo)}`
  );

  openModal('modalAbono');
}

function validarMontoAbonoVisual() {
  const monto = numberValue('abonoMonto');
  const max = numberValue('abonoSaldoMax');

  const input = $('abonoMonto');
  if (!input) return;

  if (monto > max) {
    input.classList.add('border-red-400');
  } else {
    input.classList.remove('border-red-400');
  }
}

async function guardarAbono() {
  const ventaId = intValue('abonoVentaId');
  const cuotaId = intValue('abonoCuotaId');
  const monto = numberValue('abonoMonto');
  const max = numberValue('abonoSaldoMax');

  if (ventaId <= 0) {
    Swal.fire({
      icon: 'warning',
      title: 'Venta inválida',
      text: 'No se encontró la venta.',
      background: '#1e293b',
      color: '#f8fafc'
    });
    return;
  }

  if (monto <= 0) {
    Swal.fire({
      icon: 'warning',
      title: 'Monto inválido',
      text: 'El monto debe ser mayor a 0.',
      background: '#1e293b',
      color: '#f8fafc'
    });
    return;
  }

  if (monto > max) {
    Swal.fire({
      icon: 'warning',
      title: 'Monto excedido',
      text: `El máximo permitido es ${money(max)}.`,
      background: '#1e293b',
      color: '#f8fafc'
    });
    return;
  }

  try {
    const btn = $('btnGuardarAbono');

    if (btn) {
      btn.disabled = true;
      btn.classList.add('opacity-60', 'cursor-not-allowed');
    }

    const data = await apiRequest({
      accion: 'registrar_abono',
      venta_id: ventaId,
      cuota_id: cuotaId,
      monto,
      metodo_pago: textValue('abonoMetodoPago') || 'efectivo',
      referencia: textValue('abonoReferencia'),
      observaciones: textValue('abonoObservaciones')
    });

    await Swal.fire({
      icon: 'success',
      title: 'Abono registrado',
      text: data.mensaje || 'El abono fue registrado correctamente.',
      background: '#1e293b',
      color: '#f8fafc'
    });

    closeModal('modalAbono');

    await verDetalleVentaFinanciada(ventaId);
    listarVentasFinanciadas();

  } catch (error) {
    Swal.fire({
      icon: 'error',
      title: 'No se pudo registrar',
      text: error.message,
      background: '#1e293b',
      color: '#f8fafc'
    });
  } finally {
    const btn = $('btnGuardarAbono');

    if (btn) {
      btn.disabled = false;
      btn.classList.remove('opacity-60', 'cursor-not-allowed');
    }
  }
}

/* =========================================================
   ELIMINAR PAGO
========================================================= */

async function eliminarPagoFinanciado(pagoId) {
  if (!pagoId) {
    Swal.fire({
      icon: 'warning',
      title: 'Pago inválido',
      text: 'No se encontró el pago a eliminar.',
      background: '#1e293b',
      color: '#f8fafc'
    });
    return;
  }

  const confirmacion = await Swal.fire({
    icon: 'warning',
    title: 'Eliminar pago',
    html: `
      <div class="text-left">
        <p>Se eliminará este pago y se restaurará el saldo en las cuotas correspondientes.</p>
        <p class="mt-2 text-amber-300"><b>Nota:</b> solo se permite eliminar el último pago registrado.</p>
      </div>
    `,
    showCancelButton: true,
    confirmButtonText: 'Sí, eliminar',
    cancelButtonText: 'Cancelar',
    background: '#1e293b',
    color: '#f8fafc',
    confirmButtonColor: '#dc2626',
    cancelButtonColor: '#475569'
  });

  if (!confirmacion.isConfirmed) return;

  try {
    const data = await apiRequest({
      accion: 'eliminar_pago_financiado',
      id: pagoId
    });

    await Swal.fire({
      icon: 'success',
      title: 'Pago eliminado',
      text: data.mensaje || 'El pago fue eliminado correctamente.',
      background: '#1e293b',
      color: '#f8fafc'
    });

    const ventaId = Number(data.venta_id || intValue('detalleVentaId'));

    if (ventaId > 0) {
      await verDetalleVentaFinanciada(ventaId);
    }

    listarVentasFinanciadas();

  } catch (error) {
    Swal.fire({
      icon: 'error',
      title: 'No se pudo eliminar',
      text: error.message,
      background: '#1e293b',
      color: '#f8fafc'
    });
  }
}

/* =========================================================
   CANCELAR VENTA
========================================================= */

async function confirmarCancelarVentaFinanciada(ventaId) {
  const confirmacion = await Swal.fire({
    icon: 'warning',
    title: 'Cancelar venta financiada',
    html: `
      <div class="text-left">
        <p>La venta se marcará como cancelada.</p>
        <p class="mt-2 text-amber-300">
          <b>Nota:</b> esta acción no regresa stock automáticamente.
        </p>
      </div>
    `,
    showCancelButton: true,
    confirmButtonText: 'Sí, cancelar',
    cancelButtonText: 'No',
    background: '#1e293b',
    color: '#f8fafc',
    confirmButtonColor: '#dc2626',
    cancelButtonColor: '#475569'
  });

  if (!confirmacion.isConfirmed) return;

  try {
    const data = await apiRequest({
      accion: 'cancelar_venta_financiada',
      id: ventaId
    });

    await Swal.fire({
      icon: 'success',
      title: 'Venta cancelada',
      text: data.mensaje || 'La venta fue cancelada.',
      background: '#1e293b',
      color: '#f8fafc'
    });

    listarVentasFinanciadas();

    if (intValue('detalleVentaId') === Number(ventaId)) {
      closeModal('modalDetalleVenta');
    }

  } catch (error) {
    Swal.fire({
      icon: 'error',
      title: 'No se pudo cancelar',
      text: error.message,
      background: '#1e293b',
      color: '#f8fafc'
    });
  }
}

/* =========================================================
   EXPONER FUNCIONES PARA ONCLICK
========================================================= */

window.seleccionarProductoFinanciado = seleccionarProductoFinanciado;
window.actualizarCantidadProducto = actualizarCantidadProducto;
window.actualizarPrecioProducto = actualizarPrecioProducto;
window.quitarProductoFinanciado = quitarProductoFinanciado;
window.verDetalleVentaFinanciada = verDetalleVentaFinanciada;
window.abrirModalAbono = abrirModalAbono;
window.eliminarPagoFinanciado = eliminarPagoFinanciado;
window.confirmarCancelarVentaFinanciada = confirmarCancelarVentaFinanciada;