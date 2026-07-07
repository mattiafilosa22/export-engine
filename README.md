# Gamindo Export Engine

Microservizio backend che espone API REST per **ingerire** grandi moli di eventi/anagrafiche di
una versione (campagna/gioco) e generare **export XLSX configurabili dal client**, in modo
asincrono e a memoria costante — pensato per **10 milioni di eventi**, **1 milione di player** ed
**export fino a 500.000 righe**.

**Stack**: PHP 7.3.33 · Laravel 8 · MySQL 8 · Redis · Docker.

**Architettura in breve**:

- **Ingestione ibrida**: validazione sincrona al confine (struttura, dimensione batch → `400`/`413`
  immediati), scrittura asincrona via job a chunk transazionali, idempotente (`insertOrIgnore` su
  `dedup_key`; retry non duplica).
- **Export configurabile**: il client compone ogni foglio (source, colonne, filtri su campi
  standard **e** su campi JSON, ordinamenti, aggregazioni, intervallo temporale) — nessun export
  predefinito. Generazione async, streaming a memoria costante (OpenSpout + keyset pagination),
  progress % live, cancellazione, retry automatico, export concorrenti isolati (lock Redis).
- **Tipizzazione event-driven**: eventi `answer_submitted`/`transaction`/`reward_granted` generano
  automaticamente la riga tipizzata collegata (`answers`/`transactions`/`rewards`), idempotente.

Il racconto completo di come e perché è stato costruito così è in [`docs/roadmap.md`](docs/roadmap.md).

## Quickstart

Serve solo Docker (nessun PHP/MySQL locale richiesto).

```bash
git clone https://github.com/mattiafilosa22/export-engine.git
cd export-engine
make bootstrap   # build immagini + composer install + app key + avvio (migration incluse)
```

`make bootstrap` porta su l'intero stack (app, worker di coda, MySQL, Redis) con lo schema già
migrato. Verifica che sia in piedi:

```bash
curl http://localhost:8080/health
```

Poi un giro end-to-end completo (crea una versione, ingerisce player+eventi, genera un export,
lo scarica) con il client dimostrativo:

```bash
make demo
```

Stampa ogni chiamata HTTP con il relativo cURL — utile sia come demo sia come riferimento API.

## Comandi

| Comando                        | Cosa fa                                                    |
| ------------------------------- | ----------------------------------------------------------- |
| `make up` / `make down`         | Avvia / ferma lo stack                                      |
| `make migrate`                  | Esegue le migration                                          |
| `make fresh`                    | `migrate:fresh --seed` (schema pulito)                      |
| `make test`                     | Suite PHPUnit (feature + unit)                               |
| `make lint` / `make lint-fix`   | PSR-12 (phpcs / phpcbf)                                      |
| `make analyse`                  | PHPStan (livello medio)                                      |
| `make demo`                     | Client dimostrativo end-to-end (`gamindo:demo-client`)       |
| `make docs`                     | Rigenera la documentazione API interattiva (Scribe) su `/docs` |

Comandi Artisan diretti, utili per generare dati di test/demo a scala:

```bash
# Semina una versione con player/eventi/risposte/transazioni/premi realistici, a chunk.
docker compose exec app php artisan gamindo:seed-demo --players=20000 --events=2000000

# Semina N eventi e misura la generazione dell'export corrispondente (memoria/durata).
docker compose exec app php artisan gamindo:export-benchmark --rows=500000
```

`gamindo:seed-demo` è stato eseguito realmente a piena scala in fase di sviluppo:
**1.000.000 di player e 10.000.000 di eventi**, seminati con successo.

## Endpoint

| Metodo | Path                                          | Descrizione                                 |
| ------ | ---------------------------------------------- | -------------------------------------------- |
| `POST` | `/api/v1/versions`                             | Crea una versione (sincrono)                 |
| `POST` | `/api/v1/versions/{version}/players`           | Ingestione player (batch, async, upsert)     |
| `POST` | `/api/v1/versions/{version}/events`            | Ingestione eventi (batch, async, append)     |
| `POST` | `/api/v1/versions/{version}/transactions`      | Ingestione diretta transazioni (batch, async) |
| `POST` | `/api/v1/versions/{version}/answers`           | Ingestione diretta risposte (batch, async)   |
| `POST` | `/api/v1/versions/{version}/rewards`           | Ingestione diretta premi (batch, async)      |
| `GET`  | `/api/v1/versions/{version}/players`           | Lista player (paginata)                      |
| `GET`  | `/api/v1/imports/{import}`                     | Stato di un import                           |
| `POST` | `/api/v1/versions/{version}/exports`           | Richiede un export (async)                   |
| `POST` | `/api/v1/versions/{version}/exports/preview`   | Preview sincrona (max 100 righe, no XLSX)    |
| `GET`  | `/api/v1/exports/{export}`                     | Stato/progress di un export                  |
| `POST` | `/api/v1/exports/{export}/cancel`              | Cancella un export in corso                  |
| `GET`  | `/api/v1/exports/{export}/download`            | Scarica il file XLSX                         |

Documentazione interattiva completa (prova le richieste dal browser) + OpenAPI + Postman:

```bash
make docs   # poi apri http://localhost:8080/docs
```

`make docs` rigenera anche `public/docs/collection.json` (Postman) e `openapi.yaml`, ma
`public/docs` è un artefatto build, non versionato. Per un import diretto in Postman senza dover
avviare lo stack, una copia stabile della collection è committata in `docs/postman/`:

- `docs/postman/gamindo-export-engine.postman_collection.json` — tutti gli endpoint, auth
  `X-Api-Key` già collegata alla variabile `{{api_key}}` (nessun valore reale al suo interno).
- `docs/postman/gamindo-local.postman_environment.json` — template con `baseUrl=localhost:8080`
  e `api_key` vuota (tipo `secret`): importalo e valorizza `api_key` solo se hai impostato
  `GAMINDO_API_KEY` in `.env` (altrimenti lascialo vuoto, l'auth è no-op).

In Postman: *Import* → seleziona entrambi i file → seleziona l'environment "Gamindo Local" in alto
a destra. Se cambi gli endpoint, rigenera la copia committata (JSON puro, non supporta commenti
interni — comando qui):

```bash
make docs
docker compose exec app php -r '
$c = json_decode(file_get_contents("public/docs/collection.json"), true);
$c["info"]["name"] = "Gamindo Export Engine";
$c["auth"]["apikey"][] = ["key" => "value", "value" => "{{api_key}}", "type" => "string"];
file_put_contents(
    "docs/postman/gamindo-export-engine.postman_collection.json",
    json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);
'
```

## Client dimostrativo (Client ↔ Server)

`gamindo:demo-client` (`app/Console/Commands/DemoClientCommand.php`) è un client M2M reale: parla
con l'API **solo via HTTP** (nessuna scorciatoia interna), esattamente come farebbe un sistema
esterno integrato. Copre l'intero ciclo end-to-end:

`POST /versions` → `POST /players` (batch) → poll `GET /imports/{id}` fino a `completed` →
`GET /players` (recupera gli id reali) → `POST /events` (batch, tipi misti) → poll import →
`POST /exports` → poll `GET /exports/{id}` fino a `completed` → `GET /exports/{id}/download`
(salvato in `storage/app/exports/`).

Ad ogni passo stampa il cURL equivalente (`step()` in coda al comando) — doppia funzione: demo
eseguibile **e** riferimento API sempre aggiornato, senza mantenerlo a mano.

```bash
# Esecuzione base (stack già su con `make up`/`make bootstrap`): 5 player, 20 eventi.
make demo

# Equivalente diretto, con parametri custom.
docker compose exec app php artisan gamindo:demo-client --players=50 --events=200

# Riusa una versione già esistente invece di crearne una nuova.
docker compose exec app php artisan gamindo:demo-client --version-uuid=<uuid>

# Verso un'istanza remota (non il container locale).
docker compose exec app php artisan gamindo:demo-client --base-url=https://staging.example.com
```

Testato in `tests/Feature/Console/DemoClientCommandTest.php` (`Http::fake()`, asserisce l'ordine
esatto delle chiamate: version → players → poll → events → poll → export → poll → download).

**API key**: se `GAMINDO_API_KEY` è valorizzata in `.env`, il client la legge da
`config('gamindo.api_key')` e la allega automaticamente come header `X-Api-Key` ad ogni chiamata —
nessun flag da passare, nessuna modifica al comando. Con `GAMINDO_API_KEY` vuota (default) il
middleware `api.key` è un no-op e il client funziona identico, senza header.

## Esempi cURL

#### Crea una versione

```bash
curl -X POST http://localhost:8080/api/v1/versions \
  -H 'Content-Type: application/json' \
  -d '{"name": "Summer Campaign 2026"}'
```

#### Ingestione player (batch)

```bash
curl -X POST http://localhost:8080/api/v1/versions/{version}/players \
  -H 'Content-Type: application/json' \
  -d '{"players": [{"email": "player1@example.com", "language": "it"}]}'
```

#### Ingestione eventi

Payload esatto della traccia (`player_id` richiesto); un evento `transaction` genera
automaticamente la riga collegata in `transactions`:

```bash
curl -X POST http://localhost:8080/api/v1/versions/{version}/events \
  -H 'Content-Type: application/json' \
  -d '{"events": [
        {"player_id": 1, "type": "game_completed", "occurred_at": "2026-01-15T10:00:00Z", "payload": {"score": 87}},
        {"player_id": 1, "type": "transaction", "occurred_at": "2026-01-15T10:05:00Z",
         "payload": {"type": "purchase", "amount": 9.99, "currency": "EUR"}}
      ]}'
```

#### Ingestione diretta transactions/answers/rewards

Via alternativa a quella event-driven sopra: stesso batch idempotente, ma la riga finisce
direttamente nella tabella tipizzata (`event_id = NULL`, nessun evento generato). Utile per un
client che ha già il dato strutturato e non vuole passare da un evento intermedio:

```bash
curl -X POST http://localhost:8080/api/v1/versions/{version}/transactions \
  -H 'Content-Type: application/json' \
  -d '{"transactions": [
        {"player_id": 1, "type": "purchase", "amount": 9.99, "currency": "EUR",
         "occurred_at": "2026-01-15T10:05:00Z", "dedup_key": "txn-1"}
      ]}'
```

Stessa forma per `.../answers` (`question_id` + `answer_option_id` o `answer_text`, deduplica
sulla chiave naturale `version_id+player_id+question_id`, non serve `dedup_key`) e `.../rewards`
(`reward_type`, `granted_at`).

#### Ingestione di grandi moli di dati

Ogni richiesta batch è capata a `GAMINDO_MAX_BATCH_ROWS` righe (default **5000**): un batch più
grande viene rifiutato subito con `413`, senza toccare il DB — è la validazione sincrona al
confine di cui parla la traccia. `manual-test/` contiene fixture pronte all'uso per provarlo:

```bash
# Un batch esattamente al limite (5000 righe) → 202, accettato e processato in background.
curl -X POST http://localhost:8080/api/v1/versions/{version}/players \
  -H 'Content-Type: application/json' \
  -d @manual-test/players/valid-at-cap-5000.json

# Un batch di una riga oltre il limite (5001 righe) → 413 immediato.
curl -X POST http://localhost:8080/api/v1/versions/{version}/players \
  -H 'Content-Type: application/json' \
  -d @manual-test/players/invalid-oversized-5001.json
```

Lo stesso vale per `manual-test/events/valid-at-cap-5000.json` (`.../events`). Per volumi **oltre**
il limite di una singola richiesta, il client invia più batch in sequenza (ogni batch è
idempotente su `dedup_key`, quindi un retry non duplica). Per generare invece un intero dataset
di milioni di righe lato server (demo/test, non un client reale) si usa `gamindo:seed-demo` (vedi
sopra) — è la via pensata apposta per la scala 10M eventi / 1M player, che aggira di proposito il
confine sincrono HTTP con insert massivi a chunk.

#### Export configurabile

Più fogli, colonne, filtri su campo standard e su campo JSON, ordinamento, aggregazione,
intervallo temporale, fogli di riepilogo opzionali:

```bash
curl -X POST http://localhost:8080/api/v1/versions/{version}/exports \
  -H 'Content-Type: application/json' \
  -d '{
    "date_from": "2026-01-01",
    "date_to": "2026-01-31",
    "include_summary": true,
    "sheets": [
      {
        "name": "Players", "source": "players",
        "columns": ["player_id", "email", "registered_at", "total_score"],
        "filters": {"language": "it"},
        "sort": ["total_score:desc"]
      },
      {
        "name": "Events_Summary", "source": "events",
        "group_by": ["type", "payload.language"],
        "metrics": ["count", "unique_players", "avg_score"]
      }
    ]
  }'
```

#### Preview sincrona

Stesso spec, prime 100 righe, niente coda:

```bash
curl -X POST http://localhost:8080/api/v1/versions/{version}/exports/preview \
  -H 'Content-Type: application/json' \
  -d '{"sheets": [{"source": "events"}]}'
```

#### Stato/progress ed export

```bash
curl http://localhost:8080/api/v1/exports/{export}
curl -o export.xlsx http://localhost:8080/api/v1/exports/{export}/download
```

## Esempio di export generato

[`docs/example-export.xlsx`](docs/example-export.xlsx) — export reale, generato da questo
sistema su un dataset ingerito attraverso la vera pipeline HTTP (150 player, 150 eventi misti,
incluse risposte/transazioni/premi). 9 fogli: `README`, `KPIs`, `Configurazione_Richiesta`
(riepilogo opzionale via `include_summary`), `Players`, `Events_Summary`, `Events_Detail`,
`Answers`, `Transactions`, `Data_Quality` (quest'ultimo esegue controlli reali sui dati della
versione — eventi senza `payload.language`, payload JSON vuoti, eventi avvenuti prima della
registrazione del player — con severity/occorrenze/descrizione, non un log dell'ingestione).

Rigenerabile in autonomia con un comando solo (nessuna dipendenza da servizi esterni, seed
attraverso la vera pipeline di ingestione):

```bash
docker compose exec app php docs/regenerate-example-export.php
```

## Testing & qualità

```bash
make test      # 161 test, 596 assertion — feature + unit
make lint      # PSR-12
make analyse   # PHPStan
```

CI (GitHub Actions) esegue lint + analyse + test a ogni push. Nessun merge con pipeline rossa.

## Approfondimenti

- [`docs/gamindo-db-design.md`](docs/gamindo-db-design.md) — schema dati e scelte di design
  (pensato per ~1M player / ~10M eventi).
- [`docs/BENCHMARK.md`](docs/BENCHMARK.md) — prova di memoria piatta sull'export streaming
  (500.000 righe, 28MB di picco).
- [`docs/ASYNC-ROBUSTNESS.md`](docs/ASYNC-ROBUSTNESS.md) — export concorrenti, lock, cancellazione,
  retry, progress % (Redis).
- [`docs/roadmap.md`](docs/roadmap.md) — lo sviluppo raccontato per fette verticali, slice per slice.

## Note

- Le *source* dell'export (colonne/filtri/aggregazioni interrogabili) sono una whitelist in
  `config/gamindo.php`: aggiungere un nuovo campo payload interrogabile è un cambio di config (per
  volumi grandi, anche una colonna generata indicizzata) — non è automatico dal payload libero.
