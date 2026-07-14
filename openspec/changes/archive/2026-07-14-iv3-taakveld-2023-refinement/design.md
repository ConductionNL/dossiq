# Design: iv3-taakveld-2023-refinement

## 1. Source verification (web research, 2026-07-14)

Primary sources, both retrieved and read directly (full text extracted from the PDFs, not taken
from a secondary summary):

1. **"Iv3-Informatievoorschrift Gemeenten en Gemeenschappelijke regelingen 2023 1.0"**
   (rijksoverheid.nl, 80 pages) —
   `https://www.rijksoverheid.nl/binaries/rijksoverheid/documenten/richtlijnen/2023/12/15/iv3-informatievoorschrift-gemeenten-en-gemeenschappelijke-regelingen-2022/iv3-informatievoorschrift-gemeenten-en-gren-2023-1-0.pdf`
   — section 1 "Belangrijkste wijzigingen ten opzichte van de vorige versie" (blad 4-5, the
   official change-list table) and the full per-taakveld definitions for taakveld 6 (blad 27-34).
2. **"Veel gestelde vragen verfijning Iv3 jeugd en Wmo"** (rijksoverheid.nl FAQ, version 2,
   2022-10-31) —
   `https://www.rijksoverheid.nl/binaries/rijksoverheid/documenten/richtlijnen/2023/12/15/iv3-informatievoorschrift-gemeenten-en-gemeenschappelijke-regelingen-2022/veelgestelde-vragen-verfijning-iv3-jeugd-en-wmo-versie+2-20221031.pdf`
   — used to disambiguate a wording inconsistency between the summary table and the detail
   sections (see §2).

Blad 4 verbatim (the official change-list, canonical for the exact new code → label mapping used
in `iv3_taakvelden.json`):

> De taakvelden op het gebied van individuele voorzieningen WMO en Jeugdzorg worden opgedeeld.
> - Het taakveld 6.71 Maatwerkdienstverlening 18+ wordt opgedeeld in vier nieuwe taakvelden:
>   6.71a – Hulp bij het huishouden (WMO); 6.71b – Begeleiding (WMO); 6.71c – Dagbesteding (WMO);
>   6.71d – Overige maatwerkarrangementen (WMO)
> - Het taakveld 6.72 Maatwerkdienstverlening 18- wordt opgedeeld in tien nieuwe taakvelden:
>   6.72a – Jeugdhulp begeleiding; 6.72b – Jeugdhulp behandeling; 6.72c – Jeugdhulp dagbesteding;
>   6.72d – Jeugdhulp zonder verblijf overig; 6.73a – Pleegzorg; 6.73b – Gezinsgericht;
>   6.73c – Jeugdhulp met verblijf overig; 6.74a – Jeugdhulp behandeling GGZ zonder verblijf;
>   6.74b – Jeugdhulp crisis/LTA/GGZ-verblijf; 6.74c – Gesloten plaatsing
> - Het taakveld 6.81 Geëscaleerde zorg 18+ wordt opgedeeld in twee nieuwe taakvelden:
>   6.81a Beschermd wonen (WMO); 6.81b Maatschappelijke- en vrouwenopvang (WMO)
> - Het taakveld 6.82 Geëscaleerde zorg 18- wordt opgedeeld in twee nieuwe taakvelden:
>   6.82a Jeugdbescherming; 6.82b Jeugdreclassering
>
> Voor zowel de begroting 2023 als de jaarrekening 2023 moeten de nieuwe gedetailleerde
> taakvelden WMO en Jeugd worden ingevuld... Verantwoording op de 'oude' taakvelden 6.71, 6.72,
> 6.81 en 6.82 is voor de begroting en jaarrekening niet meer toegestaan.

Blad 5 verbatim (the two renames — a *different* change from the split above, same document):

> Twee bestaande taakvelden hebben een nieuwe naam gekregen om te verduidelijken wat erop moet
> worden geboekt.
> - De naam van het taakveld 6.2 Wijkteams is gewijzigd naar 6.2 Toegang en
>   eerstelijnsvoorzieningen;
> - De naam van het taakveld 6.4 Begeleide participatie is gewijzigd naar 6.4 WSW en beschut
>   werk;

## 2. A minor label-wording inconsistency in the source itself, resolved in favour of the summary table

The per-taakveld detail sections (blad 27-34) use marginally different wording for two labels than
the official summary table (blad 4):

| Code | Blad 4 (summary) | Blad 29/32 (detail heading) |
|---|---|---|
| 6.71a | Hulp bij het huishouden (WMO) | Huishoudelijke hulp (WMO) |
| 6.74a | Jeugdhulp behandeling GGZ zonder verblijf | Jeugd behandeling GGZ zonder verblijf |

Both refer to the same taakveld (same code, same iWMO/iJw product-code mapping in the detail
text) — this is a copy-editing inconsistency within the v1.0 draft itself, not two different
codes. `iv3_taakvelden.json` uses the **blad 4 summary-table wording**, since that section is
explicitly the document's own canonical "what changed" announcement (and is the wording echoed
verbatim by the FAQ PDF's Q6 answer, which cross-references 6.72a/6.72b/6.74a using that same
phrasing) — see the FAQ excerpt in `proposal.md`/this file's citation for corroboration.

## 3. `deprecated` / `aggregatesUnder` design — direction and rationale

The task frame ("old codes remain resolvable... aggregation groups them under their successors")
does not by itself resolve a real ambiguity: one pre-2023 code splits into *several* new codes
(e.g. `6.72` → ten codes), so there is no single well-defined "successor" for an old-code-tagged
case. The reverse direction — every **new** code has exactly **one** well-defined pre-2023
**parent** (per the blad-4 table above) — is unambiguous. This change therefore implements:

- `deprecated: true` on the 4 pre-2023 codes (`6.71`, `6.72`, `6.81`, `6.82`) — a UI/reporting
  hint only; `isValidCode()`/`labelFor()` are completely unaffected (a case tagged with a
  deprecated code keeps resolving exactly as before).
- `aggregatesUnder: "<parent-code>"` on each of the 18 new 2023-refinement codes, pointing at its
  single well-defined pre-2023 parent.
- `Iv3TaakveldList::aggregationKeyFor(string $code): string` — the one method
  `Iv3ReportService` (and any future consumer) calls to resolve a taakveld code to its report
  bucket key: a refinement code resolves to its parent; a deprecated parent code (and every
  ordinary, unaffected code) resolves to itself; an unrecognised code passes through unchanged
  (fail-open, consistent with `labelFor()`'s existing null-on-unknown contract elsewhere in this
  class).

Net effect: a municipality's quarterly IV3 report stays trend-continuous through the 2023
transition — cases classified `6.72` before the cutover and cases classified `6.72a`/`6.73a`/`6.74b`
after it all land in the same `perTaakveld['6.72']` bucket, labelled with the parent's label
("Maatwerkdienstverlening 18-"). A future change could add a *second*, opt-in "refined view" that
buckets by the raw code instead — out of scope here; `aggregationKeyFor()` is additive and does
not remove the ability to read the raw `caseType.iv3Taakveld` value directly.

## 4. Why the 6.2/6.4 renames are included in this change

Strictly, the task only asked for the taakveld-6 *split*. But the two renames are announced in the
exact same official document, on the page immediately following the split table, and both were
directly encountered while doing the mandated source verification for the split. Per project
convention (fix pre-existing/adjacent inaccuracies encountered during a task rather than deferring
them), both labels are corrected here — this is a pure label-text change (same code, same
`isValidCode()`/aggregation behaviour), not a structural change, so the risk is negligible.

## 5. Testing boundary

- `Iv3TaakveldListTest`: list-integrity regex updated to allow a trailing lowercase letter
  (`6.71a`); deprecated-code resolution (`isValidCode`/`labelFor`/`isDeprecated` on `6.72`);
  refinement-code non-deprecation; `aggregationKeyFor()` for every one of the 18 new codes, for a
  deprecated parent (aggregates under itself), for an unaffected code, and for an unknown code;
  the two renamed labels.
- `Iv3ReportServiceTest`: mixed old+new taakveld aggregation into one bucket (one case tagged
  `6.72`, one tagged `6.72a` → single `6.72` bucket, combined totals, parent label); two different
  refinement successors of the same parent (`6.73a` + `6.74b`) also aggregate together.
