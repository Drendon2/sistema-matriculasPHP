<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Departamento artistico: Musica, Danza, Teatro, Pintura... Los crea el admin.
 */
class Area extends Model
{
    protected $table = 'areas';

    protected $fillable = ['nombre'];

    /**
     * Numero de colores de etiqueta disponibles (ver .tag-0..tag-N en app.css).
     *
     * Cada area recibe un color estable por su id, como un marcador de color
     * distinto por disciplina en la cartelera del estudio.
     */
    public const NUM_COLORES_ETIQUETA = 8;

    public function promotorias(): HasMany
    {
        return $this->hasMany(Promotoria::class);
    }

    /**
     * Clase CSS "tag-N" estable para esta area, segun su id.
     *
     * Vive en el modelo y no en un helper de plantilla porque es una propiedad
     * del area —su color—, no una decision de una pantalla concreta.
     */
    public function getTagColorAttribute(): string
    {
        if (! $this->id) {
            return 'tag-0';
        }

        return 'tag-'.($this->id % self::NUM_COLORES_ETIQUETA);
    }

    public function __toString(): string
    {
        return $this->nombre;
    }
}
