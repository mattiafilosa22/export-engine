# Gamindo Export Engine — Design del Database

Definizione del modello E-R del progetto. Per ogni tabella: significato concettuale, campi,
e la **doppia motivazione** — perché quel *tipo* di dato e perché quella *scelta di modello*.
Riferimento normativo per le migration: leggere prima di modificare lo schema.

---

## 0. Convenzioni trasversali (valgono per tutte le tabelle)

Queste scelte si applicano ovunque; dirle una volta all'inizio dimostra metodo.

| Scelta | Perché |
|---|---|
| Engine **InnoDB** | Transazioni ACID, foreign key, lock a livello di riga, crash recovery. MyISAM escluso per dati transazionali. |
| Charset **utf8mb4** | Unicode completo (emoji, multilingua). L'export ha de/en/es/fr/it. |
| PK/FK **BIGINT UNSIGNED** | Coerenza, nessun negativo (un id non è < 0), margine per un firehose che cresce (INT si ferma a ~2,1 mld). |
| Tempi in **DATETIME**, non TIMESTAMP | TIMESTAMP è 4 byte ma finisce nel 2038 e converte i fusi implicitamente. DATETIME copre 1000–9999. Salvo in **UTC**. |
| Soldi in **DECIMAL**, mai FLOAT | Il float ha errori binari di arrotondamento: inaccettabili sul denaro. |
| **Foreign key esplicite** per integrità | Su **tutte** le tabelle, **incluso `events`**: a 10M righe il costo è trascurabile e il DB garantisce l'integrità anche contro scritture dirette (un `DELETE` grezzo non può creare orfani). `ON DELETE RESTRICT` sul genitore (niente cascade di massa); cancellazione campagne via **soft-delete**. Solo a scala estrema (partitioning/sharding, throughput altissimo) si valuterebbe di rimuoverla spostando l'integrità in applicazione. |
| `created_at` / `updated_at` dove ha senso | Audit di base. Le tabelle *append-only* (events) non hanno `updated_at`. |

Principio guida sulle performance: **modello di scrittura normalizzato** (verità, integrità,
JOIN economiche verso dimensioni piccole) **+ ottimizzazioni di lettura reattive**, aggiunte
solo dove una query si dimostra lenta, misurando con `EXPLAIN`.

---

## 1. `versions` — la campagna/gioco pubblicato

**Concetto:** l'edizione di un gioco/campagna (es. "Barilla — Estate 2026, v2"). È la **radice**
a cui tutto si aggancia: ogni query parte da `version_id`. Poche righe (decine/centinaia).

| Campo | Tipo | Null | Perché (tipo + concetto) |
|---|---|---|---|
| `id` | BIGINT UNSIGNED, PK, AI | no | Chiave interna, coerenza con le FK. |
| `uuid` | CHAR(36), UNIQUE | no | ID pubblico non enumerabile da esporre nelle URL `/versions/{uuid}`. CHAR(36) = lunghezza fissa di un UUID. |
| `name` | VARCHAR(150) | no | Nome della campagna. |
| `client_name` | VARCHAR(150) | sì | Il cliente (Barilla). Nullable in bozza. |
| `status` | ENUM('draft','active','archived') | no | Stati chiusi e noti → ENUM compatto (1 byte) e auto-documentante. |
| `starts_at` | DATETIME | sì | Inizio finestra campagna. |
| `ends_at` | DATETIME | sì | Fine finestra campagna. |
| `config` | JSON | sì | Configurazione variabile del gioco: struttura non fissa → JSON. |
| `created_at` / `updated_at` | DATETIME | no | Audit. |

**Indici:** PK `id`, UNIQUE `uuid`.

---

## 2. `users` — l'identità della persona

**Concetto:** la persona reale (email), **una sola volta**, indipendente dalle versioni giocate.
Se Mario fra sei mesi riaccede con la stessa email a una nuova campagna, `users` **non si duplica**:
si riusa la riga esistente e nasce solo una nuova `players`.

| Campo | Tipo | Null | Perché (tipo + concetto) |
|---|---|---|---|
| `id` | BIGINT UNSIGNED, PK, AI | no | Referenziata da `players`. |
| `email` | VARCHAR(191), UNIQUE | no | 191: limite indice utf8mb4 su vecchi row-format (767 byte / 4). UNIQUE = una persona, una riga. |
| `external_id` | VARCHAR(100), UNIQUE | sì | ID da sistemi esterni (SSO). Nullable se registrazione diretta. |
| `created_at` / `updated_at` | DATETIME | no | Audit. |

**Indici:** UNIQUE `email`, UNIQUE `external_id`.

---

## 3. `players` — la partecipazione a una versione

**Concetto:** l'iscrizione di un `user` a **una** `version`. *Grain* = **(user, version)**: la stessa
persona in due campagne = due righe. Qui vive `total_score`, con scope non ambiguo ("punteggio in
questa versione"). ~1 milione di righe.

| Campo | Tipo | Null | Perché (tipo + concetto) |
|---|---|---|---|
| `id` | BIGINT UNSIGNED, PK, AI | no | `player_id` usato in export e in `events`. |
| `version_id` | BIGINT UNSIGNED, FK→versions | no | La versione della partecipazione. |
| `user_id` | BIGINT UNSIGNED, FK→users | no | La persona. Separazione identità/partecipazione. |
| `registered_at` | DATETIME | sì | Registrazione *in questa versione*. Nullable se solo "aperto". |
| `total_score` | INT UNSIGNED | no | **Aggregato denormalizzato** per-versione (default 0). Precalcolato per non riscansionare 10M eventi. |
| `language` | VARCHAR(8) | sì | Lingua del player (it, en...). |
| `created_at` / `updated_at` | DATETIME | no | Audit. |

**Vincoli/indici:** UNIQUE `(version_id, user_id)` → una partecipazione per versione.
Indice `(version_id, registered_at)` per l'export dettaglio ordinato per data.

---

## 4. `events` — il firehose (sorgente di verità)

**Concetto:** il **log append-only** di tutto ciò che accade. Immutabile: si scrive, non si aggiorna.
Sorgente delle statistiche aggregate. ~10 milioni di righe: la tabella più critica.

| Campo | Tipo | Null | Perché (tipo + concetto) |
|---|---|---|---|
| `id` | BIGINT UNSIGNED, PK, AI | no | **PK monotòna** → insert sequenziali, niente page split. Mai UUID casuale come PK. |
| `version_id` | BIGINT UNSIGNED | no | Scope obbligatorio: prima colonna di ogni indice. |
| `player_id` | BIGINT UNSIGNED | no | Chi ha generato l'evento. Serve per `unique_players`. |
| `type` | VARCHAR(40) | no | Tipo evento. VARCHAR = flessibile; a scala estrema `type_id` TINYINT + lookup per risparmiare byte/riga. |
| `occurred_at` | DATETIME | no | Quando è **avvenuto** (dominio). Distinto da `created_at` (quando ingerito): i filtri temporali usano questo. |
| `payload` | JSON | no | Dati variabili (score, level, utm_source, custom_field_*). JSON = flessibilità richiesta. |
| `payload_language` | VARCHAR(8) AS (...) VIRTUAL | sì | **Colonna generata** indicizzabile. VIRTUAL = non occupa spazio in riga, materializzata solo nell'indice. |
| `payload_utm_source` | VARCHAR(64) AS (...) VIRTUAL | sì | Campo "caldo" promosso per raggruppare/filtrare. |
| `payload_score` | INT AS (...) VIRTUAL | sì | Serve per `avg_score` (vedi nota sul grain). |
| `created_at` | DATETIME DEFAULT CURRENT_TIMESTAMP | no | Quando registrato. Niente `updated_at`: immutabile. |

**Indici:**
- `(version_id, occurred_at)` — range temporale.
- `(version_id, type, occurred_at)` — filtro tipo + data.
- `(version_id, type, payload_language, payload_utm_source)` — GROUP BY dell'`events_summary`.

**Nota — grain dello score:** lo `score` è una metrica del **giocatore** (punteggio di gioco), non
del singolo evento. Nell'esempio `avg_score` è identico su tutti i tipi di evento per lo stesso
segmento (it/linkedin = 163) proprio perché è player-level, "timbrato" sul payload di ogni evento.
`AVG(payload_score)` sugli eventi pesa per numero di eventi; se vuoi la media *per giocatore* devi
aggregare prima per player. Ogni metrica deve avere un **grain esplicito**.

**Nota — doppia scrittura (log + proiezione):** un'azione tipo "risposta" scrive sia in `events`
(la timeline uniforme, letta da `events_summary`) sia nella tabella tipizzata (`answers`, letta
dal foglio per-domanda). È il pattern event-sourcing: `events` è il log, la tabella tipizzata è
una *proiezione*. Costo: write amplification → le due scritture vanno in **transazione**. Strada di
scaling: costruire la proiezione in modo asincrono consumando lo stream invece di scrivere due volte.

---

## 5. `questions` — le domande (dimensione)

**Concetto:** dimensione piccola. Il testo vive **una volta sola** qui. `type` determina una regola,
non è solo un'etichetta.

| Campo | Tipo | Null | Perché (tipo + concetto) |
|---|---|---|---|
| `id` | BIGINT UNSIGNED, PK, AI | no | Referenziata da `answer_options` e `answers`. |
| `version_id` | BIGINT UNSIGNED, FK→versions | no | Le domande appartengono a una versione. |
| `code` | VARCHAR(20) | no | Codice leggibile ("Q1"). |
| `text` | VARCHAR(500) | no | Testo della domanda. |
| `type` | ENUM('single_choice','multiple_choice','rating','open') | no | **Determina il vincolo**: single_choice → una sola risposta per player; open → testo libero senza opzioni. |
| `position` | SMALLINT UNSIGNED | sì | Ordine di visualizzazione. |
| `created_at` / `updated_at` | DATETIME | no | Audit. |

**Vincoli/indici:** UNIQUE `(version_id, code)`.

---

## 6. `answer_options` — il catalogo delle opzioni (dimensione)

**Concetto:** le **opzioni possibili** di una domanda a scelta ("Sostenibilità", "Famiglia",
"Identità nazionale"). Senza questa tabella le opzioni esisterebbero solo come stringhe sparse in
`answers`: un'opzione mai scelta sparirebbe dall'export, nessuna integrità sui label, rinominare =
aggiornare milioni di righe. Qui invece l'opzione è definita **una volta**, e `answers` la referenzia.

| Campo | Tipo | Null | Perché (tipo + concetto) |
|---|---|---|---|
| `id` | BIGINT UNSIGNED, PK, AI | no | Referenziata da `answers.answer_option_id`. |
| `version_id` | BIGINT UNSIGNED, FK→versions | no | Scope. |
| `question_id` | BIGINT UNSIGNED, FK→questions | no | A quale domanda appartiene. |
| `code` | VARCHAR(20) | sì | Codice opzione ("A", "B") se serve. |
| `label` | VARCHAR(255) | no | Testo dell'opzione ("Sostenibilità"). |
| `position` | SMALLINT UNSIGNED | no | Ordine nell'export (così mostri sempre le opzioni nell'ordine giusto). |
| `is_correct` | TINYINT(1) | sì | Se è l'opzione corretta (quiz). NULL per sondaggi/rating. La correttezza sta **qui**, non su ogni risposta. |
| `created_at` / `updated_at` | DATETIME | no | Audit. |

**Vincoli/indici:** UNIQUE `(question_id, label)`, indice `(question_id, position)`.

**Perché migliora l'export:** la distribuzione si calcola partendo dalle **opzioni** con LEFT JOIN
sulle risposte, così compaiono anche quelle con **0 risposte**:

```sql
SELECT q.code AS question_id, q.text AS question, o.label AS answer,
       COUNT(a.id)                                                       AS answers_count,
       COUNT(a.id) / NULLIF(SUM(COUNT(a.id)) OVER (PARTITION BY q.code),0) AS percentage
FROM questions q
JOIN answer_options o ON o.question_id = q.id
LEFT JOIN answers a   ON a.answer_option_id = o.id
                     AND a.occurred_at BETWEEN :from AND :to
WHERE q.version_id = :versionId
GROUP BY q.code, q.text, o.label, o.position
ORDER BY q.code, o.position;
```

---

## 7. `answers` — le risposte date (fact per-domanda)

**Concetto:** cosa ha scelto ciascun player. Per le domande chiuse punta all'opzione via `answer_option_id`
(integrità + niente testo duplicato); per le aperte usa `answer_text`. Legata all'evento via `event_id`.

| Campo | Tipo | Null | Perché (tipo + concetto) |
|---|---|---|---|
| `id` | BIGINT UNSIGNED, PK, AI | no | Chiave interna. |
| `version_id` | BIGINT UNSIGNED | no | Scope. |
| `player_id` | BIGINT UNSIGNED | no | Chi ha risposto. |
| `event_id` | BIGINT UNSIGNED, FK→events | sì | Aggancio alla timeline. |
| `question_id` | BIGINT UNSIGNED, FK→questions | no | Quale domanda. |
| `answer_option_id` | BIGINT UNSIGNED, FK→answer_options | sì | **L'opzione scelta** (domande chiuse). NULL per le aperte. (Nome reale della colonna; nel design iniziale era `option_id`.) |
| `answer_text` | VARCHAR(500) | sì | Testo libero (domande aperte). NULL per le chiuse. |
| `occurred_at` | DATETIME | no | Quando data. Per filtri temporali. |
| `created_at` | DATETIME | no | Audit. |

**Vincoli/indici:** `UNIQUE(version_id, player_id, question_id)` → **una risposta per domanda** (regola
del single_choice; per multiple_choice si rimuove). Indice `(version_id, question_id, answer_option_id)` per
l'aggregazione di distribuzione. La correttezza si deriva da `answer_options.is_correct` via join.

---

## 8. `transactions` — i movimenti economici (fact tipizzata)

**Concetto:** azioni con **soldi**: mai in JSON. Colonne forti per precisione e integrità.

| Campo | Tipo | Null | Perché (tipo + concetto) |
|---|---|---|---|
| `id` | BIGINT UNSIGNED, PK, AI | no | Chiave interna. |
| `version_id` | BIGINT UNSIGNED | no | Scope. |
| `player_id` | BIGINT UNSIGNED | no | Chi ha transato. |
| `event_id` | BIGINT UNSIGNED, FK→events | sì | Aggancio alla timeline. |
| `type` | ENUM('purchase','spend','refund') | no | Insieme chiuso → ENUM. |
| `amount` | DECIMAL(12,2) | no | **DECIMAL**: precisione esatta. 12,2 → fino a 9.999.999.999,99. |
| `currency` | CHAR(3) | no | ISO 4217 ("EUR"): sempre 3 caratteri → CHAR(3). |
| `status` | ENUM('pending','completed','failed') | no | Stato del movimento. |
| `external_ref` | VARCHAR(100) | sì | Riferimento gateway di pagamento. |
| `occurred_at` | DATETIME | no | Quando avvenuta. |
| `created_at` | DATETIME | no | Audit. |

**Indici:** `(version_id, occurred_at)`, `(player_id)`.

---

## 9. `rewards` — i premi assegnati (fact tipizzata)

**Concetto:** assegnazione e riscatto premi (inventario). Ciclo di vita con stati.

| Campo | Tipo | Null | Perché (tipo + concetto) |
|---|---|---|---|
| `id` | BIGINT UNSIGNED, PK, AI | no | Chiave interna. |
| `version_id` | BIGINT UNSIGNED | no | Scope. |
| `player_id` | BIGINT UNSIGNED | no | A chi è assegnato. |
| `event_id` | BIGINT UNSIGNED, FK→events | sì | Aggancio alla timeline. |
| `reward_type` | VARCHAR(40) | no | coupon / badge / physical... |
| `reward_code` | VARCHAR(100) | sì | Codice del premio. |
| `status` | ENUM('granted','redeemed','expired') | no | Ciclo di vita. |
| `granted_at` | DATETIME | no | Quando assegnato. |
| `redeemed_at` | DATETIME | sì | Quando riscattato. NULL finché non riscattato. |
| `created_at` | DATETIME | no | Audit. |

**Indici:** `(version_id, status)`, `(player_id)`.

---

## 10. `exports` — lo stato dei job di export (operativa)

**Concetto:** lo **stato durevole di un lavoro asincrono**. Sistema di verità dei tre endpoint
(crea / stato / download). Distinta dalla tabella `jobs` della coda: `jobs` è il *meccanismo*,
`exports` è lo *stato di dominio* visibile al client.

| Campo | Tipo | Null | Perché (tipo + concetto) |
|---|---|---|---|
| `id` | BIGINT UNSIGNED, PK, AI | no | Chiave interna. |
| `uuid` | CHAR(36), UNIQUE | no | ID pubblico non enumerabile nelle URL `/exports/{uuid}`. |
| `version_id` | BIGINT UNSIGNED, FK→versions | no | Da `POST /versions/{versionId}/exports`. |
| `params` | JSON | no | L'**intera richiesta** (sheets, columns, filters, sort, date range). Variabile → JSON. Abilita retry, audit, riproducibilità. |
| `format` | VARCHAR(10) DEFAULT 'xlsx' | no | Formato output. |
| `status` | ENUM('pending','processing','completed','failed','cancelled') | no | Macchina a stati. Alimenta `GET /exports/{uuid}`. `cancelled` per il bonus. |
| `progress` | TINYINT UNSIGNED DEFAULT 0 | no | 0–100. Bonus progress %. Ad alta frequenza: candidato a stare in Redis. |
| `total_rows` | INT UNSIGNED | sì | Righe totali (per la %). |
| `processed_rows` | INT UNSIGNED DEFAULT 0 | no | Righe scritte: guida il progress. |
| `file_path` | VARCHAR(255) | sì | Dove sta il file. NULL finché non `completed`; letto da `/download`. |
| `file_size` | INT UNSIGNED | sì | Dimensione file. |
| `error_message` | TEXT | sì | Valorizzato se `failed`. |
| `attempts` | TINYINT UNSIGNED DEFAULT 0 | no | Conteggio tentativi. Bonus retry. |
| `created_at` | DATETIME | no | Entrata in coda (`pending`). |
| `started_at` | DATETIME | sì | Preso dal worker (`processing`). Con created → **latenza di coda**. |
| `completed_at` | DATETIME | sì | Fine. Con started → **durata elaborazione**. |

**Indici:** UNIQUE `uuid`, `(version_id, status)`.

**Architettura DB + Redis:** MySQL = sistema di verità durevole/interrogabile. Redis = accelerazione:
`progress` volatile, flag di cancellazione / lock distribuito, eventuale pub/sub per push realtime.

---

## 11. `export_templates` — configurazioni riutilizzabili (bonus)

**Concetto:** per il bonus "template salvabili". Persiste oltre il singolo export → tabella a parte.

| Campo | Tipo | Null | Perché (tipo + concetto) |
|---|---|---|---|
| `id` | BIGINT UNSIGNED, PK, AI | no | Chiave interna. |
| `version_id` | BIGINT UNSIGNED, FK→versions | no | Template legato a una versione. |
| `name` | VARCHAR(150) | no | Nome del template. |
| `params` | JSON | no | Configurazione riutilizzabile. |
| `created_at` / `updated_at` | DATETIME | no | Audit. |

---

## 12. Tabelle di infrastruttura (Laravel)

- `jobs` — la coda: job serializzati in attesa; a fine job vengono **cancellati** (per questo non
  possono ospitare lo stato interrogabile).
- `failed_jobs` — job falliti in modo definitivo.
- `migrations` — storico delle migration.

---

## Relazioni (ERD testuale)

```
users (1) ──< (N) players (N) >── (1) versions
                                        │
                          ┌─────────────┼───────────────────────────┐
                          │             │                           │
                   (N) questions   (N) events                 (N) exports
                          │             │                     (N) export_templates
                   (N) answer_options   ├──< (N) transactions
                          │             └──< (N) rewards
                          └───┐         │
                      (N) answers >─────┘  (answers.event_id → events, opzionale)
                              │
                    answers.answer_option_id → answer_options
                    answers.question_id → questions
```

---

## Appendice — Flusso completo con transazioni

Le scritture che devono essere **atomiche** (o tutte o nessuna) vanno in `DB::transaction`.

**Setup campagna (Barilla):** versione + domande + opzioni, in un'unica transazione.

```php
DB::transaction(function () {
    $v = Version::create(['uuid'=>Str::uuid(),'name'=>'Estate 2026','client_name'=>'Barilla','status'=>'active']);
    $q1 = Question::create(['version_id'=>$v->id,'code'=>'Q1','text'=>'Che valore ti trasmette Barilla?','type'=>'single_choice','position'=>1]);
    foreach (['Sostenibilità','Famiglia','Identità nazionale'] as $i=>$label) {
        AnswerOption::create(['version_id'=>$v->id,'question_id'=>$q1->id,'label'=>$label,'position'=>$i+1]);
    }
});
```

**Mario risponde (doppia scrittura atomica):** evento + risposta insieme. Se la `answers` fallisce
(es. viola l'unique = risposta duplicata), anche l'evento fa rollback → niente timeline incoerente.

```php
DB::transaction(function () use ($player, $questionId, $optionId) {
    $event = Event::create([
        'version_id'  => $player->version_id,
        'player_id'   => $player->id,
        'type'        => 'answer_submitted',
        'occurred_at' => now(),
        'payload'     => ['language'=>$player->language,'utm_source'=>'linkedin'],
    ]);
    Answer::create([
        'version_id'  => $player->version_id,
        'player_id'   => $player->id,
        'event_id'    => $event->id,
        'question_id' => $questionId,
        'answer_option_id' => $optionId,     // punta a answer_options
        'occurred_at' => $event->occurred_at,
    ]);
});
```

**Fine partita (evento + aggiornamento score atomici):** `increment()` fa un UPDATE atomico
`SET total_score = total_score + X`, sicuro sotto concorrenza (niente read-modify-write).

```php
DB::transaction(function () use ($player, $score) {
    Event::create([
        'version_id'=>$player->version_id,'player_id'=>$player->id,
        'type'=>'game_completed','occurred_at'=>now(),'payload'=>['score'=>$score],
    ]);
    $player->increment('total_score', $score);   // UPDATE atomico
});
```

**Perché la transazione:** garantisce l'**atomicità** (la A di ACID). Le scritture correlate o
riescono tutte o vengono annullate tutte: nessun evento orfano, nessun punteggio aggiornato senza
il suo evento. È la risposta corretta a "come garantisci la coerenza tra `events` e le tabelle tipizzate".

---

## Riepilogo delle decisioni chiave

1. **JSON ibrido su `events`**: payload libero + colonne generate indicizzate sui campi caldi.
2. **Identità vs partecipazione** (`users` / `players`): `total_score` con grain per-versione; email non duplicata.
3. **Catalogo opzioni** (`answer_options`): le opzioni sono un'entità, non stringhe sparse → integrità + opzioni a 0 risposte nell'export.
4. **Stato async durevole** (`exports`) + Redis per il volatile.
5. **Transazioni** sulle scritture correlate (evento+risposta, evento+score) per l'atomicità.
```
