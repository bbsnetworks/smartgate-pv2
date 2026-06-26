// ==========================================================
// dashboard-pos.js
// Archivo combinado para Dashboard Smartgate POS
// Reemplaza: dashboard.js + dashboard-home.js
// Enfocado únicamente a Punto de Venta.
// ==========================================================

let USER_FILTER = "me"; // "me" | "all" | <iduser>
let CURRENT_UID = 0;
const charts = {};

const PHP_BASE = "php/";

const BRANDING = {
  MAX_BYTES: 2 * 1024 * 1024,
  GET_URL: `${PHP_BASE}obtener_branding.php`,
  LOGO_URL: `${PHP_BASE}logo_branding.php`,
  SAVE_URL: `${PHP_BASE}actualizar_branding.php`,
};

// ==========================================================
// Inicialización
// ==========================================================
document.addEventListener("DOMContentLoaded", async () => {
  await cargarBranding();

  const esValida = await verificarSuscripcionSistema();
  if (!esValida) bloquearAccesosPorLicencia();

  initModalPosRapido();
  initPagosFinanciadosDashboard();
  await cargarUsuariosGlobal();
  await cargarTodo();

  document.getElementById("res-prod")?.addEventListener("change", () => {
    cargarSerie(
      "prod",
      document.getElementById("res-prod")?.value || "mes",
      "chart-prod",
    );
  });
});

// ==========================================================
// Utilidades generales
// ==========================================================
function escHtml(s) {
  return String(s ?? "").replace(
    /[&<>"']/g,
    (m) =>
      ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
      })[m],
  );
}

function escAttr(s) {
  return escHtml(s).replace(/`/g, "&#96;");
}

// Alias por compatibilidad con código anterior
function escapeHtml(s) {
  return escHtml(s);
}

function setText(sel, val) {
  const el = document.querySelector(sel);
  if (el) el.textContent = val;
}

function formatoMonedaMX(n) {
  const num = Number(n || 0);
  return num.toLocaleString("es-MX", {
    style: "currency",
    currency: "MXN",
    minimumFractionDigits: 2,
  });
}

function formatFechaCorta(fechaStr) {
  if (!fechaStr) return "Sin actualizar";

  const d = new Date(String(fechaStr).replace(" ", "T"));
  if (isNaN(d.getTime())) return fechaStr;

  return d.toLocaleString("es-MX", {
    dateStyle: "medium",
    timeStyle: "short",
  });
}

function validarMontoStr(s) {
  if (typeof s !== "string") return null;

  s = s.replace(",", ".").trim();

  if (!/^\d+(\.\d{1,2})?$/.test(s)) return null;

  return parseFloat(parseFloat(s).toFixed(2));
}

function getTargetUserId() {
  if (USER_FILTER === "me") return CURRENT_UID;
  if (USER_FILTER === "all") return null;

  const id = parseInt(USER_FILTER, 10);
  return Number.isFinite(id) && id > 0 ? id : CURRENT_UID;
}

// ==========================================================
// Suscripción / Licencia
// ==========================================================
async function verificarSuscripcionSistema() {
  try {
    const res = await fetch(`${PHP_BASE}verificar_suscripcion.php`, {
      cache: "no-store",
    });
    const data = await res.json();

    return !!data.valida;
  } catch (err) {
    return false;
  }
}

function bloquearAccesosPorLicencia() {
  document
    .querySelectorAll(".card-bloqueable, .accion-flotante-pos")
    .forEach((card) => {
      card.classList.add(
        "pointer-events-none",
        "opacity-50",
        "cursor-not-allowed",
      );

      card.addEventListener("click", (e) => {
        e.preventDefault();

        Swal.fire({
          icon: "error",
          title: "Licencia no activa",
          text: "Debes activar una suscripción para usar esta función",
          background: "#1e293b",
          color: "#f8fafc",
        });
      });
    });
}

async function verificarSuscripcion(retornarSolo = false) {
  let mensaje = "Verificando...";
  let clase = "text-yellow-400";
  let mostrarAgregar = false;

  try {
    const res = await fetch(`${PHP_BASE}verificar_suscripcion.php`, {
      cache: "no-store",
    });
    const data = await res.json();

    if (data.valida) {
      mensaje = `✅ Suscripción válida hasta ${data.fecha_fin}`;
      clase = "text-green-400";
    } else {
      mensaje = `❌ ${data.error || "Suscripción no válida"}`;
      clase = "text-red-400";

      const error = String(data.error || "").toLowerCase();
      if (
        error.includes("archivo") ||
        error.includes("incompleto") ||
        error.includes("vacía") ||
        error.includes("agregar")
      ) {
        mostrarAgregar = true;
      }
    }
  } catch (err) {
    mensaje = "⚠️ Error al verificar la suscripción";
    clase = "text-orange-400";
  }

  if (retornarSolo) return { mensaje, clase, mostrarAgregar };
  return { mensaje, clase, mostrarAgregar };
}

async function modalSuscripcion() {
  const estado = await verificarSuscripcion(true);

  Swal.fire({
    title: "Administrar Suscripción",
    html: `
      <p class="text-sm mb-2">Verifica o agrega la licencia actual del sistema.</p>
      <div id="estadoSuscripcion" class="text-md font-semibold ${estado.clase}">
        ${estado.mensaje}
      </div>
    `,
    showCancelButton: true,
    confirmButtonText: "Verificar",
    cancelButtonText: "Cerrar",
    showDenyButton: true,
    denyButtonText: estado.mostrarAgregar ? "Agregar licencia" : "Eliminar",
    background: "#1e293b",
    color: "#f8fafc",
    confirmButtonColor: "#3b82f6",
    denyButtonColor: estado.mostrarAgregar ? "#22c55e" : "#ef4444",
    didOpen: async () => {
      const nuevoEstado = await verificarSuscripcion();
      const divEstado = document.getElementById("estadoSuscripcion");

      if (divEstado) {
        divEstado.textContent = nuevoEstado.mensaje;
        divEstado.className = `text-md font-semibold mb-4 ${nuevoEstado.clase}`;
      }
    },
  }).then((result) => {
    if (result.isConfirmed) {
      modalSuscripcion();
    } else if (result.isDenied) {
      if (estado.mostrarAgregar) agregarLicencia();
      else eliminarSuscripcion();
    }
  });
}

function agregarLicencia() {
  Swal.fire({
    title: "Agregar Licencia",
    html: `
      <input id="input-id" type="number" class="swal2-input" placeholder="ID de la suscripción">
      <input id="input-codigo" type="text" class="swal2-input" placeholder="Código de activación">
    `,
    confirmButtonText: "Guardar",
    showCancelButton: true,
    background: "#1e293b",
    color: "#f8fafc",
    preConfirm: () => {
      const id = document.getElementById("input-id").value;
      const codigo = document.getElementById("input-codigo").value;

      if (!id || !codigo) {
        Swal.showValidationMessage("Debes ingresar ambos campos");
        return false;
      }

      return { id, codigo };
    },
  }).then(async (result) => {
    if (!result.isConfirmed) return;

    const res = await fetch(`${PHP_BASE}activar_suscripcion.php`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(result.value),
    });

    const data = await res.json();

    if (data.success) {
      await Swal.fire(
        "Listo",
        `Licencia activada hasta ${data.fecha_fin}`,
        "success",
      );
      location.reload();
    } else {
      Swal.fire("Error", data.error || "No se pudo activar", "error");
    }
  });
}

function eliminarSuscripcion() {
  Swal.fire({
    title: "¿Eliminar suscripción?",
    text: "Esto desactivará el sistema. ¿Deseas continuar?",
    icon: "warning",
    showCancelButton: true,
    confirmButtonText: "Sí, eliminar",
    cancelButtonText: "Cancelar",
    background: "#1e293b",
    color: "#f8fafc",
    confirmButtonColor: "#ef4444",
  }).then(async (result) => {
    if (!result.isConfirmed) return;

    const res = await fetch(`${PHP_BASE}eliminar_suscripcion.php`, {
      method: "POST",
    });

    const data = await res.json();

    if (data.success) {
      await Swal.fire("Eliminada", "La suscripción fue eliminada.", "success");
      location.reload();
    } else {
      Swal.fire("Error", data.error || "No se pudo eliminar.", "error");
    }
  });
}

// ==========================================================
// Branding
// ==========================================================
async function cargarBranding() {
  try {
    const r = await fetch(BRANDING.GET_URL, { cache: "no-store" });
    const b = await r.json();

    if (b.app_name) document.title = `Dashboard - ${b.app_name}`;

    const elAppName = document.getElementById("sidebarAppName");
    if (elAppName && b.app_name) elAppName.textContent = b.app_name;

    const elTitle = document.getElementById("tituloDashboard");
    if (elTitle && b.dashboard_title) elTitle.textContent = b.dashboard_title;

    const elSub = document.getElementById("dashboardSubtitulo");
    if (elSub && b.dashboard_sub) elSub.textContent = b.dashboard_sub;

    if (b.logo_etag) {
      const v = `?v=${encodeURIComponent(b.logo_etag)}`;

      const side = document.getElementById("sidebarLogoImg");
      const main = document.getElementById("mainLogoImg");

      if (side) side.src = `${BRANDING.LOGO_URL}${v}`;
      if (main) main.src = `${BRANDING.LOGO_URL}${v}`;
    }
  } catch (e) {
    console.warn("No se pudo cargar branding:", e);
  }
}

function modalBranding() {
  swalcard
    .fire({
      title: "Configuración de Marca",
      html: `
        <div class="space-y-4 text-left">
          <label class="block text-sm">Nombre de la app</label>
          <input id="brandAppName" type="text" class="swal2-input !w-full" placeholder="Smartgate POS">

          <label class="block text-sm">Título del dashboard</label>
          <input id="brandTitle" type="text" class="swal2-input !w-full" placeholder="BBSNetworks">

          <label class="block text-sm">Subtítulo</label>
          <input id="brandSub" type="text" class="swal2-input !w-full" placeholder="Resumen rápido del punto de venta">

          <label class="block text-sm">Logo (máx ${(BRANDING.MAX_BYTES / 1024 / 1024).toFixed(0)}MB)</label>
          <input id="brandLogo" type="file" accept="image/*" class="swal2-file !w-full">

          <div id="brandPreview" class="mt-2 hidden">
            <img id="brandPreviewImg" class="h-12 rounded" alt="preview">
          </div>
        </div>
      `,
      showCancelButton: true,
      confirmButtonText: "Guardar",
      cancelButtonText: "Cancelar",
      focusConfirm: false,
      didOpen: async () => {
        try {
          const r = await fetch(BRANDING.GET_URL, { cache: "no-store" });
          const b = await r.json();

          document.getElementById("brandAppName").value = b.app_name || "";
          document.getElementById("brandTitle").value = b.dashboard_title || "";
          document.getElementById("brandSub").value = b.dashboard_sub || "";

          if (b.logo_etag) {
            const prev = document.getElementById("brandPreview");
            const img = document.getElementById("brandPreviewImg");

            img.src = `${BRANDING.LOGO_URL}?v=${encodeURIComponent(b.logo_etag)}`;
            prev.classList.remove("hidden");
          }
        } catch (e) {
          /* no-op */
        }

        const input = document.getElementById("brandLogo");
        input.addEventListener("change", (ev) => {
          const f = ev.target.files[0];
          if (!f) return;

          if (f.size > BRANDING.MAX_BYTES) {
            ev.target.value = "";
            Swal.showValidationMessage(
              `La imagen no debe superar ${(BRANDING.MAX_BYTES / 1024 / 1024).toFixed(0)} MB`,
            );
            return;
          }

          const url = URL.createObjectURL(f);
          const prev = document.getElementById("brandPreview");
          const img = document.getElementById("brandPreviewImg");

          img.src = url;
          prev.classList.remove("hidden");
        });
      },
      preConfirm: () => {
        const appName = document.getElementById("brandAppName").value.trim();
        const title = document.getElementById("brandTitle").value.trim();
        const sub = document.getElementById("brandSub").value.trim();
        const file = document.getElementById("brandLogo").files[0];

        if (file && file.size > BRANDING.MAX_BYTES) {
          Swal.showValidationMessage(
            `La imagen no debe superar ${(BRANDING.MAX_BYTES / 1024 / 1024).toFixed(0)} MB`,
          );
          return false;
        }

        return { appName, title, sub, file };
      },
    })
    .then(async (res) => {
      if (!res.isConfirmed) return;

      const { appName, title, sub, file } = res.value;
      const formData = new FormData();

      formData.append("app_name", appName);
      formData.append("dashboard_title", title);
      formData.append("dashboard_sub", sub);

      if (file) formData.append("logo", file);

      try {
        const rq = await fetch(BRANDING.SAVE_URL, {
          method: "POST",
          body: formData,
        });

        const data = await rq.json();

        if (data.ok) {
          await swalSuccess.fire(
            "✔️ Guardado",
            "Configuración actualizada",
            "success",
          );
          await cargarBranding();
        } else {
          swalError.fire("Error", data.msg || "No se pudo actualizar", "error");
        }
      } catch (e) {
        swalError.fire("Error", "Falló la petición: " + e, "error");
      }
    });
}

// ==========================================================
// Usuarios / Filtro global
// ==========================================================
async function cargarUsuariosGlobal() {
  try {
    const r = await fetch(`${PHP_BASE}usuarios_dashboard.php`, {
      cache: "no-store",
    });

    const data = await r.json();
    CURRENT_UID = Number(data.uid || 0) || 0;

    const sel = document.getElementById("sel-usuario-global");
    if (!sel) return;

    sel.innerHTML = "";

    data.opciones.forEach((o) => {
      const opt = document.createElement("option");
      opt.value = o.value;
      opt.textContent = o.text;

      if (o.disabled) opt.disabled = true;

      sel.appendChild(opt);
    });

    USER_FILTER = data.rol === "worker" ? "me" : "all";
    sel.value = USER_FILTER;

    if (data.rol === "worker") sel.disabled = true;

    sel.addEventListener("change", async () => {
      USER_FILTER = sel.value;
      await cargarTodo();
    });
  } catch (e) {
    console.error("No se pudo cargar usuarios:", e);
  }
}

// ==========================================================
// Dashboard principal POS
// ==========================================================
async function cargarTodo() {
  await cargarKPIs();
  await cargarCajaCard();
  await cargarMovimientosCard();
  await cargarPagosFinanciadosCard();
  await cargarSerie(
    "prod",
    document.getElementById("res-prod")?.value || "mes",
    "chart-prod",
  );
}

async function cargarKPIs() {
  try {
    const url = new URL(`${PHP_BASE}dashboard_resumen.php`, location.href);
    url.searchParams.set("period", "hoy");
    url.searchParams.set("user", USER_FILTER);

    const res = await fetch(url, { cache: "no-store" });
    const d = await res.json();

    // Ventas del día
    setText("#kpi-ventas", d.ventas_monto_fmt ?? "$0.00");
    setText("#kpi-ventas-det", d.ventas_detalle ?? "Periodo: hoy");

    // Stock bajo
    renderStockBajo(d.stock_bajo);

    // Producto más vendido
    renderProductoTop(d);

    // Últimas ventas
    renderUltimasVentas(d.ultimas_ventas || d.ventas_recientes || []);
  } catch (e) {
    console.error("Error al cargar KPIs:", e);
  }
}

function renderStockBajo(stockBajo) {
  const ulStock = document.getElementById("lista-stock-bajo");
  const foot = document.getElementById("stock-bajo-footer");

  if (!ulStock) return;

  ulStock.innerHTML = "";

  const items = Array.isArray(stockBajo) ? stockBajo : [];

  if (items.length === 0) {
    ulStock.innerHTML = `
      <li class="rounded-xl border border-slate-700/70 bg-slate-900/30 px-3 py-3 text-slate-400 text-sm">
        Sin alertas de stock
      </li>
    `;

    if (foot) foot.textContent = "";
    return;
  }

  items.forEach((it) => {
    const li = document.createElement("li");
    li.className =
      "flex items-center justify-between gap-3 bg-red-900/25 border border-red-500/25 rounded-xl px-3 py-2";

    li.innerHTML = `
      <span class="truncate text-slate-200">${escHtml(it.nombre || "Producto")}</span>
      <span class="shrink-0 text-red-300 font-semibold">${escHtml(it.stock ?? "0")}</span>
    `;

    ulStock.appendChild(li);
  });

  if (foot) {
    foot.textContent = `${items.length} producto(s) bajo umbral`;
  }
}

function renderProductoTop(d) {
  const nombreEl = document.getElementById("kpi-producto-top");
  const detEl = document.getElementById("kpi-producto-top-det");

  if (!nombreEl || !detEl) return;

  const top = d.producto_top || d.mas_vendido || d.producto_mas_vendido || null;

  if (!top) {
    nombreEl.textContent = "—";
    detEl.textContent = "Sin datos para hoy";
    return;
  }

  const nombre = top.nombre || top.producto || "Producto";
  const cantidad = Number(
    top.cantidad || top.unidades || top.total_unidades || 0,
  );

  nombreEl.textContent = nombre;
  detEl.textContent = cantidad
    ? `${cantidad} unidad(es) vendidas`
    : top.detalle || "Producto más vendido del día";
}

function renderUltimasVentas(items) {
  const wrap = document.getElementById("ultimas-ventas");
  if (!wrap) return;

  const ventas = Array.isArray(items) ? items : [];

  if (ventas.length === 0) {
    wrap.innerHTML = `
      <div class="rounded-xl border border-slate-700 bg-slate-900/50 p-4 text-slate-400 text-sm">
        Sin ventas recientes para mostrar.
      </div>
    `;
    return;
  }

  wrap.innerHTML = ventas
    .slice(0, 5)
    .map((v) => {
      const folio = v.venta_id || v.order_id || v.id || "—";
      const monto =
        v.total_fmt || v.monto_fmt || formatoMonedaMX(v.total || v.monto || 0);
      const metodo = v.metodo_pago || v.metodo || v.tipo || "—";
      const fecha = v.fecha_pago || v.fecha || v.hora || "";

      return `
        <div class="rounded-xl border border-slate-700/70 bg-slate-900/35 p-3 flex items-center justify-between gap-3">
          <div class="min-w-0">
            <p class="font-semibold text-slate-100 truncate">Venta ${escHtml(folio)}</p>
            <p class="text-xs text-slate-400 mt-1">
              ${escHtml(metodo)} ${fecha ? `· ${escHtml(fecha)}` : ""}
            </p>
          </div>
          <div class="shrink-0 font-bold text-emerald-300">
            ${escHtml(monto)}
          </div>
        </div>
      `;
    })
    .join("");
}

// ==========================================================
// Gráfica ventas de productos
// ==========================================================
async function cargarSerie(serie, resol, canvasId) {
  try {
    const url = new URL(`${PHP_BASE}dashboard_resumen.php`, location.href);
    url.searchParams.set("serie", serie);
    url.searchParams.set("res", resol);
    url.searchParams.set("user", USER_FILTER);

    const res = await fetch(url, { cache: "no-store" });
    const d = await res.json();

    const ctx = document.getElementById(canvasId);
    if (!ctx || typeof Chart === "undefined") return;

    if (charts[canvasId]) charts[canvasId].destroy();

    charts[canvasId] = new Chart(ctx, {
      type: "line",
      data: {
        labels: d.labels || [],
        datasets: [
          {
            label: "Ventas de productos",
            data: d.data || [],
            tension: 0.25,
            fill: false,
          },
        ],
      },
      options: {
        responsive: true,
        plugins: {
          legend: { display: false },
        },
        scales: {
          x: {
            grid: { color: "rgba(148,163,184,0.1)" },
            ticks: { color: "#cbd5e1" },
          },
          y: {
            grid: { color: "rgba(148,163,184,0.1)" },
            ticks: { color: "#cbd5e1" },
          },
        },
      },
    });
  } catch (e) {
    console.error("Error al cargar gráfica:", e);
  }
}

// ==========================================================
// Caja
// ==========================================================
async function cargarCajaCard() {
  const montoEl = document.getElementById("kpi-caja-monto");
  const updEl = document.getElementById("kpi-caja-actualizado");
  const btn = document.getElementById("btn-caja-editar");

  if (!montoEl || !updEl || !btn) return;

  if (USER_FILTER === "all") {
    montoEl.textContent = "—";
    updEl.textContent = "Selecciona un usuario";
    btn.disabled = true;
    return;
  }

  const url = new URL(`${PHP_BASE}caja_controller.php`, location.href);
  url.searchParams.set("action", "get");
  url.searchParams.set("user", USER_FILTER);

  try {
    const r = await fetch(url.toString(), { cache: "no-store" });
    const data = await r.json();

    if (!data.ok) throw new Error(data.error || "Error al cargar caja");

    const info = data.data || {
      monto: 0,
      fecha_actualizacion: null,
    };

    montoEl.textContent = formatoMonedaMX(info.monto);

    const fecha = info.fecha_actualizacion;

    if (fecha) {
      const d = new Date(fecha.replace(" ", "T"));
      const hoy = new Date();

      const mismoDia =
        d.getFullYear() === hoy.getFullYear() &&
        d.getMonth() === hoy.getMonth() &&
        d.getDate() === hoy.getDate();

      updEl.innerHTML = mismoDia
        ? `Última actualización: ${formatFechaCorta(info.fecha_actualizacion)}`
        : `Última actualización: ${formatFechaCorta(info.fecha_actualizacion)}
           <i class="bi bi-exclamation-triangle-fill text-amber-400 ml-1"
              title="No has actualizado tu caja hoy"></i>`;
    } else {
      updEl.textContent = "Sin actualizar";
    }

    btn.disabled = !data.allowEdit;
    btn.onclick = () => abrirModalEditarCaja(info.monto);
  } catch (e) {
    console.error(e);
    montoEl.textContent = "—";
    updEl.textContent = "Error al cargar";
    btn.disabled = true;
  }
}

function abrirModalEditarCaja(montoActual) {
  swalcard
    .fire({
      title: "Editar monto de caja",
      html: `
        <div class="text-left">
          <label class="block text-sm mb-1 text-slate-300">Monto (MXN)</label>
          <input id="cajaMonto" type="text" class="swal2-input !w-full" placeholder="0.00" value="${(Number(montoActual) || 0).toFixed(2)}">
          <p class="text-xs text-slate-400 mt-2">Este monto representa lo que dejas en caja. Se guarda por usuario.</p>
        </div>
      `,
      showCancelButton: true,
      confirmButtonText: "Guardar",
      cancelButtonText: "Cancelar",
      focusConfirm: false,
      preConfirm: () => {
        const val = document.getElementById("cajaMonto").value;
        const n = validarMontoStr(val);

        if (n === null) {
          Swal.showValidationMessage(
            "Ingresa un monto válido con hasta 2 decimales. Ejemplo: 1234.56",
          );
          return false;
        }

        return { monto: n };
      },
    })
    .then(async (res) => {
      if (!res.isConfirmed) return;

      const { monto } = res.value;

      try {
        const body = new FormData();
        body.append("action", "save");
        body.append("user", USER_FILTER);
        body.append("monto", String(monto));

        const rq = await fetch(`${PHP_BASE}caja_controller.php`, {
          method: "POST",
          body,
        });

        const data = await rq.json();

        if (data.ok) {
          await swalSuccess.fire(
            "✔️ Guardado",
            "Monto de caja actualizado",
            "success",
          );
          await cargarCajaCard();
        } else {
          swalError.fire("Error", data.error || "No se pudo guardar", "error");
        }
      } catch (e) {
        swalError.fire("Error", "Falló la petición", "error");
      }
    });
}

// ==========================================================
// Movimientos de caja
// ==========================================================
async function cargarMovimientosCard() {
  const netoEl = document.getElementById("kpi-mov-neto");
  const detEl = document.getElementById("kpi-mov-det");
  const btnNew = document.getElementById("btn-mov-nuevo");
  const btnVer = document.getElementById("btn-mov-ver");

  if (!netoEl || !detEl || !btnNew || !btnVer) return;

  if (USER_FILTER === "all") {
    netoEl.textContent = "—";
    detEl.textContent = "Selecciona un usuario";
    btnNew.disabled = true;
    btnVer.disabled = true;
    return;
  }

  const url = new URL(
    `${PHP_BASE}caja_movimientos_controller.php`,
    location.href,
  );
  url.searchParams.set("action", "resumen_hoy");
  url.searchParams.set("user", USER_FILTER);

  try {
    const r = await fetch(url, { cache: "no-store" });
    const d = await r.json();

    if (!d.ok) throw new Error(d.error || "Error");

    const ingreso = Number(d.ingreso || 0);
    const egreso = Number(d.egreso || 0);
    const neto = ingreso - egreso;

    netoEl.textContent = formatoMonedaMX(neto);
    detEl.textContent = `Ingresos: ${formatoMonedaMX(ingreso)} · Egresos: ${formatoMonedaMX(egreso)} · Movs: ${d.cantidad || 0}`;

    btnNew.disabled = false;
    btnNew.onclick = () => abrirModalMovimientoCajaSimple();

    btnVer.disabled = false;
    btnVer.onclick = () => abrirModalListadoMovHoy();
  } catch (e) {
    console.error(e);
    netoEl.textContent = "—";
    detEl.textContent = "Error al cargar";
    btnNew.disabled = true;
    btnVer.disabled = true;
  }
}

function abrirModalMovimientoCajaSimple() {
  swalcard
    .fire({
      title: "Nuevo movimiento",
      html: `
        <div class="text-left space-y-2">
          <label class="block text-sm text-slate-300">Tipo</label>
          <select id="movTipo" class="swal2-input !w-full">
            <option value="EGRESO">Egreso (sale dinero)</option>
            <option value="INGRESO">Ingreso (entra dinero)</option>
          </select>

          <label class="block text-sm text-slate-300">Monto (MXN)</label>
          <input id="movMonto" type="text" class="swal2-input !w-full" placeholder="0.00">

          <label class="block text-sm text-slate-300">Concepto</label>
          <input id="movConcepto" type="text" class="swal2-input !w-full" placeholder="Pago a proveedor, insumos, etc">

          <label class="block text-sm text-slate-300">Observaciones (opcional)</label>
          <textarea id="movObs" class="swal2-textarea !w-full" placeholder="Detalle / folio / nota"></textarea>

          <p class="text-xs text-slate-400 mt-2">
            Se guardará como movimiento para reportes. No modifica la card “Caja”.
          </p>
        </div>
      `,
      showCancelButton: true,
      confirmButtonText: "Guardar",
      cancelButtonText: "Cancelar",
      focusConfirm: false,
      preConfirm: () => {
        const tipo = document.getElementById("movTipo").value;
        const monto = validarMontoStr(
          document.getElementById("movMonto").value,
        );
        const concepto = (
          document.getElementById("movConcepto").value || ""
        ).trim();
        const observaciones = (
          document.getElementById("movObs").value || ""
        ).trim();

        if (!concepto) {
          Swal.showValidationMessage("Ingresa un concepto");
          return false;
        }

        if (monto === null || monto <= 0) {
          Swal.showValidationMessage(
            "Ingresa un monto válido mayor a 0. Ejemplo: 250.00",
          );
          return false;
        }

        return { tipo, monto, concepto, observaciones };
      },
    })
    .then(async (res) => {
      if (!res.isConfirmed) return;

      try {
        const body = new FormData();
        body.append("action", "crear");
        body.append("user", USER_FILTER);
        body.append("tipo", res.value.tipo);
        body.append("monto", String(res.value.monto));
        body.append("concepto", res.value.concepto);
        body.append("observaciones", res.value.observaciones);

        const rq = await fetch(`${PHP_BASE}caja_movimientos_controller.php`, {
          method: "POST",
          body,
        });

        const d = await rq.json();

        if (d.ok) {
          await swalSuccess.fire(
            "✔️ Guardado",
            "Movimiento registrado",
            "success",
          );
          await cargarMovimientosCard();
        } else {
          swalError.fire("Error", d.error || "No se pudo guardar", "error");
        }
      } catch (e) {
        swalError.fire("Error", "Falló la petición", "error");
      }
    });
}

async function abrirModalListadoMovHoy() {
  try {
    const url = new URL(
      `${PHP_BASE}caja_movimientos_controller.php`,
      location.href,
    );
    url.searchParams.set("action", "listar_hoy");
    url.searchParams.set("user", USER_FILTER);

    const r = await fetch(url, { cache: "no-store" });
    const d = await r.json();

    if (!d.ok) throw new Error(d.error || "Error");

    const rows = Array.isArray(d.items) ? d.items : [];

    const html = rows.length
      ? `
        <div class="text-left max-h-80 overflow-auto pr-1 scrollbar-custom">
          ${rows
            .map(
              (x) => `
                <div class="mb-2 p-2 rounded-lg border border-slate-600/40 bg-slate-700/30">
                  <div class="flex justify-between">
                    <span class="${x.tipo === "INGRESO" ? "text-green-300" : "text-rose-300"} font-semibold">
                      ${escHtml(x.tipo)}
                    </span>
                    <span class="font-semibold">${formatoMonedaMX(x.monto)}</span>
                  </div>
                  <div class="text-xs text-slate-300 mt-1">${escHtml(x.concepto || "")}</div>
                  <div class="text-xs text-slate-400">${escHtml(x.fecha || "")}</div>
                </div>
              `,
            )
            .join("")}
        </div>
      `
      : `<p class="text-slate-300">Sin movimientos hoy.</p>`;

    swalcard.fire({
      title: "Movimientos de hoy",
      html,
      confirmButtonText: "Cerrar",
    });
  } catch (e) {
    swalError.fire("Error", "No se pudo cargar el listado");
  }
}
/* =========================================================
   Ventas financiadas - Card dashboard POS
========================================================= */

const FINANCIADAS_DASH_ENDPOINT = `${PHP_BASE}ventas_financiadas_controller.php`;

function vfMoneyDash(value) {
  const n = Number(value || 0);

  return n.toLocaleString("es-MX", {
    style: "currency",
    currency: "MXN",
  });
}

function vfFechaDash(value) {
  if (!value) return "—";

  const d = new Date(String(value) + "T00:00:00");

  if (Number.isNaN(d.getTime())) {
    return value;
  }

  return d.toLocaleDateString("es-MX", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  });
}

function vfDiasParaVencer(fecha) {
  if (!fecha) return null;

  const hoy = new Date();
  hoy.setHours(0, 0, 0, 0);

  const venc = new Date(String(fecha) + "T00:00:00");
  venc.setHours(0, 0, 0, 0);

  if (Number.isNaN(venc.getTime())) return null;

  const diffMs = venc.getTime() - hoy.getTime();

  return Math.round(diffMs / 86400000);
}

function vfEtiquetaTiempo(fecha) {
  const dias = vfDiasParaVencer(fecha);

  if (dias === null) return "Sin fecha";

  if (dias < 0) {
    return `Vencida hace ${Math.abs(dias)} día${Math.abs(dias) === 1 ? "" : "s"}`;
  }

  if (dias === 0) return "Vence hoy";
  if (dias === 1) return "Vence mañana";

  return `Vence en ${dias} días`;
}

function vfClaseTiempo(fecha) {
  const dias = vfDiasParaVencer(fecha);

  if (dias === null) return "text-slate-400";
  if (dias < 0) return "text-red-300";
  if (dias <= 3) return "text-amber-300";

  return "text-sky-300";
}

async function cargarPagosFinanciadosCard() {
  const lista = document.getElementById("lista-pagos-financiados");
  const count = document.getElementById("pagos-financiados-count");
  const disponible = document.getElementById("pagos-financiados-disponible");
  const vencidos = document.getElementById("pagos-financiados-vencidos");
  const footer = document.getElementById("pagos-financiados-footer");

  if (!lista) return;

  lista.innerHTML = `
    <li class="rounded-xl border border-slate-700 bg-slate-900/50 p-3 text-sm text-slate-400 text-center">
      Cargando pagos próximos...
    </li>
  `;

  try {
    const body = new FormData();
    body.append("accion", "listar_pagos_proximos_dashboard");

    const r = await fetch(FINANCIADAS_DASH_ENDPOINT, {
      method: "POST",
      body,
      cache: "no-store",
    });

    const text = await r.text();

    let data;

    try {
      data = JSON.parse(text);
    } catch (error) {
      console.error(
        "Respuesta cruda de ventas_financiadas_controller.php:",
        text,
      );

      throw new Error(
        text.trim()
          ? "El controlador respondió algo que no es JSON. Revisa la consola para ver la respuesta cruda."
          : "El controlador respondió vacío. Revisa errores PHP, ruta del archivo o el case listar_pagos_proximos_dashboard.",
      );
    }

    if (!r.ok || !data.success) {
      throw new Error(
        data.detalle || data.error || "No se pudo cargar la card.",
      );
    }

    const pagos = Array.isArray(data.pagos) ? data.pagos : [];
    const resumen = data.resumen || {};

    if (count) count.textContent = resumen.total_items ?? pagos.length;
    if (disponible)
      disponible.textContent = vfMoneyDash(resumen.total_disponible || 0);
    if (vencidos) vencidos.textContent = resumen.vencidos || 0;

    if (footer) {
      footer.textContent = pagos.length
        ? `Pagos dentro de ±5 días`
        : "Sin pagos próximos en ±5 días";
    }

    renderPagosFinanciadosCard(pagos);
  } catch (e) {
    console.error("Pagos financiados dashboard:", e);

    if (count) count.textContent = "—";
    if (disponible) disponible.textContent = "—";
    if (vencidos) vencidos.textContent = "—";

    lista.innerHTML = `
      <li class="rounded-xl border border-red-700/50 bg-red-900/20 p-3 text-sm text-red-200">
        <i class="bi bi-exclamation-triangle mr-1"></i>
        No se pudieron cargar los pagos próximos.
      </li>
    `;

    if (footer) footer.textContent = "Error al cargar";
  }
}

function renderPagosFinanciadosCard(pagos) {
  const lista = document.getElementById("lista-pagos-financiados");

  if (!lista) return;

  if (!pagos.length) {
    lista.innerHTML = `
      <li class="rounded-xl border border-slate-700 bg-slate-900/50 p-4 text-sm text-slate-400 text-center">
        <i class="bi bi-check2-circle text-emerald-300 mr-1"></i>
        No hay pagos próximos en ±5 días.
      </li>
    `;
    return;
  }

  lista.innerHTML = pagos
    .map((pago) => {
      const dias = vfDiasParaVencer(pago.fecha_vencimiento);
      const esVencido = dias !== null && dias < 0;

      return `
      <li class="rounded-xl border ${esVencido ? "border-red-500/40 bg-red-950/20" : "border-slate-700 bg-slate-900/50"} p-3 hover:bg-slate-800/50 transition">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="font-semibold text-white truncate">
              ${escHtml(pago.cliente_nombre || "Sin cliente")}
            </p>

            <p class="text-xs text-slate-400 mt-1">
              ${escHtml(pago.folio || "—")} · Cuota ${escHtml(pago.numero_cuota || "—")}
            </p>

            <p class="text-xs mt-1 ${vfClaseTiempo(pago.fecha_vencimiento)}">
              <i class="bi bi-calendar2-week mr-1"></i>
              ${vfEtiquetaTiempo(pago.fecha_vencimiento)} · ${vfFechaDash(pago.fecha_vencimiento)}
            </p>

            <p class="text-sm font-bold text-emerald-300 mt-2">
              ${vfMoneyDash(pago.saldo_cuota)}
            </p>

            <button type="button"
              class="btn-abono-financiado-dash mt-2 px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition"
              data-venta-id="${Number(pago.venta_id || 0)}"
              data-cuota-id="${Number(pago.cuota_id || 0)}"
              data-folio="${escAttr(pago.folio || "")}"
              data-cliente="${escAttr(pago.cliente_nombre || "")}"
              data-numero-cuota="${Number(pago.numero_cuota || 0)}"
              data-fecha="${escAttr(pago.fecha_vencimiento || "")}"
              data-saldo="${Number(pago.saldo_cuota || 0)}">
              Abonar
            </button>
          </div>
        </div>
      </li>
    `;
    })
    .join("");
}

function initPagosFinanciadosDashboard() {
  const lista = document.getElementById("lista-pagos-financiados");

  lista?.addEventListener("click", (ev) => {
    const btn = ev.target.closest(".btn-abono-financiado-dash");
    if (!btn) return;

    const pago = {
      venta_id: Number(btn.dataset.ventaId || 0),
      cuota_id: Number(btn.dataset.cuotaId || 0),
      folio: btn.dataset.folio || "",
      cliente_nombre: btn.dataset.cliente || "",
      numero_cuota: Number(btn.dataset.numeroCuota || 0),
      fecha_vencimiento: btn.dataset.fecha || "",
      saldo_cuota: Number(btn.dataset.saldo || 0),
    };

    abrirModalAbonoFinanciadoDashboard(pago);
  });

  document
    .getElementById("btn-cerrar-abono-financiado-dashboard")
    ?.addEventListener("click", cerrarModalAbonoFinanciadoDashboard);

  document
    .getElementById("btn-cancelar-abono-financiado-dashboard")
    ?.addEventListener("click", cerrarModalAbonoFinanciadoDashboard);

  document
    .getElementById("btn-guardar-abono-financiado-dashboard")
    ?.addEventListener("click", guardarAbonoFinanciadoDashboard);
}

function abrirModalAbonoFinanciadoDashboard(pago) {
  const ventaId = Number(pago.venta_id || 0);
  const cuotaId = Number(pago.cuota_id || 0);
  const saldoMax = Number(pago.saldo_cuota || 0);

  if (!ventaId || !cuotaId || saldoMax <= 0) {
    Swal.fire({
      icon: "error",
      title: "Error",
      text: "El pago seleccionado no es válido.",
      background: "#1e293b",
      color: "#f8fafc",
    });
    return;
  }

  const modal = document.getElementById("modal-abono-financiado-dashboard");
  const inputVenta = document.getElementById("dash-abono-venta-id");
  const inputCuota = document.getElementById("dash-abono-cuota-id");
  const inputSaldo = document.getElementById("dash-abono-saldo-max");
  const inputMonto = document.getElementById("dash-abono-monto");
  const inputMetodo = document.getElementById("dash-abono-metodo");
  const inputReferencia = document.getElementById("dash-abono-referencia");
  const inputObservaciones = document.getElementById(
    "dash-abono-observaciones",
  );
  const sub = document.getElementById("dash-abono-subtitulo");
  const saldoTexto = document.getElementById("dash-abono-saldo-texto");

  if (
    !modal ||
    !inputVenta ||
    !inputCuota ||
    !inputSaldo ||
    !inputMonto ||
    !inputMetodo ||
    !inputReferencia ||
    !inputObservaciones
  ) {
    Swal.fire({
      icon: "error",
      title: "Modal incompleto",
      text: "Falta agregar el modal de abono financiado en dashboard.php o algún ID no coincide.",
      background: "#1e293b",
      color: "#f8fafc",
    });
    return;
  }

  inputVenta.value = ventaId;
  inputCuota.value = cuotaId;
  inputSaldo.value = saldoMax.toFixed(2);

  inputMonto.value = saldoMax.toFixed(2);
  inputMonto.max = saldoMax.toFixed(2);

  inputMetodo.value = "efectivo";
  inputReferencia.value = "";
  inputObservaciones.value = "";

  if (sub) {
    sub.textContent = `${pago.folio || ""} · ${pago.cliente_nombre || ""} · Cuota ${pago.numero_cuota || ""}`;
  }

  if (saldoTexto) {
    saldoTexto.textContent = vfMoneyDash(saldoMax);
  }

  modal.classList.remove("hidden");
  modal.classList.add("flex");
}

function cerrarModalAbonoFinanciadoDashboard() {
  const modal = document.getElementById("modal-abono-financiado-dashboard");

  if (!modal) return;

  modal.classList.add("hidden");
  modal.classList.remove("flex");

  const inputVenta = document.getElementById("dash-abono-venta-id");
  const inputCuota = document.getElementById("dash-abono-cuota-id");
  const inputSaldo = document.getElementById("dash-abono-saldo-max");
  const inputMonto = document.getElementById("dash-abono-monto");
  const inputMetodo = document.getElementById("dash-abono-metodo");
  const inputReferencia = document.getElementById("dash-abono-referencia");
  const inputObservaciones = document.getElementById(
    "dash-abono-observaciones",
  );

  if (inputVenta) inputVenta.value = "";
  if (inputCuota) inputCuota.value = "";
  if (inputSaldo) inputSaldo.value = "";
  if (inputMonto) inputMonto.value = "";
  if (inputMetodo) inputMetodo.value = "efectivo";
  if (inputReferencia) inputReferencia.value = "";
  if (inputObservaciones) inputObservaciones.value = "";

  document.body.classList.remove("overflow-hidden");
}

async function guardarAbonoFinanciadoDashboard() {
  const ventaId = Number(
    document.getElementById("dash-abono-venta-id")?.value || 0,
  );
  const cuotaId = Number(
    document.getElementById("dash-abono-cuota-id")?.value || 0,
  );
  const saldoMax = Number(
    document.getElementById("dash-abono-saldo-max")?.value || 0,
  );
  const monto = Number(document.getElementById("dash-abono-monto")?.value || 0);
  const metodoPago =
    document.getElementById("dash-abono-metodo")?.value || "efectivo";
  const referencia =
    document.getElementById("dash-abono-referencia")?.value || "";
  const observaciones =
    document.getElementById("dash-abono-observaciones")?.value || "";

  if (!ventaId || !cuotaId) {
    Swal.fire({
      icon: "warning",
      title: "Pago inválido",
      text: "No se encontró la venta o cuota.",
      background: "#1e293b",
      color: "#f8fafc",
    });
    return;
  }

  if (monto <= 0) {
    Swal.fire({
      icon: "warning",
      title: "Monto inválido",
      text: "El monto debe ser mayor a 0.",
      background: "#1e293b",
      color: "#f8fafc",
    });
    return;
  }

  if (monto > saldoMax) {
    Swal.fire({
      icon: "warning",
      title: "Monto excedido",
      text: `El máximo permitido para esta cuota es ${vfMoneyDash(saldoMax)}.`,
      background: "#1e293b",
      color: "#f8fafc",
    });
    return;
  }

  const btn = document.getElementById("btn-guardar-abono-financiado-dashboard");

  try {
    if (btn) {
      btn.disabled = true;
      btn.classList.add("opacity-60", "cursor-not-allowed");
    }

    const body = new FormData();
    body.append("accion", "registrar_abono");
    body.append("venta_id", ventaId);
    body.append("cuota_id", cuotaId);
    body.append("monto", monto);
    body.append("metodo_pago", metodoPago);
    body.append("referencia", referencia);
    body.append("observaciones", observaciones);

    const r = await fetch(FINANCIADAS_DASH_ENDPOINT, {
      method: "POST",
      body,
    });

    const data = await r.json();

    if (!data.success) {
      throw new Error(
        data.detalle || data.error || "No se pudo registrar el abono.",
      );
    }

    cerrarModalAbonoFinanciadoDashboard();

    await Swal.fire({
      icon: "success",
      title: "Abono registrado",
      text: data.mensaje || "El abono fue registrado correctamente.",
      background: "#1e293b",
      color: "#f8fafc",
    });

    await cargarPagosFinanciadosCard();

    if (typeof cargarTodo === "function") {
      await cargarTodo();
    }
  } catch (e) {
    Swal.fire({
      icon: "error",
      title: "No se pudo registrar",
      text: e.message,
      background: "#1e293b",
      color: "#f8fafc",
    });
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.classList.remove("opacity-60", "cursor-not-allowed");
    }
  }
}
// ==========================================================
// Modal rápido POS con iframe
// ==========================================================
let POS_MODAL_CURRENT_URL = "";

function initModalPosRapido() {
  const modal = document.getElementById("modal-pos-rapido");
  const frame = document.getElementById("modal-pos-frame");
  const title = document.getElementById("modal-pos-title");
  const loading = document.getElementById("modal-pos-loading");

  const btnCerrar = document.getElementById("btn-modal-pos-cerrar");
  const btnRecargar = document.getElementById("btn-modal-pos-recargar");
  const btnAbrir = document.getElementById("btn-modal-pos-abrir");

  if (!modal || !frame || !title) return;

  document.querySelectorAll("[data-pos-modal-url]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const url = btn.dataset.posModalUrl || "";
      const modalTitle = btn.dataset.posModalTitle || "Ventana rápida";

      abrirModalPosRapido(url, modalTitle);
    });
  });

  btnCerrar?.addEventListener("click", cerrarModalPosRapido);

  btnRecargar?.addEventListener("click", () => {
    if (!POS_MODAL_CURRENT_URL) return;

    if (loading) loading.classList.remove("hidden");

    frame.src = POS_MODAL_CURRENT_URL;
  });

  btnAbrir?.addEventListener("click", () => {
    if (!POS_MODAL_CURRENT_URL) return;
    window.open(POS_MODAL_CURRENT_URL, "_blank");
  });

  frame.addEventListener("load", () => {
    if (loading) loading.classList.add("hidden");
  });

  modal.addEventListener("click", (e) => {
    if (e.target === modal) {
      cerrarModalPosRapido();
    }
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && !modal.classList.contains("hidden")) {
      cerrarModalPosRapido();
    }
  });
}

function abrirModalPosRapido(url, modalTitle = "Ventana rápida") {
  const modal = document.getElementById("modal-pos-rapido");
  const frame = document.getElementById("modal-pos-frame");
  const title = document.getElementById("modal-pos-title");
  const loading = document.getElementById("modal-pos-loading");

  if (!modal || !frame || !title || !url) return;

  POS_MODAL_CURRENT_URL = url;

  title.textContent = modalTitle;

  if (loading) loading.classList.remove("hidden");

  modal.classList.remove("hidden");
  document.body.classList.add("overflow-hidden");

  frame.src = url;
}

function cerrarModalPosRapido() {
  const modal = document.getElementById("modal-pos-rapido");
  const frame = document.getElementById("modal-pos-frame");

  if (!modal || !frame) return;

  modal.classList.add("hidden");
  document.body.classList.remove("overflow-hidden");

  frame.src = "";
  POS_MODAL_CURRENT_URL = "";

  // Refrescar dashboard al cerrar por si hubo ventas, productos o movimientos
  cargarTodo();
}
