# Voucher fonts

Bundled for the reservation voucher PDF (SPEC-013, research R2). Noto Sans is under the
SIL Open Font License 1.1 ([OFL.txt](OFL.txt)), which allows bundling.

| File | Family | Use |
| --- | --- | --- |
| `NotoSans-Regular.ttf`, `NotoSans-Bold.ttf` | Noto Sans | English vouchers; fallback for any glyph missing from the Arabic font |

Arabic vouchers use mPDF's own XB Riyaz font (shipped with `mpdf/mpdf`). Noto Naskh Arabic was
tried first, but mPDF cannot read its GPOS mark-to-ligature tables.

Source: the static hinted TTFs from `notofonts/notofonts.github.io` (`fonts/NotoSans/hinted/ttf/`),
downloaded 2026-10-27. Static files are used because mPDF does not support variable fonts.
