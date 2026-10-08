---
status: done
---

# features-roadmap Specification

## Purpose

Two different questions, kept apart. "What can dossiq do, and is it stable" is
the feature list and the roadmap, on the in-app Features & roadmap page. "How
does dossiq compare to the alternatives" is the capability comparison, on the
public docs site. Losing either one to the other is a regression: the first is
what a user checks before relying on a capability, the second is what a
procurement officer checks before choosing a system at all.

The comparison was a second tab on the in-app page until 2026-10-07. Ruben
ruled that how an app compares belongs on its public site and not in the app,
for every app. The in-app page links to it, so it stays one click away.

The comparison is a vendor-authored comparison of other people's software, so
the comparison page states its own limits before it states a score.

**Surfaces**:

- In the app: `src/manifest.json#FeaturesRoadmap` (`type: "custom"`,
  `component: "FeaturesRoadmapView"`), route `/features-roadmap`, reachable
  from the footer menu entry `FeaturesRoadmapMenu`. No top-level navigation
  entry: ADR-097 caps the main menu at six and the page has never needed one.
- On the docs site: `docs/src/pages/compare.js`, served in English at
  `https://dossiq.conduction.nl/compare` and in Dutch at `/nl/compare`, and
  linked from the navbar. It renders
  `docs/src/components/CapabilityComparison`, which imports
  `openspec/parity/capabilities.json` and `src/utils/capabilityComparison.js`
  directly. Nothing is copied at build time, so the docs page and the data
  cannot drift.

## Requirements

### Requirement: The page MUST keep the shipped feature list and the roadmap

The page SHALL render the library's `CnFeaturesAndRoadmapPage` with the feature
list dossiq provides through initial state (`features_roadmap_features`, from
`docs/features.json`, ADR-018). Linking to the comparison SHALL NOT remove or
replace the features tab or the roadmap tab.

### Requirement: The page MUST link to the comparison on the public site

The in-app page SHALL NOT render the capability comparison. It SHALL carry one
link to the comparison page on the docs site, so the surface that left the app
stays reachable from where a user used to find it. The link SHALL open in a new
tab, SHALL say so in text tied to the link, and SHALL point a Dutch reader at
the Dutch page.

#### Scenario: The comparison is one link away

- **GIVEN** a user opens `/features-roadmap`
- **WHEN** the page loads
- **THEN** the page MUST NOT render the comparison tables or its caveats
- **AND** a link reading "How dossiq compares to other case systems" MUST point at `/compare` on the docs site
- **AND** the link MUST open in a new tab

#### Scenario: Features page renders controls

- **GIVEN** a user opens `/features-roadmap`
- **WHEN** the page loads
- **THEN** the features section MUST be the section that is shown
- **AND** the shipped feature cards MUST be visible
- **AND** the roadmap toggle and the suggest-a-feature link MUST be reachable

### Requirement: The page MUST present the capability comparison by area

The comparison page on the docs site SHALL compare dossiq against the systems
in `openspec/parity/capabilities.json` across every capability in that file.
Capabilities SHALL be grouped by their area, and an area SHALL open to reveal
its rows. Each row SHALL show its number, the capability, and a rating for
every system.

A rating SHALL be conveyed by a word, never by colour alone.

A rating MAY be `unknown`, and `unknown` SHALL be rendered and counted like any
other rating rather than left blank. A later round can add a capability row
without re-reading the products an earlier round rated, and the honest cell for
those products is one that says nobody looked. Hiding it would show a reader
the other systems scored over fewer rows than ours with nothing to explain the
difference.

A reader SHALL be able to search the rows by name or number and filter them by
dossiq's rating. The totals SHALL stay over the whole list while a filter is
on, because a total over a filtered list reads as a different score.

@e2e exclude The comparison renders on the Docusaurus site, which the app's Playwright suite does not drive. The grouping and tallies are asserted in tests/vitest/capabilityComparison.spec.js, and `npm run build` in docs/ prerenders the page.

#### Scenario: Areas summarise before they expand

- **GIVEN** a user opens the comparison section
- **WHEN** the areas are listed
- **THEN** each area MUST state how many capabilities it holds and how dossiq scored
- **AND** the individual rows MUST stay collapsed until the reader opens that area

### Requirement: The comparison MUST state its own limits

The comparison page SHALL state, before any score:

1. That only open source software the team could install and run itself was
   compared, that the named systems are the whole field, and that a product's
   absence is not a verdict on that product.
2. The date the comparison was made, and that some ratings are already out of
   date because open source moves fast.
3. That a rating is the team's own reading and is not proof that a product does
   or does not have a capability.
4. A plain recommendation that the reader run their own evaluation, and that
   this table does not replace testing against their own requirements. This one
   is not optional and it is not a restatement of item 3: item 3 tells the
   reader what to discount, and this tells them what to do about it. A panel
   that discounts itself three times and never says "go and test" reads as
   hedging.
5. The first concrete step: shortlist the capabilities they need and test every
   system against that shortlist.
6. When any rating in our own column has been corrected since the reading, how
   many were corrected and when, and that the competitor columns are NOT
   corrected that way. Re-rating a competitor without re-reading the product
   would be a guess presented as a correction. The count SHALL be the ratings
   that actually MOVED. The change log holds added rows as well, and counting
   those as corrections overstates our own diligence on the one panel whose
   job is to caveat itself.
7. When rows have been added to the list since the reading, how many, when,
   and that the competitor columns are unrated on them. This is the same
   rule as item 6 pointed at the list instead of at a score: we may re-rate
   ourselves because we can read our own code, and we may not rate a product
   we did not open. A guess in a competitor's column is worse than an empty
   cell, because a reader cannot tell the two apart.
8. What the capability list is made of, that it is written in our own shape,
   and that it grows. Every system read so far is a municipal case system or a
   workflow engine, so a capability none of them has is absent from the LIST
   rather than from the market. The rows are also framed the way dossiq splits
   the work, so a product that splits it differently scores low without being
   worse: a system that owns no data because the record and its retention live
   in a separate register loses rows to its architecture. **That bias runs in
   our favour, which is exactly why the panel has to declare it.** A total that
   flatters us for a structural reason is worth less than no total. And without
   the growth clause, a reader who watches the totals fall between two releases
   has no way to tell a growing denominator from a regressing product, and the
   honest reading is the one they cannot reach.
9. Beside any column that needs reading differently, and before the first
   score: the date that column was read when it is not the date in item 2, and
   the register a product defers to when it owns no data of its own. Item 8
   states the bias in general; this states it against the named column a reader
   is about to compare with, which is the only form they can act on. A caveat
   they meet after the totals is a caveat they meet too late.

@e2e exclude The comparison renders on the Docusaurus site, which the app's Playwright suite does not drive. Every caveat is asserted against the module the page renders from in tests/vitest/capabilityComparisonCopy.spec.js.

#### Scenario: An added row is not counted as a correction

- **GIVEN** the change log holds both corrected ratings and added rows
- **WHEN** the panel says how many of our ratings we corrected
- **THEN** the count MUST be the entries that moved a rating
- **AND** the date MUST be the day those entries were made

#### Scenario: A column read on its own day does not move the shared date

- **GIVEN** a system is added to the comparison and was read after `comparedOn`
- **WHEN** the page states when the systems were read
- **THEN** `comparedOn` MUST keep the date the columns it already covers were read
- **AND** the sentence MUST count only the systems that date covers
- **AND** the new column MUST state its own reading date beside itself

#### Scenario: A column that owns no data says so beside itself

- **GIVEN** a system in the comparison keeps no record of its own
- **WHEN** a reader opens the comparison
- **THEN** the panel MUST name that system and the register that holds the record
- **AND** it MUST say the column scores low for that reason
- **AND** it MUST say that reason runs in our favour
- **AND** the note MUST appear before the totals table, not after it

#### Scenario: The panel accounts for rows a later round added

- **GIVEN** a round has added capability rows since the rated systems were read
- **WHEN** a reader opens the comparison
- **THEN** the panel MUST say how many rows were added and when
- **AND** it MUST say that every competitor column is unrated on those rows
- **AND** those rows MUST show `unknown` for every competitor, never a guess

#### Scenario: More than one round has added rows

- **GIVEN** rows were added by two or more rounds, on different days
- **WHEN** the panel reports them
- **THEN** each row MUST carry the date of the round that added it
- **AND** `rowsAddedOn` MUST be the most recent of those dates, not the only one
- **AND** the panel MUST NOT state one date for rows added on several
- **AND** the count it reports MUST cover every round, because the totals do

#### Scenario: A new area is declared with rows in it

- **GIVEN** a round adds an area for a capability the existing areas cannot hold
- **WHEN** the data declares that area
- **THEN** at least one row MUST be filed under it
- **AND** the area MUST be appended, so the row numbering stays the render order

#### Scenario: The panel says what the list is made of

- **GIVEN** a reader opens the comparison
- **WHEN** they read the panel
- **THEN** it MUST name the kind of system every rated product is
- **AND** it MUST say that a product shaped differently scores low without being worse
- **AND** it MUST say that the list grows, so a later total is not comparable to an earlier one

#### Scenario: The panel advises the reader to test for themselves

- **GIVEN** a reader opens the comparison
- **WHEN** they read the panel
- **THEN** it MUST recommend that they run their own evaluation
- **AND** it MUST say that the table does not replace testing against their own requirements

#### Scenario: A reader can date the claim

- **GIVEN** a reader opens the comparison
- **WHEN** they read the disclaimer
- **THEN** the date the comparison was made MUST be shown in their own language

### Requirement: The comparison data MUST match the audit it came from

`openspec/parity/capabilities.json` is re-issued offline from a private
audit repository, so no CI job can regenerate it and diff the result. The data
SHALL therefore be guarded by assertions: the row count, unique ids, every row
filed under a declared area, every rating drawn from the known set, and the
per-system totals the audit published.

Two further assertions guard the `unknown` rating, because it is the one value
that can be written for the wrong reason. Our own column SHALL never be
`unknown`: we can read our own code, so an empty cell there is an unfinished
row that understates our score for free. And a row carrying a competitor
`unknown` SHALL carry `addedOn`, while a row carrying `addedOn` SHALL be
`unknown` for every competitor, the column added last included: it was read
before those rows existed, so it is as empty on them as the rest. That pins the value to its only honest cause.

Every caveat SHALL additionally be asserted against the module the docs page
renders from (`docs/src/components/CapabilityComparison/comparisonCopy.js`), not
only by building the site. Each one is a plain paragraph inside a note card, and deleting
one while editing the panel around it breaks nothing a build can see. The
caveats bound to a named column SHALL be asserted from the data that declares
them, so removing the declaration removes the caveat AND reddens the test
instead of quietly removing only the caveat.

@e2e exclude Guarded by assertions over the committed data file in tests/vitest/capabilityComparison.spec.js, which no browser can reach: the failure mode is an edited JSON row, not a rendered screen.

#### Scenario: A guessed competitor rating fails

- **GIVEN** a row added after the rated systems were read
- **WHEN** somebody fills a competitor cell on it with a rating
- **THEN** the unit suite MUST fail and name the row

#### Scenario: An edited row changes a total and fails

- **GIVEN** a capability row is edited by hand
- **WHEN** the unit suite runs
- **THEN** the tally assertion for the affected system MUST fail

### Requirement: Every user-visible string MUST exist in Dutch

Capability names and area names SHALL carry a Dutch variant beside the English
one (`name_nl` beside `name`), the shape `docs/features.json` already uses. The
page SHALL render the Dutch variant for a Dutch locale and fall back to English
when a Dutch variant is absent or blank. The docs page SHALL render Dutch under
the site's `nl` locale, with its sentences from the `NL` table in
`docs/src/components/CapabilityComparison/comparisonCopy.js`. The in-app link
SHALL be translated through `t('dossiq', …)` and `l10n/nl.json`.

@e2e exclude The e2e instance runs one locale, so a Dutch render cannot be driven there. The locale selection is asserted directly in tests/vitest/capabilityComparison.spec.js (groupByArea with nl).

#### Scenario: A Dutch reader gets a Dutch table

- **GIVEN** a user whose locale is Dutch
- **WHEN** they open the comparison
- **THEN** the area names and the capability names MUST render in Dutch
