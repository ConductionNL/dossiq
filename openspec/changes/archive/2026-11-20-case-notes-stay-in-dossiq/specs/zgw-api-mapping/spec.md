## REMOVED Requirements

### Requirement: A case note leaves for the neighbouring register as a document

**Reason**: Decision 395(b): case notes stay in dossiq and are stored in OpenRegister as notes on the case; there is no sync to an external case register.
**Migration**: A note stays an OpenRegister comment on the case (openregister `object-interactions`, Notes on Objects via ICommentsManager). The push endpoint and the Notes tab action are removed in this change's tasks.

### Requirement: An internal note stays here

**Reason**: With no push, every note stays here, so a rule that keeps only internal notes here has nothing left to guard.
**Migration**: None. The visibility marker the timeline writes stays, and keeps deciding who reads a note.

### Requirement: A note that did not leave says so on the case

**Reason**: There is no push whose outcome could be recorded.
**Migration**: None. Existing push outcome lines on case timelines stay as history.
