## MODIFIED Requirements

### Requirement: Case Detail View Sections

The case detail view SHALL display all relevant sections for the case including participants, tasks, timeline, result, and B&W voorstellen. The B&W Voorstellen panel is added by the besluitvorming-workflow change.

**Tier**: V1

#### Scenario: B&W Voorstellen panel on case detail

- **WHEN** the user views a case detail page
- **THEN** a "B&W Voorstellen" panel SHALL be displayed in the case detail sidebar
- **AND** if no voorstellen exist, the panel SHALL show: "Geen voorstellen" with a "Nieuw voorstel" button
- **AND** if voorstellen exist, each SHALL show: type, status, current parafeeerstap, steller

#### Scenario: Multiple voorstellen displayed

- **WHEN** a case has 2 voorstellen (one "besloten", one "in_parafering")
- **THEN** both SHALL be listed in the B&W Voorstellen panel
- **AND** each SHALL be clickable to navigate to the voorstel detail view
- **AND** the active voorstel SHALL be visually distinguished from completed ones

#### Scenario: Create voorstel from case detail

- **WHEN** the user clicks "Nieuw voorstel" in the B&W Voorstellen panel
- **THEN** a creation dialog SHALL open pre-filled with the case context
- **AND** after creation, the new voorstel SHALL appear in the panel
