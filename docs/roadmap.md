# Roadmap — Gamindo Export Engine

Approccio: **walking skeleton + vertical slices**, con human-in-the-loop.

## Principi di esecuzione

- **Walking skeleton prima di tutto**: un percorso end-to-end sottile che tocca ogni layer (1 endpoint
  → DB → job async → mini XLSX → status/download) per **de-rischiare l'architettura asincrona** subito.
  Poi si ingrassano le fette.
- **Fette verticali**, non orizzontali: ogni slice attraversa tutti i livelli e produce qualcosa di
  dimostrabile.
- **Ciclo human-in-the-loop** per ogni slice: piano in plan mode → **approvazione utente** →
  `implementer` → `reviewer` (punteggio) → a **10/10** ok finale utente.
- **Definition of Done** (per ogni slice): test verdi, reviewer 10/10, doc aggiornati, esempio cURL
  funzionante, nessun commit senza ok utente.

## Fasi

### Slice 0 — Bootstrap & tooling
**Scopo:** avere un ambiente e un'impalcatura di qualità funzionanti su cui costruire. Nessuna feature
di dominio.
**Cosa include:**
- Scaffold **Laravel 8** con `config.platform.php` = 7.3.33 (dipendenze risolte per PHP 7.3).
- **Docker**: container app (PHP 7.3), MySQL 8.0, worker di coda separato; `Makefile`.
- **Tooling qualità**: PHP_CodeSniffer (PSR-12), PHPStan (livello medio), EditorConfig, composer
  scripts (`lint`/`analyse`/`test`), scheletro CI GitHub Actions (lint + analyse + test).
- **`.env.example` versionato** (template: MySQL, `QUEUE_CONNECTION=database`); il `.env` reale resta
  **gitignored** (già nel `.gitignore` di Laravel). Una rotta di health check.
- (Già presenti: `CLAUDE.md`, `docs/`, `.claude/agents/`.)
**Criteri di accettazione:** `make up` avvia i container; `make migrate` gira; `make lint`,
`make analyse`, `make test` eseguono verdi (anche a vuoto); `GET /health` risponde `200`.
**DoD:** pipeline verde end-to-end (lint + analyse + test) e ambiente avviabile con un comando.

### Slice 1 — Walking skeleton (de-risk architettura async)
**Scopo:** provare SOLO il pipeline asincrono dell'export — la parte davvero rischiosa. Non è una
feature completa e non testa l'ingestione.
Migration minima ma **reale** (`versions`, `events` con colonne generate); qualche evento inserito con
un **piccolo seeder** (non un endpoint di ingest, che arriva dopo); endpoint `POST /exports` → job →
XLSX banale (poche colonne) via **OpenSpout ^3.x**; endpoint stato + download.
**DoD:** POST export → job async → file scaricabile. Catena web → coda → worker → file provata
end-to-end.
**Nota:** `users`, `players` e il resto del modello arrivano nella Slice 2; qui non servono, lo
scheletro prova solo la plumbing.
_Alternativa:_ se si preferisce certezza sullo schema, invertire con la Slice 2 (modello completo prima,
poi lo scheletro sul DB reale).

### Slice 2 — Modello dati completo
Tutte le migration (`users`, `players`, `events` + colonne generate, `questions`, `answer_options`,
`answers`, `transactions`, `rewards`, `exports`) secondo `docs/gamindo-db-design.md`; seeder demo con
insert massivi.
**DoD:** `gamindo:seed-demo` genera 20k player e ~2M eventi in tempi ragionevoli.

### Slice 3 — Ingestione ibrida
FormRequest per la validazione **sincrona** (struttura, dimensione batch → 400/413); scrittura
**asincrona** via job; insert massivi a chunk transazionali; **idempotenza** (upsert / `insertOrIgnore`);
endpoint di stato dell'import.
**DoD:** batch grande accettato in ms, scritto in background, retry non duplica.

### Slice 4 — Export configurabile
Parsing dei `params` (sheets, columns, filters, sort, group_by, metrics); query builder dinamico
(filtri su colonne standard **e** su campi JSON); aggregazioni (`events_summary`; distribuzione
`answers` con window function); lettura **keyset**.
**DoD:** i due fogli dell'esempio riprodotti correttamente da parametri.

### Slice 5 — Generazione XLSX in streaming
Writer **OpenSpout** a memoria costante; aggiornamento `progress`; fino a 500k righe.
**DoD:** export da 500k righe con memoria piatta.

### Slice 6 — Robustezza async
Più export concorrenti (worker multipli, `SKIP LOCKED`); retry automatico; cancellazione; progress %.
**DoD:** export concorrenti isolati; cancellazione e retry funzionanti.

### Slice 7 — Bonus (extra challenge)
Preview limitata a 100 righe; template salvabili (`export_templates`); client di test client↔server;
OpenAPI/Swagger.
**DoD:** almeno 1–2 bonus completi e documentati.

### Slice 8 — Deliverable finali
README (setup, comandi di avvio, comandi test, esempi cURL); esempio di export generato; copertura test.
**DoD:** un revisore esterno clona, segue il README e ottiene l'output senza attriti.

## Mappa ai deliverable della traccia

| Deliverable richiesto | Slice |
|---|---|
| Repository completo | tutte |
| README (setup, comandi, test, cURL) | 8 |
| Esempio di export generato | 5 / 8 |
| Tutti i comandi per l'output | 8 |
| Extra challenge (preview, template, retry, progress, cancellazione, client) | 6 / 7 |

## Note

La roadmap **è** il racconto del progetto: "scheletro end-to-end per validare l'architettura →
modello dati → ingestione ibrida → export configurabile → streaming → robustezza → bonus → deliverable",
con un gate umano e un ciclo di review a ogni passo.

## Client di test (demo-client) — dettaglio Slice 7

Comando Artisan **`gamindo:demo-client --version=7`** che fa da consumatore machine-to-machine della
propria API (usa il client HTTP di Laravel/Guzzle, con **API key** in header). Esercita l'intero ciclo
Client↔Server, stampando ogni step:

```
1. POST /versions/{id}/players   → carica anagrafiche (batch)
2. POST /versions/{id}/events    → carica eventi (batch)
3. POST /versions/{id}/exports   → richiede l'export        (riceve export_id)
4. GET  /exports/{id}            → polling finché "completed" (mostra progress)
5. GET  /exports/{id}/download   → scarica il file XLSX
```

**Doppio guadagno:** oltre al bonus "client di integrazione", produce quasi gratis anche deliverable
della Slice 8 — l'**esempio di export generato** (lo scarica lui), gli **esempi cURL** per il README
(ricavati dai suoi passi) e la prova visibile di **progress** e flusso async.

**Auth:** resta machine-to-machine → **API key via middleware** (il client mette la chiave in header);
Sanctum non serve (nessun utente umano fa login). In produzione: OAuth2 client-credentials (Passport).
