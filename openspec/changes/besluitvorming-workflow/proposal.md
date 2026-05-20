# Proposal: besluitvorming-workflow

## Why

Bestuurlijke besluitvorming is the second-highest demand cluster in Procest's municipal market: 264 analysed requirements and 126 tenders explicitly request support for the formal decision-making lifecycle (College van B&W, Gemeenteraad, commissies). Representative tender requirements call for "het bestuurlijke besluitvormingsproces onderbrengen en ondersteunen" with support for multiple vergadergremia, structured metadata (portefeuillehouder, stemuitslag, zaaktype), and official publication via DROP/LVBB. Currently Procest has generic case infrastructure and the `voorstel`/`parafering` primitives, but no pre-configured templates that wire them into a complete besluitvormingsketen — municipalities must build the entire zaaktype stack from scratch.

This change configures the Procest workflow engine (from `workflow-engine-enhancement`) with ready-to-use decision-making workflows and adds three targeted service extensions: a parafering chain engine, an agenda compiler, and a DROP/LVBB publication hook.

## What Changes

1. **Besluitvorming zaaktype templates**: Pre-configured `caseType` + `workflowTemplate` bundles for College-besluit, Raadsbesluit, and Mandaatbesluit — including `statusType`, `propertyDefinition`, `roleType`, `documentType`, and `resultType` records.
2. **Process steps and transitions**: Full eight-phase lifecycle (Voorstel opstellen → Ambtelijk advies → Parafering → Gereed voor agendering → Geagendeerd → Vergadering → Besluit genomen → Bekendmaking → Gearchiveerd) with guard conditions and automatic actions per transition.
3. **Vergadergremia configuration**: Tenant-configurable decision bodies (College B&W, Gemeenteraad, Commissies) each with their own agenda and decision workflow, stored as `caseType` variants.
4. **Parafering chain service**: `BesluitvormingParafeerService` that activates the configured `parafeerroute` when a `voorstel` is submitted, assigns sequential `task` objects to each parafeerder, and auto-transitions the case when all required parafen are collected.
5. **Agenda management service**: `AgendaService` that compiles ready-for-agendering cases into a `vergadering` agenda, supports classification as hamerstuk or bespreekstuk, and produces an ordered agenda document.
6. **Decision metadata capture**: Structured `decision` objects with `stemuitslag`, `governingBody`, attending members (via `role`), and `besluit` text captured at the vergadering step.
7. **DROP/LVBB publication hook**: `PublicationService` integration point invoked on bekendmaking transition, assembling the required metadata payload (STOP/TPOD compatible fields) and dispatching to the configured DROP or LVBB endpoint via OpenConnector.
8. **Mandaatregister validation**: Guard on the Mandaatbesluit workflow transition that queries the configured mandaatregister to verify the signing official has sufficient authority for the decision scope before allowing the besluit step.

## Impact

- **Affected projects**: procest (primary — services, templates, Vue components), openconnector (DROP/LVBB dispatch), openregister (configuration data).
- **Code surface**: new service classes (`BesluitvormingParafeerService`, `AgendaService`, `PublicationService`, `MandaatValidationService`), new workflow template JSON files, new Vue components (`AgendaCompilerView.vue`, `VergaderingDetailView.vue`, `BesluitPublicatiePanel.vue`), new routes, and a repair/seed step for templates.
- **Dependencies**: `workflow-engine-enhancement` (REQUIRED — workflow engine must be present), `bw-parafering` spec (parafering primitives), `roles-decisions` spec (roleType/decisionType patterns), Docudesk (besluit and agenda document generation), Nextcloud Calendar (vergadering scheduling), OpenConnector (DROP/LVBB dispatch).
- **Standards**: GEMMA Bestuurlijke Besluitvorming (SGC 1), Wet elektronische bekendmaking (Wab), DROP API v2, LVBB STOP-TPOD, VNG Raadsinformatie, Awb art. 3:42–3:44 (publicatieplicht).
