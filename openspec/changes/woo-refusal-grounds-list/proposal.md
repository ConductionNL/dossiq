---
kind: mixed
depends_on: []
---

# Proposal: woo-refusal-grounds-list

Woo capability programme, round 1, wave 1. Rows 12.29 and 13.28.

| row | text | our rating today |
| --- | --- | --- |
| 12.29 | Every change to the list of exception grounds is itself recorded | no |
| 13.28 | The list of exception grounds is a controlled list an administrator maintains, and an entry can sit under another | partial (production) |

Implements Ruben's decision **D3** for the grounds: one list, in dossiq, seeded from filinq's 19
editable grounds after someone settles the count against the law. opencatalogi's constant and
filinq's copy are retired, and both apps read dossiq's list. It also implements **D12**: Woo
requests require dossiq, but the grounds keep a read-only fallback for redaction only. This
differs from the plan's original recommendation, which put the list in OpenRegister's concept
register. Ruben chose dossiq.

## Why

There are four lists of Woo art. 5 grounds in the fleet today, and they disagree:

| where | count | shape |
| --- | --- | --- |
| opencatalogi `WooService::WEIGERINGSGRONDEN` | 15 | PHP constant, flat, unaudited |
| filinq `filinq_register.json`, schema `base` (`grondslagen-woo-art5`, built) | 19 | register objects A to S, editable, audited, slugs `art-5-1-1-a` to `art-5-2-2` |
| dossiq `openspec/specs/woo-case-type/spec.md`, scenario "Mandatory weigeringsgrond" | 12 | prose list |
| dossiq `WOODocumentAssessmentService::VALID_WEIGERINGSGRONDEN` and `lib/Settings/templates/woo-verzoek.json` | 10 | codes `5.1.1` to `5.2.5`, enforced in `validate()`, several labels on the wrong article |

The dossiq code and its own spec disagree on both the number and the codes. A ground a filinq
redaction cites may not exist in opencatalogi's list, and a ground a dossiq assessment stores may
name the wrong article. `design.md` sets the four lists side by side.

## What changes

1. **The law is settled first.** A person with the text of the Woo settles which grounds exist,
   with their article, paragraph and letter, and whether the 2022 to 2024 amendments are in.
   They record the answer in this change's `design.md`. Nothing is seeded before that. This is
   task 1 and it blocks every other task.
2. **One list in dossiq's register**: a new schema `wooRefusalGround` with `code` (for example
   `5.1.2.e`), `article`, `paragraph`, `letter`, `label`, `description`, `parent` (the broader
   entry), `status` (`active` or `retired`), and `legalSource` (a link to the article on
   wetten.overheid.nl). Hierarchy: 5.1, then 5.1.2, then 5.1.2.e, as broader and narrower
   entries.
3. **An administrator maintains it** on a dossiq settings page: add, edit and retire. Nobody
   deletes. A ground already cited cannot be removed, only retired, so old decisions keep their
   ground.
4. **Every change is recorded**: OpenRegister's audit trail on the `wooRefusalGround` objects
   records who changed what and when, and the settings page shows that history per ground.
5. **dossiq reads its own list**: `WOODocumentAssessmentService::validate()` checks grounds
   against the active entries, not against `VALID_WEIGERINGSGRONDEN`. The constant and the
   template's `weigeringsgronden` array are removed. A repair step maps stored assessment codes to
   the new entries. It maps only where the mapping is unambiguous and reports the rest. It never
   guesses.
6. **Other apps read it through one named method**:
   `OCA\Dossiq\Woo\WooRefusalGrounds::list(bool $includeRetired = false): array`, resolved by
   consumers through their fleet-id helper. The return shape is fixed below and tested on both
   sides.
7. **A release-time snapshot** of the active list ships with dossiq as
   `lib/Settings/woo-refusal-grounds.snapshot.json`, written by a script from the seed, with a
   check that fails when the seed and the snapshot differ. opencatalogi and filinq vendor a copy
   for the one fallback D12 keeps: citing a ground on a redacted passage when dossiq is absent.

## What does not change

- How a ground is cited on an assessment or a redaction. The value becomes the ground's `code`.
- opencatalogi's and filinq's own code. Their halves are `filinq/grondslagen-read-from-dossiq`
  (wave 2) and the opencatalogi change that retires `WooService::WEIGERINGSGRONDEN`. Each of them
  tests its side of the call named here.
- The TOOI value lists. Those follow D3's other half, one copy in OpenRegister's concept register.

## Dependencies

None planned before it. `filinq/grondslagen-read-from-dossiq` (wave 2) depends on it.

When dossiq is absent, opencatalogi and filinq use the vendored snapshot read-only, for
redaction only: a ground can be picked and attached, not edited, and their admin pages say where
the list would come from. Nothing blocks a publication or a redaction for want of dossiq (D3,
D12).

## Wave and done

Wave 1. Done means merged on `development` with CI green. Then 12.29 and 13.28 read `yes` (build),
and `production` once a dossiq store release carries them. The task 1 decision is a precondition
and is recorded in `design.md` before the PR leaves draft.
