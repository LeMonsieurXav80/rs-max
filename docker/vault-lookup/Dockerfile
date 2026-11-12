FROM python:3.12-alpine
RUN apk add --no-cache git && git config --system --add safe.directory '*'
COPY vault_lookup.py /vault_lookup.py
USER 1000:1000
ENTRYPOINT ["python3", "-I", "/vault_lookup.py"]
