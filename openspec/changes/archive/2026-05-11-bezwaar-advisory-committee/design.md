# Design: bezwaar-advisory-committee

## Context

Under the Dutch Algemene wet bestuursrecht (Awb) Art. 7:13, a council (bestuursorgaan) handling a citizen's objection (`bezwaar`) MAY (and in many municipalities DOES, as a matter of policy) defer the substantive review to an independent advisory committee — the **bezwaaradviescommissie** (commonly abbreviated as **bac**). The committee hears the parties, deliberates, and issues a written advice that the council must consider before issuing its decision-on-objection (`besluit op bezwaar`). The advice is **not binding**: the council may deviate, but any deviation requires explicit motivation. This change formalizes that committee, its lifecycle, and the advice it produces, as a sister capability to the parent `bezwaar-lifecycle` change.

## Entity: `bezwaaradviescommissie`

A committee is a long-lived configuration entity owned by the council, not a per-case entity. Selected properties:

| Property | Type | Role |
|----------|------|------|
| `name` | string ≤ 255 | Display name (e.g. "Bezwaarcommissie sociaal domein") |
| `domain` | enum: `algemeen`, `sociaal_domein`, `wabo`, `belasting`, `personeel` | Optional jurisdiction filter |
| `chair` | string (NC UID or external party reference) | Committee chair |
| `members` | array of party refs | All committee members (chair + others) |
| `secretary` | string (NC UID) | Civil servant secretary (Art. 7:13(2)) |
| `quorum` | integer ≥ 2 | Minimum members needed to issue advice (default 3) |
| `term_starts_on` / `term_ends_on` | date | Validity window |
| `status` | enum: `active`, `archived` | Lifecycle |

A `voorzitter` (chair) plus **at least two** members where **none is a civil servant of the council that took the contested decision** is the Awb Art. 7:13(3) baseline. Member independence is enforced by REQ-BAC-2 at the moment a bezwaar case is assigned to a committee, **not** at committee creation, because the same panel can be valid for one bezwaar and invalid for another (the contested decision's author varies).

## Entity: `bac_advice_request`

A per-bezwaar record that wires one bezwaar case to one committee and tracks the advice deliverable.

| Property | Type | Role |
|----------|------|------|
| `bezwaar_case` | UUID (ref → bezwaar case) | The case being reviewed |
| `committee` | UUID (ref → committee) | The assigned committee |
| `panel` | array of party refs | Subset of `committee.members` actually sitting on this case |
| `status` | enum, 3 values | Lifecycle (see below) |
| `assigned_at` | datetime | When the council referred the bezwaar to the committee |
| `deadline` | date | Target advice date — defaults to 12 weeks from `assigned_at` (Awb Art. 7:24(1)) |
| `advice_document` | string (NC file ID or OpenRegister doc ref) | The signed advice |
| `hearing_report_ref` | string | Link to the hoorzitting report (owned by `bezwaar-lifecycle`) |

## Advice request lifecycle

```
assigned ──(panel deliberates, hearing report attached)──► in-deliberation
in-deliberation ──(chair signs advice document, all required content present)──► advice-issued
```

There is intentionally **no `withdrawn` state**: if the bezwaar is withdrawn upstream, the parent `bezwaar-lifecycle` closes the case and the `bac_advice_request` is left in its last state for audit. There is also **no rejection state**: the committee always produces some advice (even if "bezwaar niet-ontvankelijk verklaren") per Awb Art. 7:13(7).

Transitions:

- **`assigned → in-deliberation`** — triggered when the panel is finalized and the hoorzitting report from `bezwaar-lifecycle` is attached. Independence check (REQ-BAC-2) runs here and MAY block.
- **`in-deliberation → advice-issued`** — triggered when the chair (or a delegated chair-replacement) signs the advice document; the advice must satisfy the content contract (REQ-BAC-4) and the panel size must meet `committee.quorum`.

## Advice document content contract (Awb Art. 7:13(7))

The advice document is a structured object stored as a Nextcloud file with a JSON sidecar (`advice.json`). Required fields:

- `findings`: factual findings (relevante feiten) — string, ≥ 50 chars
- `hearing_summary_ref`: pointer to the hoorzittingverslag owned by `bezwaar-lifecycle`
- `legal_assessment`: legal assessment (juridisch oordeel) — string, ≥ 50 chars
- `conclusion`: one of `gegrond`, `ongegrond`, `gedeeltelijk_gegrond`, `niet_ontvankelijk`
- `recommendation`: free-text recommendation to the council
- `dissenting_opinions`: optional array of `{ member_uid, opinion }` blocks when panel disagreement exists
- `signed_by_chair_at`: datetime; the chair's signature timestamp
- `signature_evidence`: ref to e-signature evidence or a wet-ink scan note (Nextcloud file ID)

The JSON sidecar is hidden from end users; the rendered PDF is what gets published. Tasks include verifying that the PDF template renders all required fields.

## Council deviation justification

When the council issues the besluit op bezwaar **and** the decision deviates from the committee's `conclusion`, the besluit object SHALL carry a non-empty `motivatie_afwijking_advies` field. This is enforced by a guard in the `bezwaar-lifecycle` service (out of scope here) — but the spec for that guard lives in REQ-BAC-5 because the requirement originates from the committee capability. The two specs cross-reference each other.

## Audit trail

Every state change on `bac_advice_request` and every mutation of `advice_document` content is recorded by OpenRegister's automatic per-save audit log. Additionally, the following events append explicit entries to a `bac_audit_trail` field on the advice request:

- panel-member-added / panel-member-removed (with reason)
- independence-check-failed (with the failing member + reason)
- advice-signed-by-chair (with chair UID + timestamp + signature evidence ref)
- council-deviation-recorded (with besluit ref + motivatie hash)

Combined with the OpenRegister log, this satisfies Archiefwet (Dutch Public Records Act) accountability for advisory bodies.

## Why a GENERATE-style spec?

The committee is not yet in code. Writing the spec first lets a downstream `opsx-apply` change implement it against a frozen contract, and lets reviewers validate Awb compliance before any PHP is written. If the parent `bezwaar-lifecycle` change ships first, this capability slots in as an extension without invalidating any of `bezwaar-lifecycle`'s requirements.

## Open questions deferred to follow-ups

- **E-signature on the advice document**: the chair's signature is recorded as `signature_evidence`; whether that's a Nextcloud-native e-signature (`signing` app), a Validsign integration, or a scanned wet-ink page is left to a follow-up. Flagged in tasks.
- **Multi-committee rotation**: large councils run multiple committees and round-robin or domain-route between them. Out of scope V1; tracked as a follow-up issue.
