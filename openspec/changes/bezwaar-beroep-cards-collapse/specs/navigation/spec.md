# Navigation — Bezwaar & Beroep cards-collapse

## ADDED Requirements

### Requirement: REQ-NAV-001 — The BezwaarBeroepGroup MUST collapse into a single top-level menu item linking to a card-grid landing page per ADR-044 cards-collapse rule

The `BezwaarBeroepGroup` group and its four child leaves (`Bezwaren`, `Beroepen`, `BezwaarDecisions`, `BezwaarAdviceRequests`) MUST be replaced in the navigation with a single top-level menu entry labelled "Bezwaar & Beroep" that navigates directly to a new `BezwaarBeroepLanding` card-grid page. The group MUST NOT render as an expandable/collapsible nested sub-menu; it MUST be a direct clickable link. The four former child leaves MUST be rendered as cards on the landing page — one card per former leaf — so that users can navigate to each view from the landing page.

#### Scenario: Single menu item replaces nested group

- GIVEN the user opens the procest app in Nextcloud
- WHEN the sidebar navigation is rendered
- THEN a single "Bezwaar & Beroep" menu item appears at the top level with no nested sub-items beneath it

#### Scenario: Clicking the menu item opens the card-grid landing page

- GIVEN the "Bezwaar & Beroep" top-level menu item is visible
- WHEN the user clicks the "Bezwaar & Beroep" menu item
- THEN the browser navigates to the `BezwaarBeroepLanding` page showing a card grid

#### Scenario: Landing page contains one card per former leaf

- GIVEN the user is on the `BezwaarBeroepLanding` card-grid page
- WHEN the page finishes rendering
- THEN exactly four cards are displayed: "Bezwaren", "Beroepen", "Beslissingen op bezwaar", and "BAC-adviezen"

### Requirement: REQ-NAV-002 — Each former child leaf MUST be rendered as a navigable card on the BezwaarBeroepLanding page

Each of the four former child leaves of `BezwaarBeroepGroup` — `Bezwaren` (route `/bezwaren`), `Beroepen` (route `/beroepen`), `BezwaarDecisions` (route `/bezwaar-decisions`), and `BezwaarAdviceRequests` (route `/bezwaar-advice-requests`) — MUST appear as a distinct card on the `BezwaarBeroepLanding` page. Each card MUST display the leaf's label, icon, and a short descriptive summary, and MUST navigate to the corresponding leaf page when clicked.

#### Scenario: Bezwaren card navigates to the Bezwaren index

- GIVEN the user is on the `BezwaarBeroepLanding` page
- WHEN the user clicks the "Bezwaren" card
- THEN the browser navigates to the route `Bezwaren` (`/bezwaren`)

#### Scenario: Beroepen card navigates to the Beroepen index

- GIVEN the user is on the `BezwaarBeroepLanding` page
- WHEN the user clicks the "Beroepen" card
- THEN the browser navigates to the route `Beroepen` (`/beroepen`)

#### Scenario: BezwaarDecisions card navigates to the Beslissingen index

- GIVEN the user is on the `BezwaarBeroepLanding` page
- WHEN the user clicks the "Beslissingen op bezwaar" card
- THEN the browser navigates to the route `BezwaarDecisions` (`/bezwaar-decisions`)

#### Scenario: BezwaarAdviceRequests card navigates to the BAC-adviezen index

- GIVEN the user is on the `BezwaarBeroepLanding` page
- WHEN the user clicks the "BAC-adviezen" card
- THEN the browser navigates to the route `BezwaarAdviceRequests` (`/bezwaar-advice-requests`)

### Requirement: REQ-NAV-003 — All former leaf page routes MUST remain registered and reachable as deep links after the navigation change (ADR-044 hard invariant)

Per the ADR-044 hard invariant, no page is removed. The routes `/bezwaren`, `/beroepen`, `/bezwaar-decisions`, and `/bezwaar-advice-requests` MUST remain registered in the Vue Router and MUST resolve to their respective page components when accessed directly via URL, regardless of whether they appear in the main sidebar navigation. Only the NAV nesting changes; the pages themselves are not removed or altered.

#### Scenario: Direct URL navigation to Bezwaren still resolves

- GIVEN the user has applied the cards-collapse change
- WHEN the user navigates directly to `/bezwaren` via browser address bar or deep link
- THEN the Bezwaren index page renders correctly without a 404 or redirect

#### Scenario: Direct URL navigation to Beroepen still resolves

- GIVEN the user has applied the cards-collapse change
- WHEN the user navigates directly to `/beroepen` via browser address bar or deep link
- THEN the Beroepen index page renders correctly without a 404 or redirect

#### Scenario: Direct URL navigation to BezwaarDecisions still resolves

- GIVEN the user has applied the cards-collapse change
- WHEN the user navigates directly to `/bezwaar-decisions` via browser address bar or deep link
- THEN the Beslissingen op bezwaar index page renders correctly without a 404 or redirect

#### Scenario: Direct URL navigation to BezwaarAdviceRequests still resolves

- GIVEN the user has applied the cards-collapse change
- WHEN the user navigates directly to `/bezwaar-advice-requests` via browser address bar or deep link
- THEN the BAC-adviezen index page renders correctly without a 404 or redirect

#### Scenario: E2E test suite can reach all former leaf routes

- GIVEN an e2e test suite targeting procest
- WHEN each of the four former leaf routes is visited in sequence
- THEN all four pages load without error and the Playwright accessibility snapshot contains the expected page heading for each
