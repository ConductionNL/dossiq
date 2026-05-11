## Why

The case dashboard view is substantially implemented but missing responsive layout (REQ-CDV-07b), print view (REQ-CDV-07c), and skeleton loading states (REQ-CDV-01d). Tender demos require a polished case detail screen across devices. The responsive and print requirements are MVP-tier.

## What Changes

- **REQ-CDV-07b**: Add responsive CSS for tablet (768-1200px) — single-column stacking with 44x44px touch targets
- **REQ-CDV-07c**: Add print stylesheet — clean printable format with text-based status timeline, hidden action buttons, header with identifier and date
- **REQ-CDV-01d**: Add skeleton loading placeholders per panel card instead of single spinner
- **REQ-CDV-01c**: Add 404 state for case not found

## Capabilities

### New Capabilities
- `case-detail-responsive`: Tablet-optimized single-column layout
- `case-detail-print`: Clean print view with all case information

### Modified Capabilities
- `case-detail-loading`: Skeleton placeholders per panel instead of single loading indicator
- `case-detail-not-found`: 404 empty state for missing cases

## Impact

- **Frontend**: `src/views/cases/CaseDetail.vue` — responsive CSS, print CSS, skeleton states, 404 state
- **No backend changes**
