# Contract: `docs/test-suite-performance.md` §6 (Stage 1)

§6 is appended after §5. Its subsections are written in this order. 6.1 is committed **before** any test file is edited.

## 6.1 Split list (written first)

- The chosen worker count (8) and the Stage 0 wall time it is judged against (302.4 s; 25% = 75.6 s).
- A table with one row per part: original file → part file → slice (segments, or source line range) → Stage 0 summed time of that slice.
- Helpers and functions moving to `tests/Pest.php`, by name, and the new ones (`crossTenantSlices`, `crossTenantSegment`).
- The coverage test's name and part, and the expected test count (3,598) with its reason.
- The other files this stage may touch: `composer.json`, `CLAUDE.md`, the `EntryPointInventoryTest` message, `RecommendationQueueTest` and `StayListTest` (only if 6.3 requires it).

## 6.2 `composer test`

The new script, and one line confirming that `--filter` passes through and that the timeout is off.

## 6.3 Timing budgets

| Run | RecommendationQueueTest "decides 100…" (s) | StayListTest "arrivals of a 500-room hotel" (s) |
| --- | --- | --- |
| s1-r2 … r4 | | |

- The decision for each file: unchanged, or a new value with the arithmetic shown.
- A note that JUnit time includes setup (research R7).

## 6.4 Exit check

- A per-run table: label, cold/warm, wall (s), summed (s), tests, result.
- Medians: wall time and summed time, beside Stage 0 at 8 workers (302.4 s wall; summed ~2,234 s).
- Slowest file and its summed time. The six parts, each with its summed time, against 75.6 s.
- **G3**: the count and the difference from 3,597, with its reason.
- **G1**: met or missed. If missed: the revised G1 and the reason (floor = summed ÷ 8 + start-up), per research R9.

## 6.5 OPcache (optional)

- The `php.ini` lines changed, the same table as 6.4 for the `s1o` runs, both medians, and whether the setting was kept.
- If skipped: "Not measured."

## Rules

- No placeholders remain when Stage 1 closes.
- Figures come from the JUnit files and timed wall clocks only.
- No credentials, and no DB settings beyond the database name.
