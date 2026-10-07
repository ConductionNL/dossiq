## MODIFIED Requirements

### Requirement: The template seam SHALL say whether a real renderer is behind it

`TemplateEngineAdapterInterface` SHALL be bound from the `beschikking_template_adapter`
app-config key, so an integrator can substitute a renderer without editing dossiq.
When no class is named and filinq is enabled, the seam SHALL bind
`FilinqTemplateEngineAdapter`. When no class is named and filinq is not enabled, the
seam SHALL bind `MockTemplateEngineAdapter` and SHALL log a translated warning that
tells the reader to install filinq. When an administrator names the mock, the seam
SHALL bind it and SHALL log that the mock was chosen. The Integrations page SHALL
carry a Document templates card that reads Live when filinq's adapter is bound and
Simulated when the mock is bound, decided by the same rule the seam uses.

The filinq probe SHALL resolve through `FleetAppId`, never a literal app id. Filinq
renamed from docudesk, both names are in the field, and a hardcoded lookup against the
wrong one returns false and takes the integration dark without erroring.

**Feature tier**: MVP

#### Scenario: Filinq absent, and the warning says to install it
@e2e exclude The binding is a DI-time decision with no browser surface; AdapterHonestyTest asserts the resolution and the vitest seed guard asserts the card.

- **GIVEN** an instance where filinq is not installed
- **WHEN** the template seam resolves
- **THEN** it SHALL bind the mock
- **AND** the warning SHALL tell the reader to install filinq, then name its adapter class

#### Scenario: Filinq present but no adapter named, and the warning says which
@e2e exclude Same absent surface; asserted by AdapterHonestyTest.

- **GIVEN** an instance where filinq is installed and enabled and `beschikking_template_adapter` is empty
- **WHEN** the template seam resolves
- **THEN** it SHALL bind `FilinqTemplateEngineAdapter`, not the mock
- **AND** no fallback warning SHALL be logged, and the Document templates card SHALL name filinq's adapter as the one that answers

#### Scenario: An administrator chose the mock
@e2e exclude Same absent surface; asserted by AdapterHonestyTest.

- **GIVEN** an instance where filinq is enabled and `beschikking_template_adapter` names `MockTemplateEngineAdapter`
- **WHEN** the template seam resolves
- **THEN** it SHALL bind the mock
- **AND** the warning SHALL say the mock was chosen and name the key to clear

#### Scenario: The card reads what the seam bound
@e2e exclude The card is drawn from IntegrationStatusService; a PHPUnit over the service asserts both words.

- **GIVEN** filinq enabled and `beschikking_template_adapter` empty
- **WHEN** an administrator opens the Integrations page
- **THEN** the Document templates card SHALL read Live
- **AND** with filinq disabled and the key still empty, the card SHALL read Simulated
