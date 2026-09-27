<?php

declare(strict_types=1);

namespace Commerce\Payments\Adapter\Driven;

use Commerce\Payments\Application\Port\Driven\ForStoringPayments;
use Commerce\Payments\Domain\Payment;
use Commerce\Payments\Domain\PaymentId;
use Commerce\Payments\Domain\PaymentNotFound;
use Commerce\Payments\Domain\PaymentStatus;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use stdClass;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

final readonly class PostgresPayments implements ForStoringPayments
{
    private const string COLUMNS = 'id, order_id, status, amount_cents, currency, provider_charge_id, failure_reason, created_at, updated_at';

    public function __construct(private ConnectionInterface $connection) {}

    public function addUnlessPending(Payment $payment): Payment
    {
        // The conflict target names the partial unique index: only a pending payment of the order collides.
        $this->connection->statement(<<<'SQL'
            INSERT INTO payments (id, order_id, status, amount_cents, currency, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (order_id) WHERE status = 'pending' DO NOTHING
            SQL, [
            $payment->id->toString(),
            $payment->orderId,
            $payment->status->value,
            $payment->amount->cents(),
            $payment->amount->currency()->code(),
            $payment->createdAt->format(DATE_RFC3339_EXTENDED),
            $payment->updatedAt->format(DATE_RFC3339_EXTENDED),
        ]);

        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . " FROM payments WHERE order_id = ? AND status = 'pending' FOR UPDATE",
            [$payment->orderId],
        );

        return $row instanceof stdClass ? self::paymentFrom($row) : $payment;
    }

    public function save(Payment $payment): void
    {
        $this->connection->update(
            'UPDATE payments SET status = ?, provider_charge_id = ?, failure_reason = ?, updated_at = ? WHERE id = ?',
            [
                $payment->status->value,
                $payment->chargeId,
                $payment->failureReason,
                $payment->updatedAt->format(DATE_RFC3339_EXTENDED),
                $payment->id->toString(),
            ],
        );
    }

    public function get(PaymentId $id): Payment
    {
        return $this->find($id) ?? throw PaymentNotFound::withId($id->toString());
    }

    public function find(PaymentId $id): ?Payment
    {
        $row = $this->connection->selectOne('SELECT ' . self::COLUMNS . ' FROM payments WHERE id = ? FOR UPDATE', [$id->toString()]);

        return $row instanceof stdClass ? self::paymentFrom($row) : null;
    }

    private static function paymentFrom(stdClass $row): Payment
    {
        return Payment::restore(
            PaymentId::fromString((string) $row->id),
            (string) $row->order_id,
            Money::of((int) $row->amount_cents, Currency::fromCode((string) $row->currency)),
            PaymentStatus::from((string) $row->status),
            $row->provider_charge_id === null ? null : (string) $row->provider_charge_id,
            $row->failure_reason === null ? null : (string) $row->failure_reason,
            self::instant((string) $row->created_at),
            self::instant((string) $row->updated_at),
        );
    }

    private static function instant(string $timestamptz): DateTimeImmutable
    {
        return new DateTimeImmutable($timestamptz)->setTimezone(new DateTimeZone('UTC'));
    }
}
