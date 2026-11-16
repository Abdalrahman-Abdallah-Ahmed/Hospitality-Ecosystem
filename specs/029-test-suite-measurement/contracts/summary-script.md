# Contract: summary script (scratchpad, not committed)

`<scratchpad>/stage0/summarize.py`, Python 3 standard library only. Run with `python -I`.

## Inputs

- `--reports <dir>`: `storage/test-reports/`, holding `<label>.xml` files.
- `--runs <file>`: `runs.csv` (fields in [data-model.md](../data-model.md)). Only rows with `kind=warm` are used.
- `--folders <file>`: `folders.json` (file base name → folder).
- `--baseline 12`: the worker count for the per-file and folder tables.

## Outputs (stdout, Markdown)

The tables for stage-log §4.1–§4.5 and the §5 exit-check figures, ready to paste:

1. Baseline table + medians + test count.
2. Sweep table + chosen count.
3. Per-file table (baseline count), slowest first.
4. Folder table with totals row and `UNMAPPED` row.
5. Invariants sum.
6. Split list at the chosen count, and the G2 estimate.

## Failure rules

- Exit non-zero, printing the reason, if: a worker count has fewer than 3 warm runs; a warm run's JUnit file is missing or unparsable; warm runs in a block disagree on test count; folder counts don't add up to the total.
- `UNMAPPED` files are reported, never cause a failure.
