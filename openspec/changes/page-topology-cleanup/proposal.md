# Proposal: page-topology-cleanup

Refs: hydra ADR-044 (menu architecture / settings-foldout) · ADR-047 (OR owns AVG/DSAR) ·
ADR-065 (OR is the only flow engine) · ADR-066 (cross-app leaf registration) ·
ADR-036 + ADR-049 (declarative widget vocabulary) · ADR-062 (grid discipline) ·
ADR-032 (spec sizing) · procest ADR-001 (information architecture).

## Why

Twaalf procest-pagina's staan op de verkeerde plek in de informatie-architectuur, of
dragen het verkeerde manifest-`type`. Niet één daarvan is een nieuwe ontwerpvraag: voor
elk punt bestaat er al een ADR die de bestemming heeft vastgelegd. Procest loopt achter
op zijn eigen fleet-besluiten.

Drie soorten drift, alle drie geverifieerd tegen de code:

1. **Verkeerd manifest-type.** Geen van de drie "dashboards" is een echte
   dashboard-pagina. `/process-mining` en `/termijn-dashboard` zijn `type: custom` —
   handgeschreven Vue van 527 resp. 505 regels. `/doorlooptijd` is nominaal
   `type: dashboard`, maar de config is één widget die het hele 12×12-grid vult en
   waarvan de slot-component (`DoorlooptijdDashboard.vue`, 594 r.) zélf een compleet
   dashboard mét eigen `<h2>` is — het `dashboard-in-dashboard`-antipattern
   (hydra#316, `hydra-gate-dashboard-antipattern`). Vandaar dat de drie pagina's er
   verschillend uitzien: ze delen geen enkele render-laag.

2. **Functionaliteit in de verkeerde app.** AVG-verwerkingen, automatische acties,
   parafeerroutes, bezwaaradviescommissies, besluitvorming en AI-oversight zijn
   fleet-capabilities die per ADR bij openregister, decidesk resp. hermiq horen.
   Procest host er een tweede implementatie van naast de eigenaar-app.

3. **Beheer-oppervlak dubbel of misplaatst.** `/apps/procest/settings` rendert
   **dezelfde** `AdminRoot.vue` als `/settings/admin/procest`; tenant-onboarding en
   substitution zijn instellingen die als top-level app-pagina in de dagelijkse
   navigatie staan; `/settings/locations` dupliceert de map-viewMode die `/cases` al heeft.

## What Changes

### A — Dashboard-typen uniformeren (procest-intern)

- **A1** `/process-mining` van `type: custom` → `type: dashboard`. De pagina wordt de
  referentie-implementatie: header + subtitle uit de manifest, inhoud opgesplitst in
  echte `config.widgets` + `layout` (KPI-tegels, dwell-time-staafgrafiek,
  throughput-lijngrafiek, bottleneck-ranking). De nc-vue leafs die de component nu
  al direct gebruikt (`CnKpiGrid`, `CnStatsBlock`, `CnChartWidget`) worden
  gedeclareerd in plaats van geïmporteerd (ADR-049).
- **A2** `/termijn-dashboard` op dezelfde leest: `type: dashboard`, KPI-kaarten en
  rapport-tabellen als widgets, zaaktype-filter + refresh naar de manifest-header.
- **A3** `/doorlooptijd` ontnesten: de `<h2>` en de filters komen van de pagina, de
  widget-slots dragen alleen nog inhoud. `hydra-gate-dashboard-antipattern` moet
  daarna groen zijn — nu faalt hij.
- **A4** Alle drie tegen ADR-062 (grid discipline) leggen, zodat het gedeelde
  raster de stijl bepaalt en niet drie losse `<style scoped>`-blokken.

### B — Beheer-oppervlak opruimen (procest-intern)

- **B1** **BREAKING (nav)** — de in-app pagina `/apps/procest/settings` verwijderen:
  manifest-pagina `ProcestConfiguration`, slot `section-admin` en de registratie van
  `AdminRootView` in `customComponents.js` + `registry.js`. `/settings/admin/procest`
  blijft de enige beheer-ingang. Dit is óók een beveiligingsfix: een admin-component
  via de in-app router bereikbaar maken omzeilt de server-side checks van het
  NC settings-framework (ADR-004, `hydra-gate-admin-router`).
- **B2** `ProcestConfiguration` staat **twee keer** in het menu (order 95 "Case types"
  én order 99 "Configuration"). Beide entries vervangen door één settings-foldout-link
  conform ADR-044.
- **B3** `/tenant-onboarding` wordt een sectie/tab binnen `AdminRoot.vue`; pagina +
  menu-entry (order 92) vervallen. Volgt op B1 — zelfde bestand.
- **B4** `/substitution` wordt een **personal setting**: `<personal>`-registratie in
  `appinfo/info.xml` + `PersonalSettings.php` + template, met
  `SubstitutionSettings.vue` als form. `/substitution-admin` (de coördinator-console,
  server-side rolgecontroleerd) blijft een gewone app-pagina. Menu-entry order 93 vervalt.
- **B5** `/settings/locations` + `LocationDetail` + menu-entry (order 98) vervallen.
  `/cases` heeft al `viewModes: ["table","cards","map"]` met `mapConfig`. Het
  `location`-schema en de koppeling op de case-detail blijven ongemoeid — alleen het
  losse beheer-overzicht verdwijnt.

### C — Naar openregister

- **C1** `/verwerkingen` vervalt. OR heeft de bestemming al: pagina `/avg` plus
  `VerwerkingsactiviteitenController`, `DsarController`, `ProcessingLogController`.
  Procest's `VerwerkingenOverview.vue` praat nu al tegen OR's `/api/avg` — het is
  een venster, geen implementatie. Per ADR-047 hoort dat venster in OR zelf.
  Eerst vaststellen welke procest-specifieke framing (catalogue-review-status,
  unclassified-processing-teller, `InzageExportModal`) nog ontbreekt in OR's `/avg`;
  dat deel landt daar, daarna pas verwijderen hier.
- **C2** `/settings/automatic-actions` (+ `/:id`) vervalt. Per ADR-065 is OR de enige
  plek voor een flow-engine; de automatische acties worden OR flow-definities
  (`ActionService` / `ActionExecutor` / `FlowLinkService`, pagina `/flows`).
  Procest houdt hooguit een deeplink.

### D — Naar decidesk

- **D1 — besluitvorming volledig verplaatsen.** De actieve change
  `consume-decidesk-besluitvorming-leaf` retireert de nav-groep en zet decidesk's
  beslissingen als integratie-leaf op de case-detail, maar houdt
  `/besluitvorming/agenda` en `/besluitvorming/vergaderingen/:id` bewust routeerbaar.
  Deze change gaat verder: die twee pagina's, `AgendaCompilerView.vue`,
  `VergaderingDetailView.vue` en `src/manifest.d/50-besluitvorming.json` vervallen in
  procest; decidesk's `/agenda-items` en `/meetings` zijn de eigenaar. Procest houdt
  **alleen** de widget op de zaak. Volgt op `consume-decidesk-besluitvorming-leaf` —
  niet parallel.
- **D2** Bezwaaradviescommissies (`/settings/bezwaar-committees` + `/:id`) naar
  decidesk; het `bezwaaradviescommissie`-schema valt samen met decidesk's
  `governance-body`. Surface in procest via dezelfde leaf-route als D1.
- **D3** `/settings/parafeerroutes` (+ `/:id`) naar decidesk, aansluitend op het
  routed-documents/approval-model (`routedDocumentsJoin.js`).

### E — Naar hermiq

- **E1** `/settings/ai-oversight` (+ `/:id`) naar hermiq, naast `/approvals`,
  `/algorithm-register` en `/compliance`. Hermiq heeft al `ToolOversightController`
  en `AgentToolGovernanceWidget.vue` — eerst overlap vaststellen, dan de rest verhuizen.

## Volgorde en werkwijze

Blok A en B zijn procest-intern en kunnen direct. C, D en E zijn verhuizingen en
gaan **elk in twee PRs, in deze volgorde**:

1. landingsspec + implementatie in de doel-app (openregister / decidesk / hermiq),
2. pas ná merge daarvan: pagina, menu-entry, component, routes en controller uit procest.

Nooit in één PR — dan is de functionaliteit tussentijds nergens. Dit is het patroon dat
`move-portals-to-portaliq` al gebruikt (provider eerst, in-app views daarna).

`retire-status-history-page` is het precedent voor de intrekkingen in blok B: pagina en
menu-entry weg, data-schema behouden, e2e-specs omgezet naar "assert dat de pagina wég is".

## Affected Projects

- [x] procest — alle twaalf punten
- [x] openregister — C1, C2 (landingskant)
- [x] decidesk — D1, D2, D3 (landingskant)
- [x] hermiq — E1 (landingskant)

## Out of scope

- **De Codeberg→GitHub opruiming.** Aparte change in `hydra` — zie hieronder. Hoort
  niet in een procest-IA-change (ADR-032 spec sizing).
- Het `voorstel`/`adviesAanvraag`-datamodel en de bijbehorende migratie: die worden al
  afgehandeld door `procest-delegate-remaining-decisions-to-decidesk`.
- De `location`-, `bezwaaradviescommissie`- en `parafeerroute`-schema's zelf blijven
  bestaan tot de doel-app ze overneemt; geen dataverlies in deze change.
