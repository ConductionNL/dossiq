## ADDED Requirements

### Requirement: An original and the file that went out are compared side by side (REQ-DCP-001)

Generic capability (decision 182), configured per case type. Any object that holds items with an
`originalRef` and a `deliveredRef` (a frozen file set of a publication) SHALL be able to show its
items with file name, classification and SHA-256 through the `file-set-items` widget, and SHALL offer
Compare on an item whose `deliveredRef` differs from its `originalRef`. The case type configures the
widget in its manifest `content`: `filesUrl` (the endpoint with `{case}`, `{set}` and `{index}`) and
`classificationLabels`; without `filesUrl` no Compare is offered. GET on `filesUrl` SHALL answer
`{original: {fileName, mimeType, readable}, delivered: {...}}` and `<filesUrl>/original` and
`<filesUrl>/delivered` SHALL answer the bytes, behind the read access of the object's case.
`DocumentCompareDialog` SHALL mount filinq's `OCA.Filinq.mountCompare(el, {original, delivered,
labels})` (filinq REQ-DDARW-014) with both files and unmount it when it closes. When filinq or its
compare bundle is absent, or filinq refuses the call, the dialog SHALL say the compare view needs
filinq and SHALL offer both files as links. It SHALL NOT render a view that looks like a comparison.

#### Scenario: Side by side with filinq
<!-- @e2e exclude Mount contract with another app; covered by vitest tests/vitest/documentCompare.spec.js testItPassesBothFilesToTheViewer, and live by tests/e2e/woo-delivered-set.spec.ts. -->
- **GIVEN** filinq installed and a file set item whose delivered file is a redaction of its original
- **WHEN** the officer opens Compare on that item
- **THEN** the original SHALL be on one side and the delivered file on the other

#### Scenario: Without filinq the action says so
<!-- @e2e exclude Fallback branch; covered by vitest tests/vitest/documentCompare.spec.js testWithoutTheViewerItSaysSo. -->
- **GIVEN** filinq is not installed
- **WHEN** the officer opens Compare
- **THEN** the dialog SHALL say the compare view needs filinq and SHALL offer the two files as separate links

#### Scenario: A case type that configures no files URL offers no Compare
<!-- @e2e exclude Configuration branch; covered by vitest tests/vitest/documentCompare.spec.js. -->
- **GIVEN** a `file-set-items` widget without `filesUrl` in its content
- **WHEN** the set page renders
- **THEN** every item is listed and none offers Compare
