<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una persona apuntada a una actividad.
 *
 * Sin cuenta y sin matricula: lo que la trajo fue el enlace. Si ademas resulta
 * ser un estudiante del sistema, `perfil` lo dice; para casi todos sera null y
 * eso no es un dato incompleto, es lo normal.
 */
class InscritoActividad extends Model
{
    /** Llego por el enlace y lleno el formulario. */
    public const ENLACE = 'enlace';

    /** Aparecio el dia de la clase y lo anadio el responsable. */
    public const EN_SESION = 'en_sesion';

    protected $table = 'inscritos_actividad';

    protected $fillable = [
        'actividad_id',
        'nombre_completo',
        'documento',
        'telefono',
        'correo',
        'fecha_nacimiento',
        'perfil_id',
        'origen',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
        ];
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class);
    }

    /** El estudiante del sistema, si el documento coincidio con alguno. */
    public function perfil(): BelongsTo
    {
        return $this->belongsTo(Perfil::class);
    }

    /**
     * El perfil al que pertenece ese documento, o null.
     *
     * Se busca por el documento y no por el nombre a proposito: el nombre lo
     * escribe cada quien como le sale —con o sin segundo apellido, con o sin
     * tildes— y emparejar por ahi habria atado a la persona equivocada. El
     * documento es unico en `datos_estudiante`, asi que o coincide o no.
     */
    public static function perfilConDocumento(?string $documento): ?Perfil
    {
        if ($documento === null || $documento === '') {
            return null;
        }

        return DatosEstudiante::where('documento_identidad', $documento)->first()?->perfil;
    }
}
