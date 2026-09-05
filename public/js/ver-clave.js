/*
 * EL OJO PARA VER LA CONTRASENA que se esta escribiendo.
 *
 * Pedido por el usuario el 05/09/2026 para las tres pantallas sin sesion:
 * entrar, la inscripcion del estudiante y el registro del profesor. La razon es
 * la de siempre en este proyecto — casi todo el uso es desde el celular, y
 * teclear una clave a ciegas en un teclado de pulgar es donde mas gente se
 * atasca. En el registro y en la inscripcion hay ademas DOS campos que tienen
 * que coincidir, y sin verlos el unico modo de saber que no coinciden es
 * enviar el formulario y que te lo rechacen.
 *
 * EL BOTON LO CREA ESTE ARCHIVO, no la plantilla, y es a proposito: un boton de
 * «ver la clave» sin JavaScript es un boton que no hace nada. Asi la pagina que
 * llega del servidor es la de siempre y quien no tenga JavaScript no ve un
 * control muerto. Es el mismo criterio de los modales de `acciones.js`.
 *
 * Se envuelve el input en un `.campo-clave` posicionado y el boton va DENTRO,
 * absoluto. Se hace asi y no con flex por la trampa que ya costo una vez: en un
 * contenedor en COLUMNA —que es lo que es `.caja`— las medidas de flex miden
 * alto y no ancho. Absoluto no toca el flujo, asi que el input conserva su
 * `width: 100%` y no hay nada que se pueda descolocar.
 */
(function () {
  "use strict";

  // Los dos iconos, dibujados y no un caracter: el sistema tiene sus iconos en
  // SVG porque una tipografia no garantiza ni el tamano ni el centrado.
  var OJO =
    '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
  var OJO_TACHADO =
    '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';

  function svg(trazo) {
    return (
      '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" ' +
      'stroke="currentColor" stroke-width="2" stroke-linecap="round" ' +
      'stroke-linejoin="round" aria-hidden="true">' + trazo + "</svg>"
    );
  }

  function preparar(input) {
    // La marca evita duplicar el boton si esto llegara a correr dos veces.
    if (input.dataset.conOjo) { return; }
    input.dataset.conOjo = "1";

    var envoltorio = document.createElement("div");
    envoltorio.className = "campo-clave";
    input.parentNode.insertBefore(envoltorio, input);
    envoltorio.appendChild(input);

    var boton = document.createElement("button");
    // `type="button"` o el boton ENVIA el formulario: dentro de un <form>, un
    // <button> sin tipo es de envio. Aqui eso seria intentar entrar cada vez
    // que alguien quiere mirar lo que escribio.
    boton.type = "button";
    boton.className = "ojo-clave";

    function pintar() {
      var visible = input.type === "text";
      boton.innerHTML = svg(visible ? OJO_TACHADO : OJO);
      // El nombre dice la ACCION que se va a hacer, no el estado en que esta.
      // Es lo que un lector de pantalla lee al llegar al boton.
      boton.setAttribute("aria-label", visible ? "Ocultar la contraseña" : "Mostrar la contraseña");
      boton.setAttribute("title", visible ? "Ocultar la contraseña" : "Mostrar la contraseña");
    }

    boton.addEventListener("click", function () {
      input.type = input.type === "password" ? "text" : "password";
      pintar();
      // El foco vuelve al campo: quien pulsa el ojo esta escribiendo, y
      // devolverselo le ahorra un toque. `setSelectionRange` deja el cursor al
      // final en vez de seleccionarlo todo, que es lo que hace `focus()` solo
      // en algunos navegadores y borraria lo escrito con la siguiente tecla.
      var fin = input.value.length;
      input.focus();
      try { input.setSelectionRange(fin, fin); } catch (e) { /* type=text recien puesto */ }
    });

    pintar();
    envoltorio.appendChild(boton);
  }

  document.querySelectorAll(".caja input[type='password']").forEach(preparar);
})();
