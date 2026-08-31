/*
 * MODALES DE GESTIÓN
 * ===================
 * Abrir/cerrar cualquier <dialog> de la portada de Gestión —crear/editar/
 * eliminar periodo, y "Nueva área" de Departamentos— vía delegación genérica
 * sobre [data-abre-modal]/[data-cierra-modal]. El nombre del archivo quedó
 * de cuando solo existían los de Periodo; un modal nuevo que no necesite el
 * paso extra de sincronizar un desplegable (ver más abajo) no necesita tocar
 * este archivo, solo poner esos dos atributos.
 *
 * Delegación de eventos sobre `document`, como el resto de los scripts de la
 * app: los formularios y diálogos viven dentro de <main>, que `acciones.js`
 * puede reemplazar entero tras cualquier envío (éxito o error de
 * validación), así que un listener puesto directo sobre un elemento quedaría
 * huérfano en cuanto eso pase.
 *
 * Reabrir el modal correcto tras un error de validación NO lo hace este
 * archivo: lo resuelve un <script> que Blade pinta solo cuando hace falta, en
 * el parcial de cada modal (`periodo-modales.blade.php`, `area-modales.blade.php`).
 */
(function () {
  "use strict";

  // Qué <select> de cada modal hay que sincronizar con "Periodo en curso" al
  // abrirlo, y con qué modal va cada uno. "Nuevo" no entra: no parte de un
  // periodo ya elegido.
  var SELECTOR_POR_MODAL = {
    "modal-periodo-editar": "[data-select-periodo-editar]",
    "modal-periodo-eliminar": "[data-select-periodo-eliminar]",
  };

  document.addEventListener("click", function (evento) {
    var abre = evento.target.closest("[data-abre-modal]");
    if (abre) {
      var idDialogo = abre.getAttribute("data-abre-modal");
      var dialogo = document.getElementById(idDialogo);
      if (!dialogo) { return; }

      // Editar y Eliminar parten de lo que ya está elegido en "Periodo en
      // curso" arriba —el mismo periodo sobre el que actuaría "Poner en
      // curso"— en vez de obligar a elegirlo otra vez dentro del modal.
      var selectorDelModal = SELECTOR_POR_MODAL[idDialogo];
      if (selectorDelModal) {
        var enCurso = document.getElementById("id_periodo_en_curso");
        var selectModal = dialogo.querySelector(selectorDelModal);

        if (enCurso && selectModal && enCurso.value) {
          var tieneEsaOpcion = Array.prototype.some.call(selectModal.options, function (o) {
            return o.value === enCurso.value;
          });

          if (tieneEsaOpcion) {
            selectModal.value = enCurso.value;
            selectModal.dispatchEvent(new Event("change", { bubbles: true }));
          }
        }
      }

      dialogo.showModal();
      return;
    }

    var cierra = evento.target.closest("[data-cierra-modal]");
    if (cierra) {
      var propio = cierra.closest("dialog");
      if (propio) { propio.close(); }
      return;
    }

    var pedirConfirmacion = evento.target.closest("[data-pedir-confirmacion]");
    if (pedirConfirmacion && !pedirConfirmacion.disabled) {
      var select = document.querySelector("[data-select-periodo-eliminar]");
      var opcion = select && select.options[select.selectedIndex];
      if (!opcion || !opcion.value) { return; }

      var arrastre = opcion.getAttribute("data-arrastre") || "";
      var texto = arrastre
        ? "¿Eliminar «" + opcion.text.trim() + "»? Se llevará también " + arrastre + ". No se puede deshacer."
        : "¿Eliminar «" + opcion.text.trim() + "»? Esta acción no se puede deshacer.";

      var form = document.querySelector("[data-form-periodo-eliminar]");
      form.action = opcion.getAttribute("data-eliminar-url");
      form.querySelector("[data-texto-confirmar]").textContent = texto;
      form.querySelector("[data-seccion-elegir]").hidden = true;
      form.querySelector("[data-seccion-confirmar]").hidden = false;
      return;
    }

    var volver = evento.target.closest("[data-volver-a-elegir]");
    if (volver) {
      var formVolver = volver.closest("[data-form-periodo-eliminar]");
      formVolver.querySelector("[data-seccion-confirmar]").hidden = true;
      formVolver.querySelector("[data-seccion-elegir]").hidden = false;
      return;
    }
  });

  document.addEventListener("change", function (evento) {
    if (evento.target.matches("[data-select-periodo-editar]")) {
      var opcion = evento.target.options[evento.target.selectedIndex];
      var form = document.querySelector("[data-form-periodo-editar]");
      var campos = form.querySelector("[data-campos-periodo-editar]");

      if (!opcion.value) {
        campos.hidden = true;
        return;
      }

      form.action = opcion.getAttribute("data-editar-url");
      form.querySelector("[data-campo-periodo-id]").value = opcion.value;
      form.querySelector("#pe-nombre").value = opcion.getAttribute("data-nombre");
      form.querySelector("#pe-inicio").value = opcion.getAttribute("data-fecha-inicio");
      form.querySelector("#pe-fin").value = opcion.getAttribute("data-fecha-fin");
      campos.hidden = false;
      return;
    }

    if (evento.target.matches("[data-select-periodo-eliminar]")) {
      var elegido = evento.target.options[evento.target.selectedIndex];
      var indicador = document.querySelector("[data-indicador-seleccion]");
      var boton = document.querySelector("[data-pedir-confirmacion]");

      if (!elegido.value) {
        indicador.hidden = true;
        boton.disabled = true;
        return;
      }

      var bloqueos = elegido.getAttribute("data-bloqueos") || "";
      indicador.hidden = false;

      if (bloqueos) {
        indicador.textContent = "«" + elegido.text.trim() + "» todavía tiene " + bloqueos + ": no se puede eliminar.";
        boton.disabled = true;
      } else {
        indicador.textContent = "Seleccionado: «" + elegido.text.trim() + "».";
        boton.disabled = false;
      }
    }
  });
})();
