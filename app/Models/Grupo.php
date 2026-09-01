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

    /**
     * Los encuentros semanales, en orden de semana.
     *
     * Ordenadas aqui y no en cada consulta: un horario que salga «jueves,
     * lunes» no esta mal por poco, esta mal.
     */
    public function sesiones(): HasMany
    {
        return $this->hasMany(SesionGrupo::class, 'grupo_id')
            ->orderBy('dia')
            ->orderBy('hora_inicio');
    }

    public function getNivelDisplayAttribute(): string
    {
        return self::NIVELES[$this->nivel] ?? $this->nivel;
    }

    /**
     * El horario como texto, armado desde las sesiones.
     *
     * Se DERIVA y no se guarda: cuando existian las dos cosas —la columna de
     * texto y las horas— acababan discrepando al primer descuido, y entonces no
     * hay forma de saber cual de las dos miente.
     *
     * Los dias que comparten hora se juntan («Martes y jueves 4:00 p. m. a 6:00
     * p. m.») porque es como lo dice la gente, y repetir la hora en cada dia
     * hace una linea que nadie lee entera.
     */
    public function getHorarioAttribute(): string
    {
        $sesiones = $this->sesiones;

        if ($sesiones->isEmpty()) {
            return '';
        }

        return $sesiones
            ->groupBy(fn (SesionGrupo $s) => $s->hora_inicio.'-'.$s->hora_fin)
            ->map(function ($delMismoHorario) {
                $dias = $delMismoHorario->map(fn (SesionGrupo $s) => $s->dia_display)->all();
                $primera = $delMismoHorario->first();

                return $this->enumerar($dias)
                    ." {$primera->inicio_display} a {$primera->fin_display}";
            })
            ->implode(' · ');
    }

    /** «Lunes», «Lunes y jueves», «Lunes, martes y jueves». */
    private function enumerar(array $dias): string
    {
        if (count($dias) === 1) {
            return $dias[0];
        }

        $ultimo = array_pop($dias);

        // Los dias que no van primero se escriben en minuscula: «Martes y
        // jueves», no «Martes y Jueves».
        $previos = implode(', ', $dias);

        return $previos.' y '.mb_strtolower($ultimo);
    }

    /**
     * El grupo sin el salon: nombre, nivel y horario.
     *
     * Lo piden las pantallas donde el salon no aporta —los desplegables de
     * filtro y de reparto, y las tablas de una fila por matricula—, que hasta
     * ahora escribian «nombre · horario» a mano. Escrito a mano, un grupo al
     * que todavia no le han puesto sesiones sale «A · Básico ·», con el
     * separador colgando, porque el horario se DERIVA de las sesiones y sin
     * ellas es cadena vacia. Aqui las partes vacias se caen solas.
     */
    public function getRotuloBreveAttribute(): string
    {
        return implode(' · ', array_filter([$this->nombre_con_nivel, $this->horario]));
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
        $partes = [$this->rotulo_breve];

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
