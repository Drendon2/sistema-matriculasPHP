/*
 * ACCIONES SIN RECARGA
 * ====================
 * Puerto del script que trae `base.html` en el proyecto Django, sin cambios de
 * comportamiento. Es JavaScript plano y no depende de nada: funciona igual bajo
 * Laravel porque solo habla con <form> y <main>.
 *
 * Este sistema se usa de a tandas: se confirman quince matriculas seguidas, se
 * reparten treinta estudiantes en grupos, se vacia la bandeja de cancelaciones.
 * Con una recarga entera por accion, cada clic devolvia al usuario al principio
 * de la pagina y habia que volver a bajar hasta donde iba. Con listas largas eso
 * no es una molestia, es lo que hace el trabajo inviable.
 *
 * La estrategia NO es convertir cada ruta en una API. Los controladores siguen
 * haciendo lo que hacian —guardar, encolar su mensaje y redirigir—, y aqui se
 * intercepta el envio para pedir esa misma respuesta por `fetch` y cambiar solo
 * lo de dentro de <main>. El servidor sigue siendo la unica fuente de verdad y
 * sigue re-renderizando de cero: por eso los estudiantes que cambian de seccion,
 * los contadores de cupo y los avisos salen bien sin que nadie tenga que
 * reproducir esa logica en JavaScript.
 *
 * Lo que se conserva a mano es lo que el navegador perderia: la posicion del
 * scroll y que <details> estaban abiertos.
 *
 * Si no hay JavaScript, o si `fetch` falla, no pasa nada: el formulario se envia
 * como siempre. Para dejar una accion FUERA basta ponerle `data-recarga-completa`
 * al <form>.
 */
(function () {
  var main = document.querySelector("main");
  if (!main || !window.fetch || !window.DOMParser || !window.history.pushState) { return; }

  function abiertos() {
    // Solo los que tienen id: el id es la promesa de que ese <details> es el
    // mismo antes y despues. Sin el, la promotoria que cambio de posicion
    // heredaria el estado de la vecina.
    var estado = {};
    main.querySelectorAll("details[id]").forEach(function (d) { estado[d.id] = d.open; });
    return estado;
  }

  function pintar(html, estado, scroll) {
    var doc = new DOMParser().parseFromString(html, "text/html");
    var nuevo = doc.querySelector("main");
    if (!nuevo) { return false; }

    main.innerHTML = nuevo.innerHTML;
    if (doc.title) { document.title = doc.title; }

    main.querySelectorAll("details[id]").forEach(function (d) {
      if (d.id in estado) { d.open = estado[d.id]; }
    });

    // innerHTML no ejecuta <script>. Las pantallas que traen el suyo (pasar
    // lista, la encuesta del perfil) quedarian muertas tras un cambio, asi que
    // se reponen creandolos de nuevo.
    main.querySelectorAll("script").forEach(function (viejo) {
      var script = document.createElement("script");
      if (viejo.src) { script.src = viejo.src; } else { script.textContent = viejo.textContent; }
      viejo.parentNode.replaceChild(script, viejo);
    });

    window.scrollTo(0, scroll);
    return true;
  }

  document.addEventListener("submit", function (evento) {
    var form = evento.target;
    if (!form.matches("form") || !main.contains(form)) { return; }
    if ((form.method || "").toLowerCase() !== "post") { return; }
    if (form.hasAttribute("data-recarga-completa")) { return; }

    evento.preventDefault();

    // El boton pulsado importa: hay formularios con mas de uno y su name/value
    // viaja en el envio. FormData(form, submitter) lo hace solo donde existe;
    // donde no, se anade a mano.
    var enviador = evento.submitter;
    var datos;
    try {
      datos = new FormData(form, enviador);
    } catch (e) {
      datos = new FormData(form);
      if (enviador && enviador.name) { datos.append(enviador.name, enviador.value); }
    }

    var estado = abiertos();
    var scroll = window.scrollY;
    var destino = form.action || window.location.href;
    main.setAttribute("aria-busy", "true");

    fetch(destino, {
      method: "POST",
      body: datos,
      credentials: "same-origin",
      redirect: "follow",
      headers: { "X-Requested-With": "XMLHttpRequest" },
    }).then(function (respuesta) {
      return respuesta.text().then(function (html) {
        return { html: html, url: respuesta.url, ok: respuesta.ok };
      });
    }).then(function (r) {
      // La accion pudo llevar a OTRA pagina (crear algo y volver a su lista, o
      // caerse la sesion y aterrizar en el login). La respuesta que ya tenemos
      // es la buena: si se navegara de verdad, el mensaje encolado ya se habria
      // consumido en esta peticion y se perderia. Asi que se pinta igual y se
      // corrige la barra de direcciones.
      var cambioDePagina = r.url && r.url !== window.location.href;
      if (!pintar(r.html, estado, cambioDePagina ? 0 : scroll)) {
        window.location.href = r.url || window.location.href;
        return;
      }
      if (cambioDePagina) { window.history.pushState({ pintado: true }, "", r.url); }
    }).catch(function () {
      // Red caida o respuesta ilegible. Reenviar por las bravas duplicaria la
      // accion si el POST si habia llegado, asi que se recarga: la pagina vuelve
      // a contar el estado real y el usuario ve si se guardo o no.
      window.location.reload();
    }).then(function () {
      main.removeAttribute("aria-busy");
    });
  });

  // Atras/adelante entre entradas que comparten este documento (las que pusimos
  // con pushState). El navegador cambia la URL pero no vuelve a cargar nada, y
  // el <main> que se ve es el que dejamos pintado: hay que pedir la pagina de
  // verdad.
  window.addEventListener("popstate", function () { window.location.reload(); });
})();
