/*
 * COPIAR EL ENLACE DE UNA ACTIVIDAD
 * =================================
 * El enlace de un curso, un taller o un grupo de proyeccion se comparte por
 * WhatsApp, y quien lo comparte lo hace casi siempre desde el celular. Ahi
 * seleccionar un texto largo a dedo es un suplicio: hay que atinar al principio,
 * mantener pulsado, arrastrar el tirador hasta el final sin pasarse, y el campo
 * cabe en menos de una pantalla. El boton lo resuelve de un toque.
 *
 * El campo de texto se queda igual y no es redundante: es el respaldo para quien
 * tenga el portapapeles bloqueado, y lo unico que enseña a DONDE lleva el enlace
 * antes de mandarlo.
 *
 * DELEGACION en document, y no un listener por boton, por dos razones:
 * `acciones.js` reemplaza el contenido de <main> sin recargar --cualquier
 * listener atado a un boton concreto se quedaria apuntando a un elemento que ya
 * no esta en la pagina--, y la pantalla de Gestion pinta una fila por actividad,
 * asi que serian tantos listeners como actividades haya.
 */
(function () {
  if (window.__copiarEnlaceListo) { return; }
  window.__copiarEnlaceListo = true;

  var AVISO = 1800;

  function marcar(campo) {
    campo.focus();
    campo.select();
    // El numero grande no es supersticion: en iOS, `select()` sobre un campo de
    // solo lectura no marca nada, y sin marca no hay nada que copiar.
    campo.setSelectionRange(0, 99999);
  }

  /* Respaldo: `execCommand` esta obsoleto, por eso va segundo y no primero. */
  function respaldo(campo) {
    try {
      marcar(campo);

      return document.execCommand("copy");
    } catch (e) {
      return false;
    }
  }

  /*
   * Copiar de verdad. Devuelve una promesa que dice si se pudo.
   *
   * `navigator.clipboard` solo existe en contexto seguro (https, o localhost en
   * desarrollo). En produccion lo hay; el respaldo es para un navegador viejo o
   * para quien abra el sitio por http.
   *
   * EL `catch` NO ES ADORNO, y esta version se escribio sin el. `writeText` no
   * devuelve false cuando falla: RECHAZA la promesa. Rechaza si el usuario nego
   * el permiso, y tambien --esto es lo que sorprende-- si el documento no tiene
   * el foco. Sin `catch`, la promesa moria ahi: ni se copiaba, ni saltaba el
   * respaldo, ni cambiaba el boton. O sea, el boton no hacia NADA, que es
   * exactamente el fallo que ya le costo un profesor a este sistema.
   * Se descubrio pulsandolo en el navegador; las pruebas no lo habrian visto.
   */
  function copiar(campo) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(campo.value)
        .then(function () { return true; })
        .catch(function () { return respaldo(campo); });
    }

    return Promise.resolve(respaldo(campo));
  }

  function avisar(boton, texto) {
    if (boton.dataset.original === undefined) { boton.dataset.original = boton.textContent; }

    boton.textContent = texto;
    boton.classList.add("copiado");

    clearTimeout(boton.__volver);
    boton.__volver = setTimeout(function () {
      boton.textContent = boton.dataset.original;
      boton.classList.remove("copiado");
    }, AVISO);
  }

  document.addEventListener("click", function (evento) {
    var boton = evento.target.closest("[data-copiar-enlace]");
    if (!boton) { return; }

    // El campo es el hermano dentro de la misma fila. Se busca por el contenedor
    // y no por un id: la pantalla de Gestion tiene uno por actividad, y atarlos
    // por id obligaria a inventar y mantener un id unico en cada fila.
    var fila = boton.closest(".enlace-fila");
    var campo = fila && fila.querySelector(".enlace-copiable");
    if (!campo) { return; }

    copiar(campo).then(function (bien) {
      if (bien) {
        avisar(boton, "¡Copiado!");
      } else {
        // Ni el portapapeles ni el respaldo. Se deja el enlace MARCADO: quien
        // llegue hasta aqui al menos se ahorra la parte dificil en un celular,
        // que es acertar con los dos extremos de la seleccion.
        marcar(campo);
        avisar(boton, "Copia a mano");
      }
    });
  });

  // El boton solo sirve con JavaScript, asi que lo pinta el propio script. Sin
  // esto, quien lo tenga desactivado veria un boton muerto.
  function pintarBotones() {
    document.querySelectorAll(".enlace-fila:not([data-con-boton])").forEach(function (fila) {
      var campo = fila.querySelector(".enlace-copiable");
      if (!campo) { return; }

      var boton = document.createElement("button");
      boton.type = "button";
      boton.className = "btn btn-blanco btn-sm enlace-boton";
      boton.textContent = "Copiar";
      boton.setAttribute("data-copiar-enlace", "");
      // El campo ya trae su <label> oculto con el nombre de la actividad; el
      // boton lo reutiliza para no decir «Copiar» trece veces seguidas a un
      // lector de pantalla sin decir copiar QUE.
      boton.setAttribute("aria-label", "Copiar el " + (campo.labels && campo.labels[0] ? campo.labels[0].textContent.trim() : "enlace"));

      fila.appendChild(boton);
      fila.setAttribute("data-con-boton", "");
    });
  }

  pintarBotones();

  // `acciones.js` repinta <main> sin recargar --cerrar o abrir el enlace de una
  // actividad pasa por ahi--, y con el se llevan los botones. Se vuelven a
  // pintar cuando eso ocurra.
  var main = document.querySelector("main");
  if (main && window.MutationObserver) {
    new MutationObserver(pintarBotones).observe(main, { childList: true, subtree: true });
  }
})();
