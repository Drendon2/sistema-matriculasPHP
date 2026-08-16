<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * Datos que solo tienen los usuarios con rol 'estudiante'.
 *
 * La COPIA del documento solo la puede ver el administrador (el control esta en
 * la capa de permisos). Queda en blanco tras la inscripcion publica, se sube
 * despues en "Mi perfil" y NO bloquea que el profesor confirme la matricula
 * mientras tanto.
 */
class DatosEstudiante extends Model
{
    protected $table = 'datos_estudiante';

    protected $fillable = [
        'perfil_id',
        'documento_identidad',
        'copia_documento',
        'acudiente_id',
    ];

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(Perfil::class);
    }

    public function acudiente(): BelongsTo
    {
        return $this->belongsTo(Acudiente::class);
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoEstudiante::class, 'datos_estudiante_id');
    }

    /**
     * Regla de negocio: los menores de edad DEBEN tener acudiente.
     *
     * Vive aqui y no en el esquema porque la minoria de edad se deduce de
     * `perfiles.fecha_nacimiento` —otra tabla— y ningun CHECK de SQL puede
     * consultar otra tabla. Ni Postgres ni MariaDB; no es una limitacion de la
     * migracion.
     */
    public function validar(): void
    {
        $perfil = $this->perfil ?? Perfil::find($this->perfil_id);

        if ($perfil !== null && $perfil->es_menor && $this->acudiente_id === null) {
            throw ValidationException::withMessages([
                'acudiente' => 'Los estudiantes menores de edad deben registrar un acudiente.',
            ]);
        }
    }

    /**
     * Los papeles pedidos que este estudiante todavia no ha subido.
     *
     * `$exigidos` evita repetir la consulta cuando quien llama va a preguntar
     * por una lista entera de estudiantes — el listado del panel lo hace por
     * cada fila y sin esto seria una consulta por estudiante.
     *
     * @param  Collection<int, DocumentoRequerido>|null  $exigidos
     * @return list<DocumentoRequerido>
     */
    public function documentosFaltantes(?Collection $exigidos = null): array
    {
        $exigidos ??= DocumentoRequerido::where('activo', true)->get();

        $entregados = $this->documentos
            ->filter(fn (DocumentoEstudiante $d) => $d->archivo !== '' && $d->archivo !== null)
            ->pluck('requerido_id')
            ->all();

        return $exigidos
            ->reject(fn (DocumentoRequerido $d) => in_array($d->id, $entregados, true))
            ->values()
            ->all();
    }

    public function __toString(): string
    {
        return $this->perfil->nombre_completo;
    }
}
