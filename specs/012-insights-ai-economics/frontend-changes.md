# Frontend Changes (`ecosystem-frontend`): Phase 12

This is for the frontend team. All backend changes are additive, except one new error
status (503 on the advisor chat).

## 1. Insights list: citations

- **Data**: each insight has `citations: []`. When it isn't empty, render a "Sources"
  footer with one chip per citation:
  - the title;
  - the location, if set;
  - the scope badge: "Hotel" or "General guidance".
- **Unavailable sources**: when `available === false`, grey the chip out and add the
  tooltip "This source has since been removed or turned off".
- **Linking**: link the chip to the source's own page by `source_type`:
  - `policy` → hotel policies;
  - `article` → knowledge base;
  - `document` → knowledge documents.
  Don't link a chip whose source is unavailable or `general` scope (super-admin managed).
- **New category**: `knowledge`. Label it "Knowledge" and add it to the category filter.
- **Evidence badge**: keep it next to the title, unchanged. Don't merge citations into it.

## 2. Usage page (`/usage`, admins only)

- **New "AI usage" section** from `body.ai`:
  - three totals: requests, input tokens and output tokens (show total tokens prominently);
  - a table from `by_capability` (label, requests, tokens), sorted by total tokens;
  - with more than one hotel, a per-hotel table from `by_hotel`.
- **No cost**: don't show cost, currency or model anywhere on this page, not even as
  placeholders. The API never sends them.
- **`measured_since`**: when a feature has it, show "Measured since {date}" under the
  figure. Show `note` when it is present.
- **`basis`**: show it as a small info tooltip on the section title.

## 3. Super-admin AI cost report

- **New "By capability" table** per account from `by_capability`: label, calls, tokens,
  cost (EUR).
- **Estimated costs**: when `cost_is_estimated` is true, mark the cost "estimated".
- **Shared keys**: the capability keys match the usage page, so a combined view can join
  on them.

## 4. Admin AI chat

- **New 503**: handle `503` from `POST /api/ai-advisor/chat`. Show `message` as an inline
  assistant notice ("temporarily unavailable"), keep the conversation open, and allow a
  retry. Don't treat it as a logout or a fatal error.

## 5. Knowledge documents

- **No code change**: the `error` field for a document stopped by the daily limit now reads
  "The daily AI usage limit was reached; try again tomorrow." No change is needed if
  `failure_message` is already shown.
