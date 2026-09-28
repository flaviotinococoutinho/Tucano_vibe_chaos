<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driven;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Logistics\Shipping\Application\Alert;

/** An alert as an e-mail to the operation: the subject, and the lines as they are. */
final class AlertMail extends Mailable
{
    public function __construct(public readonly Alert $alert) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Tucano logistics] ' . $this->alert->subject);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<pre>' . e($this->alert->body()) . '</pre>');
    }
}
