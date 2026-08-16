<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Perfil comun a TODOS los roles. Uno por cada cuenta, sin importar el rol.
 *
 * Las reglas de VISIBILIDAD por campo (quien ve que) NO van aqui: se aplican en
 * la capa de permisos. Este modelo solo define los DATOS.
 *
 * Recordatorio de visibilidad (se implementa en los controladores, no aqui):
 *     nombre, foto ...... admin, director, profesor, companeros de la MISMA promotoria
 *     edad, telefono,
 *     acudiente ......... admin, director, profesor (profesor solo de SUS promotorias)
 *     encuesta .......... solo el dueno de la cuenta y el administrador
 *     copia_documento ... solo el administrador
 */
class Perfil extends Model
{
    public const ROLES = [
        'administrador' => 'Administrador',
        'director' => 'Director de escuela',
        'profesor' => 'Profesor',
        'estudiante' => 'Estudiante',
    ];

    /**
     * El personal de la institucion: todos los roles menos "estudiante".
     *
     * Es quien puede quedar a cargo de una promotoria —un director que tambien
     * dicta es un caso real— y quien entra al Panel. Las dos listas salen de
     * aqui para que no se separen con el tiempo.
     */
    public const ROLES_PERSONAL = ['administrador', 'director', 'profesor'];

    protected $table = 'perfiles';

    protected $fillable = [
        'user_id',
        'rol',
        'nombre_completo',
        'fecha_nacimiento',
        'telefono',
        'foto_perfil',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function encuesta(): HasOne
    {
        return $this->hasOne(EncuestaDemografica::class, 'perfil_id');
    }

    public function encuestasSatisfaccion(): HasMany
    {
        return $this->hasMany(EncuestaSatisfaccion::class, 'perfil_id');
    }

    public function datosEstudiante(): HasOne
    {
        return $this->hasOne(DatosEstudiante::class, 'perfil_id');
    }

    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class, 'estudiante_id');
    }

    public function promotoriasDictadas(): HasMany
    {
        return $this->hasMany(Promotoria::class, 'profesor_id');
    }

    public function clasesRegistradas(): HasMany
    {
        return $this->hasMany(Clase::class, 'registrada_por_id');
    }

    /** Etiqueta legible del rol, o el aviso de que no tiene ninguno. */
    public function getRolDisplayAttribute(): string
    {
        return self::ROLES[$this->rol] ?? 'sin rol asignado';
    }

    public function esPersonal(): bool
    {
        return in_array($this->rol, self::ROLES_PERSONAL, true);
    }

    /**
     * Anos cumplidos a partir de una fecha de nacimiento.
     *
     * Se escribe a mano en vez de usar `diffInYears` porque en Carbon 3 ese
     * metodo devuelve un float con signo, y aqui hace falta exactamente la
     * cuenta del original: el ano de diferencia, menos uno si todavia no ha
     * llegado el cumpleanos de este ano.
     *
     * Es publica y estatica porque el formulario de inscripcion necesita la
     * misma cuenta ANTES de que exista ningun perfil, para saber si hay que
     * exigir acudiente. Dos implementaciones de "es menor de edad" acabarian
     * discrepando justo el dia del cumpleanos.
     */
    public static function edadDe(Carbon $nacimiento): int
    {
        $hoy = Carbon::today();
        $edad = $hoy->year - $nacimiento->year;

        if ([$hoy->month, $hoy->day] < [$nacimiento->month, $nacimiento->day]) {
            $edad--;
        }

        return $edad;
    }

    /**
     * Anos cumplidos. Se calcula, no se guarda: una edad almacenada estaria mal
     * todos los dias menos el del cumpleanos.
     */
    public function getEdadAttribute(): int
    {
        return self::edadDe($this->fecha_nacimiento);
    }

    public function getEsMenorAttribute(): bool
    {
        return $this->edad < 18;
    }

    /**
     * ¿A esta persona le falta contestar la encuesta demografica?
     *
     * Cubre los dos casos, que para quien la tiene que llenar son el mismo: no
     * haberla empezado nunca, y tenerla a medias.
     */
    public function getEncuestaPendienteAttribute(): bool
    {
        $encuesta = $this->encuesta;

        return $encuesta === null || ! $encuesta->esta_completa;
    }

    public function __toString(): string
    {
        return "{$this->nombre_completo} ({$this->rol_display})";
    }
}
