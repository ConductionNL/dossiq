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

#### Scenario: Features page renders controls

- **GIVEN** a user opens `/features-roadmap`
- **WHEN** the page loads
- **THEN** the features section MUST be the section that is shown
- **AND** the shipped feature cards MUST be visible
- **AND** the roadmap toggle and the suggest-a-feature link MUST be reachable

### Requirement: The page MUST link to the comparison on the public site

The in-app page SHALL NOT render the capability comparison. It SHALL carry one
link to the comparison page on the docs site, so the surface that left the app
stays reachable from where a user used to find it. The link SHALL open in a new
tab, SHALL say so in text tied to the link, and SHALL point a Dutch reader at
the Dutch page. The comparison itself, its limits, its data check and its
Dutch strings are specified in `docs-product-pages`, the spec of the docs site
that renders it.

#### Scenario: The comparison is one link away

- **GIVEN** a user opens `/features-roadmap`
- **WHEN** the page loads
- **THEN** the page MUST NOT render the comparison tables or its caveats
- **AND** a link reading "How dossiq compares to other case systems" MUST point at `/compare` on the docs site
- **AND** the link MUST open in a new tab
