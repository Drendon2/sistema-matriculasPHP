/*
 * Dos ayudas del formulario publico de inscripcion. Puerto de los scripts que
 * el original lleva embebidos en `inscripcion.html`.
 *
 * Ninguna de las dos es una validacion: el servidor comprueba lo mismo otra vez.
 * Sin JavaScript el formulario se envia igual y los errores vuelven marcados.
 */

/* ---------------------------------------------------------------------------
 * 1. Cupos de promotoria
 *
 * Solo el primero ocupa sitio de entrada; los demas se piden uno a uno, para no
 * cargar el formulario publico con cinco desplegables vacios.
 * ------------------------------------------------------------------------- */
(function () {
  var raiz = document.getElementById("promotorias");
  if (!raiz) { return; }

  var selects = Array.prototype.slice.call(raiz.querySelectorAll("select"));
  var extras = Array.prototype.slice.call(raiz.querySelectorAll(".promo-extra"));
  var anadir = raiz.querySelector(".promo-anadir");
  var cuenta = raiz.querySelector("[data-cuenta]");
  var limite = selects.length;
  if (!limite || !cuenta) { return; }

  function visible(campo) { return !campo.hasAttribute("hidden"); }

  function actualizar() {
    // Un cupo oculto no cuenta aunque conserve valor.
    var usados = selects.filter(function (s) {
      var campo = s.closest(".promo-campo");
      return s.value && (!campo || visible(campo));
    }).length;
    cuenta.textContent = usados + " de " + limite;

    // La misma promotoria no puede ocupar dos cupos.
    var tomadas = selects.filter(function (s) { return s.value; })
      .map(function (s) { return s.value; });
    selects.forEach(function (s) {
      Array.prototype.forEach.call(s.options, function (o) {
        o.disabled = o.value !== "" && o.value !== s.value && tomadas.indexOf(o.value) !== -1;
      });
    });

    // El boton desaparece cuando ya no queda ningun cupo por abrir.
    if (anadir) {
      anadir.hidden = !extras.some(function (c) { return !visible(c); });
    }
  }

  if (anadir) {
    anadir.addEventListener("click", function () {
      for (var i = 0; i < extras.length; i++) {
        if (!visible(extras[i])) {
          extras[i].removeAttribute("hidden");
          var s = extras[i].querySelector("select");
          actualizar();
          if (s) { s.focus(); }
          return;
        }
      }
    });
  }

  raiz.addEventListener("click", function (evento) {
    var boton = evento.target.closest(".promo-quitar");
    if (!boton || !raiz.contains(boton)) { return; }
    var campo = boton.closest(".promo-campo");
    var s = campo.querySelector("select");
    if (s) { s.value = ""; }
    campo.setAttribute("hidden", "");
    actualizar();
    if (anadir && !anadir.hidden) { anadir.focus(); }
  });

  raiz.addEventListener("change", function (evento) {
    if (evento.target.tagName === "SELECT") { actualizar(); }
  });

  actualizar();
})();

/* ---------------------------------------------------------------------------
 * 2. Acudiente segun la edad
 *
 * El bloque del acudiente se enciende solo cuando la fecha de nacimiento dice
 * que la persona es menor. La misma cuenta la repite el servidor
 * (Perfil::edadDe), que es quien decide de verdad.
 * ------------------------------------------------------------------------- */
(function () {
  var fecha = document.getElementById("fecha_nacimiento");
  var fieldset = document.getElementById("fieldset-acudiente");
  var nota = document.getElementById("acudiente-nota");
  var nombre = document.getElementById("acudiente_nombre");
  var marcaRequerido = document.getElementById("acudiente-nombre-requerido");
  if (!fecha || !fieldset || !nota || !nombre || !marcaRequerido) { return; }

  function esMenorDeEdad(valorIso) {
    var partes = valorIso.split("-");
    if (partes.length !== 3) { return null; }
    var nacimiento = new Date(Number(partes[0]), Number(partes[1]) - 1, Number(partes[2]));
    if (isNaN(nacimiento.getTime())) { return null; }
    var hoy = new Date();
    var edad = hoy.getFullYear() - nacimiento.getFullYear();
    var cumpleanosNoLlega = hoy.getMonth() < nacimiento.getMonth() ||
      (hoy.getMonth() === nacimiento.getMonth() && hoy.getDate() < nacimiento.getDate());
    if (cumpleanosNoLlega) { edad -= 1; }
    return edad < 18;
  }

  function actualizar() {
    var menor = esMenorDeEdad(fecha.value);
    if (menor) {
      fieldset.dataset.activo = "true";
      nota.textContent = "Obligatorio: tu fecha de nacimiento indica que eres menor de edad.";
      nombre.required = true;
      nombre.setAttribute("aria-required", "true");
      marcaRequerido.hidden = false;
    } else {
      fieldset.dataset.activo = "false";
      nota.textContent = menor === null
        ? "Se activa automáticamente si tu fecha de nacimiento indica que eres menor de edad."
        : "No es obligatorio: tu fecha de nacimiento indica que eres mayor de edad.";
      nombre.required = false;
      nombre.setAttribute("aria-required", "false");
      marcaRequerido.hidden = true;
    }
  }

  fecha.addEventListener("change", actualizar);
  fecha.addEventListener("input", actualizar);
  actualizar();
})();
