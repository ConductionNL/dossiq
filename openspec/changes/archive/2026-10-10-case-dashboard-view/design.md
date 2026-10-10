## Architecture

### Responsive Layout (REQ-CDV-07b)

Use CSS media queries at `max-width: 1200px` to switch from two-column (60/40) to single-column layout. All panels stack vertically. Touch targets ensured at 44x44px minimum via existing NcButton/NcSelect sizing.

### Print View (REQ-CDV-07c)

`@media print` rules:
- Hide navigation, action buttons (Save/Delete), interactive dropdowns
- Status timeline renders as text list
- Add header with case identifier, print date
- Force white background, remove shadows

### Skeleton Loading (REQ-CDV-01d)

Replace the single `NcLoadingIcon` with `CnDetailCard` placeholders showing animated skeleton bars for each panel section.

### Not Found State (REQ-CDV-01c)

After fetch completes, if case data is empty/null, show `NcEmptyContent` with "Zaak niet gevonden" and back-to-overview button.

## Decisions

1. **CSS-only responsive** — no JavaScript layout switching needed, just media queries
2. **Print via @media print** — standard browser print, no PDF generation
3. **Skeleton per card** — uses CSS animation, no additional library
