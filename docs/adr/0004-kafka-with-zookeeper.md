# 0004. Kafka 3.9 em modo ZooKeeper

- Status: aceito
- Data: 2026-09-27

## Contexto

O Kafka 4.0 (março de 2025) removeu o ZooKeeper e só funciona em modo KRaft. O 3.9 é a última linha que ainda suporta ZooKeeper, e muita empresa ainda opera clusters assim. Entender o papel do ZooKeeper (eleição do controller, registro de brokers, metadados) continua sendo assunto de entrevista.

## Decisão

- Usar Apache Kafka 3.9.2 em modo ZooKeeper, com um broker e um nó ZooKeeper por padrão.
- A mesma imagem oficial `apache/kafka:3.9.2` roda o broker e o ZooKeeper (a distribuição traz os scripts do ZooKeeper). As imagens da Confluent têm cerca de 670 MB cada.
- A configuração fica em arquivos `server.properties` e `zookeeper.properties` versionados, para que cada parâmetro seja visível e comentado.

## Consequências

- O broker registra avisos de depreciação do ZooKeeper, e isso é intencional.
- O caminho de migração para KRaft (Kafka 4) fica documentado como estudo.
- Com um broker só, replicação e eleição de líder só podem ser demonstradas num overlay opcional com mais brokers.

## Alternativas consideradas

- **Kafka 4 em KRaft**: é o modo atual do Kafka, mas não atende ao objetivo de estudar a arquitetura com ZooKeeper.
- **Confluent Platform 7.9**: mesma versão do Kafka, com imagens bem maiores.
