# admin-settings Delta: fee-rules-column

## ADDED Requirements

### Requirement: The fee table names each rule and its calculation

The VTH settings' fee table SHALL show each shillinq fee rule by its name (its type value when it has none), its
regulation and article, and its calculation in words.

#### Scenario: Building costs

- **GIVEN** a rule "Bouwen, per euro bouwkosten", percentage 2.35 of `bouwkosten`, minimum 125, Legesverordening 2026 2.3.1
- **THEN** the row reads "Bouwen, per euro bouwkosten", "Legesverordening 2026, 2.3.1", "2,35 % van de bouwkosten, minimaal € 125,00"
