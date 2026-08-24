<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un dia concreto de una actividad: una clase, el taller, o un ensayo.
 *
 * La fila puede existir mucho antes de que el dia llegue —las fechas de un
 * curso se escriben al crearlo—, asi que "existe" y "se dio" son dos cosas
 * distintas y hacen falta dos columnas para contarlas. `iniciada_en` es la
 * segunda: en null mientras nadie oprima el boton.
 */
class SesionActividad extends Model
{
    protected $table = 'sesiones_actividad';

    protected $fillable = [
        'actividad_id',
        'fecha',
        'iniciada_en',
        'iniciada_por_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'iniciada_en' => 'datetime',
        ];
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class);
    }

    public function iniciadaPor(): BelongsTo
    {
        return $this->belongsTo(Perfil::class, 'iniciada_por_id');
    }

    /** Si ya se oprimio "Iniciar". */
    public function yaEmpezo(): bool
    {
        return $this->iniciada_en !== null;
    }
}
