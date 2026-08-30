## Architecture

### Data Layer

The werkvoorraad queries the same OpenRegister objects as the dashboard and My Work views:
- Cases: schema `case`, filter by non-final status, all assignees (not just current user)
- Tasks: schema `task`, filter by non-terminal status

Team scoping will be built on Nextcloud groups in a future iteration. For now, the werkvoorraad shows ALL open cases (org-wide view), which is useful for small teams.

### UI Layout

```
+-----------------------------------------------------------+
| Werkvoorraad                                    [Refresh]  |
+-----------------------------------------------------------+
| [Open Cases: 24] [Overdue: 3] [Completed: 12] [Unassigned: 5] |
+-----------------------------------------------------------+
| [All] [Unassigned] | Filter: [Case Type v] [Status v]     |
+-----------------------------------------------------------+
| Case list table                                            |
| ID | Title | Type | Status | Handler | Deadline | Priority |
+-----------------------------------------------------------+
```

### Components

- **KPI Cards**: Reuse `CnStatsBlock` pattern from Dashboard
- **Filter tabs**: All / Unassigned tabs with item counts
- **Case table**: Sortable table with case info, handler, deadline, priority
- **Case type filter**: NcSelect dropdown filtering by case type
- **Status filter**: NcSelect dropdown filtering by status

## Decisions

1. **No team scoping in first iteration** — show all org cases; team groups come in future REQ-WV-02 implementation
2. **Reuse existing store** — use `useObjectStore()` for all data queries
3. **No bulk reassignment yet** — will be added in REQ-WV-05 follow-up
