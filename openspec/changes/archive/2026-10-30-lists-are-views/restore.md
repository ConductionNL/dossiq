# Restore: what each view needs back

Ruben's rule: nothing users have gets lost. `7c7bd42c1` turned four lists into
default views of the Cases page and dropped what the pages carried on top of a
plain list. This file is the inventory to put back once nextcloud-vue lets a
view carry page config `overrides`. The values are the old ones, read from
`4dc9bf6c0` (`src/manifest.json`, `src/manifest.d/70-privacy-and-procurement.json`,
`src/menu-layout.simple.json`, `src/views/MyWorkCards.vue`, `MyWorkCaseCard.vue`,
`WorkloadSummaryBar.vue`, `src/utils/workQueueHelpers.js`).

The JSON blocks are written as one `overrides` object per view. Key names follow
the old page config where the library already had a key; where the old behaviour
lived in a component (cards, slots), the block names what has to render and the
key COMP's API ends up with replaces the name used here. Keep the `data-testid`s:
the e2e specs read them.

Profiles: the default profile is SIMPLE (`menu-layout.simple.json`). Its overlay
replaced the Queue page config whole, so the team queue values below are the
simple ones; the FULL profile's differences are listed after them.

## Applied (nextcloud-vue saved-view-overrides, library batch 18)

The blocks below are the inventory as first written. What landed in
`src/manifest.json` (Cases `defaultSavedViews[].overrides`) differs where the
library's API does:

- Routes are `route: "Cases", query: { savedView: "default:<slug>" }` (the
  data subject requests' breadcrumb and first tab), not `{ name, query }`.
- `my-work`'s card is the library's record card, `cardLayout: { headPill,
  number, title, meta, footerDate }`: the tier pill is `headPill` on
  `deadlineTier` (`data-testid="cn-object-card-head-pill"`), not a pill with
  its own testid and `data-tier`.
- The slots are `WorkloadSummaryBar` and `SubstitutedWork` (registered in
  `src/registry.js`, each reading its own endpoint).
- The data subject requests open on the view's own `sort` (deadline
  ascending), not an override `defaultSort`.
- The team queue's menu entry is back under its old id `Queue`, opening
  `default:team-queue`: label "Queue" at order 15 in the full profile
  (relocated under My work), "Team queue" at order 14 with the attention
  badge in the simple one. Assigned to me's entry is back under `MyWork`,
  opening `default:my-work`, order 20 under My work in the full profile and
  removed from the simple one as before. Both are pinned by default again,
  so every pin keeps working and no repair step is needed.

Closed with nextcloud-vue saved-view-overrides v2 (library batch 18, schema
2.132.0):

- Base filters: the conditions each page applied as its base filter are the
  view's `overrides.lockedFilters`, which the reader cannot clear (team queue:
  `assignee IS NULL`, not final, not hidden, not a draft, not a template;
  Assigned to me: `assignee @me`, not hidden, not a draft; data subject
  requests: the three request kinds). The views' own `filters` are empty.
- Per profile: the full profile patches the team queue view back to its Queue
  page (`src/menu-layout.json` `pages`, a `configPatch` on the view by slug
  that merges into its overrides): the title "Queue", the unread and priority
  columns and the case type folders.
- The Assigned to me card: `description` cut at 140 characters, case type and
  status as `chips` through their formatters, and `footerDate` with
  `overdueVariant: error`.
- The urgency order: `sortOption: urgency` selects the sidebar sort entry.

Merged with development (dq batches 12 to 15, nextcloud-vue ^2.109,
schema 2.133.0): the team-queue view carries batch 12's Queue page. In the
simple profile that is its list view (`listConfig`, opening on `list`,
oldest first as the view's `sort` on `startDate`), its columns, counts,
chips and header, the library team workload card "Team today" and the
per-type card in `sidePanel`; in both profiles its record card
(`cardLayout`) and empty text. The full profile's patch carries that page's
columns, view modes (opening on the table), folders and title.

What still differs from the pages:

- The Queue page's own `mapConfig` and `board` are not overridable; the view
  uses the Cases page's, which are the same board and the newer map.
- Assigned to me's New case dialog is the Cases one (normal size, one
  column); the old page opened it large in two columns and its edit action
  opened the case page (`editOpensDetail`). Neither key is overridable.

- The full profile's team queue also shows the simple look's counts, case
  type chips and side cards, which its Queue page did not have. That adds
  to what a user had; nothing is taken away.
- The deadline tier pill reads `deadlineTier`, which the urgency source
  copies onto the rows (`annotate`). The old card read the work queue map
  whatever the order, so a row the work queue does not rank shows no pill.
- Nobody has looked at the restored views in a browser: the e2e specs that
  read them are type-checked against the library's record card, not run.

## 1. `team-queue` (was /queue, page `Queue`)

Filter stays as built (`assignee IS NULL`, `isFinalStatus`, `statusHiddenInLists`,
`isDraft`, `isTemplate` all false). Its two windows stay as the views
`unclaimed-overdue` and `unclaimed-due-this-week` (were the Queue's own
`late` / `this-week` default views).

| What | Old value | Override key |
|---|---|---|
| Title | "Team queue" (simple), "Queue" (full) | header `title` |
| Three counts above the list | Without a handler (`queueCount`, caption "{queueNewToday} came in today"), Waiting longest (`queueOldestDays`, suffix " days"), Deadline within 7 days (`queueDueSoon`), all from GET `/api/dashboard/kpis` | `statRow` |
| Chip per case type | `quickFiltersFrom` over `caseType` titles, "All types", max 4 visible, in the toolbar | `quickFilters` |
| Side cards | Team today (GET `/api/work-queue/workload` `handlers`, bar on `openCaseCount`, limit 5, error text for non-coordinators); Work in stock per case type (`/api/dashboard/kpis` `queueByType`) | `sidePanel` |
| Columns | Waiting (`startDate`, `age` widget, 96px), Case (title + "{identifier} · {initiatorDisplayName} · {priority}"), Type (badge), Deadline (date with due labels, red below 0, amber up to 5) | `columns` |
| Count line, footer | "{shown} of {total} open cases without a handler · oldest first"; "a case leaves the queue as soon as someone picks it up" | header / `countText`, `footerNote` |
| Header buttons | Download, Actions (header-actions, import, refresh, copy-selected, delete-selected, documentation), New case | `headerButtons` |
| Empty text | "Nothing is waiting. Every open case has someone on it." | `emptyText` |
| Folder sidebar | off (simple); caseType folders (full) | `folderSidebar` |
| Menu badge | count of the view's filter, `counterVariant: attention`, entry "Team queue" at order 14 (simple) | menu badge |

```json
{
  "title": "Team queue",
  "showTitle": true,
  "searchPlaceholder": "Search by case, number or requester",
  "statRow": [
    {
      "id": "queue-unassigned",
      "type": "stat",
      "title": "Without a handler",
      "content": {
        "label": "Without a handler",
        "caption": "{queueNewToday} came in today",
        "format": {
          "style": "decimal",
          "decimals": 0
        },
        "endpointSource": {
          "url": "/apps/dossiq/api/dashboard/kpis"
        },
        "valueField": "queueCount",
        "layout": "stacked"
      }
    },
    {
      "id": "queue-oldest",
      "type": "stat",
      "title": "Waiting longest",
      "content": {
        "label": "Waiting longest",
        "caption": "the oldest case in the queue",
        "format": {
          "style": "decimal",
          "decimals": 0,
          "suffix": " days"
        },
        "endpointSource": {
          "url": "/apps/dossiq/api/dashboard/kpis"
        },
        "valueField": "queueOldestDays",
        "layout": "stacked"
      }
    },
    {
      "id": "queue-due-soon",
      "type": "stat",
      "title": "Deadline within 7 days",
      "content": {
        "label": "Deadline within 7 days",
        "caption": "pick these up first",
        "format": {
          "style": "decimal",
          "decimals": 0
        },
        "endpointSource": {
          "url": "/apps/dossiq/api/dashboard/kpis"
        },
        "valueField": "queueDueSoon",
        "layout": "stacked"
      }
    }
  ],
  "quickFilterMaxVisible": 4,
  "quickFilterPlacement": "toolbar",
  "quickFiltersFrom": {
    "field": "caseType",
    "register": "dossiq",
    "schema": "caseType",
    "labelField": "title",
    "allLabel": "All types"
  },
  "sidePanel": [
    {
      "id": "queue-team-today",
      "type": "record-list",
      "title": "Team today",
      "content": {
        "endpointSource": {
          "url": "/apps/dossiq/api/work-queue/workload",
          "responsePath": "handlers"
        },
        "presentation": "rows",
        "avatar": "{displayName}",
        "title": "{displayName}",
        "bar": {
          "valueField": "openCaseCount"
        },
        "trailing": [
          "{openCaseCount} cases"
        ],
        "limit": 5,
        "emptyText": "Nobody in your teams has open cases",
        "errorText": "Only a coordinator sees the team's workload"
      }
    },
    {
      "id": "queue-by-type",
      "type": "record-list",
      "title": "Work in stock per case type",
      "content": {
        "endpointSource": {
          "url": "/apps/dossiq/api/dashboard/kpis",
          "responsePath": "queueByType"
        },
        "presentation": "rows",
        "title": "{title}",
        "trailing": [
          "{open} open · {waiting} waiting"
        ],
        "emptyText": "No open cases"
      }
    }
  ],
  "columns": [
    {
      "key": "startDate",
      "width": "96px",
      "label": "Waiting",
      "widget": "age"
    },
    {
      "key": "title",
      "width": "328px",
      "label": "Case",
      "link": true,
      "cellClass": "cn-cell--strong",
      "secondary": "{identifier} · {initiatorDisplayName} · {priority}"
    },
    {
      "key": "caseType",
      "width": "179px",
      "label": "Type",
      "formatter": "caseTypeName",
      "widget": "badge",
      "widgetProps": {
        "variant": "info"
      }
    },
    {
      "key": "deadline",
      "width": "141px",
      "label": "Deadline",
      "widget": "date",
      "widgetProps": {
        "due": {
          "todayLabel": "Today",
          "overdueLabel": {
            "one": "{count} day overdue",
            "other": "{count} days overdue"
          },
          "upcomingLabel": {
            "one": "{count} day left",
            "other": "{count} days left"
          },
          "upcomingWeeksLabel": {
            "one": "{count} week left",
            "other": "{count} weeks left"
          }
        },
        "variantWhen": [
          {
            "op": "lt",
            "value": 0,
            "variant": "error"
          },
          {
            "op": "lte",
            "value": 5,
            "variant": "warning"
          }
        ]
      }
    }
  ],
  "countText": "{shown} of {total} open cases without a handler · oldest first",
  "footerNote": "a case leaves the queue as soon as someone picks it up",
  "headerButtons": [
    {
      "action": "export",
      "label": "Download",
      "icon": "TrayArrowDown"
    },
    {
      "action": "actions-menu",
      "label": "Actions",
      "items": [
        "header-actions",
        "import",
        "refresh",
        "copy-selected",
        "delete-selected",
        "documentation"
      ]
    },
    {
      "action": "add",
      "label": "New case",
      "variant": "primary",
      "icon": "Plus"
    }
  ],
  "emptyText": "Nothing is waiting. Every open case has someone on it.",
  "folderSidebar": null
}
```

Menu entry and badge (was the simple overlay's `Queue` menu item; the full
profile had `Queue`, icon `TrayFull`, order 15, relocated under `WorkGroup`,
no badge):

```json
{
  "view": "default:team-queue",
  "label": "Team queue",
  "order": 14,
  "counterVariant": "attention",
  "count": {
    "register": "dossiq",
    "schema": "case",
    "filter": {
      "assignee": "IS NULL",
      "isFinalStatus": false,
      "statusHiddenInLists": false,
      "isDraft": false,
      "isTemplate": false
    }
  }
}
```

Full profile differences: title "Queue"; columns `@self.unread` (unreadIndicator),
`identifier`, `title`, `caseType` (caseTypeName), `status` (statusTypeName),
`priorityOrder` (priorityBadge), `deadline`; `folderSidebar` on `caseType`
("All types"); no stat row, no side cards, no case type chips.

## 2. `my-work` (was /my-work, page `MyWork`, component `MyWorkCards.vue`)

Filter stays as built (`assignee @me`, `statusHiddenInLists`/`isDraft` false).

| What | Old value | Override key |
|---|---|---|
| Header | title "Assigned to me", breadcrumb "My work" → `MyWorkHome`, meta "{total} cases in your name · most urgent first", footer "Urgency follows from the deadline, the priority and how long a case has been idle." | header |
| Header buttons | Download, Actions, New case | `headerButtons` |
| Urgency order | default sort Urgency (server rank from GET `/api/work-queue`), Newest = `-startDate`; on failure the deadline order with the note "Ordered by deadline: the urgency could not be computed." (`data-testid="urgency-fallback"`) | `sortOption: urgency` (built; needs the library) |
| Card layout | opens in cards, toggle cards/table. Card: title, description cut at 140 chars, identifier, case type chip (name), status chip (name), deadline tier pill (`data-testid="deadline-tier-pill"`, `data-tier="<tier>"`, class per tier: Late / Critical / Soon / Normal from `deadlineTier`), "Deadline: <date>" red when overdue; the whole card opens the case | `cardLayout`, `views`/`mode` |
| Table columns | identifier, title, case type, status, deadline | `columns` |
| Workload strip | below the header: "Team workload", a chip per colleague with open-case count from GET `/api/work-queue/workload`, own chip first as "{name} (you)", hidden on 403 or no rows; choosing a colleague opens Cases filtered on their `assignee` (`data-testid="workload-summary"`) | per-view slot `below-header` |
| Substituted work | above the list: "Work you are standing in for", cards marked "for {name}, until {date}" (`substituted-work`, `substituted-marker`), Hide/Show toggle remembered per browser (`substituted-toggle`), from `fetchSubstitutedWork()` | per-view slot `before-collection` |
| Sidebar | facet sidebar on, metadata group off | `sidebar` |
| Row click | opens the case, no View action | `rowClickToView`, `showViewAction` |
| Menu | full profile: `MyWork` "Assigned to me", icon `ClipboardAccountOutline`, order 20, under `WorkGroup`, pinned by default. Simple: no menu entry, linked from My work (already repointed to the view) | menu |

```json
{
  "title": "Assigned to me",
  "showTitle": true,
  "breadcrumb": {
    "label": "My work",
    "route": "MyWorkHome"
  },
  "headerMeta": "{total} cases in your name · most urgent first",
  "footerNote": "Urgency follows from the deadline, the priority and how long a case has been idle.",
  "headerButtons": [
    {
      "action": "export",
      "label": "Download",
      "icon": "TrayArrowDown"
    },
    {
      "action": "actions-menu",
      "label": "Actions"
    },
    {
      "action": "add",
      "label": "New case",
      "variant": "primary",
      "icon": "Plus"
    }
  ],
  "viewMode": "cards",
  "viewModes": [
    "cards",
    "table"
  ],
  "columns": [
    "identifier",
    "title",
    {
      "key": "caseType",
      "label": "Case type",
      "formatter": "caseTypeName"
    },
    {
      "key": "status",
      "label": "Status",
      "formatter": "statusTypeName"
    },
    "deadline"
  ],
  "sidebar": {
    "enabled": true,
    "showMetadata": false
  },
  "rowClickToView": true,
  "showViewAction": false,
  "sortOption": "urgency",
  "cardLayout": {
    "title": "title",
    "description": {
      "field": "description",
      "maxLength": 140
    },
    "meta": [
      "identifier",
      {
        "field": "caseType",
        "formatter": "caseTypeName",
        "chip": true
      },
      {
        "field": "status",
        "formatter": "statusTypeName",
        "chip": true
      }
    ],
    "pill": {
      "source": "urgency",
      "field": "deadlineTier",
      "testid": "deadline-tier-pill",
        "tierAttribute": "data-tier",
      "labels": {
        "overdue": "Late",
        "critical": "Critical",
        "warning": "Soon",
        "normal": "Normal"
      },
      "variant": {
        "overdue": "error",
        "critical": "error",
        "warning": "warning",
        "normal": "neutral"
      }
    },
    "footer": {
      "field": "deadline",
      "label": "Deadline",
      "format": "date",
      "overdueVariant": "error"
    }
  },
  "slots": {
    "below-header": [
      "WorkloadSummaryBar"
    ],
    "before-collection": [
      "SubstitutedWorkGroup"
    ]
  }
}
```

The slot components are `WorkloadSummaryBar.vue` and the substituted group of
`MyWorkCards.vue` (`git show 4dc9bf6c0:src/views/...`). Restore them as generic
components registered by name, not as a page.

Not in the brief but lost too: the substituted work group above. `tests/vitest/substitutedWorkOnMyWork.spec.js`
lost its three index cases with it.

## 3. `data-subject-requests` (was /privacy/requests, page `PrivacyRequests`)

Filter stays as built (`dataSubjectRequestType` in inzage, correctie, verwijdering).

| What | Old value | Override key |
|---|---|---|
| Header | title "Data subject requests", shown; breadcrumb "Privacy"; meta "{total} open · term one month, extendable by two" | header |
| Tabs | AVG-verzoeken (Data subject requests, count of open requests) / Datalekken (Data breaches → `AvgIncidents`, permission `privacy`, count of `avgIncident`) | `tabs` |
| Notice | info: "A data subject request is a case with a handler, a legal month and a history. The search, the preview of what gets erased and the export are done by OpenRegister." | notice |
| Chips | All, Subject access (inzage), Correction (correctie), Erasure (verwijdering), each with count | `quickFilters` |
| Columns | Data subject request (title + "{identifier} · {initiatorDisplayName}"), Kind, Received, Term (deadlineCountdown), Status (statusBadge), assignee | `columns` |
| Sort | deadline ascending | sort |
| Form | add label "New data subject request"; fields caseType, title, requester (InitiatorPicker), dataSubjectRequestType, description (textarea), assignee | form keys |
| Other | search "Search by case or person involved", footer "20 per page", Download + New data subject request buttons, View action, sidebar with metadata | header, `actions`, `sidebar` |

```json
{
  "title": "Data subject requests",
  "showTitle": true,
  "breadcrumb": {
    "label": "Privacy",
    "route": {
      "name": "Cases",
      "query": {
        "savedView": "default:data-subject-requests"
      }
    }
  },
  "pageTabs": [
    {
      "label": "Data subject requests",
      "route": {
        "name": "Cases",
        "query": {
          "savedView": "default:data-subject-requests"
        }
      },
      "countFrom": {
        "register": "dossiq",
        "schema": "case",
        "filter": {
          "dataSubjectRequestType": [
            "inzage",
            "correctie",
            "verwijdering"
          ],
          "isFinalStatus": false
        }
      }
    },
    {
      "label": "Data breaches",
      "route": "AvgIncidents",
      "permission": "privacy",
      "countFrom": {
        "register": "dossiq",
        "schema": "avgIncident",
        "filter": {}
      }
    }
  ],
  "headerMeta": "{total} open · term one month, extendable by two",
  "addLabel": "New data subject request",
  "headerButtons": [
    {
      "action": "export",
      "label": "Download",
      "icon": "TrayArrowDown"
    },
    {
      "action": "add",
      "label": "New data subject request",
      "variant": "primary",
      "icon": "Plus"
    }
  ],
  "searchPlaceholder": "Search by case or person involved",
  "footerNote": "20 per page",
  "notice": {
    "variant": "info",
    "text": "A data subject request is a case with a handler, a legal month and a history. The search, the preview of what gets erased and the export are done by OpenRegister."
  },
  "quickFilters": [
    {
      "label": "All",
      "showCount": true,
      "filter": {},
      "default": true
    },
    {
      "label": "Subject access",
      "activeLabel": "Kind: subject access",
      "showCount": true,
      "filter": {
        "dataSubjectRequestType": "inzage"
      }
    },
    {
      "label": "Correction",
      "activeLabel": "Kind: correction",
      "showCount": true,
      "filter": {
        "dataSubjectRequestType": "correctie"
      }
    },
    {
      "label": "Erasure",
      "activeLabel": "Kind: erasure",
      "showCount": true,
      "filter": {
        "dataSubjectRequestType": "verwijdering"
      }
    }
  ],
  "columns": [
    {
      "key": "title",
      "label": "Data subject request",
      "secondary": "{identifier} · {initiatorDisplayName}"
    },
    {
      "key": "dataSubjectRequestType",
      "label": "Kind"
    },
    {
      "key": "startDate",
      "label": "Received",
      "formatter": "date"
    },
    {
      "key": "deadline",
      "label": "Term",
      "widget": "deadlineCountdown"
    },
    {
      "key": "status",
      "label": "Status",
      "formatter": "statusTypeName",
      "widget": "statusBadge"
    },
    "assignee"
  ],
  "defaultSort": [
    {
      "field": "deadline",
      "order": "asc"
    }
  ],
  "includeFields": [
    "caseType",
    "title",
    "requester",
    "dataSubjectRequestType",
    "description",
    "assignee"
  ],
  "fieldOverrides": {
    "description": {
      "widget": "textarea"
    },
    "requester": {
      "widget": "InitiatorPicker",
      "label": "Requester"
    }
  },
  "actions": [
    {
      "id": "view",
      "label": "View",
      "icon": "EyeOutline",
      "handler": "navigate",
      "route": "CaseDetail",
      "type": "handler"
    }
  ],
  "sidebar": {
    "enabled": true,
    "showMetadata": true
  }
}
```

The tab and breadcrumb routes pointed at `PrivacyRequests`; they now point at
the view. The `AvgIncidents` page never had the tab strip back; that is how it
was.

## 4. `woo-requests` and `complaints`

Nothing to restore: Woo requests was already a saved filter of Cases before
this branch, and complaints never had a page.

## 5. Pins

The `Queue` and `MyWork` menu entries kept their ids and open their views,
so users' pins and the default pins need nothing.

## 6. Other references to the old routes (not rewritten here)

`QueueWidgetItems::ROUTE_PATHS['MyWork']` still writes `my-work` (the redirect
catches it). e2e specs outside this lane's four still navigate to `/my-work`
or `/queue`: case-list-lenses, case-claim, my-open-work-widget, navigation,
spec-coverage/queue, case-page-and-list-as-a-place, simple-structure-menu,
docs-screenshots, spec-coverage/work-navigation, one-personal-queue,
helpers/nav.ts.
