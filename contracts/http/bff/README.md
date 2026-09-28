# BFF hypermedia contract

The BFF speaks [Siren](https://github.com/kevinswiber/siren) (`application/vnd.siren+json`) to the web. Every response is a **screen**: what to show, what the user can do next, and where each thing leads. The web renders what arrives and follows links and actions; it never builds a URL or decides a flow. This is server-driven UI through hypermedia (HATEOAS): the server decides the next steps, the client draws them.

Errors are [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem details (`application/problem+json`).

The examples in [`examples/`](examples) are real documents: the BFF tests check them against [`siren.schema.json`](siren.schema.json), and the web tests render them. Problems live apart, in [`examples/problems/`](examples/problems).

## Addresses

- Every BFF resource lives under `/bff/v1`. The entry point is `GET /bff/v1`.
- The browser path mirrors the BFF path without the prefix: the web shows `/orders/{id}` by fetching `/bff/v1/orders/{id}`, and turns every `/bff/v1/...` href back into a browser path.
- Hrefs are absolute-path references (`/bff/v1/...`). A link with class `external` points outside the app (the docs, for example) and opens as a plain link.

## Entity

| Member | Type | Meaning |
|---|---|---|
| `class` | `string[]` | what the entity is. A screen is `["screen", "<name>"]`, and `"live"` joins it when the screen asks to be refreshed. A component is `["<component>"]` |
| `title` | `string` | the title a person reads, in Portuguese |
| `properties` | `object` | the content of the screen or component, already formatted for people where it matters (money, labels, tones) |
| `entities` | `entity[]` | embedded components, each with a `rel` |
| `links` | `link[]` | where the user can go |
| `actions` | `action[]` | what the user can do, with the fields to fill |

A **link** has `rel` (`string[]`), `href`, and optionally `title` and `class`. An **action** has `name`, `title` (the text of its button), `method`, `href`, `type` (`application/json` for POST) and `fields`. A **field** has `name`, `type` and `title`, plus the extensions below.

## Link relations

Registered relation types ([RFC 8288](https://www.rfc-editor.org/rfc/rfc8288)) mean what the registry says: `self`, `up`, `collection`, `item`, `next` and `prev`. Relations of this domain are extension types, which RFC 8288 requires to be URIs, and each one points to its own definition here:

| Relation | Meaning |
|---|---|
| <a id="rel-catalog"></a>`https://github.com/flaviotinococoutinho/chaos_playground/blob/develop/contracts/http/bff/README.md#rel-catalog` | the catalog of products |
| <a id="rel-track"></a>`https://github.com/flaviotinococoutinho/chaos_playground/blob/develop/contracts/http/bff/README.md#rel-track` | the tracking page of the shipment of an order |

## Screens

| Class | Resource | Properties | Actions and links |
|---|---|---|---|
| `home` | `GET /bff/v1` | `headline`, `tagline` | action `track-by-code`; link catalog |
| `catalog` | `GET /bff/v1/products?page=` | `page`, `perPage`, `total` | `product-card` entities (`item`); links `next` and `prev` when they exist, `up` |
| `product` | `GET /bff/v1/products/{sku}` | `sku`, `name`, `category`, `categoryLabel`, `price`, `weightGrams`, `dimensions` | action `buy`; links `collection`, `up` |
| `checkout` | `GET /bff/v1/checkout?sku=&quantity=` | `sku`, `name`, `quantity`, `unitPrice`, `subtotal` | action `place-order`; link `up` (the product) |
| `order` | `GET /bff/v1/orders/{id}` | `orderId`, `orderNumber`, `status`, `statusLabel`, `tone`, `placedAt`, `reservationExpiresAt`, `total`, `trackingCode`, and `notice` and `refreshAfterSeconds` when they apply | `order-line` entities (`item`); action `pay` only while the order waits for payment; link track once it ships; link catalog |
| `tracking` | `GET /bff/v1/tracking/{code}` | `trackingCode`, `status`, `statusLabel`, `tone`, `carrier` (the code), `carrierLabel` (the name people read), `destination`, `updatedAt`, and `refreshAfterSeconds` while the parcel moves | `timeline-step` entities (`item`), oldest first; link `up` |

The product screen of a product out of line comes without `buy` and with a `notice`. `orderNumber` is the decimal text of a Snowflake, as Commerce publishes it (`97856663872212992`): the web shows it as it comes and never parses it.

`GET /bff/v1/tracking?code=` answers `303 See Other` with the tracking page as `Location`, so the form of `track-by-code` lands on the address of the page.

## Components

| Class | Properties |
|---|---|
| `product-card` | `sku`, `name`, `category`, `categoryLabel`, `price`; link `self` to the product |
| `order-line` | `sku`, `name`, `quantity`, `unitPrice`, `subtotal` |
| `timeline-step` | `status`, `label`, `at`, and `hub` or `attempt` when the step has one |

**Money** is always `{"amount": 15990, "currency": "BRL", "formatted": "R$ 159,90"}`: the amount in cents for whoever computes, the formatted text for whoever shows. **Instants** are [RFC 3339](https://www.rfc-editor.org/rfc/rfc3339) in UTC; the web formats them for the reader. **Tone** is one of `neutral`, `waiting`, `info`, `success` and `danger`, and says how a status should feel, never which color it gets.

## Live screens

A screen with `"live"` in its class asks to be fetched again from its `self` link after `refreshAfterSeconds`. The web stops refreshing when the tab is hidden and when the screen stops being live.

| Screen | Live while | Every |
|---|---|---|
| order right after `pay` (`?awaiting=payment` in `self`) | the PSP has not answered | 2 s |
| order `paid` or `shipped` | the parcel is being prepared or is on its way | 5 s |
| tracking | the journey has not ended (delivered, returned or cancelled) | 5 s |

After `pay`, the order follows the payment. An approved card turns it `paid`, and the first render after that carries the notice "Pagamento aprovado." with a `self` link that no longer awaits. A declined card cancels the order in Commerce, and the screen ends with a `danger` notice that says so. Every cancelled order explains why in its notice (declined card, expired reservation, customer request), and a returned order says the refund was asked for.

## Actions and fields

| Action | Request | Answer |
|---|---|---|
| `track-by-code` | `GET /bff/v1/tracking`, field `code` | `303` to the tracking page. The BFF takes the code the way people type it (any case, spaces around, and the letters Crockford Base32 reads as digits: O as 0, I and L as 1), and the field's `pattern` lets those through |
| `buy` | `GET /bff/v1/checkout`, fields `sku` and `quantity` | the checkout screen |
| `place-order` | `POST /bff/v1/orders`, JSON of the fields | `201 Created`, `Location` of the order, and the order screen |
| `pay` | `POST /bff/v1/orders/{id}/payments`, JSON of the fields | `202 Accepted`, `Location` of the live order screen, and that screen |

Field types are the input types of HTML (`text`, `email`, `number`, `hidden`, `tel`) plus `select`, whose choices come in `options` (`[{"value", "title"}]`). Fields may also carry `value`, `required`, `placeholder`, `autocomplete`, `inputmode`, `pattern`, `min`, `max` and `maxlength`, with the meaning they have in HTML, so the browser helps (autofill, the right keyboard on a phone) and validates before the request.

Actions that change something carry a hidden `idempotencyKey` field, filled by the BFF with a fresh UUIDv7 each time it renders the form. A double click or a retry sends the same key, and the service answers the same thing instead of placing a second order ([draft-ietf-httpapi-idempotency-key-header](https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/)). A refusal does not keep the key: after fixing a field, the same form goes again.

There is no login. The checkout gives the browser a guest id in the `tucano_guest` cookie ([RFC 6265](https://www.rfc-editor.org/rfc/rfc6265): `HttpOnly`, `SameSite=Lax`, `Path=/bff`, `Secure` in production), and every order of that browser goes to Commerce with it, so a retry sends the same body with the same key. The web keeps the default `credentials` of `fetch`. Screens are never cached (`Cache-Control: no-store`), because each render carries keys made for it.

## Errors

- The problems the BFF makes speak to the person, in Portuguese, in `detail`; `title` stays the phrase of the HTTP status.
- `422` with `errors`: an object from field name to messages, with the names of the fields of the action, so the web shows each message next to its field. Every field that needs attention comes in the same answer.
- `404` when the product, the order or the tracking code does not exist.
- `409` when the order cannot be placed (no stock for that quantity) or paid (it no longer waits for payment), and when the product left the line.
- `503` with `Retry-After` when a service behind the BFF is out or slower than `UPSTREAM_TIMEOUT_MS`; the web says when it is worth trying again. Only the screens that need that service fail: with Commerce out, the catalog still opens.
- Every problem carries `correlationId`, the same id of the logs of every service the request went through.
