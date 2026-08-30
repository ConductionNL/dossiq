## ADDED Requirements

### Requirement: Pre-Seeded Bezwaar Workflow Template

The system SHALL provide a pre-seeded workflow template for the Bezwaar case type that encodes the AWB-mandated process steps, transitions, and guards. The template SHALL be imported via the repair step alongside the bezwaar case type.

**Feature tier**: V1

The bezwaar workflow template SHALL define the following transitions:

| From Status | To Status | Label | Guards |
|-------------|-----------|-------|--------|
| Ontvangen | Ontvankelijkheidstoets | Start toets | roleGuard: Behandelaar bezwaar |
| Ontvankelijkheidstoets | In behandeling | Ontvankelijk | requiredField: isTimely assessment |
| Ontvankelijkheidstoets | Niet-ontvankelijk | Niet-ontvankelijk verklaren | requiredField: dispositionDetails |
| In behandeling | Hoorzitting gepland | Hoorzitting plannen | -- |
| In behandeling | Advies uitgebracht | Hoorrecht afgezien | requiredField: hearingWaived=true |
| Hoorzitting gepland | Hoorzitting afgerond | Hoorzitting afronden | requiredField: minutesSummary |
| Hoorzitting afgerond | Advies uitgebracht | Advies uitbrengen | requiredField: advisoryReport |
| In behandeling | Beslissing op bezwaar | Direct beslissen | roleGuard: Beslisser (when no committee) |
| Advies uitgebracht | Beslissing op bezwaar | Beslissing nemen | requiredField: dispositionType, dispositionDetails |
| Beslissing op bezwaar | Afgehandeld | Afronden | checklist: decision letter sent, rechtsmiddelenclausule included |
| Any active | Ingetrokken | Intrekken | requiredField: withdrawal reason |

The workflow template SHALL include workflow steps for each status phase:

| Status Phase | Steps |
|-------------|-------|
| Ontvangen | Registreer bezwaarschrift, Controleer volledigheid, Bevestig ontvangst |
| Ontvankelijkheidstoets | Toets termijn, Toets belanghebbendheid, Toets besluit-karakter |
| In behandeling | Stel dossier samen, Informeer primair beslisser, Plan hoorzitting of registreer afzien |
| Hoorzitting gepland | Verstuur uitnodigingen, Bereid hoorzitting voor |
| Hoorzitting afgerond | Maak verslag, Deel verslag met partijen |
| Advies uitgebracht | Stel advies op, Deel advies met bestuursorgaan |
| Beslissing op bezwaar | Neem beslissing, Stel besluit op, Verstuur besluit met rechtsmiddelenclausule |

#### Scenario: Bezwaar workflow template is seeded after repair

- **WHEN** the Procest app repair step runs
- **THEN** a workflow template SHALL exist for the Bezwaar case type
- **AND** the template SHALL contain all defined transitions with their guards
- **AND** the template SHALL contain all defined steps per status phase
- **AND** the template SHALL be published (isDraft: false, isActive: true)

#### Scenario: Administrator can customize the bezwaar workflow

- **WHEN** an administrator opens the Bezwaar case type in the admin settings
- **THEN** the pre-seeded workflow template SHALL be visible in the workflow tab
- **AND** the administrator SHALL be able to create a new version to customize steps and transitions
- **AND** the original pre-seeded template SHALL remain as a base version

### Requirement: Pre-Seeded Beroep Workflow Template

The system SHALL provide a pre-seeded workflow template for the Beroep case type with transitions for tracking court proceedings.

**Feature tier**: V1

| From Status | To Status | Label | Guards |
|-------------|-----------|-------|--------|
| Beroep ontvangen | Verweerschrift in voorbereiding | Start verweer | roleGuard: Behandelaar |
| Verweerschrift in voorbereiding | Verweerschrift ingediend | Verweer indienen | requiredDocument: Verweerschrift |
| Verweerschrift ingediend | Zitting gepland | Zitting plannen | -- |
| Zitting gepland | Zitting afgerond | Zitting afronden | -- |
| Zitting afgerond | Uitspraak ontvangen | Uitspraak registreren | requiredField: ruling outcome |
| Uitspraak ontvangen | Afgehandeld | Afronden | -- |
| Any active | Ingetrokken | Intrekken | -- |
| Any active | Schikking | Schikking treffen | requiredField: settlement details |

#### Scenario: Beroep workflow template is seeded after repair

- **WHEN** the Procest app repair step runs
- **THEN** a workflow template SHALL exist for the Beroep case type
- **AND** the template SHALL contain all defined transitions
- **AND** the template SHALL be published (isDraft: false, isActive: true)
