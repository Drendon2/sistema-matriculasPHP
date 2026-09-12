/**
 * Claro u oscuro en el acto, sin recargar.
 *
 * EL BOTON FUNCIONA SIN ESTE GUION. Es un <form> de verdad: sin JavaScript se
 * envia, el servidor pone la galleta y la pagina vuelve ya pintada del otro
 * color. Lo que esto anade es que el color cambie al pulsar en vez de despues
 * de un viaje de ida y vuelta — que es lo unico que se siente desde un celular.
 *
 * ─── POR QUE SIGUE HABIENDO UN POST ────────────────────────────────────────
 *
 * La tentacion es escribir la galleta desde aqui y ahorrarse el viaje. NO SE
 * PUEDE: Laravel la CIFRA (`EncryptCookies` esta en la pila y `tema` no esta
 * exceptuada), asi que lo que hay en `document.cookie` es un bloque con iv,
 * valor y firma. Una galleta escrita a mano no la descifra el servidor y se
 * descarta en silencio: el color cambiaria al pulsar y volveria al recargar,
 * sin que nada falle. Comprobado el 12/09/2026 mirando el `Set-Cookie`.
 *
 * (El comentario de `Support\Tema` decia que se dejo legible «para que el guion
 * pueda leerla sin preguntarle al servidor». `httpOnly: false` la hace visible,
 * pero cifrada, asi que eso nunca fue cierto. Ya esta corregido alli.)
 *
 * Asi que se hacen las dos cosas: el atributo cambia YA —que es lo que se ve— y
 * el POST viaja por detras para que la proxima carga salga igual. Nadie espera
 * a ese viaje.
 *
 * ─── LO QUE NO HACE FALTA HACER AQUI ───────────────────────────────────────
 *
 * Cambiar el icono. Cual de los dos botones se ve lo decide el CSS a partir de
 * `data-tema`, asi que al cambiar el atributo el boton se cambia solo. Un
 * guion que ademas moviera iconos tendria dos verdades que se pueden separar.
 */
(function () {
  "use strict";

  var forma = document.querySelector("[data-tema-forma]");

  if (!forma || !window.fetch) { return; }

  forma.addEventListener("submit", function (evento) {
    // `submitter` dice CUAL de los dos botones se pulso, y con el, a que tema
    // se va. Sin el —navegadores viejos, o un envio por teclado que no lo
    // reporte— no se adivina: se deja ir al servidor, que hace lo correcto.
    var boton = evento.submitter;

    if (!boton || (boton.value !== "claro" && boton.value !== "oscuro")) { return; }

    evento.preventDefault();

    // Esto es todo el cambio de color: la hoja entera cuelga de `color-scheme`
    // y `light-dark()`, y las dos recalculan solas al cambiar el atributo.
    document.documentElement.setAttribute("data-tema", boton.value);

    var cuerpo = new FormData(forma);
    cuerpo.set("tema", boton.value);

    fetch(forma.action, {
      method: "POST",
      body: cuerpo,
      credentials: "same-origin",
      // `Accept` a mano: sin el, `fetch` manda un comodin y Laravel contesta
      // JSON a lo que deberia ser una redireccion. Es la trampa de la casa.
      headers: { "Accept": "text/html", "X-Requested-With": "XMLHttpRequest" },
    }).then(function (respuesta) {
      // Si el servidor no lo guardo —un testigo caducado da 419— el color ya
      // cambio pero no sobrevivira a la recarga. Antes que dejar esa mentira
      // puesta, se manda el formulario de verdad: cuesta una recarga y queda
      // guardado.
      if (!respuesta.ok) { forma.submit(); }
    }).catch(function () {
      forma.submit();
    });
  });
})();
