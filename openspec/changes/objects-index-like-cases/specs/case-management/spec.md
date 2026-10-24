# case-management Delta: objects-index-like-cases

**Status**: draft

## ADDED Requirements

### Requirement: The Objects index MUST read like the All cases index

The Objects page SHALL be an `index` page over the case object schema with the All cases page's parts: search, the object-type folder list, saved views, the sidebar's count line, export and a fixed add label.

#### Scenario: The schema cannot be read

- **GIVEN** OpenRegister does not answer the schema request
- **WHEN** a user opens Objects
- **THEN** the add button reads "Add case object" and not the generic "Add Item"
