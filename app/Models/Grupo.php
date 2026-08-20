<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un horario concreto de una promotoria, creado por quien la dicta.
 *
 * Se crea segun la disponibilidad de quien ensena, y ahi se reparte a los
 * estudiantes que YA se matricularon en la promotoria — el estudiante nunca
 * elige horario.
 *
 * Lo que identifica a un grupo es su NOMBRE, unico dentro de la promotoria, y
 * no su nivel: una promotoria puede tener varios grupos de Basico —es el caso
 * corriente cuando atiende a mucha gente— y ahi «Basico» no dice cual. El nivel
 * sigue estando porque agrupa por dificultad, pero paso a ser un dato del grupo
 * y no su identidad.
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
        'nombre',
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
     * Como se lee el grupo de un vistazo: nombre, nivel, horario y salon.
     *
     * Existe para que las pantallas no compongan cada una su version: son diez
     * las que pintan un grupo, y cuando el nombre se antepuso al nivel hubo que
     * tocarlas todas. La proxima vez que cambie el orden, se cambia aqui.
     *
     * El salon va al final y solo si lo hay: es lo unico que puede faltar en la
     * practica, y una linea que termine en un separador suelto se ve rota.
     */
    public function getRotuloAttribute(): string
    {
        $partes = [$this->nombre_con_nivel, $this->horario];

        if ($this->salon !== '' && $this->salon !== null) {
            $partes[] = $this->salon;
        }

        return implode(' · ', array_filter($partes));
    }

    /**
     * Nombre y nivel juntos, salvo cuando el nivel repetiria el nombre.
     *
     * Un grupo puede llamarse «Básico» —es como quedaron los que existian antes
     * de que los grupos tuvieran nombre, y sigue siendo un nombre razonable
     * cuando de ese nivel solo hay uno—. Pintar «Básico · Básico» no informa de
     * nada y se lee como un error del sistema.
     */
    public function getNombreConNivelAttribute(): string
    {
        return $this->nombre === $this->nivel_display
            ? $this->nombre
            : "{$this->nombre} · {$this->nivel_display}";
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

    /**
     * El nombre CON su promotoria delante, para los mensajes que salen de la
     * pantalla del grupo — «Fulano fue asignado a Guitarra - Martes tarde».
     * Fuera de la promotoria, «Martes tarde» a secas no ubica a nadie.
     */
    public function __toString(): string
    {
        return "{$this->promotoria->nombre} - {$this->nombre}";
    }
}
