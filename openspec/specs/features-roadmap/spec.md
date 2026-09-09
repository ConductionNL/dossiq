---
status: done
---

# features-roadmap Specification

## Purpose

The Features & roadmap page answers two different questions, and it keeps them
apart. "What can dossiq do, and is it stable" is the feature list and the
roadmap. "How does dossiq compare to the alternatives" is the capability
comparison. Losing either one to the other is a regression: the first is what a
user checks before relying on a capability, the second is what a procurement
officer checks before choosing a system at all.

The comparison is a vendor-authored comparison of other people's software, so
the page states its own limits before it states a score.

**Surface**: `src/manifest.json#FeaturesRoadmap` (`type: "custom"`,
`component: "FeaturesRoadmapView"`), route `/features-roadmap`, reachable from
the footer menu entry `FeaturesRoadmapMenu`. No top-level navigation entry:
ADR-097 caps the main menu at six and the page has never needed one.

## Requirements

### Requirement: The page MUST keep the shipped feature list and the roadmap

The page SHALL render the library's `CnFeaturesAndRoadmapPage` with the feature
list dossiq provides through initial state (`features_roadmap_features`, from
`docs/features.json`, ADR-018). Adding the comparison SHALL NOT remove or
replace the features tab or the roadmap tab.

#### Scenario: Features page renders controls

- **GIVEN** a user opens `/features-roadmap`
- **WHEN** the page loads
- **THEN** the features section MUST be the section that is shown
- **AND** the shipped feature cards MUST be visible
- **AND** the roadmap toggle and the suggest-a-feature link MUST be reachable

### Requirement: The page MUST present the capability comparison by area

The page SHALL offer a second section that compares dossiq against the systems
in `src/data/capabilityComparison.json` across every capability in that file.
Capabilities SHALL be grouped by their area, and an area SHALL open to reveal
its rows. Each row SHALL show its number, the capability, and a rating for
every system.

A rating SHALL be conveyed by a word, never by colour alone.

#### Scenario: Areas summarise before they expand

- **GIVEN** a user opens the comparison section
- **WHEN** the areas are listed
- **THEN** each area MUST state how many capabilities it holds and how dossiq scored
- **AND** the individual rows MUST stay collapsed until the reader opens that area

### Requirement: The comparison MUST state its own limits

The comparison section SHALL state, before any score:

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
   many were corrected and when, and that the other three columns are NOT
   corrected that way. Re-rating a competitor without re-reading the product
   would be a guess presented as a correction.
7. What the capability list is made of, that it is written in our own shape,
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

`src/data/capabilityComparison.json` is generated once, offline, from a private
audit repository, so no CI job can regenerate it and diff the result. The data
SHALL therefore be guarded by assertions: the row count, unique ids, every row
filed under a declared area, every rating drawn from the known set, and the
per-system totals the audit published.

@e2e exclude Guarded by assertions over the committed data file in tests/vitest/capabilityComparison.spec.js, which no browser can reach: the failure mode is an edited JSON row, not a rendered screen.

#### Scenario: An edited row changes a total and fails

- **GIVEN** a capability row is edited by hand
- **WHEN** the unit suite runs
- **THEN** the tally assertion for the affected system MUST fail

### Requirement: Every user-visible string MUST exist in Dutch

Capability names and area names SHALL carry a Dutch variant beside the English
one (`name_nl` beside `name`), the shape `docs/features.json` already uses. The
page SHALL render the Dutch variant for a Dutch locale and fall back to English
when a Dutch variant is absent or blank. Page chrome SHALL be translated
through `t('dossiq', …)` and `l10n/nl.json`.

@e2e exclude The e2e instance runs one locale, so a Dutch render cannot be driven there. The locale selection is asserted directly in tests/vitest/capabilityComparison.spec.js (groupByArea with nl).

#### Scenario: A Dutch reader gets a Dutch table

- **GIVEN** a user whose locale is Dutch
- **WHEN** they open the comparison
- **THEN** the area names and the capability names MUST render in Dutch
