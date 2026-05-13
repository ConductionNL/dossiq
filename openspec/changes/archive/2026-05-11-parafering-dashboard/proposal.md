# Proposal: Parafering Dashboard

## Summary

Implement a parafering dashboard for the Procest app that gives secretariaat staff a centralized overview of all active voorstellen in the parafering workflow and gives individual actors a personal "Ter parafering" inbox in the MyWork view. A reminder feature allows the secretariaat to notify actors who have not yet acted on their assigned step. A "Voorstellen" navigation item is added to the Procest sidebar as the entry point to the dashboard.

## Problem

The `parafering-actions` change allows actors to record parafering decisions and advance voorstellen through the route. However, there is currently no centralized view to monitor the state of all active voorstellen from a secretariaat perspective:

- Secretariaat has no dashboard to see which voorstellen are in parafering, who is currently responsible, and how long each step has been waiting
- Individual actors (wethouders, afdelingshoofden) have no quick inbox — they must hunt through Nextcloud tasks or navigate to individual cases to find what is waiting for them
- There is no mechanism for secretariaat to send reminders to actors who are overdue on their step, nor is such a reminder logged in the audit trail
- There is no "Voorstellen" navigation item — users must navigate via case detail pages to reach any voorstel-related view

Without this change:
- Secretariaat cannot monitor parafering progress across all active voorstellen without opening each case individually
- Overdue voorstellen are invisible until someone notices a missed deadline
- Actors may miss their parafering tasks if Nextcloud task notifications are overlooked
- There is no navigational entry point to a voorstel list in the Procest sidebar

## Affected Projects

- [ ] Project: `procest` — Add `ParafeerHerinneringService`, `ParafeerHerinneringController`, `VoorstellenList.vue` secretariaat dashboard, `ParafeerInbox.vue` MyWork section, reminder button, and "Voorstellen" sidebar navigation item

## Scope

### In Scope (V1)

- **Secretariaat Parafering Overview** (REQ-PDB-001): A parafering dashboard at `/voorstellen` listing all active voorstellen with current step name, waiting actor, days in current step, and overall progress (e.g., "stap 3/5"). Voorstellen overdue on any step are highlighted with a warning indicator. The list is sortable by onderwerp, status, days waiting, and steller. Empty state displayed when no active voorstellen exist.
- **Personal Parafering Inbox** (REQ-PDB-002): A "Ter parafering" section embedded in the MyWork view showing voorstellen where the current user is the active step actor. Each item shows onderwerp, case reference, steller, and waiting-since date. Direct actionable links (paraferen/terugsturen) without opening the full detail view. Empty state displayed when no voorstellen are pending.
- **Send Parafering Reminder** (REQ-PDB-003): A "Herinnering sturen" button on overdue voorstel rows in the secretariaat dashboard. Clicking it sends a Nextcloud notification to the current step actor and logs the reminder action in the voorstel's parafering audit trail.
- **Voorstel List Navigation** (REQ-PDB-004): A "Voorstellen" navigation item added to the Procest sidebar linking to `/voorstellen`. Access is scoped by case access — users see only voorstellen from cases they have access to.

### Out of Scope

- Bulk reminder sending (send reminders to multiple actors at once) — V2
- Email reminders (only Nextcloud notifications in V1) — V2
- Dashboard filter by case type, department, or portefeuillehouder — V2
- Voorstel creation or editing from the dashboard — handled by parafering-actions and case detail views
- Calendar / deadline view of voorstellen — V2
- Export of dashboard data to CSV — V2 (OpenRegister built-in export is available but not surfaced here)

## Approach

1. **Service**: `ParafeerHerinneringService.php` — sends a Nextcloud notification to the current step actor and records the reminder event in the parafering audit trail via `ObjectService`
2. **Controller**: `ParafeerHerinneringController.php` — `POST /api/parafeer-herinnering` (send reminder, secretariaat only); delegates to `ParafeerHerinneringService`
3. **Frontend dashboard**: `VoorstellenList.vue` — fetches all `in_parafering` voorstellen via `GET /api/objects/voorstel?status=in_parafering`, renders a sortable table using `CnTableLayout`; calculates days waiting from `updatedAt` of the last `parafeeractie` for each voorstel; shows overdue warning badge when days waiting exceeds a configurable threshold
4. **Frontend inbox**: `ParafeerInbox.vue` — embedded in `MyWork.vue`, fetches voorstellen where `currentStepActor` matches the current user UID; renders compact list with direct action links using `ParafeerActieDialog`
5. **Navigation**: Add "Voorstellen" item to the Procest sidebar navigation component pointing to `/voorstellen`
6. **Seed data**: 4 realistic Dutch voorstel seed objects added to `procest_register.json`

## Cross-Project Dependencies

- **parafering-actions** (required dependency): `ParafeerActieDialog` component used directly from the personal inbox for quick action taking; `parafeerActieApi.js` reused for recording actions
- **parafeerroute-engine** (required dependency): `routeSnapshot` and `currentStep` on `voorstel` are consumed to derive current step name and total step count for the dashboard display
- **OpenRegister**: `voorstel` and `parafeeractie` object retrieval via `ObjectService`; automatic audit trail on every save
- **NotificatieService** (platform): Used by `ParafeerHerinneringService` to send reminder notifications to actors
