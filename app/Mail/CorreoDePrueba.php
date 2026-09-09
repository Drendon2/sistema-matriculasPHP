<?php

namespace App\Mail;

use App\Models\ConfiguracionInstitucion;
use App\Support\CorreoDeLaInstitucion;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El correo que se manda desde Gestion → Institucion para comprobar que el
 * servidor de correo esta bien configurado.
 *
 * ─── POR QUE ES UN MAILABLE Y NO UN `Mail::raw()` ──────────────────────────
 *
 * Porque `MailFake::raw()` ESTA VACIO: no envia, no guarda nada y no falla.
 * O sea que una prueba de este boton escrita sobre `raw()` no puede afirmar que
 * se mando nada — `assertSent` no ve un correo que el falso nunca registro—, y
 * la unica forma de escribirla seria afirmando algo mas debil que lo que
 * importa. Con un Mailable, lo que corre en las pruebas es lo mismo que corre
 * en el servidor.
 *
 * Y de paso el texto vive en una vista, como el otro correo del sistema, en vez
 * de en una cadena dentro de un controlador.
 */
class CorreoDePrueba extends Mailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        $institucion = ConfiguracionInstitucion::actual();
        $remitente = CorreoDeLaInstitucion::remitente($institucion);

        // El «De:» a mano, igual que en `EnlaceParaLaClave` y por lo mismo: un
        // remitente con nombre no aplica el global de `config('mail.from')`, y
        // sin esto el envio falla con «An email must have a From header» por el
        // camino que se esta probando — o sea justo donde mas confunde.
        return new Envelope(
            from: $remitente === null
                ? null
                : new Address($remitente['address'], $remitente['name']),
            subject: 'Prueba de correo — '.$institucion->nombre_institucion,
        );
    }

    public function content(): Content
    {
        return new Content(
            // En texto, como el otro. Ver `EnlaceParaLaClave`.
            text: 'correo.prueba',
            with: ['institucion' => ConfiguracionInstitucion::actual()->nombre_institucion],
        );
    }
}
