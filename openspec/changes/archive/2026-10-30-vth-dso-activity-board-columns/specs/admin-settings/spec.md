# admin-settings Delta: vth-dso-activity-board-columns

## ADDED Requirements

### Requirement: The DSO activity table shows what each activity becomes and when it last arrived

The VTH tab's "Activities from the Omgevingsloket" table SHALL read integriq's DSO activity overview and SHALL show the columns DSO activity, Becomes case type, Variant and Last received. A row integriq marks `unmapped` SHALL read "Not linked yet: %n request(s) waiting" in its case type cell, drawn as an error. A row's note SHALL stay as the activity's second line, and a row that is not active SHALL carry an "Inactive" pill.

#### Scenario: A linked activity and an unlinked one
- GIVEN integriq maps "Kappen van een houtopstand" to Omgevingsvergunning regulier, variant Regulier, last received 5 October, and one request waits with an activity no row maps
- WHEN an administrator opens the VTH tab
- THEN the first row reads Kappen van een houtopstand / Omgevingsvergunning regulier / Regulier / 5 okt
- AND the last row's case type cell reads "Nog niet gekoppeld: 1 aanvraag wacht" in red
