# Benchmark — export a memoria piatta

La generazione XLSX è in **streaming a memoria costante**: lettura del detail via
keyset (`lazyById`, mai `OFFSET`) e scrittura via OpenSpout (che scarica su disco
senza materializzare le righe). Il picco di memoria **non cresce** col numero di righe.

## Comando

```bash
docker compose exec app php artisan gamindo:export-benchmark --rows=500000
# opzioni: --rows=N (default 500000), --keep (non ripulire i dati/il file)
```

Semina una version con N eventi, genera l'export (foglio detail, path keyset) e stampa
righe, **peak memory**, durata e dimensione file; poi ripulisce (salvo `--keep`).

## Risultati misurati (PHP 7.3, MySQL 8, container)

| rows    | peak_mem | duration | file     |
|---------|----------|----------|----------|
| 50.000  | 28.0 MB  | ~1.3s    | 1.01 MB  |
| 500.000 | 28.0 MB  | ~13s     | 10.2 MB  |

Il picco resta **28 MB** da 50k a 500k → memoria piatta (DoD Slice 5). Una guardia di
regressione automatica è in `tests/Feature/Export/ExportStreamingTest.php`
(`test_generation_memory_does_not_scale_with_row_count`).

## Nota

La garanzia vale sul **path keyset** (foglio detail **non ordinato**). Un detail
**ordinato** usa un cursor bufferizzato (vedi `GenericSheetBuilder::read()`): per volumi
molto grandi va ristretto con i filtri, oppure servirebbe un keyset composito `(sort, id)`
(rimandato).
