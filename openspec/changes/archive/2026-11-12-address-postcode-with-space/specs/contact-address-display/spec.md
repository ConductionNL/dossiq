# contact-address-display Delta

## ADDED Requirements

### Requirement: An address writes its postcode with a space

An address on a contact or organisation page SHALL write a Dutch postcode with a space between the digits and the letters, whatever the stored form.

#### Scenario: DqContact
- **GIVEN** Sanne de Vries, residence postcode stored as "2999AA"
- **THEN** the contact card reads "Lindelaan 14, 2999 AA Zuiddrecht" and Woonadres reads "2999 AA Zuiddrecht"
