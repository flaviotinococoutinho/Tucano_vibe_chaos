# Changelog

Todas as mudanças relevantes ficam registradas aqui. O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o versionamento segue o [SemVer](https://semver.org/lang/pt-BR/).

## [Unreleased]

## [0.2.0] - 2026-09-27

### Added

- Stack local em Docker Compose: PostgreSQL 18, MySQL 8.4, MongoDB 8, Redis 8, Kafka 3.9 com ZooKeeper, Floci, Mailpit, Toxiproxy, flagd e Kong 3.9.
- Feature flags privadas por ambiente (local, staging e production) com OpenFeature e flagd.
- Comandos `make` para operar a stack e job de CI que valida compose, configuração do Kong, flags e scripts.
- Shared kernel PHP (`tucano/shared-kernel`): UUIDv7, Snowflake com sequência em APCu, Base32 de Crockford, `Money`, `Clock`, envelope CloudEvents e o atributo `#[UseCase]`, testado em PHP 8.3 e 8.4.

### Changed

- Documentação revisada: tom direto, primeira pessoa, pontuação simples, tipos de dados precisos e direções corrigidas no context map.

## [0.1.0] - 2026-09-27

### Added

- Estrutura inicial do repositório, Git Flow e convenções de engenharia.
- Blueprint de arquitetura: C4, context map, linguagem ubíqua, eventos, identificadores, máquinas de estados, casos de uso e ADRs 0001 a 0014.
- Fluxo de release: tags SemVer imutáveis e GitHub Release gerada a partir deste changelog.

[Unreleased]: https://github.com/flaviotinococoutinho/chaos_playground/compare/v0.2.0...develop
[0.2.0]: https://github.com/flaviotinococoutinho/chaos_playground/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/flaviotinococoutinho/chaos_playground/releases/tag/v0.1.0
