<?php

namespace App\Models;

use App\Support\Color;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

/**
 * Ajustes de la institucion, editables sin tocar codigo.
 *
 * Fila unica (id = 1): el proyecto sirve a UNA institucion a la vez, pero
 * ninguno de estos datos deberia estar quemado en el codigo si se quiere
 * reinstalar para otra entidad sin tocar plantillas.
 *
 * Cubre dos cosas distintas:
 *
 * - La MARCA (nombre, logo, color de acento), que solo afecta como se ve.
 * - Una REGLA OPERATIVA: cuantas promotorias puede cursar un estudiante en un
 *   mismo periodo. Esta si cambia el comportamiento, asi que lo que se guarde
 *   aqui gobierna las validaciones de Matricula.
 *
 * No incluye nada del catalogo academico (areas, promotorias, periodos, cupos):
 * eso son registros propios, no ajustes de una sola fila.
 */
class ConfiguracionInstitucion extends Model
{
    /**
     * Techo de ranuras GRABADO en el esquema (ver el CHECK `ranura_valida` de
     * `matriculas`). NO es la regla de negocio: esa es
     * `limite_promotorias_por_periodo`, editable en caliente.
     *
     * Este numero existe solo porque un CHECK no puede consultar una fila de
     * configuracion: se fija al migrar y ahi se queda. Se eligio holgado para
     * que subir el limite operativo nunca exija una migracion; si algun dia
     * hicieran falta mas de 6, hay que migrar los dos constraints de Matricula.
     */
    public const RANURA_MAXIMA_ABSOLUTA = 6;

    /** Clave con la que la peticion en curso guarda la fila ya resuelta. */
    private const MEMORIA = 'configuracion-institucion.actual';

    protected $table = 'configuracion_institucion';

    protected $fillable = [
        'nombre_institucion',
        'logo',
        'firma',
        'firmante_nombre',
        'firmante_cargo',
        'color_acento',
        'limite_promotorias_por_periodo',
        'promotorias_visibles_para_estudiantes',
        'alerta_clase_no_dictada',
        'alerta_abandono',
        'faltas_para_abandono',
    ];

    /**
     * Los mismos valores por defecto que declara la migracion, repetidos aqui a
     * proposito y no por descuido.
     *
     * Sin esto, la fila que crea `actual()` la primera vez vuelve con solo el
     * `id`: los defaults los pone la base de datos al insertar, pero el modelo
     * en memoria no los ha leido, y `limite_promotorias_por_periodo` sale null
     * justo en la peticion que estrena el sistema.
     *
     * Sirve ademas para el otro caso que cubre `actual()`: cuando la tabla
     * todavia no existe y hay que devolver una instancia suelta que las
     * plantillas puedan pintar igual.
     */
    protected $attributes = [
        'nombre_institucion' => 'Casa de la Cultura',
        'logo' => '',
        'firma' => '',
        'firmante_nombre' => '',
        'firmante_cargo' => '',
        'color_acento' => '#0a7a59',
        'limite_promotorias_por_periodo' => 2,
        'promotorias_visibles_para_estudiantes' => true,
        // Las tres de las alertas van AQUI y no solo en la migracion, como
        // todas sus vecinas: `actual()` crea la fila con `firstOrCreate` y esa
        // instancia NO relee lo que la base puso por defecto. Sin esta linea,
        // una instalacion recien migrada devolvia null en las tres — los dos
        // interruptores se leian como apagados y el umbral como cero, o sea
        // que las alertas salian apagadas y, si alguien las encendia, la de
        // abandono avisaba de todo el mundo.
        'alerta_clase_no_dictada' => true,
        'alerta_abandono' => true,
        'faltas_para_abandono' => 5,
    ];

    protected function casts(): array
    {
        return [
            'limite_promotorias_por_periodo' => 'integer',
            'promotorias_visibles_para_estudiantes' => 'boolean',
            'alerta_clase_no_dictada' => 'boolean',
            'alerta_abandono' => 'boolean',
            'faltas_para_abandono' => 'integer',
        ];
    }

    /**
     * La configuracion vigente, creandola con los valores por defecto si falta.
     *
     * Se resuelve en caliente y no por una migracion de datos, para que un
     * proyecto recien clonado funcione sin pasos extra. Si la tabla todavia no
     * existe (antes de migrar) devuelve una instancia en memoria con los
     * defaults: de esto cuelga el compositor de vistas que corre en CADA
     * pagina, y no debe tumbar el sitio.
     *
     * SE RESUELVE UNA SOLA VEZ POR PETICION. El compositor de vistas de
     * `AppServiceProvider` dice eso en su comentario desde el principio, pero
     * no era verdad: `View::composer('*')` corre una vez por VISTA pintada, y
     * una pagina son el layout mas sus parciales. Eran cuatro `SELECT` iguales
     * en cada carga de cada pantalla del sistema.
     *
     * La copia vive en el contenedor y no en una propiedad estatica de la
     * clase. Es a proposito: el contenedor se construye de nuevo en cada
     * peticion y en cada prueba, asi que la copia muere sola. Una estatica
     * sobreviviria a toda la suite y devolveria la fila de la prueba anterior,
     * que es la clase de error que se tarda un dia en encontrar.
     */
    public static function actual(): self
    {
        if (app()->bound(self::MEMORIA)) {
            return app()->make(self::MEMORIA);
        }

        try {
            $configuracion = static::firstOrCreate(['id' => 1]);
        } catch (Throwable) {
            // Tabla sin migrar. NO se memoriza: es un estado que se arregla
            // solo en cuanto alguien migre, y guardarlo obligaria a que la
            // instancia suelta sobreviviera a la peticion que ya tiene tabla.
            return new static;
        }

        app()->instance(self::MEMORIA, $configuracion);

        return $configuracion;
    }

    /** Fila unica: cualquier guardado escribe sobre la misma. */
    protected static function booted(): void
    {
        static::saving(function (self $configuracion) {
            $configuracion->id = 1;
        });

        // Cualquier guardado tira la copia de la peticion. Hoy todo el codigo
        // llega por `actual()` y guarda sobre ESA instancia, asi que la copia
        // ya saldria al dia; esto es para el dia que alguien cargue la fila por
        // su cuenta y la guarde, que entonces la copia memorizada quedaria
        // vieja y la pantalla seguiria pintando la marca anterior.
        static::saved(function () {
            app()->forgetInstance(self::MEMORIA);
        });

        // Sin configuracion el sistema se quedaria sin marca.
        static::deleting(function () {
            throw new RuntimeException('La configuracion de la institucion no se puede eliminar.');
        });
    }

    public function getColorAcentoOscuroAttribute(): string
    {
        return Color::acentoOscuro($this->color_acento ?? '#0a7a59');
    }

    public function getColorAcentoSuaveAttribute(): string
    {
        return Color::acentoSuave($this->color_acento ?? '#0a7a59');
    }

    /** Contraste del texto blanco sobre el acento (los botones primarios). */
    public function getContrasteTextoBotonAttribute(): float
    {
        return Color::contraste('#ffffff', $this->color_acento ?? '#0a7a59');
    }
}
