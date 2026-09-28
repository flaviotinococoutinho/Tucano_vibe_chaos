# The chaos experiments of chaos/experiments, run by the Chaos Toolkit, pinned. The Tucano
# probes are mounted from chaos/, not copied, so changing a probe needs no rebuild. The
# container talks only HTTP: to Kong, to Toxiproxy and to the partners simulator.
FROM python:3.13-alpine

RUN pip install --no-cache-dir --root-user-action=ignore chaostoolkit==1.21.1 chaostoolkit-lib==1.45.1

ENV PYTHONPATH=/chaos
WORKDIR /results
ENTRYPOINT ["chaos"]
