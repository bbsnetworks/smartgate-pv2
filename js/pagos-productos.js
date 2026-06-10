// === BLOQUEO / AUTO-LLENADO DE MONTO ENTREGADO SEGÚN MÉTODO ===
const metodoPagoSelect = document.getElementById("metodoPago");
const montoEntregadoInput = document.getElementById("montoEntregado");

function isNoEfectivo(metodo) {
  return metodo === "Tarjeta" || metodo === "Transferencia";
}

function setMontoEntregadoBloqueado(bloqueado) {
  if (!montoEntregadoInput) return;

  montoEntregadoInput.disabled = bloqueado;

  // Opcional: un look "deshabilitado" más obvio
  if (bloqueado) {
    montoEntregadoInput.classList.add("opacity-70", "cursor-not-allowed");
  } else {
    montoEntregadoInput.classList.remove("opacity-70", "cursor-not-allowed");
  }
}
// === BLOQUEO DEL BOTÓN DE COBRAR (anti doble click) ===
const btnCobrar =
  document.getElementById("btnCobrar") || // ponle este id a tu botón si no lo tiene
  document.querySelector('[data-action="procesarVenta"]') || // opcional
  document.querySelector('button[onclick*="procesarVenta"]'); // último recurso

let ventaEnProceso = false;

function setBotonCobrarBloqueado(bloqueado, texto = null) {
  if (!btnCobrar) return;

  btnCobrar.disabled = bloqueado;
  btnCobrar.classList.toggle("opacity-60", bloqueado);
  btnCobrar.classList.toggle("cursor-not-allowed", bloqueado);

  if (texto !== null) {
    btnCobrar.dataset.textoOriginal ??= btnCobrar.innerHTML;
    btnCobrar.innerHTML = texto;
  } else if (!bloqueado && btnCobrar.dataset.textoOriginal) {
    btnCobrar.innerHTML = btnCobrar.dataset.textoOriginal;
  }
}
function syncMontoEntregadoConTotal() {
  if (!metodoPagoSelect || !montoEntregadoInput) return;

  const metodo = metodoPagoSelect.value;
  const total = getTotalNumber();
  const totalFmt = total.toFixed(2);

  // ✅ Si no hay productos, siempre 0.00 y habilitado
  if (total <= 0) {
    setMontoEntregadoBloqueado(false);
    montoEntregadoInput.value = "0.00";
    return;
  }

  // ✅ Tarjeta/Transferencia: bloquea y pone total exacto
  if (isNoEfectivo(metodo)) {
    setMontoEntregadoBloqueado(true);
    montoEntregadoInput.value = totalFmt;
    return;
  }

  // ✅ Efectivo: habilita y NO conserva el valor que venía de tarjeta/transferencia
  setMontoEntregadoBloqueado(false);

  // Si el valor actual era exactamente el total (venía de tarjeta/transferencia), lo limpiamos
  const actual = parseFloat(
    (montoEntregadoInput.value || "").replace(",", "."),
  );
  if (!isNaN(actual) && Math.abs(actual - total) < 0.001) {
    montoEntregadoInput.value = "";
  }

  // (Opcional) Si prefieres que en efectivo se ponga por defecto el total exacto, usa esto:
  // montoEntregadoInput.value = totalFmt;
}

// Cuando cambie el método de pago
if (metodoPagoSelect) {
  metodoPagoSelect.addEventListener("change", syncMontoEntregadoConTotal);
}

let productosAgregados = [];
let sugerenciaController;
let sugerenciasHabilitadas = true;

let inputCodigo = document.getElementById("codigo");
let sugerenciasDiv = document.getElementById("sugerencias");

// Al presionar Enter (como al escanear)
inputCodigo.addEventListener("keypress", function (e) {
  if (e.key === "Enter") {
    e.preventDefault();
    const codigo = this.value.trim();
    if (codigo !== "") {
      // 🔴 Desactivar sugerencias temporalmente
      sugerenciasHabilitadas = false;

      // 🔴 Cancelar fetch anterior si existe
      if (sugerenciaController) sugerenciaController.abort();

      // 🔴 Ocultar div
      ocultarSugerencias();

      buscarProducto(codigo);
      this.value = "";

      // 🔄 Reactivar sugerencias tras 300 ms
      setTimeout(() => {
        sugerenciasHabilitadas = true;
      }, 300);
    }
  }
});
function getTotalNumber() {
  return productosAgregados.reduce(
    (acc, p) => acc + parseFloat(p.precio) * parseInt(p.cantidad || 0),
    0,
  );
}
function formateaMoneda(n) {
  return `$${Number(n).toFixed(2)}`;
}

function buscarProducto(codigo) {
  ocultarSugerencias();

  fetch(`../php/buscar_producto.php?codigo=${encodeURIComponent(codigo)}`)
    .then((res) => res.json())
    .then((data) => {
      if (!data || data.success === false) {
        swalError.fire("Producto no encontrado", data?.error || "", "error");
        return;
      }

      const productos = data.productos || [];

      if (!productos.length) {
        swalError.fire("Producto no encontrado", "", "error");
        return;
      }

      mostrarSelectorInventario(productos);
    })
    .catch(() => {
      swalError.fire("Error al buscar producto", "", "error");
    });
}
function agregarProductoAlCarrito(producto) {
  const inventarioId = parseInt(
    producto.inventario_usuario_id || producto.id,
    10,
  );

  if (!inventarioId) {
    swalError.fire(
      "Error",
      "El producto no tiene inventario asignado.",
      "error",
    );
    return;
  }

  const stock = parseInt(producto.stock || 0, 10);

  if (stock <= 0) {
    swalError.fire(
      "Sin stock",
      "Este inventario no tiene stock disponible.",
      "warning",
    );
    return;
  }

  /*
    Este punto es clave:
    Se compara por inventario_usuario_id, no por producto_id.
    Así el mismo producto de dos dueños diferentes queda separado.
  */
  const existente = productosAgregados.find((p) => {
    const idActual = parseInt(p.inventario_usuario_id || p.id, 10);
    return idActual === inventarioId;
  });

  if (existente) {
    if (existente.cantidad + 1 > stock) {
      swalInfo.fire(
        "Stock insuficiente",
        `Solo hay ${stock} piezas disponibles de este inventario.`,
        "warning",
      );
      return;
    }

    existente.cantidad++;
  } else {
    productosAgregados.push({
      id: inventarioId,
      inventario_usuario_id: inventarioId,
      producto_id: producto.producto_id,
      usuario_propietario_id: producto.usuario_propietario_id,

      codigo: producto.codigo,
      nombre: producto.nombre,
      descripcion: producto.descripcion || "",

      propietario: producto.propietario || "—",
      categoria: producto.categoria || "—",
      proveedor_nombre: producto.proveedor_nombre || "—",

      precio: Number(producto.precio || 0),
      precio_proveedor: Number(producto.precio_proveedor || 0),
      stock: stock,
      cantidad: 1,
    });
  }

  actualizarTabla();
}
function mostrarSelectorInventario(productos) {
  const html = `
    <div class="text-left space-y-3">
      <p class="text-slate-300 text-sm">
        Selecciona de qué inventario deseas vender este producto.
      </p>

      <div class="max-h-80 overflow-y-auto border border-slate-700 rounded-xl">
        ${productos
          .map(
            (p, index) => `
              <button
                type="button"
                class="w-full text-left px-4 py-3 hover:bg-slate-700 border-b border-slate-700 last:border-b-0"
                onclick="seleccionarInventarioVenta(${index})"
              >
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <div class="font-semibold text-slate-100">
                      ${p.codigo} — ${p.nombre}
                    </div>

                    <div class="text-xs text-blue-300 mt-1">
                      Dueño:
                      <span class="font-semibold">
                        ${p.propietario || "—"}
                      </span>
                    </div>

                    <div class="text-xs text-slate-400 mt-1">
                      Stock: ${p.stock || 0}
                      ${p.categoria ? ` · ${p.categoria}` : ""}
                    </div>

                    <div class="text-xs text-slate-500 mt-1">
                      Proveedor: ${p.proveedor_nombre || "—"}
                    </div>
                  </div>

                  <div class="text-right">
                    <div class="text-xs text-slate-400">Precio</div>
                    <div class="font-bold text-emerald-400">
                      $${Number(p.precio || 0).toFixed(2)}
                    </div>
                  </div>
                </div>
              </button>
            `,
          )
          .join("")}
      </div>
    </div>
  `;

  window.__inventariosVentaTemp = productos;

  swalcard.fire({
    title: "Seleccionar inventario",
    width: 680,
    html,
    showConfirmButton: false,
    showCancelButton: true,
    cancelButtonText: "Cancelar",
    didOpen: () => {
      Swal.getPopup().classList.add("bg-slate-800", "text-slate-100");
    },
  });
}
function seleccionarInventarioVenta(index) {
  const producto = window.__inventariosVentaTemp?.[index];

  if (!producto) {
    swalError.fire("Error", "No se pudo seleccionar el inventario.", "error");
    return;
  }

  Swal.close();
  agregarProductoAlCarrito(producto);
}
function ocultarSugerencias() {
  if (sugerenciasDiv) {
    sugerenciasDiv.innerHTML = "";
    sugerenciasDiv.classList.add("hidden");
  }
}

function actualizarTabla() {
  const tbody = document.getElementById("tablaProductos");
  tbody.innerHTML = "";
  let total = 0;

  productosAgregados.forEach((prod, i) => {
    const fila = document.createElement("tr");

    const totalFila = (prod.precio * prod.cantidad).toFixed(2);
    total += parseFloat(totalFila);

    fila.innerHTML = `
  <td class="border border-slate-700 px-4 py-2">
    <div class="font-semibold">${prod.nombre}</div>
    <div class="text-xs text-slate-400">Código: ${prod.codigo || "—"}</div>
  </td>

  <td class="border border-slate-700 px-4 py-2">
    <span class="inline-flex items-center px-2 py-1 rounded-lg bg-slate-900/70 border border-slate-600 text-blue-300 text-sm font-semibold">
      ${prod.propietario || "—"}
    </span>
  </td>

  <td class="border border-slate-700 px-4 py-2">
    <input 
      type="number" 
      min="1" 
      max="${prod.stock || 999999}"
      value="${prod.cantidad}" 
      class="w-20 bg-slate-900 text-slate-100 text-center border border-slate-600 rounded px-2 py-1" 
      onchange="cambiarCantidad(${i}, this.value)"
    >
    <div class="text-xs text-slate-400 mt-1">Stock: ${prod.stock || 0}</div>
  </td>

  <td class="border border-slate-700 px-4 py-2">$${Number(prod.precio || 0).toFixed(2)}</td>

  <td class="border border-slate-700 px-4 py-2">$${totalFila}</td>

  <td class="border border-slate-700 px-4 py-2 text-center">
    <button onclick="eliminarProducto(${i})" class="text-red-500 hover:text-red-400 font-bold">🗑️</button>
  </td>
`;

    tbody.appendChild(fila);
  });

  document.getElementById("totalPagar").textContent = total.toFixed(2);

  // ✅ Si es Tarjeta/Transferencia, actualiza el input con el nuevo total
  syncMontoEntregadoConTotal();
}

function cambiarCantidad(index, valor) {
  const cantidad = parseInt(valor, 10) || 1;
  const stock = parseInt(productosAgregados[index].stock || 0, 10);

  if (cantidad <= 0) {
    productosAgregados[index].cantidad = 1;
  } else if (stock > 0 && cantidad > stock) {
    productosAgregados[index].cantidad = stock;

    swalInfo.fire(
      "Stock insuficiente",
      `Solo hay ${stock} piezas disponibles de este inventario.`,
      "warning",
    );
  } else {
    productosAgregados[index].cantidad = cantidad;
  }

  actualizarTabla();
}

function eliminarProducto(index) {
  productosAgregados.splice(index, 1);
  actualizarTabla();
}

async function procesarVenta() {
  // anti doble ejecución
  if (ventaEnProceso) return;

  // validación básica antes de bloquear (opcional)
  if (productosAgregados.length === 0) {
    swalError.fire("No hay productos en la venta", "", "warning");
    return;
  }

  ventaEnProceso = true;
  setBotonCobrarBloqueado(true, "Procesando...");

  try {
    const total = getTotalNumber();
    const metodoPago = document.getElementById("metodoPago").value;

    let pagado = 0;

    if (isNoEfectivo(metodoPago)) {
      pagado = total;
      if (montoEntregadoInput) montoEntregadoInput.value = total.toFixed(2);
    } else {
      const pagoStr = (montoEntregadoInput?.value || "").replace(",", ".");
      pagado = parseFloat(pagoStr);

      if (isNaN(pagado) || pagado <= 0) {
        await swalError.fire(
          "Monto inválido",
          "La cantidad entregada debe ser mayor a 0.",
          "error",
        );
        return;
      }
      if (pagado < total) {
        const falta = total - pagado;
        await swalError.fire(
          "Pago insuficiente",
          `Faltan ${formateaMoneda(falta)} para completar el total.`,
          "error",
        );
        return;
      }
    }

    const confirm = await swalInfo.fire({
      title: "Confirmar venta",
      html: `
    <div class="text-left space-y-2">
      <div><strong>Total:</strong> ${formateaMoneda(total)}</div>
      <div><strong>Método:</strong> ${metodoPago}</div>
    </div>
  `,
      icon: "question",
      showCancelButton: true,
      confirmButtonText: "Aceptar",

      // ✅ Bloqueo anti doble click
      showLoaderOnConfirm: true,
      preConfirm: () => {
        Swal.disableButtons(); // deshabilita Aceptar/Cancelar al primer click
        return true; // deja que se cierre el modal
      },
      allowOutsideClick: () => !Swal.isLoading(),
      allowEscapeKey: () => !Swal.isLoading(),
      allowEnterKey: () => !Swal.isLoading(),
    });

    if (!confirm.isConfirmed) return;

    // (opcional) cambia el texto tras confirmar
    setBotonCobrarBloqueado(true, "Guardando...");

    const productosParaTicket = [...productosAgregados];
    const cambio = pagado - total;

    const res = await fetch("../php/registrar_pago_producto.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        productos: productosAgregados,
        metodo_pago: metodoPago,
      }),
    });

    // Si tu PHP puede devolver HTML/500, esto evita que truene el .json()
    const raw = await res.text();
    let data;
    try {
      data = JSON.parse(raw);
    } catch {
      throw new Error("Respuesta no válida del servidor");
    }

    if (!res.ok) {
      throw new Error(data?.error || "Error HTTP al procesar la venta");
    }

    if (data.success) {
      generarTicketVenta(data, productosParaTicket, { pagado, cambio });

      const cambioColor = cambio > 0 ? "#22c55e" : "#e5e7eb";
      await swalSuccess.fire({
        title: "Venta realizada con éxito",
        html: `
          <div class="text-left space-y-1">
            <div><strong>Folio:</strong> ${data.venta_id}</div>
            <div><strong>Total:</strong> ${formateaMoneda(total)}</div>
            <div><strong>Pagó:</strong> ${formateaMoneda(pagado)}</div>
            <div><strong>Método:</strong> ${metodoPago}</div>

            <div style="
              margin-top:12px;
              padding-top:10px;
              border-top:1px solid #334155;
              font-weight:800;
              font-size:28px;
              line-height:1.1;
              color:${cambioColor};
              text-align:center;
            ">
              Cambio: ${formateaMoneda(cambio)}
            </div>
          </div>
        `,
        icon: "success",
      });

      // Reset UI
      productosAgregados = [];
      actualizarTabla();
      document.getElementById("metodoPago").value = "Efectivo";
      if (montoEntregadoInput) montoEntregadoInput.value = "";
      syncMontoEntregadoConTotal();
    } else {
      await swalError.fire(
        "Error",
        data.error || "No se pudo procesar la venta",
        "error",
      );
    }
  } catch (err) {
    console.error(err);
    await swalError.fire(
      "Error",
      err.message || "No se pudo procesar la venta",
      "error",
    );
  } finally {
    ventaEnProceso = false;
    setBotonCobrarBloqueado(false, null);
  }
}

async function generarTicketVenta(
  data,
  productos,
  pagoInfo = { pagado: 0, cambio: 0 },
) {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({
    unit: "mm",
    format: [58, 130 + productos.length * 10],
  });

  const logo = await cargarImagenBase64("../img/logo-black.webp");

  const fechaCompleta = new Date(data.fecha_pago);
  const fecha = fechaCompleta.toLocaleDateString("es-MX");
  const hora = fechaCompleta.toLocaleTimeString("es-MX", {
    hour: "2-digit",
    minute: "2-digit",
  });

  doc.addImage(logo, "PNG", 19, 5, 20, 20);
  doc.setFont("courier", "bold");
  doc.setFontSize(10);
  doc.text("Venta de Productos", 29, 30, { align: "center" });
  doc.setFont("courier", "normal");
  doc.text(`${fecha}, ${hora}`, 29, 36, { align: "center" });
  doc.line(5, 38, 53, 38);

  let y = 44;
  doc.setFont("courier", "bold");
  doc.text(`Folio: ${data.venta_id}`, 29, y, { align: "center" });
  y += 5;
  doc.setFont("courier", "normal");
  doc.text(`Vendedor: ${data.usuario}`, 29, y, { align: "center" });
  y += 6;

  let total = 0;
  productos.forEach((p) => {
    const precio = parseFloat(p.precio);
    const cantidad = parseInt(p.cantidad);
    const subtotal = precio * cantidad;
    total += subtotal;

    doc.text(p.nombre, 5, y);
    doc.text(`x${cantidad} $${precio.toFixed(2)}`, 5, y + 5);
    doc.text(`$${subtotal.toFixed(2)}`, 53, y + 5, { align: "right" });
    y += 10;
  });

  doc.line(5, y, 53, y);
  y += 6;
  doc.setFont("courier", "bold");
  doc.text(`Total: ${formateaMoneda(total)}`, 29, y, { align: "center" });
  y += 5;

  // (Opcional) mostrar pagó/cambio también en ticket
  if (pagoInfo) {
    doc.setFont("courier", "normal");
    doc.text(`Pagó: ${formateaMoneda(pagoInfo.pagado)}`, 5, y);
    y += 5;
    doc.text(`Cambio: ${formateaMoneda(pagoInfo.cambio)}`, 5, y);
    y += 7;
  }

  doc.setFont("courier", "italic");
  doc.text("¡Gracias por tu compra!", 29, y, { align: "center" });

  doc.autoPrint();
  window.open(doc.output("bloburl"), "_blank");
}

function cargarImagenBase64(ruta) {
  return new Promise((resolve) => {
    const img = new Image();
    img.crossOrigin = "Anonymous";
    img.onload = function () {
      const canvas = document.createElement("canvas");
      canvas.width = this.naturalWidth;
      canvas.height = this.naturalHeight;
      canvas.getContext("2d").drawImage(this, 0, 0);
      resolve(canvas.toDataURL("image/png"));
    };
    img.src = ruta;
  });
}

inputCodigo.addEventListener("input", () => {
  if (!sugerenciasHabilitadas) return; // Ignora si escaneo activo

  const termino = inputCodigo.value.trim();
  if (sugerenciaController) sugerenciaController.abort();

  if (termino.length < 2) {
    sugerenciasDiv.classList.add("hidden");
    return;
  }

  sugerenciaController = new AbortController();
  fetch(
    `../php/buscar_sugerencias.php?termino=${encodeURIComponent(termino)}`,
    {
      signal: sugerenciaController.signal,
    },
  )
    .then((res) => res.json())
    .then((sugerencias) => {
      sugerenciasDiv.innerHTML = "";
      if (sugerencias.length === 0) {
        sugerenciasDiv.classList.add("hidden");
        return;
      }

      sugerencias.forEach((prod) => {
        const item = document.createElement("div");
        item.className =
          "px-4 py-3 cursor-pointer hover:bg-slate-500 border-b text-lg";
        item.innerHTML = `
  <div class="flex items-start justify-between gap-3">
    <div>
      <strong>${prod.codigo}</strong>
      <br>
      <span class="text-stone-50">${prod.nombre}</span>

      <div class="text-xs text-slate-400 mt-1">
        Stock total: ${prod.stock_total || 0}
        ${prod.categoria ? ` · ${prod.categoria}` : ""}
      </div>

      <div class="text-xs text-blue-300 mt-1">
        Inventarios disponibles: ${prod.inventarios || 0}
      </div>
    </div>

    <div class="text-right">
      <div class="text-xs text-slate-400">Precio</div>
      <div class="font-bold text-emerald-400">
        ${
          Number(prod.precio_min || 0) === Number(prod.precio_max || 0)
            ? `$${Number(prod.precio_min || 0).toFixed(2)}`
            : `$${Number(prod.precio_min || 0).toFixed(2)} - $${Number(prod.precio_max || 0).toFixed(2)}`
        }
      </div>
    </div>
  </div>
`;
        item.onclick = () => {
          inputCodigo.value = "";
          ocultarSugerencias();
          buscarProductoPorId(prod.producto_id);
        };
        sugerenciasDiv.appendChild(item);
      });

      sugerenciasDiv.classList.remove("hidden");
    })
    .catch((err) => {
      if (err.name !== "AbortError") {
        console.error("Error al cargar sugerencias:", err);
      }
    });
});

// Ocultar sugerencias al perder foco
inputCodigo.addEventListener("blur", () => {
  setTimeout(() => {
    if (sugerenciasDiv) sugerenciasDiv.classList.add("hidden");
  }, 200);
});
function buscarProductoPorId(productoId) {
  fetch(
    `../php/buscar_producto.php?producto_id=${encodeURIComponent(productoId)}`,
  )
    .then((res) => res.json())
    .then((data) => {
      if (!data || data.success === false) {
        swalError.fire("Producto no encontrado", data?.error || "", "error");
        return;
      }

      const productos = data.productos || [];

      if (!productos.length) {
        swalError.fire(
          "Sin inventario",
          "Este producto no tiene inventario disponible.",
          "warning",
        );
        return;
      }

      mostrarSelectorInventario(productos);
    })
    .catch(() => {
      swalError.fire("Error", "No se pudo consultar el inventario.", "error");
    });
}
