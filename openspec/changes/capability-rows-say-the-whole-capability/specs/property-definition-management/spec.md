# property-definition-management

## ADDED Requirements

### Requirement: You group templates in folders (REQ-CRS-39)

The Templates page SHALL show a folder sidebar over the categories its templates carry, with All templates on top. Picking a folder SHALL narrow the list to its templates.

Rows: `11.23` (dossiq matrix).

#### Scenario: A folder narrows the templates
- **GIVEN** two templates in the category Permits and three in others
- **WHEN** you pick Permits
- **THEN** the list shows the two only
