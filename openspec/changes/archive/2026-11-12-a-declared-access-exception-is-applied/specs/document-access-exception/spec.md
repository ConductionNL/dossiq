## ADDED Requirements

### Requirement: An exception written straight onto a document is applied as the writer applies it

When a document is stored with an `accessException` that the last entry of its `accessExceptionHistory` does not hold, the system SHALL append a history entry (`set`, or `change` when the history holds another exception) naming `setBy` and `setAt`, SHALL derive and save the document's readers as the background service account, and SHALL restrict the document's file to the exception's people and groups when the document names its file. When a document with an applied exception names a new file, that file SHALL be restricted. The file SHALL be restricted before the record is saved; when restricting fails, nothing SHALL be saved. Applying a stored exception SHALL NOT lift or widen any restriction.

#### Scenario: The example set restricts a quote
- GIVEN the Zuiddrecht example set declares on "Offerte herplant essen.pdf" the exception Pieter Jansen and group Vergunningen with reason "Bedrijfsgevoelige prijzen"
- WHEN the set is installed or refreshed
- THEN the document's Who can see this card reads "Alleen Pieter Jansen en team Vergunningen"
- AND its history holds one `set` entry by Pieter Jansen
- AND OpenRegister serves the file only to Pieter Jansen, team Vergunningen, the case's handler and administrators

#### Scenario: An exception the writer stored is left alone
- GIVEN a document whose exception was set through the Who can see this card
- WHEN the document is saved again without a change to its exception or its file
- THEN no history entry is added and the file is not restricted again
