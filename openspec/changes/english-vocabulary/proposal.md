# English vocabulary for procest — ZGW/Jeugdwet domain

> Implements the fleet policy in `hydra/openspec/changes/fleet-english-vocabulary`.
> Read §2 (internationalise, don't transliterate) and §4 (statutory marker) first —
> procest is the app that exercises §4 hardest.

## Why

An anchored scan found **28 Dutch-named schemas and 149 Dutch property names**
in procest — the second-largest concentration in the fleet. All code is English;
procest is furthest from that.

procest is also the hard case. Its vocabulary is not casual Dutch, it is **ZGW
and Jeugdwet statutory vocabulary**: `besluitinformatieobject`, `jeugdwetZaak`,
`mandateringsBesluit`, `handhavingsactie`, `adviesAanvraag`. Some of those name
concepts every jurisdiction has (a case, a decision, an inspection); others name
Dutch law itself.

## What changes

### Internationalised (§2) — the concept exists everywhere

| Dutch | English |
|---|---|
| `adviesAanvraag` | `AdviceRequest` |
| `bewijsstuk` | `Evidence` |
| `handhavingsactie` | `EnforcementAction` |
| `inspectieChecklist` / `inspectieRapport` | `InspectionChecklist` / `InspectionReport` |
| `klantSentiment` | `CustomerSentiment` |
| `medewerkerRolToewijzing` | `EmployeeRoleAssignment` |
| `mandaat*` family | `Mandate*` (matches shillinq — one English word per concept) |
| `termijn` | `term` / `deadline` (disambiguate per use) |

### Statutory marker (§4) — English name, law recorded

`Zaak`, `Besluit`, `Besluitinformatieobject`, `JeugdwetZaak` and the ZGW family
get English identifiers plus:

```json
"x-statutory-basis": { "jurisdiction": "NL", "instrument": "ZGW / Jeugdwet", "term": "Zaak" }
```

The Dutch term is preserved **as data in the marker**, not as an identifier — so
the law it implements is still discoverable, and the code is still English.

⚠️ `Zaak` → `Case` is a fleet-wide word. It must match whatever
zaakafhandelapp and openklant use, or two apps will disagree about the same
concept. Confirm before applying.

### Dutch → l10n (§3)

Every renamed `title`/`description` gets its Dutch original in `l10n/nl.json`,
keyed on the new English string. `check-l10n` must pass, and existing keys must
be **re-pointed, not re-extracted**, or the Dutch is lost.

### Code, not just schemas (§5)

Walk every `lib/` and `src/` file: class names, method names, **file names**,
array keys, filter keys, enum literals, lifecycle transition names, route ids,
manifest page ids.

## Tasks

- [ ] Inventory: list every schema and every lib/+src/ file with a Dutch
      identifier, and record the real count (do not inherit the scan estimate).
- [ ] Agree the ZGW word list with zaakafhandelapp/openklant before renaming.
- [ ] Rename schemas + properties + enum values, fragment by fragment.
- [ ] Add `x-statutory-basis` to every statutory schema.
- [ ] Rename classes, methods and files; update DI registrations and any
      register fragment that wires a guard/listener **by class name**.
- [ ] Diff every filter/read key against the new schema — a missed key fails
      silently, never loudly.
- [ ] Add Dutch translations to `l10n/nl.json`; run `check-l10n`.
- [ ] Data migration (procest has live data — reseed is not an option).
- [ ] Run the full unit suite, `validate-seeds`, `validate-registers`, and
      hydra gates 46/53/54/55/57/61 before opening the PR.

## Risks

- Renamed keys read with `??` become permanently absent rather than erroring.
- ZGW terms are shared across apps — a unilateral rename desynchronises them.
- `x-statutory-basis` is a new extension key; confirm OpenRegister tolerates
  unknown `x-` keys on a schema before relying on it.
