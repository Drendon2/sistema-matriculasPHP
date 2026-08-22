<?php

/**
 * La ranura pasa a ser OPCIONAL: solo la gasta un programa.
 *
 * Las ranuras son el mecanismo que hace cumplir el limite de matriculas: cada
 * una viva ocupa un numero del 1 al RANURA_MAXIMA_ABSOLUTA, y el indice unico
 * `una_matricula_por_ranura_y_periodo` impide que dos compartan el mismo. Eso
 * las convertia en un techo para TODO, y desde que hay tipos ese techo estorba:
 * un taller, un curso y un grupo de proyeccion no consumen plaza del limite, y
 * por tanto tampoco deben consumir ranura. Si la consumieran, el techo del
 * esquema —6— acabaria limitandolos por la puerta de atras.
 *
 * Con `ranura` a NULL la columna generada `ranura_activa` sale tambien NULL, y
 * un indice unico de MariaDB admite tantos NULL como haga falta. De ahi sale la
 * ausencia de tope, sin tocar ni el indice ni el CHECK `ranura_valida`, que
 * sigue diciendo lo mismo para las que si la llevan.
 *
 * Lo que NO se pierde al soltar la ranura: matricularse dos veces en la misma
 * promotoria lo sigue impidiendo `unica_matricula_por_periodo`, que es un unico
 * sobre (estudiante, promotoria, periodo) y nunca dependio de la ranura.
 *
 * Las filas que ya existen son todas de tipo `programa` —es el defecto con el
 * que nacio la columna `tipo`— asi que conservan su ranura y nada cambia.
 */

use App\Models\Promotoria;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A mano y no con Schema::table()->change(): `ranura_activa` es una
        // columna GENERADA a partir de `ranura`, y el `change()` de Laravel
        // reescribe la definicion de la columna con lo que cree que sabe de
        // ella. En una tabla con una generada encima, eso es pedir problemas.
        DB::statement('ALTER TABLE matriculas MODIFY ranura TINYINT UNSIGNED NULL DEFAULT NULL');

        // Las que ya no deberian llevarla la sueltan. Hoy no hay ninguna —todo
        // lo existente es `programa`— pero la migracion tiene que dejar la base
        // coherente aunque alguien haya marcado tipos antes de desplegarla.
        DB::table('matriculas')
            ->join('promotorias', 'promotorias.id', '=', 'matriculas.promotoria_id')
            ->where('promotorias.tipo', '!=', Promotoria::PROGRAMA)
            ->update(['matriculas.ranura' => null]);
    }

    public function down(): void
    {
        // Volver atras exige que ninguna este sin ranura: con la columna
        // obligatoria otra vez, una fila a NULL no cabe. Se les da la primera
        // libre en su periodo, que es lo que habrian tenido de no existir los
        // tipos. Si no queda ninguna libre, el ALTER falla — correctamente,
        // porque esos datos no caben en el modelo viejo.
        foreach (DB::table('matriculas')->whereNull('ranura')->get() as $fila) {
            $ocupadas = DB::table('matriculas')
                ->where('estudiante_id', $fila->estudiante_id)
                ->where('periodo_id', $fila->periodo_id)
                ->where('estado', '!=', 'retirada')
                ->whereNotNull('ranura')
                ->pluck('ranura')
                ->all();

            for ($r = 1; $r <= 6; $r++) {
                if (! in_array($r, array_map('intval', $ocupadas), true)) {
                    DB::table('matriculas')->where('id', $fila->id)->update(['ranura' => $r]);
                    break;
                }
            }
        }

        DB::statement("ALTER TABLE matriculas MODIFY ranura TINYINT UNSIGNED NOT NULL DEFAULT '1'");
    }
};
