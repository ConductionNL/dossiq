## Delta Spec: case-dashboard-view

### REQ-CDV-07b: Tablet Layout — IMPLEMENTED
- Single-column layout at viewport <= 1200px
- All panels stack vertically: status timeline, case info, deadline, activity, tasks, documents, participants
- Touch targets meet WCAG AA 44x44px minimum

### REQ-CDV-07c: Print View — IMPLEMENTED
- `@media print` rules hide action buttons, navigation, interactive elements
- Status timeline rendered as text (not interactive dots)
- Header includes case identifier and print date
- Clean white background, no shadows

### REQ-CDV-01d: Skeleton Loading — IMPLEMENTED
- Per-panel skeleton placeholders shown during data loading
- Each panel card shows animated skeleton bars
- Status timeline, info panel, and deadline panel render skeleton immediately

### REQ-CDV-01c: Case Not Found — IMPLEMENTED
- 404 state with NcEmptyContent: "Zaak niet gevonden"
- "Terug naar overzicht" button navigates to case list
