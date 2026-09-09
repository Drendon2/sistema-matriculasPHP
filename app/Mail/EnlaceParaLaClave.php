<?php

namespace App\Mail;

use App\Models\ConfiguracionInstitucion;
use App\Models\User;
use App\Support\CorreoDeLaInstitucion;
use App\Support\RestablecerClave;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * EL PRIMER Y UNICO CORREO QUE MANDA ESTE SISTEMA.
 *
 * Conviene saberlo antes de anadir el segundo: hasta el 09/09/2026 no existian
 * ni `app/Mail` ni `app/Notifications`, y «nada avisa a nadie de nada» era una
 * propiedad del sistema escrita en CLAUDE.md — cuando un profesor confirma una
 * matricula, la unica forma de enterarse es volver a entrar y mirar. Eso SIGUE
 * SIENDO ASI. Esto no abre las notificaciones por correo: abre una puerta para
 * volver a entrar, que es otra cosa y tiene otro publico.
 *
 * ─── VA SIN COLA, A PROPOSITO ──────────────────────────────────────────────
 *
 * La clase declara `Queueable` porque lo hace la plantilla de Laravel, pero se
 * envia con `Mail::send()` y no con `Mail::queue()`. En el hosting de hoy no
 * corre ningun trabajador de colas: encolarlo dejaria la fila en la base y el
 * correo sin salir, sin que nada fallara ni avisara — que es exactamente la
 * clase de fallo que este proyecto ya ha pagado varias veces. El coste es que
 * la peticion espera al servidor de correo, y por eso `config/mail.php` lleva
 * un `timeout` corto.
 *
 * ─── NI EL NOMBRE NI EL LOGO DE LA ENTIDAD ESTAN QUEMADOS ──────────────────
 *
 * Salen de `configuracion_institucion`, como todo lo demas: esto es un producto
 * que se instala en casas ajenas.
 */
class EnlaceParaLaClave extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $usuario,
        public readonly string $enlace,
    ) {}

    /**
     * EL «DE:» SE PONE AQUI, Y NO SOBRA.
     *
     * `Mail::build()` —el remitente que se arma con las credenciales escritas
     * en Gestion → Institucion— NO aplica el remitente global de
     * `config('mail.from')`; eso solo lo hace `Mail::mailer()`. Sin esta linea
     * el envio falla con «An email must have a From header», y **solo por ese
     * camino**: por el del `.env` seguiria funcionando, asi que el fallo
     * aparece justo en la entidad que configuro el correo desde la pantalla y
     * no en la que lo tiene en el archivo.
     *
     * Nulo cuando no hay remitente por ninguna parte, que es lo correcto:
     * entonces manda el global y si tampoco hay, el envio se queja — que es lo
     * que tiene que pasar.
     */
    public function envelope(): Envelope
    {
        $remitente = CorreoDeLaInstitucion::remitente();

        return new Envelope(
            from: $remitente === null
                ? null
                : new Address($remitente['address'], $remitente['name']),
            subject: 'Restablecer tu contraseña — '.ConfiguracionInstitucion::actual()->nombre_institucion,
        );
    }

    public function content(): Content
    {
        return new Content(
            // Se manda en TEXTO y no en HTML. No es pereza: un correo de una
            // sola frase y un enlace no gana nada con maquetacion, y en texto
            // plano se ve igual en cualquier cliente, no cae en la pestana de
            // promociones por traer imagenes y no hay forma de que el enlace
            // «real» y el que se ve sean distintos, que es la forma de un correo
            // de suplantacion. Quien reciba esto tiene que poder LEER a donde va.
            text: 'correo.enlace-clave',
            with: [
                'nombre' => $this->usuario->perfil?->nombre_completo ?: $this->usuario->username,
                'institucion' => ConfiguracionInstitucion::actual()->nombre_institucion,
                'enlace' => $this->enlace,
                'minutos' => RestablecerClave::VALIDEZ_MINUTOS,
            ],
        );
    }
}
