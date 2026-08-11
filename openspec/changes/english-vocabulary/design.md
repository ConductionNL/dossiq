## Context

Token-aware scan: **75 schemas / 315 Dutch properties** across 12 register files, and the
largest code surface in the fleet — **84 files, 55 classes, 198 methods**.

procest is also the app everything else waits on. Four apps hold `zaakId` as a foreign
key into it: openconnector, docudesk, pipelinq and (via `zaaktype`) pipelinq again. The
ratified application order puts procest first for exactly this reason.

The vocabulary is ZGW — the Dutch government's case-handling API family (ZRC, DRC, BRC,
ZTC, NRC) — plus sociaal domein (Wmo, Jeugdwet, Participatiewet), subsidie, mandaat and
termijnbewaking. Much of it is wire.

## Goals / Non-Goals

**Goals:**

- Rename procest's own domain vocabulary and unblock the four dependent apps.
- Keep ZGW resource names at the protocol boundary, where they are the contract.
- Resolve the `Besluit` / `Decision` self-collision *before* renaming anything.

**Non-Goals:**

- Renaming ZGW API resource names as they appear in URLs, payloads and the
  `zaakLk01`/`edcLk01` StUF envelope.
- Renaming the six ORI schemas without first settling the openregister collision.
- Landing `Zaak` → `Case` without the other three apps in the same window.

## Decisions

### 1. ⚠️ `Besluit` → `Decision` is NOT safe. procest already has a `decision` schema.

The fleet policy ratified "procest gets `Decision` (the instrument)". That decision was
made without looking at procest's register, and it is wrong.

`procest_register.json` already declares:

| schema | description |
|---|---|
| `decision` | **"A formal decision on a case"** |
| `decisionType` | "Decision type definition for a case type" |
| `decisionDocument` | "Links a document to a decision" |

alongside `besluit` and `besluitinformatieobject` ("ZGW DRC join between a besluit
(decision) and an informatieobject"). **The same concept is already modelled twice, in two
languages.**

Renaming `Besluit` → `Decision` is therefore not a rename — it is a **merge of two
schemas**, and under ADR-037 fragment merging the two declarations would be combined with
their list values concatenated. That is the precise mechanism that produced shillinq#485's
unsatisfiable schema.

**Decision: do not rename `Besluit` to `Decision` in this change.** Two options, and
choosing between them is a modelling decision that needs a human:

- **(a) Deliberate merge** — `besluit` and `decision` really are one concept, so unify
  them, pick one property set, and migrate. Correct, and much larger than a rename.
- **(b) Distinct names** — `Besluit` becomes `FormalDecision` (ZGW instrument) and
  `decision` keeps its name.

⚠️ decidesk also declares `Decision` — the ADR-005 governance supertype, a *third*
concept. Since slug resolution is instance-global, whichever option is chosen must be
checked against decidesk too.

### 2. ZGW resource names stay at the protocol boundary

`BrcController`, `DrcController`, `ZrcClient`, `zaakinformatieobject`,
`besluitinformatieobject`, `zaaktypeInformatieobjecttype` — these are ZGW API resources
and joins, named as the standard names them. `createBesluitInformatieObject` posts to a
ZGW endpoint of that name.

**Decision:** the controllers and the resource-shaped schemas keep ZGW vocabulary; the
methods that express *procest's own* logic around them are renamed. Each of the 198
methods is classified individually — `nieuweZaak` (ours → `newCase`) is not
`createBesluitInformatieObject` (a protocol operation).

This is the largest single piece of work in the change and it cannot be scripted.

### 3. `Zaak` → `Case` moves with four apps, or not at all

procest owns the name. openconnector, docudesk and pipelinq hold `zaakId`; pipelinq also
holds `zaaktype`. Every one of them reads with a null-coalescing default, so a
unilateral rename yields `null` in three other apps with no test failing anywhere.

**Decision:** `Zaak` → `Case`, `zaakId` → `caseId`, `zaaktype` → `caseType`, landed as one
coordinated window across four repos. The dependent apps' changes already record the block.

⚠️ `hoofdzaak`/`deelzaak` (`createHoofdzaakWithDeelzaken`, `DeelzaakController`) are the
ZGW parent/child case relation → `parentCase`/`subCase`.

### 4. The six ORI schemas collide with openregister

`procest/lib/Settings/ori_register.json` declares `vergadering`, `agendapunt`,
`raadsdocument`, `stemming`, `raadslid`, `fractie` — **the same six slugs as
openregister's ORI Mock Register**. procest's is an operational register ("for Procest…
includes demo data"); openregister's is explicitly a mock of the VNG ODS-Open-
Raadsinformatie specification.

**Decision:** do not rename these six in this change. They are a pre-existing six-way
collision, and openregister's copies are marked wire-exempt. Renaming procest's half
would leave two apps with divergent vocabularies for one slug — worse than the collision.
Escalated to the fleet change.

### 5. Sociaal domein and subsidie: statutory, English name plus marker

`wmoZaak`, `jeugdwetZaak`, `participatiewetZaak` name three Dutch social-care statutes
with no clean international counterpart. `subsidieRegeling`/`subsidieAanvraag`,
`mandaatRegeling`/`mandateringsBesluit`, `dwangsomUitbetaling`, `beschikking`,
`bezwaartermijn`, `avgClassificatie`, `toestemming`.

**Decision:** English names plus statute markers — `WmoCase`, `YouthActCase`,
`ParticipationActCase` (each marked with its act), `GrantScheme`/`GrantApplication`,
`MandateScheme`/`MandateDecision`, `PenaltyPayment`, `Ruling`, `ObjectionPeriod`,
`GdprClassification`, `Consent`.

`avgClassificatie` → `GdprClassification`, not `AvgClassification`: the AVG *is* the GDPR,
so this is internationalisation, not a statutory carve-out.

### 6. `termijn` is not one word

`termijnDefinitie`, `termijnInstance`, `termijnGebeurtenis`, `TermijnController`,
`BezwaarTermijnJob`, `DailyTermijnScanJob`, `withinTermijn` — here `termijn` is a
**statutory deadline** being monitored, not a contract term. → `Deadline*`:
`DeadlineDefinition`, `DeadlineInstance`, `DeadlineEvent`.

⚠️ hrmq's `aanzegtermijn`/`vervaltermijn` are *notice periods*, and decidesk's
`TermijnRegeling` is a *term of office*. Three apps, three meanings, one Dutch word —
the `regel` situation again. It must not become a fleet word.

## Risks / Trade-offs

- **`Besluit` → `Decision` lands as specified and merges two schemas** → an unsatisfiable
  schema, discovered at seed or payload time rather than at rename time. Mitigated by
  decision 1 blocking it outright.
- **A unilateral `zaakId` rename** → three apps silently read `null`. Mitigated by the
  four-app window.
- **A ZGW protocol operation is renamed** → the adapter posts to an endpoint that does
  not exist, and the failure looks like an empty result. Mitigated by per-method
  classification.
- **198 methods invite a scripted rename** → prefix corruption is a known outcome
  (`verplichting`→`commitment` once turned `openstaande_verplichtingen` into
  `openstaande_commitmenten` and rewrote `@spec` paths). Mitigated by anchored, per-file
  edits only.
- **`strtolower()`-compared literals** → a camelCase rename makes a lowercase comparison
  unsatisfiable forever; PHPStan catches it, tests do not. procest has lifecycle guards of
  exactly this shape.
- **Register fragments wire guards and listeners by class name** → a renamed class stops
  being wired, silently, with no error. Every fragment `handler`/`guard`/`preconditions`
  entry must be updated with the class.

## Migration Plan

1. **Resolve decision 1** — merge or distinct names for `besluit`/`decision`. Blocking.
2. Classify the 198 methods and 55 classes as protocol-facing or ours.
3. Count stored objects across 75 schemas.
4. Rename app-local vocabulary (sociaal domein, subsidie, mandaat, termijn) with markers.
5. Rename classes/methods; update every register fragment that wires by class name.
6. **Four-app window:** `Zaak`/`zaakId`/`zaaktype` with openconnector, docudesk, pipelinq.
7. Leave the six ORI slugs alone pending the fleet decision.

**Rollback:** steps 4–5 are app-local. Step 6 is a four-repo window and reverts only as a
set.

## Open Questions

- **Blocking:** is `besluit` the same concept as procest's existing `decision`? A human
  modelling call, not a rename decision.
- Which of the 198 Dutch methods are ZGW protocol operations? The scan counts them; the
  classification does not exist yet and is the bulk of the work.
- Do the six ORI schemas belong in procest at all, given openregister ships them as a
  mock of the same specification?
