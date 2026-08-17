/*
 * PASO ENTRE PERIODOS CON EL DEDO
 * ===============================
 * Deslizar a los lados sobre la barra del periodo equivale a pulsar la flecha
 * correspondiente.
 *
 * Es un ATAJO y nada más. Los enlaces de las flechas están ahí, se ven y
 * funcionan sin JavaScript; esto solo evita apuntar a un círculo de dos
 * centímetros en un teléfono. Si este archivo no carga, la pantalla no pierde
 * ninguna función.
 *
 * El sentido es el del calendario y no el de una galería de fotos: deslizar
 * hacia la IZQUIERDA lleva hacia adelante en el tiempo, igual que la flecha
 * derecha. Es la convención de los calendarios del teléfono, que es con lo que
 * la gente compara esto.
 */
(function () {
  "use strict";

  var barra = document.querySelector("[data-periodo-nav]");
  if (!barra) { return; }

  // Distancia mínima para que cuente. Por debajo de esto casi siempre es un
  // toque con la mano temblando, no un gesto: cambiar de periodo por accidente
  // es peor que no tener el atajo.
  var MINIMO = 60;

  var inicioX = null;
  var inicioY = null;

  barra.addEventListener("touchstart", function (evento) {
    if (evento.touches.length !== 1) { return; }
    inicioX = evento.touches[0].clientX;
    inicioY = evento.touches[0].clientY;
  }, { passive: true });

  barra.addEventListener("touchend", function (evento) {
    if (inicioX === null) { return; }

    var toque = evento.changedTouches[0];
    var dx = toque.clientX - inicioX;
    var dy = toque.clientY - inicioY;

    inicioX = null;
    inicioY = null;

    if (Math.abs(dx) < MINIMO) { return; }

    // Más horizontal que vertical: si no, un desplazamiento de la página con el
    // dedo algo torcido cambiaría de periodo sin que nadie lo pidiera.
    if (Math.abs(dx) <= Math.abs(dy)) { return; }

    var destino = barra.querySelector(dx < 0 ? "[data-periodo-adelante]" : "[data-periodo-atras]");

    if (destino) { window.location.href = destino.href; }
  }, { passive: true });
})();
