<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Como le fue a UN inscrito en UNA sesion de actividad.
 *
 * La hermana de `Asistencia`, que va contra una matricula. Aqui va contra un
 * inscrito, que puede no tener ni cuenta.
 *
 * No hay un cuarto estado "sin marcar": eso se representa por la AUSENCIA de
 * fila, igual que en `Asistencia`, y por la misma razon — que no exista la fila
 * es informacion real, y guardarla como valor la volveria indistinguible de una
 * respuesta deliberada.
 */
class AsistenciaActividad extends Model
{
    /**
     * Las mismas tres de siempre, tomadas de `Asistencia` y no copiadas.
     *
     * El vocabulario de "vino / no vino / no vino con excusa" es UNO en todo el
     * sistema, y dos listas con los mismos valores son dos listas que un dia
     * discrepan. Las tablas si estan separadas —cuelgan de cosas distintas—,
     * pero lo que significan las marcas no.
     */
    public const ESTADOS = Asistencia::ESTADOS;

    public const ASISTIO = Asistencia::ASISTIO;

    protected $table = 'asistencias_actividad';

    protected $fillable = [
        'sesion_id',
        'inscrito_id',
        'estado',
        'fecha_registro',
    ];

    protected function casts(): array
    {
        return [
            'fecha_registro' => 'datetime',
        ];
    }

    /** Se refresca en cada guardado: es la marca de la ultima correccion. */
    protected static function booted(): void
    {
        static::saving(function (self $asistencia) {
            $asistencia->fecha_registro = now();
        });
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionActividad::class, 'sesion_id');
    }

    public function inscrito(): BelongsTo
    {
        return $this->belongsTo(InscritoActividad::class, 'inscrito_id');
    }
}
