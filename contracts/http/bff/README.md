# BFF hypermedia contract

The BFF speaks [Siren](https://github.com/kevinswiber/siren) (`application/vnd.siren+json`) to the web. Every response is a **screen**: what to show, what the user can do next, and where each thing leads. The web renders what arrives and follows links and actions; it never builds a URL or decides a flow. This is server-driven UI through hypermedia (HATEOAS): the server decides the next steps, the client draws them.

Errors are [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem details (`application/problem+json`).

The examples in [`examples/`](examples) are real documents: the BFF tests build each one with the functions the BFF answers with and compare, CI checks them against [`siren.schema.json`](siren.schema.json), and the web tests render them. Problems live apart, in [`examples/problems/`](examples/problems).

## Addresses

- Every BFF resource lives under `/bff/v1`. The entry point is `GET /bff/v1`.
- The browser path mirrors the BFF path without the prefix: the web shows `/orders/{id}` by fetching `/bff/v1/orders/{id}`, and turns every `/bff/v1/...` href back into a browser path.
- Hrefs are absolute-path references (`/bff/v1/...`). A link with class `external` points outside the app (the docs, for example) and opens as a plain link.

## Entity

| Member | Type | Meaning |
|---|---|---|
| `class` | `string[]` | what the entity is. A screen is `["screen", "<name>"]`, and `"live"` joins it when the screen asks to be refreshed. A component is `["<component>"]` |
| `title` | `string` | the title a person reads, in Portuguese; a component has one when it has a heading (an order in the list) |
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
| <a id="rel-live"></a>`https://github.com/flaviotinococoutinho/chaos_playground/blob/develop/contracts/http/bff/README.md#rel-live` | the live position of the courier carrying the parcel: a WebSocket on the same origin, to open with `ws:` or `wss:` as the page is served, speaking the [live delivery](../../tracking/README.md) protocol. Offered only while a parcel of the own fleet is out for delivery |
| <a id="rel-navigation"></a>`https://github.com/flaviotinococoutinho/chaos_playground/blob/develop/contracts/http/bff/README.md#rel-navigation` | the navigation every screen embeds: who is shopping, and the links of the header |
| <a id="rel-orders"></a>`https://github.com/flaviotinococoutinho/chaos_playground/blob/develop/contracts/http/bff/README.md#rel-orders` | the orders of the profile shopping ("Meus pedidos") |
| <a id="rel-profiles"></a>`https://github.com/flaviotinococoutinho/chaos_playground/blob/develop/contracts/http/bff/README.md#rel-profiles` | the profiles of this browser, where a person picks who is shopping |
| <a id="rel-history"></a>`https://github.com/flaviotinococoutinho/chaos_playground/blob/develop/contracts/http/bff/README.md#rel-history` | a step of the story of an order: one of its own moves or, once it ships, a step of its parcel |

## Screens

| Class | Resource | Properties | Actions and links |
|---|---|---|---|
| `home` | `GET /bff/v1` | `headline`, `tagline` | action `track-by-code`; link catalog |
| `catalog` | `GET /bff/v1/products?page=` | `page`, `perPage`, `total` | `product-card` entities (`item`); links `next` and `prev` when they exist, `up` |
| `product` | `GET /bff/v1/products/{sku}` | `sku`, `name`, `category`, `categoryLabel`, `price`, `weightGrams`, `dimensions` | action `buy`; links `collection`, `up` |
| `checkout` | `GET /bff/v1/checkout?sku=&quantity=` | `sku`, `name`, `quantity`, `unitPrice`, `subtotal` | action `place-order`; link `up` (the product) |
| `orders` | `GET /bff/v1/orders?page=` | `page`, `perPage`, `total`, and a `notice` that a new order may take a few seconds to appear | `order-summary` entities (`item`), newest first; links `next` and `prev` when they exist; link catalog |
| `order` | `GET /bff/v1/orders/{id}` | `orderId`, `orderNumber`, `status`, `statusLabel`, `tone`, `headline`, `placedAt`, `reservationExpiresAt` (null once the order no longer waits for payment), `total`, `trackingCode`, `progress`, and `notice` and `refreshAfterSeconds` when they apply | `order-line` entities (`item`); `timeline-step` entities (history), oldest first; action `pay` only while the order waits for payment; link `collection` (the orders); link track once it ships; link live under the rule of the tracking screen; link catalog |
| `profiles` | `GET /bff/v1/profiles` | `intro` | `profile` entities (`item`); action `create-profile`; link `up` |
| `tracking` | `GET /bff/v1/tracking/{code}` | `trackingCode`, `status`, `statusLabel`, `tone`, `carrier` (the code), `carrierLabel` (the name people read), `destination`, `updatedAt`, and `refreshAfterSeconds` while the parcel moves | `timeline-step` entities (`item`), oldest first; link `up`; link live while a parcel of the own fleet is out for delivery |

Every screen also embeds one `navigation` component (`rel-navigation`), after its own entities, so the web draws its header from the screen it shows.

The product screen of a product out of line comes without `buy` and with a `notice`. `orderNumber` is the decimal text of a Snowflake, as Commerce publishes it (`97856663872212992`): the web shows it as it comes and never parses it.

The orders screen reads a read model a few seconds behind the orders ([ADR 0012](../../../docs/adr/0012-acid-writes-base-reads.md)), so it says a new order may take a few seconds to show. A browser with no session gets an empty list, and asking for it starts no session.

`GET /bff/v1/tracking?code=` answers `303 See Other` with the tracking page as `Location`, so the form of `track-by-code` lands on the address of the page.

## The story of an order

- **`headline`**: one sentence in the voice of the store that says where the order is now: "Estamos esperando o pagamento.", "Seu pedido está a caminho com o Correio Nacional.", "Seu pedido saiu para entrega com a Tucano Express."
- **`progress`**: the milestones "Pedido feito", "Pagamento aprovado", "Preparando o envio", "A caminho" and "Entregue", in this order, each `{"label", "state", "at"}`. `state` is `done`, `current`, `upcoming` or `stopped`, and `at` comes only when the milestone is done or stopped and the BFF knows when. The payment milestone in progress reads as the wait ("Aguardando pagamento", or "Confirmando o pagamento" right after `pay`). A cancelled order ends at a stopped milestone named by its reason ("Pagamento recusado", "Prazo para pagar acabou", "Cancelado a seu pedido"), a returned order at "Devolvido" after "A caminho", and nothing comes after a stopped milestone. Each order of the list carries the same `progress`, with the times the list knows: when the order was placed and when it reached its status.
- **History**: `timeline-step` entities under `rel-history`, oldest first: the moves of the order, from its history in Commerce, merged with the steps of its parcel from the tracking page once the order has a tracking code. Once the steps of the parcel are in, the moves they tell better (`shipped`, `delivered`, `returned`) leave. The `status` of a step is the code of the machine it came from (the order or the shipment).
- **Delivery news is an enrichment**: logistics has `ENRICHMENT_TIMEOUT_MS` to answer, not the whole `UPSTREAM_TIMEOUT_MS`. Without an answer in time (a timeout, a 503, a network error), the order still answers `200` with its own moves and a `neutral` notice that the news could not be fetched and the page tries again, and it stays live until logistics answers. An order that already has a notice of its own (a return, a payment just approved) keeps it, and still tries again. A parcel logistics has no page for (`404`: just picked up, or past the days the page is kept) adds nothing and says nothing.

## Components

| Class | Properties |
|---|---|
| `navigation` | `shopper`: `{"profileId", "label", "initial"}` of the profile shopping, or null when nobody shops in this browser yet; links catalog, orders ("Meus pedidos") and profiles, titled with the label of the shopper or "Entrar" |
| `product-card` | `sku`, `name`, `category`, `categoryLabel`, `price`; link `self` to the product |
| `order-summary` | `title` ("Pedido 97856663872212992"); `orderId`, `orderNumber`, `status`, `statusLabel`, `tone`, `placedAt`, `total`, `itemsLabel` ("Domain-Driven Design e mais 1 item", "2x Caneca de cerâmica"), `progress`; link `self` to the order |
| `order-line` | `sku`, `name`, `quantity`, `unitPrice`, `subtotal` |
| `profile` | `profileId`, `name` (null until the profile names itself), `label` (the name, or "Visitante"), `initial`, `active`; action `use-profile` on every profile but the active one |
| `timeline-step` | `status`, `label`, `at`, and `hub`, `attempt` or `detail` (a sentence that helps: why an order was cancelled, why a visit did not deliver) when the step has one |

**Money** is always `{"amount": 15990, "currency": "BRL", "formatted": "R$ 159,90"}`: the amount in cents for whoever computes, the formatted text for whoever shows. **Instants** are [RFC 3339](https://www.rfc-editor.org/rfc/rfc3339) in UTC; the web formats them for the reader. **Tone** is one of `neutral`, `waiting`, `info`, `success` and `danger`, and says how a status should feel, never which color it gets.

## Live screens

A screen with `"live"` in its class asks to be fetched again from its `self` link after `refreshAfterSeconds`. The web stops refreshing when the tab is hidden and when the screen stops being live.

| Screen | Live while | Every |
|---|---|---|
| order right after `pay` (`?awaiting=payment` in `self`) | the PSP has not answered | 2 s |
| order `paid` or `shipped` | the parcel is being prepared or is on its way | 5 s |
| order of any status without its delivery news | logistics has not answered | 5 s |
| tracking | the journey has not ended (delivered, returned or cancelled) | 5 s |

After `pay`, the order follows the payment. An approved card turns it `paid`, and while the order is prepared the screen keeps the notice "Pagamento aprovado." and its `self` keeps `?awaiting=payment`; once the parcel ships, `self` lets go of it and the notice leaves. A declined card cancels the order in Commerce, and the screen ends with a `danger` notice that says so. Every cancelled order explains why in its notice (declined card, expired reservation, customer request), and a returned order says the refund was asked for.

## Actions and fields

| Action | Request | Answer |
|---|---|---|
| `track-by-code` | `GET /bff/v1/tracking`, field `code` | `303` to the tracking page. The BFF takes the code the way people type it (any case, spaces around, and the letters Crockford Base32 reads as digits: O as 0, I and L as 1), and the field's `pattern` lets those through |
| `buy` | `GET /bff/v1/checkout`, fields `sku` and `quantity` | the checkout screen |
| `place-order` | `POST /bff/v1/orders`, JSON of the fields | `201 Created`, `Location` of the order, and the order screen |
| `pay` | `POST /bff/v1/orders/{id}/payments`, JSON of the fields | `202 Accepted`, `Location` of the live order screen, and that screen |
| `use-profile` | `POST /bff/v1/profiles/active`, hidden field `profileId` | `303 See Other` to the orders, with that profile shopping |
| `create-profile` | `POST /bff/v1/profiles`, field `name` (1 to 40 characters) | `303 See Other` to the orders, with the new profile shopping |

Field types are the input types of HTML (`text`, `email`, `number`, `hidden`, `tel`) plus `select`, whose choices come in `options` (`[{"value", "title"}]`). Fields may also carry `value`, `required`, `placeholder`, `autocomplete`, `inputmode`, `pattern`, `min`, `max` and `maxlength`, with the meaning they have in HTML, so the browser helps (autofill, the right keyboard on a phone) and validates before the request.

Actions that place something in a service carry a hidden `idempotencyKey` field, filled by the BFF with a fresh UUIDv7 each time it renders the form. A double click or a retry sends the same key, and the service answers the same thing instead of placing a second order ([draft-ietf-httpapi-idempotency-key-header](https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/)). A refusal does not keep the key: after fixing a field, the same form goes again. The profile actions change only the session and carry no key: choosing a profile twice lands in the same place, and two creations in flight start from the same cookie, so only one of them stays.

## Sessions and profiles

There is no login. A browser holds up to eight **profiles**, and one of them is shopping; switching profiles plays the part of signing in with another account ([ADR 0030](../../../docs/adr/0030-each-customer-sees-only-its-orders.md)). Each profile is a customer of its own in Commerce: the BFF places every order with the id of the profile shopping, and reads every order through the routes of that customer, so an order of another profile answers `404` like an order that does not exist, and paying one is refused the same way, before any charge.

The session lives in the `tucano_session` cookie ([RFC 6265](https://www.rfc-editor.org/rfc/rfc6265): `HttpOnly`, `SameSite=Lax`, `Path=/bff`, a `Max-Age` of one year, `Secure` in production): the JSON of the profiles and of the one shopping, in base64url, a dot, and its HMAC-SHA256 under `SESSION_SECRET`, checked in constant time. A profile is `{"id", "name"}`: a UUIDv7 the BFF made, and a name of 1 to 40 characters, or null. A cookie that does not open (forged, signed with another key, longer than 4096 characters, or holding something that is not a session) counts as no session: the BFF leaves a `warn` line without the value, and expires the cookie.

Only what needs a customer starts a session: the checkout, and the profile actions. A profile the checkout starts has no name until its first order, and then takes the first word of the name typed in it, so only a first name lives in the cookie. Reading the orders, the profiles or any other screen never starts a session. The old `tucano_guest` cookie is expired when it shows up, and its id is never adopted: it was never signed, so it is an id the browser chose.

The web keeps the default `credentials` of `fetch`. Screens are never cached (`Cache-Control: no-store`), because each render carries keys made for it and the navigation of the session that asked.

## Errors

- The problems the BFF makes speak to the person, in Portuguese, in `detail`; `title` stays the phrase of the HTTP status.
- `422` with `errors`: an object from field name to messages, with the names of the fields of the action, so the web shows each message next to its field. Every field that needs attention comes in the same answer. A ninth profile is refused on `name`, and a profile the browser does not hold on `profileId`.
- `404` when the product, the order or the tracking code does not exist, and when the order belongs to another profile: the same answer, so it never tells one from the other.
- `409` when the order cannot be placed (no stock for that quantity) or paid (it no longer waits for payment), and when the product left the line.
- `503` with `Retry-After` when a service behind the BFF is out or slower than `UPSTREAM_TIMEOUT_MS`; the web says when it is worth trying again. Only the screens that need that service fail: with Commerce out, the catalog still opens, and with the read model of the orders out, only the list falls. The news of a parcel never turns the order into a `503`: without logistics, the order opens with what Commerce knows.
- Every problem carries `correlationId`, the same id of the logs of every service the request went through.
