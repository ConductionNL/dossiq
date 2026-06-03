<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: case-management (Case Management)
     This spec extends the existing `case-management` capability. Do NOT define new entities or build new CRUD — reuse what `case-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Deelzaak Support

## Summary

Add sub-case (deelzaak) creation and linking to Procest, enabling hierarchical case structures where a main case (hoofdzaak) can spawn child cases that follow their own workflows while remaining linked to the parent. This includes vervolg-zaak (follow-up case) support for cases that trigger new cases upon completion, and general zaak-relatie management for linking related cases.

Deelzaken are a fundamental building block for complex government processes like VTH (where an omgevingsvergunning spawns advice sub-cases) and bezwaar (where the objection creates a new case linked to the original decision).

## Demand Evidence

### Cluster Data (from market intelligence DB)

| Cluster | Requirements | Tenders |
|---------|-------------|---------|
| Deelzaak (sub-case) support | 298 | 115 |
| Zaak relations / sub-cases | 928 | 184 |
| **Total** | **1,226** | **~245 unique** |

### Top Tenders

| Tender | Organisation | URL |
|--------|-------------|-----|
| Levering en ondersteuning nieuw VTH systeem op basis van SaaS | Omgevingsdienst Noordzeekanaalgebied | https://www.tenderned.nl/aankondigingen/overzicht/308208 |
| Zaaksysteem | Veiligheidsregio Brabant-Noord | https://www.tenderned.nl/aankondigingen/overzicht/319120 |
| Zaaksysteem | Gemeente Overbetuwe | https://www.tenderned.nl/aankondigingen/overzicht/331221 |
| Zaaksysteem | Gemeente Waalwijk | https://www.tenderned.nl/aankondigingen/overzicht/314729 |
| Leveren, implementeren en onderhouden van een Zaaksysteem | Gemeente Zaanstad | https://www.tenderned.nl/aankondigingen/overzicht/367903 |
| Zaaksysteem/KCC | Gemeente Stein | https://www.tenderned.nl/aankondigingen/overzicht/229235 |
| VTH Applicatie | Gemeente Zaanstad | https://www.tenderned.nl/aankondigingen/overzicht/216588 |

### Representative Requirements from Tenders

1. "In de ZTC kan bij elk zaaktype worden geconfigureerd welke zaaktypen daarbij als vervolg- of deelzaak aangemaakt kunnen c.q. moeten worden. Een vervolg- of deelzaak gedraagt zich volledig als een zaak, inclusief de mogelijkheid om daar weer deelzaken met een minimum van 3 niveaus onder te creeren."
2. "In de Oplossing kan bij elk zaaktype worden geconfigureerd welke zaaktypen daarbij (automatisch of handmatig) als vervolg- of deelzaak aangemaakt kunnen c.q. moeten worden. Waarbij de zaken automatisch worden toegevoegd aan de werkvoorraad."
3. "De Oplossing biedt de functionaliteit van een hoofd- en deelzaak. De afhandeltermijn en archiefkenmerk van de deelzaak worden overgenomen van de hoofdzaak."
4. "De Oplossing signaleert de behandelaar, bij wijzigingen op de zaak die door iemand anders dan de behandelaar zijn uitgevoerd. Tenminste bij het toevoegen van een nieuw document of e-mail en het afronden van een deelzaak."
5. "In de werkvoorraad en het detailvenster wordt door middel van signaleringen getoond wanneer streef- en fatale termijnen verlopen en wanneer er wijzigingen, zoals het toevoegen van een nieuw document en het afronden van een deelzaak, door iemand anders dan de behandelaar zijn uitgevoerd."
6. "De Oplossing biedt de mogelijkheid om de behandeling van een zaak op te schorten of te verlengen. Zodra een zaak is opgeschort of verlengd, worden de relevante termijnen aangepast."
7. "De Oplossing ondersteunt eenduidige en uniforme zaakafhandeling. Voordat zaakafhandeling kan plaatsvinden, moeten alle verplicht aan te geven onderdelen zijn ingevuld, en moet de medewerker op de zaak een resultaat en/of een besluit geregistreerd hebben."

## Scope

### In Scope

- **Deelzaak data model**: Parent-child relationship between cases stored in OpenRegister, with minimum 3 levels of nesting support
- **Zaaktype deelzaak configuration**: Per zaaktype, configure which zaaktypen can be created as deelzaak (automatic or manual trigger)
- **Vervolg-zaak support**: Cases that trigger a new case upon reaching a specific status (e.g., vergunning granted triggers toezicht case)
- **Deelzaak creation**: Manual and automatic creation of sub-cases from a parent case, with inheritance of configurable fields (afhandeltermijn, archiefkenmerk, betrokkenen)
- **Zaak-relatie management**: Generic linking between cases (parent/child, related, predecessor/successor) with typed relationships
- **Hierarchical case view**: Visual tree view showing hoofdzaak with all deelzaken and their statuses
- **Deelzaak completion tracking**: Parent case tracks completion status of all deelzaken; configurable rules for when parent can proceed (all deelzaken must be complete, or specific ones)
- **Cross-case signalering**: When a deelzaak completes or changes, the parent case handler is notified
- **Shared dossier**: Deelzaken can inherit documents from the parent case and contribute documents back

### Out of Scope

- Specific deelzaak configurations for VTH, bezwaar, or besluitvorming (covered by respective workflow changes)
- Doorlooptijd tracking across deelzaken (covered by `doorlooptijd-dashboard`)
- Alert widgets (covered by `signalering-widgets`)

## Dependencies

- **workflow-engine-enhancement** (REQUIRED): Deelzaak creation can be triggered by workflow transitions
- **Procest case management**: Core case model in `openspec/specs/case-management/`
- **OpenRegister**: Case objects and relationships stored in OpenRegister
- **signalering-widgets** (RECOMMENDED): Cross-case notifications when deelzaken complete

## Acceptance Criteria

1. GIVEN a zaaktype configuration, WHEN an administrator defines allowed deelzaak types, THEN only the configured zaaktypen are available when creating a deelzaak from a parent case
2. GIVEN a parent case, WHEN a handler creates a deelzaak, THEN the deelzaak inherits configured fields (afhandeltermijn, archiefkenmerk) from the parent and is linked bidirectionally
3. GIVEN a zaaktype with automatic deelzaak creation configured, WHEN the parent case reaches the trigger status, THEN the deelzaak is automatically created and added to the assigned handler's werkvoorraad
4. GIVEN a parent case with 3 levels of deelzaken, WHEN a user views the case hierarchy, THEN they see a visual tree showing all levels with status indicators per deelzaak
5. GIVEN a parent case configured to require all deelzaken completed before closure, WHEN the handler attempts to close the parent while deelzaken are still open, THEN the closure is blocked with a clear message listing the open deelzaken
6. GIVEN a deelzaak that completes, WHEN the completion is registered, THEN the parent case handler receives a notification and the parent case hierarchy view updates
7. GIVEN a vervolg-zaak configuration, WHEN a case reaches the configured trigger status, THEN a new follow-up case is created with a "predecessor" link to the original case and the original case shows a "successor" link
8. GIVEN a parent case with deelzaken, WHEN a user views the case dossier, THEN they see documents from the parent case and can optionally include documents from deelzaken in a consolidated view
