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
    ];

    protected function casts(): array
    {
        return [
            'limite_promotorias_por_periodo' => 'integer',
            'promotorias_visibles_para_estudiantes' => 'boolean',
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
     */
    public static function actual(): self
    {
        try {
            return static::firstOrCreate(['id' => 1]);
        } catch (Throwable) {
            return new static;
        }
    }

    /** Fila unica: cualquier guardado escribe sobre la misma. */
    protected static function booted(): void
    {
        static::saving(function (self $configuracion) {
            $configuracion->id = 1;
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
