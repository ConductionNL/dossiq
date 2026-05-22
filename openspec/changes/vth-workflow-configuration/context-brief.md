# VTH Workflow Configuration

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Configuratie › Workflow-editor

**Rationale:** VTH-templates in workflow-editor.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Summary

Configure the Procest workflow engine (from `workflow-engine-enhancement`) with domain-specific workflows for Vergunningverlening, Toezicht en Handhaving (VTH). This is primarily a configuration change -- defining VTH-specific zaaktypen, process steps, and status transitions -- with targeted extensions for VTH-specific functionality like leges calculation, mobile inspection support, and DSO integration hooks.

This change should carefully compare against what Procest already provides generically and only add what VTH specifically needs beyond the generic workflow engine.

## Demand Evidence

### Cluster Data (from market intelligence DB)

| Cluster | Requirements | Tenders |
|---------|-------------|---------|
| VTH activiteitenbeheer | 767 | 193 |
| DSO (Digitaal Stelsel Omgevingswet) | 1,134 | 239 |
| Omgevingsvergunning (env. permit) | 83 | 36 |
| Inspection management | 256 | 52 |
| Mobile inspection | 76 | 28 |
| Leges (fees) calculation | 230 | 65 |
| Enforcement | 269 | 59 |
| Permit granting | 73 | 33 |
| **Total** | **2,888** | **~436 unique** |

### Top Tenders

| Tender | Organisation | URL |
|--------|-------------|-----|
| Vergunning-, Toezicht- en Handhaving software Omgevingswet | Gemeente Zeist | https://www.tenderned.nl/aankondigingen/overzicht/362898 |
| VTH systeem | Gemeente Zoetermeer | https://www.tenderned.nl/aankondigingen/overzicht/263767 |
| Levering en ondersteuning nieuw VTH systeem op basis van SaaS | Omgevingsdienst Noordzeekanaalgebied | https://www.tenderned.nl/aankondigingen/overzicht/308208 |
| VTH Applicatie | Gemeente Zaanstad | https://www.tenderned.nl/aankondigingen/overzicht/216588 |
| VTH software gemeente Waalwijk | Gemeente Waalwijk | https://www.tenderned.nl/aankondigingen/overzicht/256225 |
| Levering en implementatie van een SaaS-oplossing ter ondersteuning van de VTH-pr | Rijkswaterstaat Centrale Informatievoorz | https://www.tenderned.nl/aankondigingen/overzicht/402863 |

### Representative Requirements from Tenders

1. "VTH049 Kunnen opstellen van een concept beschikking"
2. "De Oplossing wordt geleverd als applicatie die ondersteuning biedt bij de uitvoering van de processen Vergunningverlening, Toezicht en Handhaving (VTH)"
3. "De oplossing beschikt over een leges module die op basis van kenmerken in de zaak, het leges bedrag automatisch berekent en toevoegt aan een kenmerk in de zaak"
4. "De Oplossing biedt de mogelijkheid om: Te verrekenen, eerder opgelegde leges in mindering te brengen. Teruggaaf van leges. Navorderen."
5. "Planning voor inspectie koppelen met agenda"
6. "De Oplossing ondersteunt het gebruik van tablets, smartphones en andere mobiele devices via aangepaste interface of responsive design zonder kwaliteit in te leveren op leesbaarheid van tekst met behoud van in ieder geval de Toezicht- en Handhavingsfunctionaliteit."
7. "Gemeente De Bilt ziet het verbeteren van de datakwaliteit als belangrijke pijler voor VTH. Vergunningen als data vastleggen (doorzoekbaar, optelbaar)."
8. "De ondersteuning van de ICT-oplossing voor het volgen van de LHSO-classificatie en het starten van een handhavingstraject door het starten van een zaak..."
9. "Voor vergunningverlening, toezicht, handhaving en monitoring is het van belang dat een beschikking i.r.t. een subject, object en/of activiteit wordt geregistreerd"

## Scope

### In Scope -- Configuration (using workflow engine)

- **VTH zaaktypen templates**: Pre-configured zaaktypen for Omgevingsvergunning, Toezicht, Handhaving
- **VTH process steps**: Standard VTH workflow steps (intake, beoordeling, advies, beschikking, bekendmaking)
- **VTH status transitions**: Domain-specific statuses (Aanvraag ontvangen, In behandeling, Beschikking opgesteld, Verzonden, etc.)
- **VTH role definitions**: Inspector, Vergunningverlener, Handhaver, Juridisch adviseur
- **VTH checklist templates**: Per-step checklists (completeness check, BIBOB, advies extern)

### In Scope -- Extra functionality beyond generic workflows

- **Leges calculation module**: Rule-based fee calculation engine configurable per zaaktype/activiteit (rates, exemptions, verrekening, teruggaaf, navordering)
- **Mobile inspection view**: Responsive/mobile-optimized view for inspectors in the field (checklist-based, photo upload, GPS location)
- **DSO integration hooks**: Receive applications from Digitaal Stelsel Omgevingswet (API endpoint for DSO verzoeken)
- **LHSO classification support**: Landelijke Handhavingsstrategie Omgevingsrecht classification in enforcement workflows
- **Beschikking generation**: Template-based generation of permits/decisions with merge fields from case data
- **Activiteit-object-subject linking**: Register vergunningen as data linked to activity, location (object), and applicant (subject)

### Out of Scope

- Generic workflow engine functionality (covered by `workflow-engine-enhancement`)
- GIS/map integration for locations (covered by `gis-integration`)
- General signalering (covered by `signalering-widgets`)
- Bezwaar/beroep after VTH decisions (covered by `bezwaar-beroep-workflow`)

## Dependencies

- **workflow-engine-enhancement** (REQUIRED): This change configures the workflow engine; it cannot proceed without the engine being built first
- **Existing procest specs**: `openspec/specs/vth-module/`, `openspec/specs/legesberekening/`, `openspec/specs/mobiel-inspectie/`, `openspec/specs/dso-omgevingsloket/`
- **OpenRegister**: VTH-specific schemas stored in OpenRegister
- **Nextcloud Calendar**: Inspection planning linked to calendar

## Acceptance Criteria

1. GIVEN the workflow engine is deployed, WHEN an administrator installs the VTH workflow templates, THEN zaaktypen for Omgevingsvergunning, Toezicht, and Handhaving are available with pre-configured process steps
2. GIVEN an Omgevingsvergunning zaak, WHEN the case reaches the leges step, THEN the system automatically calculates fees based on configured rates and activity characteristics
3. GIVEN a leges calculation, WHEN the administrator configures verrekening rules, THEN previously imposed leges are automatically deducted from the new calculation
4. GIVEN an inspector in the field, WHEN they open a toezichtzaak on a mobile device, THEN they see a responsive inspection checklist with photo upload and GPS tagging capabilities
5. GIVEN a DSO integration endpoint, WHEN a verzoek arrives from the Omgevingswet digital system, THEN a new case is automatically created with the correct zaaktype and pre-filled data
6. GIVEN an enforcement case, WHEN the handler classifies the violation using LHSO, THEN the system suggests the appropriate handhaving trajectory based on the classification
7. GIVEN a VTH beschikking step, WHEN the handler generates the permit document, THEN case data (applicant, location, activities, conditions) is automatically merged into the template
8. GIVEN a granted vergunning, WHEN a user searches by activity, location, or subject, THEN the vergunning is findable as structured data (not just a document)
