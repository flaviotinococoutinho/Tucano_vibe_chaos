#!/bin/bash
# Healthy only after the ready.d hooks finished, so no service starts before
# its buckets, queues and tables exist.
exec 3<>/dev/tcp/127.0.0.1/4566 || exit 1
printf 'GET /_floci/init HTTP/1.0\r\nHost: localhost\r\n\r\n' >&3
grep -q '"ready":true' <&3
