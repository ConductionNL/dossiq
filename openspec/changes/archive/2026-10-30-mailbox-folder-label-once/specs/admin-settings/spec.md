# admin-settings Delta: mailbox-folder-label-once

## ADDED Requirements

### Requirement: An admin field label reads the same after the library translates it

An admin settings field label SHALL show its intended text after CnSettingsFieldsSection translates it. A label whose translation is itself a catalogue key with another translation SHALL be handed over as its key.

#### Scenario: The shared mailbox folder
- GIVEN the Dutch locale
- WHEN the Zaak-e-mail section renders the folder field
- THEN its label reads "Map", not "Kaart"
