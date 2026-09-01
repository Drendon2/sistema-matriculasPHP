<?php

namespace Tests\Unit;

use App\Support\Regreso;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A donde se vuelve tras borrar, y por que ese campo oculto no es una puerta.
 */
class RegresoTest extends TestCase
{
    private const LISTA = 'https://escuelas.example.com/gestion/usuarios';

    private function conReferente(?string $referente): Request
    {
        $peticion = Request::create(self::LISTA.'/7/eliminar', 'GET');

        if ($referente !== null) {
            $peticion->headers->set('referer', $referente);
        }

        return $peticion;
    }

    public function test_recoge_los_filtros_con_los_que_se_miraba_la_lista(): void
    {
        $this->assertSame(
            'rol=__sin__&page=3',
            Regreso::consulta($this->conReferente(self::LISTA.'?rol=__sin__&page=3'), self::LISTA)
        );
    }

    public function test_sin_referente_no_se_inventa_ninguno(): void
    {
        $this->assertSame('', Regreso::consulta($this->conReferente(null), self::LISTA));
    }

    /** Llegar desde otra pantalla no arrastra los filtros de esa otra. */
    public function test_un_referente_de_otra_pantalla_se_descarta(): void
    {
        $this->assertSame(
            '',
            Regreso::consulta($this->conReferente('https://escuelas.example.com/gestion/grupos?area=2'), self::LISTA)
        );
    }

    public function test_devuelve_la_lista_con_sus_filtros(): void
    {
        $this->assertSame(
            self::LISTA.'?rol=__sin__&page=3',
            Regreso::url(self::LISTA, 'rol=__sin__&page=3')
        );
    }

    public function test_sin_filtros_devuelve_la_lista_a_secas(): void
    {
        $this->assertSame(self::LISTA, Regreso::url(self::LISTA, ''));
        $this->assertSame(self::LISTA, Regreso::url(self::LISTA, null));
    }

    /**
     * Lo que de verdad prueba esta clase.
     *
     * El campo va oculto en un formulario, o sea que cualquiera puede mandar lo
     * que quiera. Si el destino se compusiera con lo que llegue, bastaria con
     * meter una direccion entera para convertir el borrado en una redireccion
     * abierta — y ademas dentro de una pantalla donde la persona acaba de
     * escribir su contrasena. El destino lo pone SIEMPRE el servidor.
     *
     * @param  string  $malicioso  lo que alguien podria enviar a mano
     */
    #[DataProvider('intentos')]
    public function test_nunca_manda_fuera_del_listado(string $malicioso): void
    {
        $destino = Regreso::url(self::LISTA, $malicioso);

        $this->assertStringStartsWith(self::LISTA, $destino);
    }

    /**
     * Lo que `limpiar()` SI hace, dicho con precision.
     *
     * No es lo que impide salir del sitio —eso lo impide que el destino base lo
     * ponga el servidor, y esta prueba se escribio justo despues de comprobar
     * que la de arriba seguia verde sin la limpieza—. Lo que garantiza es que
     * detras del `?` haya una cadena de consulta de verdad: se deshace y se
     * vuelve a montar, asi que lo que salga tiene que sobrevivir a ese viaje sin
     * cambiar.
     */
    public function test_lo_que_sale_es_una_cadena_de_consulta_y_nada_mas(): void
    {
        $destino = Regreso::url(self::LISTA, '//malo.example.com');

        $consulta = (string) parse_url($destino, PHP_URL_QUERY);
        parse_str($consulta, $partes);

        $this->assertSame(http_build_query($partes), $consulta);
    }

    /** @return array<string, array{string}> */
    public static function intentos(): array
    {
        return [
            'otro sitio entero' => ['https://malo.example.com'],
            'sin esquema' => ['//malo.example.com'],
            'ruta absoluta' => ['/gestion/institucion'],
            'javascript' => ['javascript:alert(1)'],
            'con salto de linea' => ["rol=x\r\nLocation: https://malo.example.com"],
            'retroceso de ruta' => ['../../institucion'],
        ];
    }
}
