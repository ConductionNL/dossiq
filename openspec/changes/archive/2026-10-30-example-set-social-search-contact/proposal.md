# Proposal: example-set-social-search-contact

## Why

Three boards draw example data the shipped data did not hold:
- DqSociaalDomein: the jeugdwet case's legal basis ("Jeugdwet artikel 2.3"), who reported it ("Huisarts, met toestemming
  van de ouders"), its regisseur (Fatma Kaya, jeugdteam) and a deadline about five weeks after receipt.
- DqZoekresultaten: a saved search for "Lindelaan" with its count alert at 12.
- DqContact: the portal resident Sanne de Vries as a contact.

## What changes

- The jeugdwet package's example case carries the legal basis, the initiator, its regisseur (`fatma.kaya`, the cast's
  new jeugdteam regisseur, OpenRegister `example-set-views`) and a deadline 35 days after receipt.
- The Zuiddrecht example set declares a public saved search on the search page, owned by the cast's Woo coordinator,
  with the count alert `gte 12` (needs OpenRegister example-set views).
- The set seeds Sanne de Vries as a `brpPerson`, every field from the cast.
