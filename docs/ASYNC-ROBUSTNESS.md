# Robustezza async dell'export

Redis è lo **stato volatile** (progress %, flag di cancellazione, lock distribuito);
MySQL resta la verità durevole e il backend della coda. Richiede `CACHE_DRIVER=redis`
e `REDIS_CLIENT=predis` (vedi `.env.example`).

## Worker concorrenti

La coda `database` isola già i job tra worker (row lock sulla riga job). Per elaborare
più export in parallelo basta scalare il servizio worker:

```bash
docker compose up -d --scale worker=3
```

Ogni export è comunque elaborato da **un solo worker**: il job prende un **lock Redis**
per-export (`export:{uuid}`, TTL 120s) — così anche un retry che si sovrappone a un run
lento non riprocessa lo stesso export.

## Cancellazione

```
POST /api/v1/exports/{uuid}/cancel
```

- export **pending** → cancellato subito (`status=cancelled`); il job lo salta.
- export **processing** → viene posto un flag su Redis; il worker lo rileva al successivo
  checkpoint (ogni 1000 righe), **interrompe lo stream, elimina il file parziale** e mette
  `status=cancelled`. Nessun retry (la cancellazione è terminale).
- export **terminato** (completed/failed/cancelled) → **409**.

## Retry

Il job ha `$tries=3` con `$backoff=[10,30]` (secondi). Un retry è **idempotente**: ricarica
lo stato, salta se già terminale, e riscrive il file (stesso path) — nessun duplicato.

## Progress % live

`GET /api/v1/exports/{uuid}` espone `progress` (0–100) e `processed_rows`. Durante
l'elaborazione il `progress` è letto da Redis (aggiornato ~ogni 1000 righe); il totale è
calcolato upfront. A fine job `progress=100` (durevole) e lo stato volatile è ripulito.
