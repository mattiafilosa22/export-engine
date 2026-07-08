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

## Heavy Load Testing

### 1. Batch Ingestione Pesante

```bash
# Batch al limite (5000 righe) → 202 Accepted
curl -X POST http://localhost:8080/api/v1/versions/{version}/players \
  -H 'Content-Type: application/json' \
  -d @manual-test/players/valid-at-cap-5000.json

# Batch oltre limite (5001 righe) → 413 Payload Too Large
curl -X POST http://localhost:8080/api/v1/versions/{version}/players \
  -H 'Content-Type: application/json' \
  -d @manual-test/players/invalid-oversized-5001.json
```

### 2. Seeder Database

```bash
# Genera versione con 20k player + 2M eventi (~ 2-3 min, a chunk transazionali)
docker compose exec app php artisan gamindo:seed-demo --players=20000 --events=2000000

# Scala massima provata (produzione)
docker compose exec app php artisan gamindo:seed-demo --players=1000000 --events=10000000
```

### 3. Export Grandi Volumi

```bash
# Benchmark: genera export da 500k righe, misura memoria e durata
docker compose exec app php artisan gamindo:export-benchmark --rows=500000
# Output: picco memoria, righe/sec, durata totale
```

### 4. Monitoring Progress

```bash
# Poll export fino a completamento (vedi progress %)
while true; do
  curl -s http://localhost:8080/api/v1/exports/{export_id} | jq '{status: .data.status, progress: .data.progress}'
  sleep 2
done
```

### 5. Verifiche Performance

```bash
# Dimensione DB dopo seed
docker compose exec db mysql -u root -p$MYSQL_ROOT_PASSWORD gamindo -e "
  SELECT table_name, ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb
  FROM information_schema.TABLES
  WHERE table_schema = 'gamindo'
  ORDER BY size_mb DESC;"

# Conteggio dati
docker compose exec app php artisan tinker
>>> DB::table('players')->count()        // player
>>> DB::table('events')->count()         // eventi
>>> DB::table('exports')->count()        // export generati
```

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

## Documentazione API

### Browser Interattivo (Scribe)

Documentazione interattiva per provare le richieste direttamente dal browser:

```bash
make docs   # Genera http://localhost:8080/docs/
```

Accedi a http://localhost:8080/docs/ per una UI interattiva di tutti gli endpoint con descrizioni,
parametri, response di esempio.

### Postman (Consigliato per Testing & Debugging)

**Consigliamo Postman** — offre un'esperienza migliore rispetto a cURL per composizione richieste,
variabili d'ambiente, test automation, e gestione dello storico.

#### Setup Rapido (2 min)

1. **Scarica Postman** da https://www.postman.com/downloads/ (gratuito)

2. **Importa collection e environment** (pre-configurati, committati nel repo):
   - `docs/postman/gamindo-export-engine.postman_collection.json` — tutti gli endpoint
   - `docs/postman/gamindo-local.postman_environment.json` — variabili (baseUrl, api_key)

3. **In Postman**: `File → Import → seleziona i due file sopra`

4. **Attiva l'environment**: In alto a destra dropdown → seleziona **"Gamindo Local"**

5. **Valorizza api_key** (opzionale, solo se `GAMINDO_API_KEY` impostata in `.env`):
   - Clicca l'occhio accanto a "Gamindo Local" → edit → cambia "api_key"

#### Variabili d'Ambiente Disponibili

| Variabile | Default | Descrizione |
|-----------|---------|-------------|
| `baseUrl` | `http://localhost:8080` | URL base API (modifica per staging/prod) |
| `api_key` | (vuoto) | Header X-Api-Key (richiesto solo se env GAMINDO_API_KEY set) |

#### Aggiorna Collection (dopo cambio endpoint)

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

Poi re-importa in Postman (o aggiorna la collection esistente).

### OpenAPI

Generato automaticamente da Scribe in `public/docs/openapi.yaml` (utile per integrazioni CI/CD,
generatori di client SDK):

```bash
make docs   # genera anche openapi.yaml
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

#### Lista player (paginata)

```bash
# Ottiene i player della versione (keyset pagination, default 50 per pagina)
curl 'http://localhost:8080/api/v1/versions/{version}/players'

# Con parametri di paginazione
curl 'http://localhost:8080/api/v1/versions/{version}/players?per_page=100&after_id=42'
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


#### Vedere il resoconto di un import

```bash
curl --location 'http://localhost:8080/api/v1/imports/{import}' \
--header 'Content-Type: application/json' \
--header 'Accept: application/json'
```

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

#### Polling import fino a completamento

```bash
# Genera un import_id (dalla risposta di un POST /players o /events, header Location)
# Quindi polling ogni 100ms finché status != "pending"
IMPORT_ID="<import-id-da-risposta-202>"
while true; do
  RESPONSE=$(curl -s http://localhost:8080/api/v1/imports/$IMPORT_ID)
  STATUS=$(echo $RESPONSE | jq -r '.status')
  echo "Status: $STATUS"
  if [ "$STATUS" != "pending" ]; then
    echo "Import completato:"
    echo $RESPONSE | jq .
    break
  fi
  sleep 0.1
done
```

#### Stato/progress export

```bash
# Stato + progress (se in corso)
curl http://localhost:8080/api/v1/exports/{export}

# Output esempio (in progresso):
# {"id": "uuid", "version_id": 1, "status": "processing", "progress": 45, "rows": 5000}
```

#### Polling export fino a completamento + download

```bash
# Richiesta export
EXPORT_ID=$(curl -s -X POST http://localhost:8080/api/v1/versions/{version}/exports \
  -H 'Content-Type: application/json' \
  -d '{"sheets": [{"source": "players"}]}' | jq -r '.id')

echo "Export ID: $EXPORT_ID"

# Polling fino a completamento (status = "completed" o "failed")
while true; do
  RESPONSE=$(curl -s http://localhost:8080/api/v1/exports/$EXPORT_ID)
  STATUS=$(echo $RESPONSE | jq -r '.status')
  PROGRESS=$(echo $RESPONSE | jq -r '.progress // 0')
  echo "[$PROGRESS%] Status: $STATUS"

  if [ "$STATUS" = "completed" ]; then
    echo "Export pronto, scaricamento..."
    curl -o export.xlsx http://localhost:8080/api/v1/exports/$EXPORT_ID/download
    echo "Salvato in export.xlsx"
    break
  elif [ "$STATUS" = "failed" ]; then
    echo "Export fallito:"
    echo $RESPONSE | jq .
    break
  fi
  sleep 1
done
```

#### Cancellazione export in corso

```bash
# Cancella un export (solo se status = "pending" o "processing")
curl -X POST http://localhost:8080/api/v1/exports/{export}/cancel \
  -H 'Content-Type: application/json'

# Output: {"id": "uuid", "status": "cancelled"}
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
