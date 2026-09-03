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
    // destino llega nuevo en cada repintado y dice la verdad sobre SU contenido.
    //
    // Hay dos maneras de que ya esté puesto, y las dos acaban aquí: porque lo
    // trajo este archivo hace un rato, o porque venía dentro de la respuesta de
    // una acción, que el servidor pinta y marca (ver `App\Support\Fragmento`).
    // No hay nada que pedir, pero sí que aplicar lo que depende de tener el
    // cuerpo delante — sin esto, una promotoría repintada sale con sus grupos
    // plegados y con la cuenta de marcados en cero.
    if (destino.dataset.cargado === "si") {
      colocado(destino);
      return;
    }

    detalle.dataset.cargando = "si";

    fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) {
        if (!r.ok) { throw new Error(r.status); }
        return r.text();
      })
      .then(function (html) {
        destino.innerHTML = html;
        destino.dataset.cargado = "si";
        colocado(destino);
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

  // Lo que hay que hacer cuando el cuerpo de una promotoria esta delante, venga
  // de donde venga. En un solo sitio a proposito: son dos caminos —pedirlo al
  // desplegar y recibirlo dentro de una accion— y separarlos garantizaba que uno
  // de los dos se quedara sin la mitad de esto la proxima vez que se anada algo.
  function colocado(destino) {
    reabrirGrupos(destino);
    refrescarLotes(destino);
  }

  // Las tablas recien inyectadas traen sus casillas: se avisa a lote.js para que
  // ponga al dia la cuenta y los botones de esa promotoria.
  function refrescarLotes(raiz) {
    document.dispatchEvent(new CustomEvent("lote:refrescar", { detail: { raiz: raiz } }));
  }

  /* ---------------------------------------------------------------------
     2. Las listas de grupo abiertas, que sobreviven al repintado
     --------------------------------------------------------------------- */

  /*
   * Las listas de estudiantes de cada grupo van plegadas y se abren a mano. Al
   * quitar a alguien de un grupo, `acciones.js` repinta <main> sin recargar y
   * la lista que estabas mirando se cerraria sola.
   *
   * Y NO lo arregla `acciones.js`, aunque ya guarde y reponga los <details> con
   * id: los repone JUSTO despues de reemplazar <main>, y en ese momento el
   * cuerpo de la promotoria todavia dice «Cargando…» — estos <details> no
   * existen aun, asi que no hay nada que reponer. Se piden despues, y para
   * entonces `acciones.js` ya termino.
   *
   * De ahi que el recuerdo viva AQUI, que es quien inyecta ese cuerpo y sabe
   * cuando esta puesto. En memoria y no en `sessionStorage` a proposito: es una
   * comodidad de un rato, no un ajuste; al recargar la pagina se vuelve al
   * estado de partida, que es plegado.
   *
   * Sobrevive a los repintados porque este archivo se carga UNA vez y fuera de
   * <main> (ver la cabecera): la variable sigue ahi cuando el HTML se rehace.
   */
  var gruposAbiertos = {};

  document.addEventListener(
    "toggle",
    function (evento) {
      var detalle = evento.target;
      if (detalle.matches && detalle.matches("details[data-grupo][id]")) {
        gruposAbiertos[detalle.id] = detalle.open;
      }
    },
    true
  );

  function reabrirGrupos(raiz) {
    raiz.querySelectorAll("details[data-grupo][id]").forEach(function (detalle) {
      if (gruposAbiertos[detalle.id]) { detalle.open = true; }
    });
  }
})();
