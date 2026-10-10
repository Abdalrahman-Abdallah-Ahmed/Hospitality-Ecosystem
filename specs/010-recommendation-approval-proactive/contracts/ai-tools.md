# Contract: AI Tools Added or Changed

Internal tool contracts (the model is the caller). Tests call each tool directly with
`new Request($args)`.

## Concierge (`GuestConciergeAgent`)

### `PitchActivityTool` (new, WP-17 adjusted)

Offered **only** when `PitchTurn::mayPitch()` and the turn has one candidate.

| Arg | Type | Rule |
| --- | --- | --- |
| `guest_words` | string, required | Must appear in the current guest message (normalized) |

`handle()` refuses with a plain sentence when:
1. the turn may not pitch, or something was already staged this turn;
2. `guest_words` is not in the current message;
3. under `lockForUpdate` on the stay, the cap / retry gates no longer pass;
4. the candidate is no longer offerable (rejected, expired, activity inactive) — FR-015.

On success: sets `recommendations.pitch_decision_id`, sets `pitch_decisions.is_retry` when
the turn is the retry, stages it on the turn, and returns
`Staged recommendation {id} for {name}. Mention it briefly after answering the guest…`.
Never stamps delivery; `PitchCoordinator::complete()` does after the send.

### `SetContactPreferenceTool` (new)

| Arg | Type | Rule |
| --- | --- | --- |
| `preference` | `opt_out` \| `resume` | Required |
| `guest_words` | string | The guest's words asking for it; stored as evidence |

Calls `GuestContactPreferenceService`. Returns a confirmation sentence for the model to relay.

### `GetRecommendationsTool` (changed)

Returns only recommendations already offered to the guest (`delivered_at` set or
`pitch_decision_id` set). Never `pending_approval`, unoffered `approved`, or
`rejected_by_admin`.

### Instructions (changed)

- Removed: "Proactively recommend activities … tailor a recommendation yourself".
- Added, built per turn in its own method:
  - eligible with a candidate → the WP-17.4 text naming that one activity;
  - otherwise → do not suggest activities the guest did not ask about; answer factual
    questions from the activities tool without presenting one as the hotel's suggestion;
  - opted-out guests → never offer unprompted;
  - "messages you sent first in the last 24 h" — trigger, text, recommendation id (R13).

## Admin AI (`AdminToolset`)

### `GetRecommendationsForReviewTool` (new, read)

Permission `recommendations.view`. Args (all optional): `status` (default
`pending_approval`), `guest`, `room_number`, `reservation_id`, `activity`, `arrival_from`,
`arrival_to`. Returns `{ total, returned, partial, items }`, at most 50 items, each with id,
guest, room, activity, reason, confidence, status, source.

### `DecideRecommendationTool` (new, write, confirm before running)

Permission `recommendations.approve`. Writes `Recommendation`.

| Arg | Type | Rule |
| --- | --- | --- |
| `recommendation_id` | uuid, required | One id only; no list argument exists |
| `action` | `approve` \| `reject` | Required |
| `reason` | string ≤ 500 | Optional, reject only |

- Implements `ConfirmsBeforeRunning`: summary `Approve "{activity}" for {guest} (room {n})`
  in the admin's language. Runs only after the admin confirms (009 `AdvisorTurn`).
- Calls `RecommendationApprovalService`; audited as the AI acting for the admin (009 guard).
- The advisor instructions tell the model to refuse bulk approval and point to the queue.
