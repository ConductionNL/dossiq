# Design: data-model-link

## D-1. The target is one URL

`/apps/openregister/#/registers/<dossiq register id>` lists the register's
schemas with properties. The register id is resolved the way
`AvgRegisterLink` resolves its target, through the manifest's `@resolve:`
sentinel on the dossiq register.

## D-2. Two entry points, one target

The menu entry sits in `section: "integrations"` (ADR-110). The Objects
section of `#CaseDetail` and the `#CaseObjects` index carry a header link
with the same href, `visibleIf` admin.

## D-3. What this does not do

It does not render a model page in dossiq. The pages-and-widgets half of
the data model has no browser anywhere in the fleet; the register keeps the
row partial and the umbrella lists it.
