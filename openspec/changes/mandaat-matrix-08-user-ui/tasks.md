# Tasks — Member 08: User Bevoegdheden UI (code)

> **Build status (hydra audit).** Mostly greenfield. Dev has only lib/Service/MandaatValidationService.php::validate() (191 lines, single-method) + lib/Controller/MandaatController.php (124 lines). The MandateringsBesluit/Mandaat/OrganisatieRol/MedewerkerRolToewijzing schemas, full authorization+escalation engines, Decidesk import, case+decision integration, temporal+conflict resolver, and admin/user UI are not on dev. Tasks stay [ ] as genuine forward work; the existing slim MandaatValidationService is the foundation to grow on.

Sourced from giant task 11 (User Bevoegdheden Dashboard).

## 1. Bevoegdheden Panel

- [ ] Create `BevoegdhedenPanel.vue` (side panel or modal) opened from case detail
- [ ] Load applicable Mandaat records for the case zaaktype, filtered by the user's current roles
- [ ] Table columns: Mandaat # | Omschrijving | Bevoegdheidtype | Plafond | Subdelegatie | Geldend v/t | Details
- [ ] Implement "What can I do?" filter — decision types the user can unilaterally execute

## 2. Detail Widget

- [ ] Build `MandaatMatrixWidget.vue` row-detail expansion: description, wettelijke grondslag link, current role holders, waarnemer note, MandateringsBesluit source
- [ ] nl + en i18n; NL Design System theming; NcSelect with inputLabel
- [ ] Test on case detail page; with different roles (incl. waarnemer); filter functionality
