## Delta Spec: werkvoorraad

### REQ-WV-01a: Team Overview — PARTIALLY IMPLEMENTED
- Werkvoorraad view shows all open cases with handler, case type, status, deadline
- Per-member breakdown not yet implemented (requires team scoping REQ-WV-02)
- Unassigned tab filters cases with no assignee

### REQ-WV-03a: Urgency-Based Sorting — IMPLEMENTED
- Default sort: overdue first, then by deadline proximity
- Items without deadline appear last

### REQ-WV-04a/b: Filter by Case Type and Overdue — IMPLEMENTED
- Case type dropdown filter
- Overdue filter via "Unassigned" tab and visual highlighting

### REQ-WV-07a: KPI Cards — IMPLEMENTED
- Open cases, overdue, completed this week, unassigned counts
- Each KPI clickable to filter the list

### Navigation and Routing — IMPLEMENTED
- Werkvoorraad menu item in sidebar with AccountGroup icon
- Route `/werkvoorraad` with Werkvoorraad component
