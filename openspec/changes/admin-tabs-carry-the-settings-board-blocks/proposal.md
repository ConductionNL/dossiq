# Proposal: the admin tabs carry the blocks the settings boards draw

## Why

The 3 November design round redrew dossiq's settings boards. The admin page lacked what they now draw: on Integrations the apps dossiq needs, one table of statutory terms, the queue urgency, the search index run and the Woo refusal grounds; on Advanced the search index rows; on General the time zone, the date format and a beta switch for the new case page; on VTH an advice card and a public notices card beside the checklists. The setup wizard names its first step Welcome.

## What changes

- Integrations: Add connection in integriq above the connections table; after Kadaster the apps dossiq needs (by product name, with the app id each was found under), Term definitions (Awb) in one table with Add definition, Urgency in the queue beside the Search index run (Update now queues maintenance as a background job), and Woo refusal grounds with Add ground.
- Advanced: Search index after the KCC settings, with the rows in the tables against the rows in the index, the last run and its duration, and the command that rebuilds. When OpenRegister does not answer the card says so instead of showing zeros.
- General: Time zone, Date format and, under Beta features, "New case page visible to everyone at once, without waiting for the cache".
- VTH: Inspection checklists, Advice and advisers, Public notices and Enforcement strategy three abreast.
- The setup wizard's first step is titled Welcome.

## Out of scope

The side-state panels the boards draw at their foot (Zonder Integriq, Inkomende mail, Klachtcategorieën), the Test button on a failing connection and a ground's change history wait for the side-state decision and the backend.

## Boards

DqInstellingen, DqBeheerGeavanceerd, DqInstellingenAlgemeen, DqVthInstellingen, DqInstellen.
