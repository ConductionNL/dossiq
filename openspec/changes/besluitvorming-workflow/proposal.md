# Besluitvorming Workflow

## Summary

Configure the Procest workflow engine (from `workflow-engine-enhancement`) with workflows for the municipal decision-making process (bestuurlijke besluitvorming). This covers the lifecycle of formal decisions by college van B&W, gemeenteraad, and other governing bodies -- from proposal drafting through approval, signing, publication, and archival.

This is primarily a workflow configuration change that defines decision-specific zaaktypen and process steps, with targeted extensions for parafering (approval chains), agenda management, and publication to official registers (DROP/LVBB).

## Demand Evidence

### Cluster Data (from market intelligence DB)

| Cluster | Requirements | Tenders |
|---------|-------------|---------|
| Besluitvorming (decision process) | 264 | 126 |
| **Total** | **264** | **126** |

### Top Tenders

| Tender | Organisation | URL |
|--------|-------------|-----|
| Zaaksysteem | Werkorganisatie HLT Samen | https://www.tenderned.nl/aankondigingen/overzicht/399455 |
| Geintegreerd Zaaksysteem met KCS-functionaliteit | Gemeente Beverwijk | https://www.tenderned.nl/aankondigingen/overzicht/411386 |
| Zaaksysteem gemeente Winterswijk | Gemeente Winterswijk | https://www.tenderned.nl/aankondigingen/overzicht/198896 |
| Zaaksysteem (met geintegreerd DMS en RMA) | Gemeente Nissewaard | https://www.tenderned.nl/aankondigingen/overzicht/257916 |
| Vergunning-, Toezicht- en Handhaving software Omgevingswet | Gemeente Zeist | https://www.tenderned.nl/aankondigingen/overzicht/362898 |
| De levering, onderhoud en implementatie van een SaaS-oplossing voor een Zaaksysteem | Gemeente Horst aan de Maas | https://www.tenderned.nl/aankondigingen/overzicht/345060 |

### Representative Requirements from Tenders

1. "De gemeente Coevorden wil het bestuurlijke besluitvormingsproces onderbrengen en ondersteunen binnen de Oplossing. Hierbij is de wens om de processen voor verschillende vergadergremia (bijv. college en gemeenteraad) in te richten."
2. "Besluitvormingsstukken zijn voorzien van metadata (zaaktype, onderwerp, portefeuillehouder, status)."
3. "Het kunnen publiceren van een aanvraag of besluit via het officiele publicatiemechanisme (nu DROP, wordt later LVBB) via de Oplossing."
4. "VTH070 Kunnen vastleggen van samenhang met andere procedures/besluitvorming."
5. "SGC 1 Ondersteuning bestuurlijke besluitvormingsketen"
6. "De Gemeente Zeist wil het bestuurlijke besluitvormingsproces onderbrengen en ondersteunen binnen de Oplossing."
7. "Er dienen meerjarige mutaties op basis van besluitvorming geraamd te kunnen worden per reserve en voorziening."
8. "De Gemeente Meppel kan zonder tussenkomst van de leverancier zelf, op basis van Zero-coding, rapportages binnen het zaaksysteem creeren en opslaan. Bestuurlijke besluitvorming."

## Scope

### In Scope -- Configuration (using workflow engine)

- **Besluitvorming zaaktypen**: Pre-configured zaaktypen for College-besluit, Raadsbesluit, Mandaatbesluit
- **Besluitvorming process steps**: Voorstel opstellen, Ambtelijk advies, Parafering, Agendering, Vergadering, Besluit, Bekendmaking, Archivering
- **Status transitions**: Met pre-conditions per stap (e.g., all parafen required before agendering)
- **Vergadergremia configuration**: Configure multiple decision bodies (College B&W, Gemeenteraad, Commissies) with their own agenda and decision workflows

### In Scope -- Extra functionality beyond generic workflows

- **Parafering chain**: Sequential approval workflow where designated officials sign off (paraaf) on a decision document before it reaches the decision body; configurable chain per zaaktype/mandaat level
- **Agenda management**: Compile decisions into meeting agendas per vergadergremia, with support for hamerstukken (consent agenda) vs. bespreekstukken (discussion items)
- **Decision metadata**: Structured capture of decision metadata (portefeuillehouder, onderwerp, zaaktype, stemuitslag) as OpenRegister data
- **Publication hooks**: Integration point for publishing decisions to DROP (Decentrale Regelgeving en Officiële Publicaties) or LVBB (Landelijke Voorziening Bekendmaken en Beschikbaarstellen)
- **Mandaatregister linking**: Link decisions to the mandaatregister to validate decision authority

### Out of Scope

- Generic workflow engine (covered by `workflow-engine-enhancement`)
- Raadsinformatie system (see existing spec `openspec/specs/open-raadsinformatie/`)
- Document generation/templates (generic Procest/Docudesk functionality)
- Financial impact tracking of decisions (ERP domain)

## Dependencies

- **workflow-engine-enhancement** (REQUIRED): This change configures the workflow engine
- **Procest roles-decisions spec**: Existing spec at `openspec/specs/roles-decisions/`
- **Procest bw-parafering spec**: Existing spec at `openspec/specs/bw-parafering/`
- **Docudesk**: Document generation for besluitvorming documents
- **Nextcloud Calendar**: Vergadering scheduling

## Acceptance Criteria

1. GIVEN the workflow engine with besluitvorming templates installed, WHEN an administrator configures a College-besluit zaaktype, THEN the standard process steps (voorstel, advies, parafering, agendering, besluit, bekendmaking) are pre-configured
2. GIVEN a besluitvorming case, WHEN the voorstel is submitted for parafering, THEN the configured approval chain is activated and each designated official receives a task to paraaf the document
3. GIVEN a parafering chain, WHEN all required parafen are collected, THEN the case automatically transitions to "Gereed voor agendering" and becomes available for agenda compilation
4. GIVEN multiple besluitvorming cases ready for agendering, WHEN the agenda manager compiles a meeting agenda, THEN cases can be assigned as hamerstuk or bespreekstuk with configurable ordering
5. GIVEN a vergadering zaak, WHEN the decision is taken, THEN the stemuitslag, besluit text, and attending members are recorded as structured metadata
6. GIVEN a besluit that must be published, WHEN the handler triggers publication, THEN the system provides an integration point for DROP/LVBB publication with the required metadata
7. GIVEN a mandaatbesluit, WHEN the decision is registered, THEN the system validates that the signing official has the authority per the configured mandaatregister
8. GIVEN a completed besluitvorming case, WHEN the case is archived, THEN all related documents (voorstel, adviezen, parafen, besluit, bekendmaking) are linked in the case dossier
