# Problem types

Every error of the Tucano services leaves as [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem details (`application/problem+json`), with `type`, `title`, `status`, `detail`, `instance` and `correlationId`, plus `errors` (messages per field) on a validation failure.

Most problems are `about:blank`: the status says it all (a 404 is a 404). A problem gets its own type when two of them share a status and ask the client for different answers. Then `type` is this page plus the name of the problem, such as `https://github.com/flaviotinococoutinho/chaos_playground/blob/develop/contracts/http/problems.md#stock-not-reserved`, and a client decides by `type`, never by parsing `detail`.

In the code, a domain error declares its name with the `#[ProblemType('...')]` attribute, and the problem details of each service turn it into the URI. A new type is a new section here, with its anchor.

| Type | Status | Service |
|---|---|---|
| [`stock-not-reserved`](#stock-not-reserved) | 409 or 503 | commerce |
| [`product-unavailable`](#product-unavailable) | 409 | commerce |
| [`order-not-payable`](#order-not-payable) | 409 | commerce |
| [`idempotency-key-reused`](#idempotency-key-reused) | 422 | commerce |

## <a id="stock-not-reserved"></a>stock-not-reserved

The order asked for more units than the fulfillment center could reserve. With `409`, the stock is not there: fewer units or another product may work. With `503`, the reservation lost a race for the same rows under heavy contention and gave up to protect the database; the same order may go through a moment later.

## <a id="product-unavailable"></a>product-unavailable

The order has a product the catalog does not sell: it left the line, or it never existed. Retrying does not help; the product has to leave the order.

## <a id="order-not-payable"></a>order-not-payable

The order no longer waits for payment: it was paid, cancelled or its reservation expired. The client should read the order again and show what happened to it.

## <a id="idempotency-key-reused"></a>idempotency-key-reused

The `Idempotency-Key` of the request was already used with a different body ([draft-ietf-httpapi-idempotency-key-header](https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/)). A retry must send the same body; a new request needs a new key. A form that was already submitted and then changed is the usual cause.
