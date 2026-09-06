<?php

namespace App\Support;

use App\Models\ConfiguracionInstitucion;
use Illuminate\Support\Str;

/**
 * La politica de tratamiento de datos personales: su texto por defecto y como
 * se pinta.
 *
 * DOS ORIGENES Y UNO MANDA. Si `configuracion_institucion.politica_datos` trae
 * algo, ese es el texto y aqui no se decide nada mas. Si esta vacia —que es
 * como nace toda instalacion— se pinta `porDefecto()`, redactado sobre la Ley
 * 1581 de 2012 y el Decreto 1377 de 2013 y con el nombre y el contacto de la
 * entidad ya metidos dentro.
 *
 * POR QUE EL TEXTO POR DEFECTO NO SE SIEMBRA EN LA BASE, que era lo obvio:
 * sembrado, quedaria con el nombre de institucion que hubiera el dia de la
 * migracion —«Casa de la Cultura», el de fabrica, en una base recien montada— y
 * dejaria de ponerse al dia el dia que la entidad cambie su nombre, su correo o
 * su direccion. Resuelto en caliente, una entidad que no toca nada tiene
 * siempre una politica que la nombra bien.
 *
 * EL FORMATO ES DELIBERADAMENTE POBRE y no Markdown: un titulo es una linea que
 * empieza por `## `, una vinneta una que empieza por `- `, y lo demas son
 * parrafos separados por una linea en blanco. Con eso basta para un texto legal
 * y evita meter un conversor —y su superficie de inyeccion— para pintar una
 * sola pagina. TODO se escapa antes de salir: lo que hay en esa columna lo
 * escribio una persona en un textarea, no el proyecto.
 */
class PoliticaDatos
{
    /** El texto vigente: el que escribio la entidad, o el de fabrica. */
    public static function texto(ConfiguracionInstitucion $institucion): string
    {
        $propio = trim((string) $institucion->politica_datos);

        return $propio !== '' ? $propio : self::porDefecto($institucion);
    }

    /** ¿Esta pintando el texto de fabrica? Lo dice la pantalla de Gestion. */
    public static function esLaDeFabrica(ConfiguracionInstitucion $institucion): bool
    {
        return trim((string) $institucion->politica_datos) === '';
    }

    /**
     * El texto a HTML.
     *
     * Devuelve HTML ya escapado, listo para imprimir sin escapar otra vez. Es
     * el unico sitio del proyecto que hace eso, y por eso el escape se aplica
     * AQUI, trozo por trozo, antes de envolver nada en etiquetas: lo que hay en
     * esa columna lo escribio una persona en un textarea.
     */
    public static function aHtml(string $texto): string
    {
        $html = [];
        $parrafo = [];
        $lista = [];

        foreach (preg_split('/\R/', $texto) ?: [] as $linea) {
            $linea = trim($linea);

            // Linea en blanco: se acaba el bloque que viniera.
            if ($linea === '') {
                $html = array_merge($html, self::cerrar($parrafo, $lista));
                $parrafo = $lista = [];

                continue;
            }

            if (Str::startsWith($linea, '## ')) {
                $html = array_merge($html, self::cerrar($parrafo, $lista));
                $parrafo = $lista = [];
                $html[] = '<h3>'.e(trim(Str::after($linea, '## '))).'</h3>';

                continue;
            }

            if (Str::startsWith($linea, '- ')) {
                // Una vinneta corta el parrafo que venia, no se suma a el. La
                // lista abierta, en cambio, sigue: vinnetas seguidas son UNA
                // lista, no una por linea.
                $html = array_merge($html, self::cerrar($parrafo, []));
                $parrafo = [];
                $lista[] = e(trim(Str::after($linea, '- ')));

                continue;
            }

            // Un parrafo detras de una lista la cierra.
            $html = array_merge($html, self::cerrar([], $lista));
            $lista = [];
            $parrafo[] = e($linea);
        }

        return implode("\n", array_merge($html, self::cerrar($parrafo, $lista)));
    }

    /**
     * Cierra el parrafo y la lista que estuvieran abiertos.
     *
     * Es una funcion PURA —recibe lo acumulado y devuelve el HTML— y no un
     * cierre que muta variables de fuera por referencia, que es como estaba
     * escrita. Con la version por referencia PHPStan solo ve los arrays vacios
     * con los que nacen y da las dos comparaciones por imposibles.
     *
     * Cerrar es obligatorio antes de empezar otra cosa: un titulo detras de una
     * vinneta, con el <ul> sin cerrar, se pinta DENTRO de la lista.
     *
     * @param  list<string>  $parrafo
     * @param  list<string>  $lista
     * @return list<string>
     */
    private static function cerrar(array $parrafo, array $lista): array
    {
        $html = [];

        if ($parrafo !== []) {
            $html[] = '<p>'.implode(' ', $parrafo).'</p>';
        }

        if ($lista !== []) {
            $html[] = '<ul>'.implode('', array_map(fn (string $i) => "<li>{$i}</li>", $lista)).'</ul>';
        }

        return $html;
    }

    /**
     * El texto de fabrica.
     *
     * LAS DOS FINALIDADES QUE LO MOTIVAN estan en «Para que los usamos» y son
     * exactamente las que se firman en el formato de consentimiento: el
     * analisis estadistico y planeacion de los procesos formativos, y el uso de
     * la imagen para comunicarlos y promocionarlos. Si algun dia cambian en el
     * formato hay que cambiarlas tambien aqui — un consentimiento que autoriza
     * algo que la politica no anuncia no vale.
     *
     * EL TEXTO DE FABRICA NO DA POR HECHO NADA SOBRE QUIEN LO USA, y eso es
     * deliberado desde el 06/09/2026. Decia «politicas publicas del sector
     * cultura» y «procesos formativos y culturales», que es exacto para una casa
     * de la cultura publica y falso para un colegio privado, una escuela
     * deportiva o una fundacion. Este producto se vende a esas tambien, y un
     * texto por defecto que llama «cultural» o «publica» a quien no lo es sale
     * mal justo el primer dia, que es el dia en que nadie lo ha revisado.
     *
     * Se quito en dos pasos y los dos los pidio el usuario: primero el sector,
     * despues lo de publicas. Lo que quedo —«analisis estadistico y planeacion
     * de los procesos formativos», con entrega «a las autoridades competentes
     * cuando la ley lo exija»— cubre igual el caso de la casa de la cultura, que
     * si alimenta politica publica: lo hace sin obligar a las demas a decir que
     * son algo que no son.
     *
     * La entidad que quiera concretar su sector o su naturaleza reescribe la
     * politica desde Gestion, que para eso es editable. **El FORMATO que se
     * firma no lo es**, y esa asimetria esta viva: si alguien la necesita, es
     * una decision a tomar a proposito.
     *
     * Los renglones de contacto que la entidad no ha rellenado NO se pintan
     * vacios: se caen. Es preferible una politica sin telefono a una que diga
     * «Telefono:» y nada detras.
     */
    public static function porDefecto(ConfiguracionInstitucion $institucion): string
    {
        $nombre = $institucion->nombre_institucion;

        $contacto = collect([
            'NIT' => $institucion->entidad_nit,
            'Dirección' => $institucion->entidad_direccion,
            'Correo electrónico' => $institucion->entidad_correo,
            'Teléfono' => $institucion->entidad_telefono,
        ])
            ->filter(fn ($valor) => trim((string) $valor) !== '')
            ->map(fn ($valor, $etiqueta) => "- {$etiqueta}: {$valor}")
            ->implode("\n");

        $identificacion = $contacto === ''
            ? "El responsable del tratamiento de tus datos personales es {$nombre}."
            : "El responsable del tratamiento de tus datos personales es {$nombre}, "
                ."con los siguientes datos de contacto:\n\n{$contacto}";

        $canal = trim((string) $institucion->entidad_correo) !== ''
            ? "escribiendo a {$institucion->entidad_correo}"
            : 'escribiendo a la dirección de contacto de la institución';

        $bloques = [
            '## Quiénes somos',
            $identificacion,
            'Esta política explica qué datos personales recogemos de quienes participan en nuestros '
                .'procesos formativos, para qué los usamos, con quién los compartimos y qué '
                .'puedes hacer tú con ellos. Se rige por la Ley 1581 de 2012, el Decreto 1377 de 2013 y '
                .'las demás normas colombianas sobre protección de datos personales.',

            '## Qué datos recogemos',
            // Las vinnetas de un mismo grupo van en UN solo bloque, separadas
            // por un salto simple. Con una linea en blanco entre ellas —que es
            // como se separan los bloques— cada una saldria en su propia lista
            // de un elemento, y una lista de siete <ul> seguidos se pinta
            // suelta y se lee peor.
            implode("\n", [
                '- Datos de identificación: nombre, número de documento y fecha de nacimiento.',
                '- Datos de contacto: teléfono y, si lo entregas, correo electrónico.',
                '- Datos del acudiente, cuando quien participa es menor de edad.',
                '- Datos académicos: las promotorías, cursos y talleres en los que te matriculas, tu '
                    .'grupo, tu horario y tu asistencia.',
                '- Respuestas a la encuesta de caracterización: barrio, zona, estrato, nivel educativo, '
                    .'ocupación y otros datos sociodemográficos.',
                '- Fotografía de perfil y las copias de los documentos que se piden para la matrícula.',
                '- Tu imagen —fotografías, video y voz— cuando se registran las actividades '
                    .'formativas.',
            ]),

            '## Para qué los usamos',
            implode("\n", [
                '- Para gestionar tu matrícula: inscribirte, asignarte grupo y horario, registrar tu '
                    .'asistencia y expedir tus certificados.',
                '- Para comunicarnos contigo o con tu acudiente sobre las actividades en las que '
                    .'participas.',
                '- Para el análisis estadístico y la planeación de los procesos formativos: saber '
                    .'quién participa, en qué y con qué continuidad, y decidir con eso la oferta. Los '
                    .'datos se usan agregados y anonimizados siempre que el análisis lo permita, y se '
                    .'entregan a las autoridades competentes cuando la ley lo exige o cuando tú lo has '
                    .'autorizado.',
                '- Para la comunicación y la promoción de los procesos formativos de la '
                    .'institución, usando tu imagen en piezas informativas, redes sociales, '
                    .'publicaciones y material de divulgación. Esta finalidad requiere tu autorización '
                    .'expresa y puedes negarla sin que eso afecte tu matrícula.',
                '- Para cumplir las obligaciones legales de reporte y de conservación de información '
                    .'que nos correspondan.',
            ]),

            '## Datos sensibles y datos de menores de edad',
            'Algunos datos de la encuesta de caracterización son sensibles: la pertenencia étnica, la '
                .'condición de discapacidad, la afiliación a salud y la condición de víctima del conflicto '
                .'armado. Responderlos es facultativo: nadie está obligado a entregarlos, y no hacerlo no '
                .'impide matricularse ni participar en nada. Tu imagen también recibe trato de dato '
                .'sensible cuando se usa para divulgación.',
            'Cuando quien participa es menor de edad, la autorización la otorga su padre, su madre o su '
                .'acudiente. El tratamiento respeta el interés superior del niño, la niña y el adolescente '
                .'y sus derechos fundamentales, y se tiene en cuenta la opinión del menor de acuerdo con '
                .'su madurez.',

            '## Tus derechos',
            implode("\n", [
                '- Conocer, actualizar y rectificar tus datos personales.',
                '- Solicitar prueba de la autorización que otorgaste.',
                '- Ser informado sobre el uso que les hemos dado.',
                '- Presentar quejas ante la Superintendencia de Industria y Comercio por infracciones '
                    .'a la ley.',
                '- Revocar la autorización o pedir que se supriman tus datos, cuando no exista un deber '
                    .'legal o contractual que nos obligue a conservarlos.',
                '- Acceder gratuitamente a tus datos personales.',
            ]),

            '## Cómo ejercerlos',
            'Puedes consultar, actualizar, rectificar o suprimir tus datos, o revocar tu autorización, '
                ."{$canal}, indicando tu nombre, tu documento y qué solicitas.",
            'Las consultas se atienden en un plazo máximo de diez días hábiles y los reclamos en un plazo '
                .'máximo de quince días hábiles. Si no podemos responder dentro del plazo, te informamos '
                .'los motivos y la fecha en que lo haremos.',
            'Buena parte de tus datos los puedes actualizar tú directamente desde «Mi perfil», sin '
                .'necesidad de pedirlo.',

            '## Cómo obtenemos tu autorización',
            'Al matricularte se te entrega un formato de autorización de tratamiento de datos personales '
                .'y uso de imagen, que descargas, firmas y devuelves por este mismo sistema. El formato '
                .'distingue si eres mayor de edad —firmas tú— o menor de edad, en cuyo caso firma tu '
                .'acudiente. En él autorizas por separado el tratamiento de tus datos y el uso de tu '
                .'imagen: puedes aceptar uno y negar el otro.',

            '## Seguridad y conservación',
            'El acceso a la información está limitado por roles: quien dicta una promotoría ve lo '
                .'necesario para su grupo, y los documentos que subes solo los ve la administración de la '
                .'institución. Los archivos no se sirven desde una carpeta pública. Conservamos tus datos '
                .'mientras dure tu vínculo con la institución y después durante el tiempo que exijan las '
                .'normas de archivo aplicables.',

            '## Vigencia',
            'Esta política puede actualizarse. La versión publicada en esta página es la vigente.',
        ];

        return implode("\n\n", $bloques);
    }
}
