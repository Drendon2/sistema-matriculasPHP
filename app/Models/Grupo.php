<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Promotoria + nivel: el horario concreto que crea quien la dicta.
 *
 * Se crea segun la disponibilidad de quien ensena, y ahi se reparte a los
 * estudiantes que YA se matricularon en la promotoria — el estudiante nunca
 * elige horario.
 */
class Grupo extends Model
{
    public const NIVELES = [
        'basico' => 'Básico',
        'intermedio' => 'Intermedio',
        'avanzado' => 'Avanzado',
    ];

    protected $table = 'grupos';

    protected $fillable = [
        'promotoria_id',
        'nivel',
        'horario',
        'salon',
        'cupo_maximo',
    ];

    protected function casts(): array
    {
        return [
            'cupo_maximo' => 'integer',
        ];
    }

    public function promotoria(): BelongsTo
    {
        return $this->belongsTo(Promotoria::class);
    }

    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class);
    }

    public function clases(): HasMany
    {
        return $this->hasMany(Clase::class);
    }

    public function getNivelDisplayAttribute(): string
    {
        return self::NIVELES[$this->nivel] ?? $this->nivel;
    }

    /**
     * Sitios libres en el grupo para ese periodo.
     *
     * Cuenta tambien las cancelaciones en tramite: el sitio sigue ocupado
     * mientras nadie apruebe la salida.
     */
    public function cuposDisponibles(?Periodo $periodo): int
    {
        if ($periodo === null) {
            return $this->cupo_maximo;
        }

        $ocupados = $this->matriculas()
            ->where('periodo_id', $periodo->id)
            ->whereIn('estado', Matricula::ESTADOS_INSCRITO)
            ->count();

        return $this->cupo_maximo - $ocupados;
    }

    public function __toString(): string
    {
        return "{$this->promotoria->nombre} - {$this->nivel_display}";
    }
}
