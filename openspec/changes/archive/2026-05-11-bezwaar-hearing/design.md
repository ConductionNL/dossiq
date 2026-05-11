# Design: bezwaar-hearing

## Context

The hoorzitting is the most procedurally sensitive step in the Dutch bezwaar process. Under Awb art. 7:2 the bestuursorgaan is obliged to offer the bezwaarmaker an opportunity to be heard before deciding on the objection; under art. 7:3 the right may be waived (afzien van hoorrecht). Surrounding articles tighten the procedure: art. 7:4 grants inspection-of-file with a hard "at least one week before the hearing" deadline, art. 7:6 protects sensitive documents, art. 7:7 obliges minutes (verslag). Failure to comply produces an automatic appeal-success risk at the beroep stage, so the system needs an explicit, auditable capability — not an ad-hoc calendar event.

This change documents that capability as a sister spec to `bezwaar-lifecycle` (statussen + deadlines) and `bezwaar-advisory-committee` (advisory report). Implementation will follow in a separate apply change; this proposal is spec-only.

## Entity: `hearingSession`

Stored as an OpenRegister object on the procest register, Schema.org type `schema:Event`.

| Property | Type | Required | Role |
|----------|------|----------|------|
| `case` | UUID (`$ref: case`) | Yes | Parent bezwaar case |
| `scheduledDate` | datetime | Yes | Hearing date/time |
| `location` | string | No | Physical location, or `"Online"` |
| `videoCallUrl` | string (URL) | No | Video conference link |
| `chairperson` | UUID (role) | Yes | Voorzitter |
| `members` | array<UUID> | No | Commissie leden present |
| `invitees` | array<object> | Yes | `{role, name, channel, accessibilityNeeds[]}` |
| `inspectionAvailableFrom` | date | Yes | First date dossier is available for inspection |
| `inspectionDeadline` | date | Yes | `scheduledDate − 7 days` (Awb art. 7:4 lid 2) |
| `attendance` | array<object> | No | Captured at hearing: `{invitee, present, arrivalTime}` |
| `minutesSummary` | string (text) | No | Short verslag |
| `minutesDocument` | UUID (file) | No | Full verslag document |
| `audioRecording` | UUID (file) | No | Optional audio capture, retention-tagged |
| `recordingConsent` | enum | No | `granted`, `denied`, `not_requested` |
| `followUpQuestions` | array<object> | No | Post-hearing questions to bezwaarmaker |
| `status` | enum | Yes | `gepland`, `uitgenodigd`, `dossier_beschikbaar`, `uitgevoerd`, `geannuleerd`, `afgezien` |
| `hearingWaived` | boolean | No | Set to `true` when art. 7:3 waiver applies |
| `waiverReason` | string | No | Free-text reason (required when `hearingWaived = true`) |

## Lifecycle

```
                 ┌──(waiver)──► afgezien
                 │
concept (case) ──► gepland ──(invite)──► uitgenodigd
                                  │
                                  ▼
                          dossier_beschikbaar
                                  │ (≥ scheduledDate − 7 days)
                                  ▼
                              uitgevoerd ──► (minutes captured)
                                  │
                          geannuleerd (parallel terminal)
```

`gepland → uitgenodigd` MUST run the invitation flow; `uitgenodigd → dossier_beschikbaar` is automatic when `inspectionAvailableFrom ≤ today`. `uitgevoerd` is set by the voorzitter after the hearing concludes and requires either `minutesSummary` or `minutesDocument`.

## Invitation flow (with accessibility)

Each `invitee` carries a `channel` enum: `berichtenbox`, `email`, `post`, `in_person`. The system picks Berichtenbox when the bezwaarmaker has a connected MijnOverheid account (per `mijn-overheid-integration`); otherwise it falls back to email; otherwise it queues a paper print task.

Accessibility hooks (`invitee.accessibilityNeeds[]` enum: `low_literacy`, `interpreter`, `sign_language`, `physical_access`) drive variants:

- `low_literacy` → invitation rendered with B1-level Dutch template; key facts (date/time/location) repeated in a top callout box
- `interpreter` → an additional invitation block lists the requested taal, and a tolk-booking task is created on the case
- `sign_language` → gebarentolk task created
- `physical_access` → location confirmation task to verify wheelchair / elevator access

Every accessibility need produces an audit trail entry on the case to demonstrate Awb art. 2:1 / art. 7:2 reasonable-accommodation duty.

## Inspection-of-file mechanics (Awb art. 7:4)

`inspectionDeadline` is a hard floor: it MUST be at least 7 calendar days before `scheduledDate`. The system computes it on creation; if the user later shifts `scheduledDate` closer than 7 days the system blocks the save with a Dutch error: `Inzagetermijn (art. 7:4) wordt geschonden — minimaal 7 dagen voor de hoorzitting`. Documents marked as `confidential` (art. 7:6) are excluded from the inspection bundle but listed with a reason placeholder ("Document onthouden op grond van art. 7:6 Awb"). Access events on inspection documents are logged with `inspection-trail` tags so legal can prove compliance.

## Minutes capture

`uitgevoerd` requires either:
- `minutesSummary` (text, ≥ 1 sentence), OR
- `minutesDocument` (uploaded verslag file).

`audioRecording` is optional and gated by `recordingConsent = granted` from the bezwaarmaker (Awb compliance + AVG/GDPR Art. 6). If consent is denied the system MUST refuse the upload and write a denial audit entry. Audio files inherit the case's retention regime (typically 5 jaar per Selectielijst gemeenten).

## Follow-up questions

After `uitgevoerd` the voorzitter MAY add `followUpQuestions[]` entries `{question, askedTo, deadline}`. The case status remains `Hoorzitting afgerond` but a separate widget surfaces outstanding follow-ups on the dashboard until each is answered or marked withdrawn. The answers attach to the case dossier as `hearingFollowUp` documents.

## Legal compliance hooks

- Every status transition writes an audit entry tagged `awb-art-7:X` referencing the applicable article
- `hearingWaived = true` writes a separate `waiver` audit entry with reason and UID
- The invitation timestamp is preserved as `invitedAt` on each invitee for chain-of-custody
- Inspection events log per-document access with `actor`, `documentId`, `accessedAt`, `purpose = "art-7:4-inspection"`
- Recording consent + denial events are written as immutable audit entries

These hooks let beroep dossier export (`bezwaar-beroep-workflow`) prove procedural compliance without manual reconstruction.

## Why a spec-only change?

The hearing capability already has a partial spec but no change record. This change formalizes the contract first so that downstream implementation (UI, services, repair-step seed) lands against a stable, validated specification rather than diverging from it.
