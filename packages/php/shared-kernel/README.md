# tucano/shared-kernel

Shared kernel dos serviços PHP (catalog, commerce, logistics e tracking). Fica pequeno de propósito: tudo o que entra aqui passa a exigir acordo de todos os contextos.

| Pacote | O que tem |
|---|---|
| `Identity` | `UuidIdentifier` (UUIDv7 gerado pelo domínio), `Snowflake` + `SnowflakeGenerator` e `CrockfordBase32` |
| `Identity\Snowflake\Sequence` | `ApcuSequence` para PHP-FPM e `InMemorySequence` para processos de longa duração |
| `Money` | `Money` em centavos e `Currency` ISO 4217 |
| `Address` | `Address` com `Thoroughfare` (tipo e nome do logradouro), número em texto, `Divisions` (estado, município, distrito, subdistrito e bairro, com o geocódigo do IBGE), `PostalCode` e `Coordinates`, montado pelo `AddressBuilder` e lido e escrito por `fromArray` e `toArray` ([ADR 0020](../../../docs/adr/0020-address-by-thoroughfare-and-divisions.md)) |
| `Privacy` | `Sensitive`, o proxy de proteção que mostra só a máscara de um dado pessoal ou de cartão, e `DataCategory`, as categorias que sabem se mascarar e a que regra respondem, LGPD ou PCI DSS ([ADR 0024](../../../docs/adr/0024-sensitive-data-behind-a-proxy.md)) |
| `Time` | `Clock`, `SystemClock` (sempre UTC) e `FrozenClock` para testes |
| `Domain` | `AggregateRoot`, `DomainEvent`, `DomainError` com a sua `ErrorCategory`, e o atributo `ProblemType`, que dá a um erro o nome pelo qual os clientes o reconhecem (o `type` do RFC 9457) |
| `Messaging` | `CloudEvent`, o envelope CloudEvents 1.0 dos tópicos Kafka, e `EventFields`, a leitura tipada e tolerante do `data` que os consumidores fazem |
| `Documentation` | atributo `#[UseCase('UC-ORD-01')]`, que liga o código à ficha do caso de uso |

Roda em PHP 8.3 (o Lumen do catálogo) e 8.4 (Laravel e Swoole), e o CI testa as duas versões.

## Snowflake no PHP-FPM

No FPM cada request roda num processo filho isolado. Uma sequência em memória se repetiria entre filhos no mesmo milissegundo, então o gerador usa `ApcuSequence`, que guarda o contador na memória compartilhada do pool com `apcu_inc` atômico. Em workers de CLI e no Swoole basta `InMemorySequence`.

```php
$generator = new SnowflakeGenerator(
    new NodeId(datacenter: 1, worker: 11),
    new ApcuSequence('snowflake:1:11'),
    new SystemClock(),
);

$trackingCode = 'TX' . $generator->next()->toBase32(); // TX02PQRFBTW5G03
```

No JSON o Snowflake sai como string, porque números acima de 2^53 perdem precisão em JavaScript. O Twitter passou por isso e criou o campo `id_str`.

## Rodando

```bash
make packages-check                                       # Pint, PHPStan e PHPUnit dos pacotes em PHP 8.3 e 8.4
make php dir=packages/php/shared-kernel c="composer test" # um comando avulso
```
