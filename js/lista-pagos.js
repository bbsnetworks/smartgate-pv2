let offset = 0;
const limit = 20;

document.addEventListener("DOMContentLoaded", () => {
  cargarPagos(true);

  document.getElementById("selectMes").addEventListener("change", () => cargarPagos(true));
  document.getElementById("selectYear").addEventListener("change", () => cargarPagos(true));
  document.getElementById("filtroPagos").addEventListener("input", () => cargarPagos(true));
});

async function cargarPagos(reset = false) {
  if (reset) {
    offset = 0;
    document.getElementById("tablaPagos").innerHTML = "";
  }

  const mes = document.getElementById("selectMes").value;
  const year = document.getElementById("selectYear").value;
  const busqueda = document.getElementById("filtroPagos").value;

  const res = await fetch("../php/pagos_productos_controller.php?accion=obtener", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ mes, year, busqueda, offset, limit }),
  });

  const data = await res.json();
  if (!data.success) {
    swalError.fire("Error", data.error || "No se pudo cargar la lista", "error");
    return;
  }

  const tbody = document.getElementById("tablaPagos");

  if (reset) window.listaPagos = [];

  data.pagos.forEach((venta, index) => {
  const indexGlobal = window.listaPagos.length;
  window.listaPagos.push(venta);

  const fila = document.createElement("tr");
  fila.className = "hover:bg-slate-700/40";

  const productosHTML = renderListaProductosCompacta(venta.productos || []);

    fila.innerHTML = `
  <td class="px-4 py-3 text-center">${offset + index + 1}</td>

  <td class="px-4 py-3">
    <div class="font-semibold text-slate-100 break-all">${escapeHTML(venta.venta_id)}</div>
  </td>

  <td class="px-4 py-3 whitespace-nowrap">${escapeHTML(venta.fecha_pago)}</td>

  <td class="px-4 py-3">
    <span class="inline-flex px-2 py-1 rounded-lg bg-slate-900/70 border border-slate-700 text-slate-200">
      ${escapeHTML(venta.usuario)}
    </span>
  </td>

  <td class="px-4 py-3 text-right font-bold text-emerald-400">
    ${formatoMoneda(venta.total)}
  </td>

  <td class="px-4 py-3">
    ${productosHTML}
  </td>

  <td class="px-4 py-3 text-center">
    <div class="flex flex-col gap-2 items-center">
      <button 
        onclick="verDetalleVenta(${indexGlobal})" 
        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-blue-500/15 text-blue-300 border border-blue-600 hover:bg-blue-600 hover:text-white transition text-sm">
        👁️ Ver
      </button>

      <button 
        onclick="eliminarPago('${venta.venta_id}')" 
        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-red-400 border border-red-600 hover:bg-red-600 hover:text-white transition text-sm">
        🗑️ Eliminar
      </button>
    </div>
  </td>
`;
    tbody.appendChild(fila);
  });

  if (data.pagos.length === limit) {
    const btnVerMas = document.createElement("tr");
    btnVerMas.innerHTML = `<td colspan="7" class="text-center py-4"><button onclick="verMasPagos()" class="px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white rounded">Ver más</button></td>`;
    tbody.appendChild(btnVerMas);
  }
  if (reset) renderPaginacion(data.total);

  offset += limit;
}
function escapeHTML(texto) {
  return String(texto ?? "").replace(/[&<>"']/g, (m) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;",
  })[m]);
}

function formatoMoneda(valor) {
  return `$${Number(valor || 0).toFixed(2)}`;
}
function nombreProducto(p) {
  const marca = String(p.marca ?? "").trim();
  const modelo = String(p.modelo ?? "").trim();

  if (marca && modelo) return `${marca} ${modelo}`;
  if (modelo) return modelo;
  if (marca) return marca;

  if (p.nombre) return String(p.nombre).trim();

  return "Sin marca/modelo";
}
function renderListaProductosCompacta(productos = []) {
  if (!productos.length) {
    return `<span class="text-slate-400">Sin productos</span>`;
  }

  const visibles = productos.slice(0, 3);

  const items = visibles.map((p) => `
    <div class="py-1 border-b border-slate-700/70 last:border-b-0">
      <div class="flex items-start justify-between gap-2">
        <div>
          <div class="font-semibold text-slate-100">
            ${escapeHTML(nombreProducto(p))}
          </div>

          <div class="text-xs text-slate-400">
            Marca: ${escapeHTML(p.marca || "—")} · Modelo: ${escapeHTML(p.modelo || "—")}
          </div>

          <div class="text-xs text-slate-400">
            ${p.codigo ? `Código: ${escapeHTML(p.codigo)} · ` : ""}
            Cant: ${p.cantidad}
          </div>

          <div class="text-xs text-blue-300">
            Dueño: <span class="font-semibold">${escapeHTML(p.propietario || "—")}</span>
          </div>
        </div>

        <div class="text-right whitespace-nowrap">
          <div class="text-xs text-slate-400">Total</div>
          <div class="font-semibold text-emerald-400">
            ${formatoMoneda(p.total)}
          </div>
        </div>
      </div>
    </div>
  `).join("");

  const hayMas = productos.length > 3;

  return `
    <div class="relative max-w-[360px]">
      <div class="${hayMas ? "max-h-36 overflow-hidden" : ""}">
        ${items}
      </div>

      ${
        hayMas
          ? `
            <div class="absolute bottom-0 left-0 right-0 h-12 bg-gradient-to-t from-slate-800 to-transparent pointer-events-none"></div>
            <div class="mt-2 text-xs text-slate-400">
              +${productos.length - 3} producto(s) más
            </div>
          `
          : ""
      }
    </div>
  `;
}
function verDetalleVenta(index) {
  const venta = window.listaPagos?.[index];

  if (!venta) {
    swalError.fire("Error", "No se pudo cargar el detalle de la venta.", "error");
    return;
  }

  const productosHTML = (venta.productos || []).map((p) => `
    <tr class="border-b border-slate-700">
      <td class="px-3 py-2">
        <div class="font-semibold text-slate-100">${escapeHTML(nombreProducto(p))}</div>
        <div class="text-xs text-slate-400">
          Marca: ${escapeHTML(p.marca || "—")} · Modelo: ${escapeHTML(p.modelo || "—")}
        </div>

        <div class="text-xs text-slate-400">
          ${p.codigo ? `Código: ${escapeHTML(p.codigo)}` : ""}
        </div>
      </td>

      <td class="px-3 py-2">
        <span class="inline-flex px-2 py-1 rounded-lg bg-slate-900/70 border border-slate-600 text-blue-300 text-xs font-semibold">
          ${escapeHTML(p.propietario || "—")}
        </span>
      </td>

      <td class="px-3 py-2 text-center">${p.cantidad}</td>

      <td class="px-3 py-2 text-right">${formatoMoneda(p.precio_unitario || p.precio)}</td>

      <td class="px-3 py-2 text-right font-semibold text-emerald-400">
        ${formatoMoneda(p.total)}
      </td>
    </tr>
  `).join("");

  swalcard.fire({
    title: `Detalle de venta`,
    width: 900,
    html: `
      <div class="text-left text-slate-200 space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
          <div class="rounded-xl bg-slate-900/70 border border-slate-700 p-3">
            <div class="text-xs text-slate-400">Folio</div>
            <div class="font-semibold text-slate-100 break-all">${escapeHTML(venta.venta_id)}</div>
          </div>

          <div class="rounded-xl bg-slate-900/70 border border-slate-700 p-3">
            <div class="text-xs text-slate-400">Fecha</div>
            <div class="font-semibold text-slate-100">${escapeHTML(venta.fecha_pago)}</div>
          </div>

          <div class="rounded-xl bg-slate-900/70 border border-slate-700 p-3">
            <div class="text-xs text-slate-400">Vendido por</div>
            <div class="font-semibold text-slate-100">${escapeHTML(venta.usuario)}</div>
          </div>

          <div class="rounded-xl bg-slate-900/70 border border-slate-700 p-3">
            <div class="text-xs text-slate-400">Total</div>
            <div class="font-bold text-emerald-400">${formatoMoneda(venta.total)}</div>
          </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-700">
          <table class="w-full text-sm">
            <thead class="bg-slate-700 text-slate-200">
              <tr>
                <th class="px-3 py-2 text-left">Producto</th>
                <th class="px-3 py-2 text-left">Dueño</th>
                <th class="px-3 py-2 text-center">Cant.</th>
                <th class="px-3 py-2 text-right">Precio</th>
                <th class="px-3 py-2 text-right">Total</th>
              </tr>
            </thead>
            <tbody class="bg-slate-800">
              ${productosHTML || `
                <tr>
                  <td colspan="5" class="px-3 py-6 text-center text-slate-400">
                    Sin productos
                  </td>
                </tr>
              `}
            </tbody>
          </table>
        </div>
      </div>
    `,
    confirmButtonText: "Cerrar",
    didOpen: () => {
      Swal.getPopup().classList.add("bg-slate-800", "text-slate-100");
    }
  });
}
function verMasPagos() {
  cargarPagos(false);
}
function renderPaginacion(totalRegistros) {
  const contenedor = document.getElementById("paginacionPagos");
  contenedor.innerHTML = "";

  const totalPaginas = Math.ceil(totalRegistros / limit);
  const paginaActual = Math.floor(offset / limit);

  if (totalPaginas <= 1) return;

  for (let i = 0; i < totalPaginas; i++) {
    const btn = document.createElement("button");
    btn.className = `px-3 py-1 rounded-lg text-sm font-medium ${
      i === paginaActual
        ? 'bg-blue-600 text-white'
        : 'bg-slate-700 text-slate-200 hover:bg-slate-600'
    }`;
    btn.innerText = i + 1;
    btn.onclick = () => {
      offset = i * limit;
      cargarPagos(true);
    };
    contenedor.appendChild(btn);
  }
}

function filtrarPagos(texto) {
  const tbody = document.getElementById("tablaPagos");
  const filtro = texto.toLowerCase();
  tbody.innerHTML = "";

  let index = 1;

  window.listaPagos.forEach((venta) => {
    const contiene = 
      venta.venta_id.toLowerCase().includes(filtro) ||
      venta.fecha_pago.toLowerCase().includes(filtro) ||
      venta.usuario.toLowerCase().includes(filtro) ||
      venta.productos.some(p => nombreProducto(p).toLowerCase().includes(filtro));

    if (contiene) {
      const productosHTML = venta.productos
        .map(p => {
          const nombre = nombreProducto(p);
          const nombreCorto = nombre.length > 30 ? nombre.slice(0, 30) + "..." : nombre;

          return `
            <div title="${escapeHTML(nombre)}">
              <strong>${escapeHTML(nombreCorto)}</strong> x${p.cantidad} - ${formatoMoneda(p.total)}
            </div>
          `;  
        })
        .join("");

      const fila = document.createElement("tr");
      fila.innerHTML = `
        <td class="px-4 py-2">${index++}</td>
        <td class="px-4 py-2">${venta.venta_id}</td>
        <td class="px-4 py-2">${venta.fecha_pago}</td>
        <td class="px-4 py-2">${venta.usuario}</td>
        <td class="px-4 py-2">$${Number(venta.total).toFixed(2)}</td>
        <td class="px-4 py-2">${productosHTML}</td>
        <td class="px-4 py-2 text-center">
          <button onclick="eliminarPago('${venta.venta_id}')" class="text-red-700 hover:text-white border border-red-700 hover:bg-red-800 focus:ring-4 focus:outline-none focus:ring-red-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center me-2 mb-2 dark:border-red-500 dark:text-red-500 dark:hover:text-white dark:hover:bg-red-600 dark:focus:ring-red-900">
            🗑️ Eliminar
          </button>
        </td>`;
      tbody.appendChild(fila);
    }
  });

  if (tbody.children.length === 0) {
    tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-gray-500">No se encontraron resultados</td></tr>`;
  }
}

function eliminarPago(venta_id) {
  // Si es admin o root, no pedir código
  if (window.tipoUsuario === 'admin' || window.tipoUsuario === 'root') {
    confirmarEliminacion(venta_id);
    return;
  }

  // Si es otro tipo, pedir código
  swalInfo.fire({
    title: 'Código de administrador',
    text: 'Ingrese el código de 10 caracteres para autorizar esta acción',
    input: 'text',
    inputAttributes: {
      maxlength: 10,
      autocapitalize: 'off',
      autocorrect: 'off'
    },
    showCancelButton: true,
    confirmButtonText: 'Validar',
    cancelButtonText: 'Cancelar',
    preConfirm: (codigo) => {
      if (!codigo || codigo.length !== 10) {
        Swal.showValidationMessage('El código debe tener 10 caracteres');
        return false;
      }

      return fetch('../php/pagos_productos_controller.php?accion=validar_codigo', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ codigo: codigo })
      })
        .then(res => res.json())
        .then(data => {
          if (!data.success) {
            throw new Error(data.error || 'Código no válido');
          }
        })
        .catch(error => {
          Swal.showValidationMessage(error.message);
        });
    }
  }).then((result) => {
    if (result.isConfirmed) {
      confirmarEliminacion(venta_id);
    }
  });
}

function confirmarEliminacion(venta_id) {
  swalInfo.fire({
    title: "¿Eliminar pago?",
    text: "Esta acción no se puede deshacer",
    icon: "warning",
    showCancelButton: true,
    confirmButtonText: "Sí, eliminar",
    cancelButtonText: "Cancelar"
  }).then((confirmResult) => {
    if (confirmResult.isConfirmed) {
      fetch(`../php/pagos_productos_controller.php?accion=eliminar&venta_id=${venta_id}`)
        .then((res) => res.json())
        .then((data) => {
          if (data.success) {
            swalSuccess.fire("Eliminado", data.msg, "success").then(cargarPagos);
          } else {
            swalError.fire("Error", data.error || "No se pudo eliminar", "error");
          }
        })
        .catch(() => {
          swalError.fire("Error", "No se pudo conectar con el servidor", "error");
        });
    }
  });
}


