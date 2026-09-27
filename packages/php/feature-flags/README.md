# tucano/feature-flags

Porta de feature flags dos serviços PHP. O código de negócio depende só da interface `FeatureFlags`; por trás dela fica o OpenFeature falando com o flagd.

```text
ProductionGuard -> CachedFlags -> OpenFeatureFlags -> flagd (HTTP, porta 8013)
```

- `ProductionGuard` devolve o fallback para qualquer flag `chaos.*` ou `labs.*` quando o ambiente é produção, sem nem consultar o servidor.
- `CachedFlags` segura cada avaliação por alguns segundos. O provider PHP do flagd faz uma chamada HTTP por avaliação; com o cache, uma flag muito lida vira uma chamada a cada poucos segundos.
- `OpenFeatureFlags` é o adapter para o cliente OpenFeature.
- `InMemoryFlags` serve para testes e para rodar sem flagd.

## Uso

```php
$flags = Flagd::connect(
    service: 'commerce',
    environment: Environment::fromName(getenv('APP_ENV') ?: null),
    host: 'toxiproxy',
    port: 18013,
    cache: new ApcuFlagCache(),   // PHP-FPM; em CLI ou Swoole use InMemoryFlagCache
);

$strategy = $flags->text('inventory.reservation-strategy', 'atomic');
$express = $flags->enabled('checkout.express-shipping', false, FlagContext::forCustomer($customerId, $email));
```

Toda chamada leva um fallback. Se o flagd cair ou a flag não existir, o serviço segue com o comportamento conservador.

## Qual cache usar

| Runtime | Cache | Por quê |
|---|---|---|
| PHP-FPM | `ApcuFlagCache` | a memória do processo morre a cada request; o APCu é compartilhado pelos filhos do pool |
| CLI (consumers, relay) e Swoole | `InMemoryFlagCache` | o processo vive muito, então a memória dele já basta |

## Testes

```bash
make packages-check   # unitários em PHP 8.3 e 8.4
make php dir=packages/php/feature-flags net=chaos-playground_backend \
  c="FLAGD_HOST=flagd vendor/bin/phpunit --group integration"   # contra o flagd da stack
```

Os testes de integração rodam contra um flagd de verdade, servindo `infra/flags/environments/local.flagd.json`. O CI sobe esse flagd no próprio runner.
