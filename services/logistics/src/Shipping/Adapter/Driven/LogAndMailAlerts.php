<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driven;

use Illuminate\Contracts\Mail\Mailer;
use Logistics\Shipping\Application\Alert;
use Logistics\Shipping\Application\Port\Driven\ForRaisingAlerts;
use Psr\Log\LoggerInterface;

/**
 * Two channels. The log line at level alert is what a log-based alerting rule
 * matches on (Loki, CloudWatch), and it goes out first; the e-mail is for the
 * people of the operation, and in the lab it lands in Mailpit. A mail server
 * down throws after the log line, so whoever raised the alert can try again.
 */
final readonly class LogAndMailAlerts implements ForRaisingAlerts
{
    public function __construct(private LoggerInterface $logger, private Mailer $mailer, private string $to) {}

    public function raise(Alert $alert): void
    {
        $this->logger->alert($alert->subject, ['lines' => $alert->lines]);
        $this->mailer->to($this->to)->send(new AlertMail($alert));
    }
}
