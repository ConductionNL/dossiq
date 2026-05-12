# Case Types

The Case Types page (under Settings) allows administrators to manage case type definitions and their configurations.

![Case Types](/screenshots/case-types.png)

## Overview

The page is divided into several sections:

### Version Information
Displays application metadata:
- **Application Name**: Procest
- **Version**: Current installed version
- **Up to date**: Status indicator for configuration currency
- **Re-import configuration**: Button to re-import default case type configurations
- **Support**: Contact information for support (support@conduction.nl) and SLA inquiries (sales@conduction.nl)

### Configuration
Register and schema settings that link Procest to OpenRegister:
- **Register** -- The OpenRegister register ID used for case data.
- **Case schema** -- Schema ID for case objects.
- **Task schema** -- Schema ID for task objects.
- **Status schema** -- Schema ID for status objects.
- **Role schema** -- Schema ID for role objects.
- **Result schema** -- Schema ID for result objects.
- **Decision schema** -- Schema ID for decision objects.
- **Case type schema** -- Schema ID for case type definitions.
- **Status type schema** -- Schema ID for status type definitions.

### Case Type Management
A table listing all configured case types with ZGW (Zaakgericht Werken) properties including catalogus, confidentiality, description, identifier, processingDeadline, title, and many more.

Example case types include:
- **Bezwaar** (BEZWAAR) -- Objection procedure, openbaar (public) confidentiality
- **Vergunning** (VERGUNNING) -- Permit application, openbaar confidentiality
- **Melding** (MELDING) -- Public space report, geheim (confidential)
- **Vergunning aanvraag** (VRG-001) -- Environmental permit application

### ZGW API Mapping
Configuration for property mappings between English OpenRegister fields and Dutch ZGW API fields. Supported resources: zaak, zaaktype, status, statustype, resultaat, resultaattype, rol, roltype, eigenschap, besluit, besluittype, informatieobjecttype. Each mapping can be edited or reset to defaults.
