<?php

namespace App\Support;

use App\Models\Grupo;
use App\Models\SesionGrupo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Lee el horario semanal que llega de un formulario y lo deja guardado.
 *
 * Vive aparte porque los grupos se crean desde DOS pantallas —el Panel, donde
 * quien dicta arma sus horarios, y Gestion, donde direccion mantiene el
 * catalogo— y la regla del horario tiene que ser la misma en las dos. Cuando la
 * regla de «un nivel por promotoria» estaba escrita en los dos sitios, quitarla
 * de uno solo dejo el otro cerrado; esto es para no repetir aquel viaje.
 *
 * El formulario manda una fila por dia de la semana, marcada o no:
 *
 *     sesiones[2][activo] = 1
 *     sesiones[2][desde]  = "16:00"
 *     sesiones[2][hasta]  = "18:00"
 */
class HorarioDeGrupo
{
    /**
     * Las sesiones marcadas, ya validadas y listas para guardar.
     *
     * @return list<array{dia: int, hora_inicio: string, hora_fin: string}>
     *
     * @throws ValidationException
     */
    public static function leer(Request $request): array
    {
        $enviado = $request->input('sesiones', []);
        $sesiones = [];
        $errores = [];

        foreach (array_keys(SesionGrupo::DIAS) as $dia) {
            $fila = $enviado[$dia] ?? [];

            if (empty($fila['activo'])) {
                continue;
            }

            $desde = self::normalizar($fila['desde'] ?? '');
            $hasta = self::normalizar($fila['hasta'] ?? '');
            $nombre = SesionGrupo::DIAS[$dia];

            if ($desde === null || $hasta === null) {
                $errores["sesiones.{$dia}.desde"] = "Falta la hora de {$nombre}.";

                continue;
            }

            if ($hasta <= $desde) {
                // El mensaje va al campo de la hora de fin porque es el que hay
                // que corregir, y ahi es donde se pinta.
                $errores["sesiones.{$dia}.hasta"] =
                    "En {$nombre}, la hora de fin tiene que ser posterior a la de inicio.";

                continue;
            }

            $sesiones[] = ['dia' => $dia, 'hora_inicio' => $desde, 'hora_fin' => $hasta];
        }

        // Un grupo ES un horario: sin ninguna sesion no se sabe cuando reunirse,
        // y el estudiante repartido ahi no tendria a donde ir.
        if ($sesiones === [] && $errores === []) {
            $errores['sesiones'] = 'Marca al menos un día y ponle hora: un grupo sin horario no sirve para nada.';
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return $sesiones;
    }

    /**
     * Deja el grupo exactamente con estas sesiones: agrega las nuevas, corrige
     * las que cambiaron de hora y borra los dias que se desmarcaron.
     *
     * @param  list<array{dia: int, hora_inicio: string, hora_fin: string}>  $sesiones
     */
    public static function guardar(Grupo $grupo, array $sesiones): void
    {
        $dias = array_column($sesiones, 'dia');

        // Primero se van los dias que ya no estan. Antes de escribir, para que
        // el unico (grupo, dia) no choque con una fila que esta a punto de
        // desaparecer.
        $grupo->sesiones()->whereNotIn('dia', $dias ?: [0])->delete();

        foreach ($sesiones as $sesion) {
            SesionGrupo::updateOrCreate(
                ['grupo_id' => $grupo->id, 'dia' => $sesion['dia']],
                ['hora_inicio' => $sesion['hora_inicio'], 'hora_fin' => $sesion['hora_fin']]
            );
        }
    }

    /**
     * Lo que hay que pintar en el formulario: la sesion de cada dia, o null.
     *
     * Respeta lo que el usuario acababa de escribir cuando la validacion
     * rechaza el formulario —de ahi el `old`—, porque perder seis filas de
     * horario por una hora mal puesta es motivo suficiente para no volver a
     * usar la pantalla.
     *
     * @return array<int, array{activo: bool, desde: string, hasta: string}>
     */
    public static function paraElFormulario(?Grupo $grupo): array
    {
        $viejo = old('sesiones');
        $guardadas = $grupo?->exists ? $grupo->sesiones->keyBy('dia') : collect();
        $filas = [];

        foreach (array_keys(SesionGrupo::DIAS) as $dia) {
            if ($viejo !== null) {
                $fila = $viejo[$dia] ?? [];

                $filas[$dia] = [
                    'activo' => ! empty($fila['activo']),
                    'desde' => (string) ($fila['desde'] ?? ''),
                    'hasta' => (string) ($fila['hasta'] ?? ''),
                ];

                continue;
            }

            $sesion = $guardadas->get($dia);

            $filas[$dia] = [
                'activo' => $sesion !== null,
                // El <input type="time"> solo entiende hh:mm, sin segundos.
                'desde' => $sesion ? substr((string) $sesion->hora_inicio, 0, 5) : '',
                'hasta' => $sesion ? substr((string) $sesion->hora_fin, 0, 5) : '',
            ];
        }

        return $filas;
    }

    /**
     * Una hora del formulario en la forma que guarda la base, o null si no vale.
     *
     * El navegador manda «16:00», pero un formulario enviado sin JavaScript o
     * desde un navegador viejo puede mandar «16:00:00» o venir vacio.
     */
    private static function normalizar(string $hora): ?string
    {
        $hora = trim($hora);

        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $hora, $c)) {
            return null;
        }

        return "{$c[1]}:{$c[2]}:00";
    }
}
