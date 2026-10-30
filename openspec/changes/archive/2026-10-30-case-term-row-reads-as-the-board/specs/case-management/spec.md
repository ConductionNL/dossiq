## ADDED Requirements

### Requirement: The term row reads in words with the extension the case type allows

A case SHALL carry its type's extension period (`statutoryExtension`). The Termijn en betaling card SHALL label the term "Wettelijke termijn" and read it in words with that extension, and SHALL label the payment check "Laatst gecontroleerd".

#### Scenario: a Woo case
- **GIVEN** a Woo case whose type has a term of P28D and an extension of P14D
- **THEN** the card reads "Wettelijke termijn: 4 weken, te verdagen met 2"
