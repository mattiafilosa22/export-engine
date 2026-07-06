# CLAUDE.md — Gamindo Export Engine

## Overview

Microservizio backend che espone API REST per generare report statistici di una
"versione" di gioco/campagna. Riceve grandi moli di eventi/anagrafiche, li salva, e
genera export XLSX personalizzabili in modo **asincrono**. Obiettivo:
priorità a solidità ingegneristica.

> **Stato attuale del repo**: pre-codice. Esistono solo `CLAUDE.md`, `docs/` e
> `.claude/agents/`; **manca lo scaffold Laravel** (niente `composer.json`, `Makefile`,
> migration o sorgenti). La Slice 0 (bootstrap & tooling in `docs/roadmap.md`) non è ancora
> stata eseguita: endpoint, comandi `make` e migration descritti qui sotto sono lo **stato
> target**, non ancora presenti. Aggiornare/rimuovere questa nota una volta completata la Slice 0.

## Stack & vincoli (non negoziabili)

- **PHP 7.3.x** — verificare compatibilità di ogni dipendenza (`config.platform.php` = 7.3.33).
- **Laravel 8** — framework.
- **MySQL 8.0**
- **Composer**, **PHPUnit**, **Docker**.
- Coda: **database** (`QUEUE_CONNECTION=database`). Redis usato per lo **stato volatile** (vedi Architettura).
- Export XLSX con writer **in streaming** (**OpenSpout `^3.x`** — la 4.x richiede PHP 8.1, incompatibile
  con 7.3), mai in-memory su grandi volumi.

## Architettura (decisa — non re-inventare)

- **HTTP sottile, lavoro pesante in coda.** Nessuna operazione onerosa nel ciclo richiesta/risposta.
- **Ingestione ibrida**: validazione **sincrona** al confine (struttura, dimensione batch, auth →
  `400`/`413` immediati), poi scrittura **asincrona** via job. Insert massivi a chunk transazionali.
  Idempotenza obbligatoria (upsert / `insertOrIgnore` su chiave unica): consegna at-least-once.
- **Export asincrono**: `POST` crea la richiesta (`status=pending`) e accoda un job; un worker
  genera il file e aggiorna lo stato; endpoint separati per stato e download.
- **MySQL = sistema di verità** durevole e interrogabile, e backend della coda. **Redis = stato
  volatile**: `progress` dell'export, flag di cancellazione, lock distribuito (un export = un solo worker).
- Lettura di export grandi: **keyset pagination** (`lazyById`), mai `OFFSET`.

## Logging & osservabilità

- Usare il logging di Laravel/Monolog via **`LoggerInterface` iniettato** (non il facade globale):
  testabile, DI, SOLID. Un thin wrapper di dominio è ammesso, mai un logger da zero.
- **Container-friendly**: log su `stderr`/`stdout` (li raccoglie Docker), non su file nel container.
- **Log strutturati**: contesto come array (`['export_id' => $uuid, 'rows' => $n]`), non interpolazione.
- **Correlation id**: propagare l'`uuid` dell'export/richiesta su ogni log → tracciare un flusso tra
  web e worker.
- **Cosa loggare**: ciclo di vita di ingestione (batch, righe inserite, skip idempotenti, retry) ed
  export (start → progress → completed/failed con eccezione + durata). Livelli PSR-3 appropriati.

## Modello dati

Schema completo e razionale in **`docs/gamindo-db-design.md`** (leggerlo prima di toccare le migration).
Le 3 decisioni chiave da rispettare:

1. `events` è un **log append-only**; PK auto-increment monotòna; campi JSON "caldi" promossi a
   **colonne generate indicizzate** (`payload_language`, `payload_utm_source`, `payload_score`).
2. Split **identità/partecipazione**: `users` (email unica) + `players` (grain per-versione, con
   `total_score` denormalizzato).
3. Opzioni di risposta come entità (`answer_options`), non stringhe sparse; `answers` referenzia
   l'opzione via `answer_option_id`.

Scritture correlate (evento+risposta, evento+score, setup campagna) sempre in `DB::transaction`.

## Endpoint

```
# Ingestione
POST /api/v1/versions/{versionId}/players     (batch, upsert)
POST /api/v1/versions/{versionId}/events      (batch, append)
# Export
POST /api/v1/versions/{versionId}/exports
GET  /api/v1/exports/{exportId}
GET  /api/v1/exports/{exportId}/download
```

## Convenzioni di codice

### Struttura a livelli (per richiesta HTTP)

- **Controller** = strato sottile: niente business logic; riceve la richiesta, invoca l'Action,
  risponde con la Resource.
- **Validazione** nei **FormRequest** (uno per endpoint che ne ha bisogno).
- **Logica di business / caso d'uso** nelle **Action** (una Action = un'operazione, responsabilità
  singola).
- **Model Eloquent = magro**: relazioni, scope, metodi di query/accesso dati; niente orchestrazione
  di caso d'uso. Le Action richiamano il model.
- **Output** via **API Resource**.

### Stile

- **Guard clause / early return**: valida le precondizioni in cima al metodo, esci subito
  (`return`/`throw`) su null o stato invalido; evita nesting profondo.
- **Metodi corti**, a responsabilità singola.
- **Niente magic string/number**: i valori ricorrenti come **costanti di classe** (es.
  `Event::TYPE_ANSWER_SUBMITTED`, stati, chiavi) o enum, mai stringhe letterali sparse nel codice.
- **Commenti brevi e mirati**: una riga sul *perché*/cosa fa il metodo; codice autoesplicativo,
  niente commenti prolissi o ovvi.
- Principi **SOLID**; stile **PSR-12**; best practice **PHP/Laravel**.

> Enforcement operativo in `.claude/agents/implementer.md`; criteri di punteggio in
> `.claude/agents/reviewer.md`.

## Convenzioni dati (DB) — decise

- **PK/FK**: sempre `BIGINT UNSIGNED` (headroom sull'auto-increment, coerenza di tipo sui join,
  default Laravel).
- **Attributi non-chiave**: tipo più stretto sensato (es. `SMALLINT` per gli ordinamenti,
  `ENUM`/`TINYINT` per gli stati). Right-sizing dove ci sono milioni di righe.
- **Denaro**: `DECIMAL`, mai `FLOAT` (virgola fissa, aritmetica esatta).
- **Tempi di dominio** (`occurred_at`, `granted_at`, `registered_at`…): `DATETIME` normalizzato a
  **UTC** in applicazione. `created_at`/`updated_at` di Laravel (TIMESTAMP) vanno bene.
- **Campi JSON caldi**: promossi a **colonne generate `VIRTUAL` indicizzate**; il `payload` resta snello.
- **Indici**: deliberati e verificati con `EXPLAIN`. Su `events` (write-heavy) tenerli essenziali.
- **Engine/charset**: InnoDB + utf8mb4 ovunque.
- **Export XLSX**: writer in streaming (**OpenSpout**) a memoria costante; lettura **keyset** (`lazyById`).
- **Migration**: le migration di dominio in **stile classe anonima** (`return new class extends Migration`,
  standard da Laravel 8.37+). Le migration di default del framework (`jobs`, `failed_jobs`) restano com'erano.

## Testing

- **Feature test** (HTTP/integrazione): endpoint di ingestione ed export, flusso async
  (`POST` → job → stato → download), codici di risposta (400/413/202), idempotenza.
- **Unit test**: unità isolate — Action, parsing dei `params`, builder di query, logica di dominio,
  scope/metodi del model.
- **PHPUnit**. I test (feature + unit) sono **parte del "done"** di ogni slice: nessuno slice chiuso
  senza test verdi.

## Qualità del codice & tooling

- **Stile PSR-12**: **PHP_CodeSniffer** (`phpcs` per controllare, `phpcbf` per correggere).
  *Niente Laravel Pint*: richiede PHP 8, incompatibile con 7.3. (Alternativa: `php-cs-fixer ^2.x`.)
- **Analisi statica**: **PHPStan** (livello medio) su ogni push.
- **EditorConfig** per whitespace coerente.
- **composer scripts**: `composer lint`, `composer analyse`, `composer test`.
- **CI** (GitHub Actions): lint + analyse + test ad ogni push. Niente merge con pipeline rossa.
- **Documentazione API**: **Scribe `^2.x`** (`php artisan scribe:generate`) — la 3.x richiede PHP 7.4,
  incompatibile con 7.3. Genera HTML + OpenAPI + Postman dagli endpoint e dai FormRequest.

## Comandi

```
make up            # avvia i container (app, queue, db)
make migrate       # esegue le migration
make fresh         # migrate:fresh --seed
make test          # PHPUnit (feature + unit)
make lint          # phpcs (PSR-12)
make analyse       # phpstan
php artisan scribe:generate   # genera la documentazione API (Scribe)
php artisan gamindo:seed-demo --version-id=7 --players=20000 --events=2000000
```

## Workflow — Human in the loop

- **Tu (sessione principale) = tech lead/orchestratore.** Leggi questo file, pianifichi, deleghi.
- Si lavora a **fette verticali** (es. migration → ingestione → export), non tutto insieme.
- **Gate umano**: ogni slice inizia con un piano in **plan mode** approvato dall'utente PRIMA di scrivere codice.
- **Ciclo di qualità**: `implementer` realizza la slice → `reviewer` la valuta e assegna un punteggio.
  Finché il punteggio non è **10/10**, il task torna all'`implementer` con le note. Solo a 10/10 si
  chiede l'approvazione finale all'utente.
- Nessun commit/merge senza l'ok dell'utente.