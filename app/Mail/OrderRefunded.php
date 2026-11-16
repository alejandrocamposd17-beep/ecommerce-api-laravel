<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Correo de aviso de reembolso. Se envía en segundo plano (cola) para no hacer esperar al cliente. */
class OrderRefunded extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        // Solo se envía si la transacción que lo generó se confirmó
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reembolso de la orden '.$this->order->order_number);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.order-refunded', with: ['order' => $this->order->loadMissing('items')]);
    }
}
