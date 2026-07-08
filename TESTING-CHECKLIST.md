# Testing Checklist — Gamindo Export Engine

Esegui questi comandi **uno per uno** nel terminale per verificare tutti i check.

---

## 🔧 Setup Iniziale

```bash
# 1. Verifica che lo stack sia su
curl http://localhost:8080/health

# 2. Accedi al container app
docker compose exec app bash
```

---

## 📊 Test 1: Ingestione Batch Pesante

```bash
# Dentro il container app, oppure da host con docker compose exec

# Test 1a: Batch AL LIMITE (5000 righe) → 202 Accepted
curl -X POST http://localhost:8080/api/v1/versions/test/players \
  -H 'Content-Type: application/json' \
  -d @manual-test/players/valid-at-cap-5000.json

# Test 1b: Batch OLTRE LIMITE (5001 righe) → 413 Payload Too Large
curl -X POST http://localhost:8080/api/v1/versions/test/players \
  -H 'Content-Type: application/json' \
  -d @manual-test/players/invalid-oversized-5001.json
```

**Check**: 
- ✓ Primo ritorna 202
- ✓ Secondo ritorna 413 immediato (senza toccare DB)

---

## 🌱 Test 2: Seeder Database

```bash
# Test 2a: Seed medio (20k player + 2M eventi)
docker compose exec app php artisan gamindo:seed-demo --players=20000 --events=2000000

# Test 2b: Verifica conteggio dopo seed
docker compose exec app php artisan tinker
>>> DB::table('players')->count()
>>> DB::table('events')->count()
>>> exit()
```

**Check**:
- ✓ Seed completo senza errori (~ 2-3 min)
- ✓ Player conteggiati correttamente
- ✓ Eventi conteggiati correttamente

---

## 📤 Test 3: Export Grandi Volumi

```bash
# Test 3a: Crea versione test
VERSION=$(curl -s -X POST "http://localhost:8080/api/v1/versions" \
  -H 'Content-Type: application/json' \
  -d '{"name":"Load Test"}' | jq -r '.data.uuid')

echo "Version: $VERSION"

# Test 3b: Ingestione player
curl -s -X POST "http://localhost:8080/api/v1/versions/$VERSION/players" \
  -H 'Content-Type: application/json' \
  -d '{"players":[{"email":"a@a.com","language":"it"},{"email":"b@a.com","language":"en"}]}' > /dev/null
sleep 3

# Test 3c: Ingestione eventi (100 righe)
curl -s -X POST "http://localhost:8080/api/v1/versions/$VERSION/events" \
  -H 'Content-Type: application/json' \
  -d '{
    "events": [
      {"player_id":1,"type":"game_completed","occurred_at":"2026-01-15T10:00:00Z","payload":{"score":100}},
      {"player_id":2,"type":"game_completed","occurred_at":"2026-01-15T10:05:00Z","payload":{"score":150}},
      {"player_id":1,"type":"transaction","occurred_at":"2026-01-15T10:10:00Z","payload":{"type":"purchase","amount":9.99}}
    ]
  }' > /dev/null
sleep 3

# Test 3d: Richiesta export
EXPORT=$(curl -s -X POST "http://localhost:8080/api/v1/versions/$VERSION/exports" \
  -H 'Content-Type: application/json' \
  -d '{"sheets":[{"source":"players"},{"source":"events"}]}' | jq -r '.data.id')

echo "Export ID: $EXPORT"

# Test 3e: Polling export con progress
echo "Polling export..."
while true; do
  RESPONSE=$(curl -s "http://localhost:8080/api/v1/exports/$EXPORT")
  STATUS=$(echo "$RESPONSE" | jq -r '.data.status')
  PROGRESS=$(echo "$RESPONSE" | jq -r '.data.progress // 0')
  ROWS=$(echo "$RESPONSE" | jq -r '.data.rows // "null"')
  SIZE=$(echo "$RESPONSE" | jq -r '.data.file_size // "null"')
  
  printf "[%3d%%] Status: %-12s | Rows: %s | Size: %s bytes\r" "$PROGRESS" "$STATUS" "$ROWS" "$SIZE"
  
  [ "$STATUS" = "completed" ] && break
  [ "$STATUS" = "failed" ] && echo "EXPORT FALLITO" && exit 1
  sleep 2
done
echo ""

# Test 3f: Download file
curl -s "http://localhost:8080/api/v1/exports/$EXPORT/download" -o /tmp/test_export.xlsx
ls -lh /tmp/test_export.xlsx
```

**Check**:
- ✓ Export ritorna 202
- ✓ Progress sale da 0 a 100
- ✓ File scaricato con size > 0

---

## ⚡ Test 4: Benchmark Export 500k Righe

```bash
# Test 4a: Benchmark streaming memory (memoria piatta)
docker compose exec app php artisan gamindo:export-benchmark --rows=500000

# Verifica output:
# - Memory usage piatto (non sale linearmente)
# - Rows/sec sostenuto
# - Durata totale ragionevole
```

**Check**:
- ✓ Memoria non esplode (< 50MB)
- ✓ Throughput > 1000 rows/sec
- ✓ File generato in < 60 sec

---

## 📈 Test 5: Verifiche Database

```bash
# Test 5a: Dimensioni tabelle
docker compose exec db mysql -u root -proot gamindo -e "
  SELECT table_name, 
         ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb,
         TABLE_ROWS
  FROM information_schema.TABLES
  WHERE table_schema = 'gamindo'
  ORDER BY size_mb DESC;"

# Test 5b: Statistiche dati
docker compose exec app php artisan tinker
>>> $stats = [
...   'players' => DB::table('players')->count(),
...   'events' => DB::table('events')->count(),
...   'answers' => DB::table('answers')->count(),
...   'transactions' => DB::table('transactions')->count(),
...   'rewards' => DB::table('rewards')->count(),
...   'exports' => DB::table('exports')->count(),
... ];
>>> print_r($stats);
>>> exit()
```

**Check**:
- ✓ Tabelle hanno righe aspettate
- ✓ DB size ragionevole (< 1GB per 2M eventi)
- ✓ Indici presenti e efficienti

---

## 🔍 Test 6: Idempotenza (No Duplicate)

```bash
# Test 6a: Crea versione
V=$(curl -s -X POST "http://localhost:8080/api/v1/versions" \
  -H 'Content-Type: application/json' \
  -d '{"name":"Idempotency Test"}' | jq -r '.data.uuid')

# Test 6b: Primo batch ingestione (3 player)
BATCH='{"players":[{"email":"p1@a.com","language":"it"},{"email":"p2@a.com","language":"it"},{"email":"p3@a.com","language":"it"}]}'

curl -s -X POST "http://localhost:8080/api/v1/versions/$V/players" \
  -H 'Content-Type: application/json' \
  -d "$BATCH" > /dev/null
sleep 2

# Test 6c: Reinvia STESSO batch (deve essere idempotente)
IMPORT_RESPONSE=$(curl -s -X POST "http://localhost:8080/api/v1/versions/$V/players" \
  -H 'Content-Type: application/json' \
  -d "$BATCH")

echo "Second import:"
echo "$IMPORT_RESPONSE" | jq '{inserted: .data.inserted, duplicates: .data.duplicates}'

# Test 6d: Verifica DB (solo 3 player, non 6)
docker compose exec app php artisan tinker
>>> DB::table('players')->where('version_id', DB::raw("(SELECT id FROM versions WHERE uuid = '$V')"))->count()
>>> exit()
```

**Check**:
- ✓ Primo import: 3 inserted
- ✓ Secondo import: 0 inserted, 3 duplicates
- ✓ DB ha esattamente 3 player (no duplicati)

---

## ✅ Test 7: API Key Auth (Opzionale)

```bash
# Se GAMINDO_API_KEY è impostata in .env

# Test 7a: Senza API Key (deve fallire se richiesta)
curl -X POST http://localhost:8080/api/v1/versions \
  -H 'Content-Type: application/json' \
  -d '{"name":"Test"}'

# Test 7b: Con API Key
API_KEY=$(grep GAMINDO_API_KEY .env | cut -d= -f2)

curl -X POST http://localhost:8080/api/v1/versions \
  -H 'Content-Type: application/json' \
  -H "X-Api-Key: $API_KEY" \
  -d '{"name":"Test"}'
```

**Check**:
- ✓ Richieste con key funzionano
- ✓ Richieste senza key bloccate (se configurato)

---

## 📋 Resoconto Finale

Dopo aver eseguito tutti i test, verifica:

- ✅ Setup stack OK
- ✅ Batch limite funzionano (5000 OK, 5001 reject)
- ✅ Seeder genera dati correttamente
- ✅ Export funziona con polling progress
- ✅ Benchmark 500k righe a memoria piatta
- ✅ DB consistency verificato
- ✅ Idempotenza funziona (no duplicati)
- ✅ API Key auth OK (se configurato)

**Se tutti i check passano, il sistema è pronto per il deploy!** 🚀
