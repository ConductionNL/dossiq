# Proposal: features-overlay-category

## Why

The pre-commit hook regenerates docs/features.json from openspec/features.overlay.json with the shared
ConductionNL/.github `scripts/extract-features.py` on every commit that touches a spec. `category`, which the dashboard
groups the Features tab by, lived only in the generated file, so every spec commit dropped it and
DashboardControllerContractTest broke twice (restored by hand in INT-DQ).

## What changes

Each overlay entry carries its `category`, so the generator writes it from the source. The generator itself is fixed in
ConductionNL/.github (`fix/extract-features-keeps-fields`): it reads `category` from the overlay and keeps any field the
existing file holds that its sources do not give.
