<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use Illuminate\Mail\Transport\ArrayTransport;
use Logistics\Shipping\Application\Alert;
use Logistics\Shipping\Application\Port\Driven\ForRaisingAlerts;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/** The port as the container builds it: deduplicated, logged and mailed (to the array mailer of the tests). */
final class AlertMailTest extends TestCase
{
    #[Test]
    public function an_alert_reaches_the_operation_by_e_mail_once_per_window(): void
    {
        $alerts = $this->app->make(ForRaisingAlerts::class);
        $alert = Alert::of('stalled-journeys:a', '2 shipments stalled with the carrier', 'TX02PX83Y5M5G00 picked_up with correio-nacional', 'TX02PX9D4HQ2R01 <out_for_delivery>');

        $alerts->raise($alert);
        $alerts->raise($alert);

        $transport = $this->app->make('mailer')->getSymfonyTransport();
        self::assertInstanceOf(ArrayTransport::class, $transport);
        $sent = $transport->messages();
        self::assertCount(1, $sent, 'The same news within the window does not go out again.');
        $message = $sent->first();
        self::assertInstanceOf(SentMessage::class, $message);
        $email = $message->getOriginalMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('[Tucano logistics] 2 shipments stalled with the carrier', $email->getSubject());
        self::assertSame('ops@tucano.local', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('TX02PX9D4HQ2R01 &lt;out_for_delivery&gt;', (string) $email->getHtmlBody(), 'The lines go as text, escaped.');
    }
}
