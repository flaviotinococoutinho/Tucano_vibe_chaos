#!/usr/bin/env bash
# Creates the topics the services expect. Safe to run again.
set -euo pipefail

bootstrap=kafka:9092
kafka_topics=/opt/kafka/bin/kafka-topics.sh

until "$kafka_topics" --bootstrap-server "$bootstrap" --list >/dev/null 2>&1; do
  echo "waiting for kafka..."
  sleep 2
done

create() {
  local topic=$1
  shift
  "$kafka_topics" --bootstrap-server "$bootstrap" --create --if-not-exists \
    --topic "$topic" --partitions 3 --replication-factor 1 "$@"
}

week_ms=604800000
two_weeks_ms=1209600000

# Full product state, one record per key: compaction keeps the latest version forever.
create catalog.products.v1 --config cleanup.policy=compact --config min.compaction.lag.ms=60000
# The address of ADR 0020 changed the shape of both: v2 is current, and v1 stays
# until its consumer groups have no lag left on it.
create commerce.orders.v1 --config retention.ms="$week_ms"
create commerce.orders.v2 --config retention.ms="$week_ms"
create logistics.shipments.v1 --config retention.ms="$week_ms"
create logistics.shipments.v2 --config retention.ms="$week_ms"

consumer_groups=(
  commerce.catalog-sync
  logistics.catalog-sync
  logistics.order-intake
  logistics.label-requests
  logistics.pickup-bookings
  commerce.shipment-sync
  commerce.order-projector
  logistics.timeline-projector
  commerce.notification-router
  bff.live
)

for group in "${consumer_groups[@]}"; do
  create "dlq.$group" --config retention.ms="$two_weeks_ms"
done

"$kafka_topics" --bootstrap-server "$bootstrap" --list
