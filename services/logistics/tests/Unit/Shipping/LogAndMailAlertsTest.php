<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use Illuminate\Contracts\Mail\Mailer;
use Logistics\Shipping\Adapter\Driven\LogAndMailAlerts;
use Logistics\Shipping\Application\Alert;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Doubles\RecordingLogger;

final class LogAndMailAlertsTest extends TestCase
{
    #[Test]
    public function the_log_line_goes_out_before_the_e_mail_and_a_mail_server_down_is_told_to_the_caller(): void
    {
        $mailer = self::createStub(Mailer::class);
        $mailer->method('to')->willThrowException(new TransportException('Connection could not be established with host "toxiproxy:11025"'));
        $logger = new RecordingLogger();

        try {
            (new LogAndMailAlerts($logger, $mailer, 'ops@tucano.local'))->raise(Alert::of('stalled-journeys:a', '1 shipment stalled with the carrier', 'TX02PX83Y5M5G00'));
            self::fail('The e-mail that did not go out has to be told.');
        } catch (TransportException) {
            self::assertSame(['1 shipment stalled with the carrier'], $logger->messagesAt('alert'));
        }
    }
}
