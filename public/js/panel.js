/*
 * PANEL: CARGA AL DESPLEGAR
 * =========================
 * Una sola cosa, y solo hace falta en el Panel: el cuerpo de cada promotoría se
 * pide cuando alguien la abre, no antes. El índice manda solo los títulos; con
 * trescientos estudiantes, mandarlo todo eran cientos de KB para enseñar una
 * lista de veintiún renglones plegados.
 *
 * La casilla de «todos» y la cuenta de marcados vivían aquí y se fueron a
 * lote.js: dejaron de ser cosa del Panel en cuanto los pendientes y las
 * cancelaciones empezaron a usarlas.
 *
 * Va como ARCHIVO y fuera de <main>. Los scripts que viven dentro se vuelven a
 * ejecutar en cada repintado sin recarga (ver acciones.js), y como estos delegan
 * en `document`, cada repintado dejaría un oyente más escuchando lo mismo.
 * Cargado desde el <head> del layout se registra una vez y sobrevive a los
 * cambios de <main>, que es exactamente lo que necesita.
 *
 * Sin JavaScript el Panel sigue sirviendo: cada promotoría lleva dentro un
 * <noscript> con el enlace a su propia página.
 */
(function () {
  "use strict";

  /* ---------------------------------------------------------------------
     1. El cuerpo de una promotoría, al desplegarla
     --------------------------------------------------------------------- */

  function cargar(detalle) {
    var destino = detalle.querySelector("[data-cuerpo-destino]");
    var url = detalle.getAttribute("data-cuerpo");
    if (!destino || !url || detalle.dataset.cargando === "si") { return; }

    // `data-cargado` se marca sobre el DESTINO y no sobre el <details>: tras un
    // repintado sin recarga el <details> es un elemento nuevo con el mismo id,
    // así que una marca en él viajaría con datos viejos. En el destino no: el
    // destino nuevo llega sin marcar y se vuelve a pedir, que es lo correcto
    // después de confirmar o rechazar algo.
    if (destino.dataset.cargado === "si") { return; }

    detalle.dataset.cargando = "si";

    fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) {
        if (!r.ok) { throw new Error(r.status); }
        return r.text();
      })
      .then(function (html) {
        destino.innerHTML = html;
        destino.dataset.cargado = "si";
        refrescarLotes(destino);
      })
      .catch(function () {
        // Un fallo de red no puede dejar la promotoría en "Cargando…" para
        // siempre: se dice qué pasó y se deja una salida que no depende de que
        // el JavaScript vuelva a funcionar.
        destino.innerHTML =
          '<p class="aviso">No se pudo cargar esta promotoría. ' +
          '<a href="' + url + '">Ábrela en su propia página</a> o vuelve a intentarlo.</p>';
      })
      .finally(function () {
        delete detalle.dataset.cargando;
      });
  }

  // `toggle` no burbujea, así que se escucha en fase de captura. Es lo que
  // permite un solo oyente para todas las promotorías, incluidas las que
  // aparezcan después de un repintado.
  document.addEventListener(
    "toggle",
    function (evento) {
      var detalle = evento.target;
      if (detalle.matches && detalle.matches("details[data-cuerpo]") && detalle.open) {
        cargar(detalle);
      }
    },
    true
  );

  // Las tablas recien inyectadas traen sus casillas: se avisa a lote.js para que
  // ponga al dia la cuenta y los botones de esa promotoria.
  function refrescarLotes(raiz) {
    document.dispatchEvent(new CustomEvent("lote:refrescar", { detail: { raiz: raiz } }));
  }
})();
