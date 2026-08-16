<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un papel que ESTA institucion exige para dar por valida una matricula.
 *
 * Que papeles hacen falta cambia de una entidad a otra —una pide certificado de
 * EPS, otra el recibo de servicios, otra nada—, asi que la lista es un registro
 * editable desde Gestion y no una constante del codigo.
 *
 * Se DESACTIVA en vez de borrarse cuando deja de pedirse: los archivos que ya
 * subieron los estudiantes cuelgan de aqui, y borrar el requisito se los
 * llevaria por delante junto con la prueba de que en su momento cumplieron.
 */
class DocumentoRequerido extends Model
{
    protected $table = 'documentos_requeridos';

    protected $fillable = [
        'nombre',
        'descripcion',
        'obligatorio',
        'activo',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'obligatorio' => 'boolean',
            'activo' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(DocumentoEstudiante::class, 'requerido_id');
    }

    /** Menor primero. Con el mismo numero manda el nombre. */
    public function scopeOrdenados(Builder $query): Builder
    {
        return $query->orderBy('orden')->orderBy('nombre');
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    public function __toString(): string
    {
        return $this->nombre;
    }
}
