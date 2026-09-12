/**
 * Abre el aviso de clases sin confirmar al entrar.
 *
 * ─── POR QUE EL GUION SOLO ABRE ────────────────────────────────────────────
 *
 * El `<dialog>` ya viene en el HTML, CERRADO. Sin este guion no se ve nada, y
 * eso es lo correcto: la garantia de que el estudiante se entere es el PUNTO
 * ROJO del menu, que es CSS y no necesita JavaScript. Esto lo amplifica.
 *
 * Se usa `showModal()` y no `show()` porque lo que hace falta es que se vea por
 * encima de todo y que el tabulador se quede dentro mientras este abierto —eso
 * lo da el navegador—. Tambien trae `Esc` gratis, que es como se cierra un
 * dialogo en cualquier sitio y aqui tiene que funcionar: esto pide, no obliga.
 *
 * NO se marca nada al cerrar. La marca de «ya se enseño» la pone el SERVIDOR al
 * pintarlo, una vez por sesion; si dependiera de este guion, quien cierre la
 * pestana lo volveria a ver en la pantalla siguiente.
 */
(function () {
  "use strict";

  var aviso = document.querySelector("[data-aviso-clases]");

  if (!aviso || typeof aviso.showModal !== "function") { return; }

  aviso.showModal();

  var cerrar = aviso.querySelector("[data-cerrar-aviso]");

  if (cerrar) {
    cerrar.addEventListener("click", function () { aviso.close(); });
  }
})();
