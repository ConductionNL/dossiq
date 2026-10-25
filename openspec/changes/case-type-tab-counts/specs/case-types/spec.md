# case-types Delta

## ADDED Requirements

### Requirement: The case type's tabs say how much each holds

The case type page's tab strip SHALL show a count after Fasen en taken (the tasks over all phases), Regels (the
rules of this case type) and Vertalingen (the languages the case type is kept in), as it does after Velden,
Statussen and Versies.

#### Scenario: A permit case type with six tasks, three rules and two languages

- **GIVEN** a case type whose phases run six tasks, with three rules, in a register kept in Dutch and English
- **THEN** the tabs read "Fasen en taken 6", "Regels 3" and "Vertalingen 2"
