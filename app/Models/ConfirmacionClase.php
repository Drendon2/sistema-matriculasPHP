<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un estudiante da fe, desde su propia sesion, de que la clase se dio.
 *
 * Es el contrapeso del boton de quien dicta: quien registra la clase es parte
 * interesada, asi que el registro por si solo no prueba nada.
 *
 * Confirma cualquier estudiante inscrito en el grupo, no solo aquel a quien
 * marcaron presente: lo que se verifica es que la clase EXISTIO, y hacerlo
 * depender de la asistencia que marca la propia persona verificada dejaria la
 * verificacion en sus manos.
 *
 * Se puede retirar. Una confirmacion es una afirmacion de alguien sobre lo que
 * vio, y quien se equivoco de renglon tiene que poder deshacerlo; dejar la marca
 * fija por miedo a que alguien juegue con ella tendria el precio de volver
 * permanente justo el error que este registro existe para evitar.
 *
 * Quien dicta ve CUANTAS confirmaciones lleva, no quienes las dieron.
 */
class ConfirmacionClase extends Model
{
    protected $table = 'confirmaciones_clase';

    protected $fillable = [
        'clase_id',
        'matricula_id',
        'fecha',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $confirmacion) {
            $confirmacion->fecha ??= now();
        });
    }

    public function clase(): BelongsTo
    {
        return $this->belongsTo(Clase::class);
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class);
    }
}
