# Tasks — Member 08: User Bevoegdheden UI (code)

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
