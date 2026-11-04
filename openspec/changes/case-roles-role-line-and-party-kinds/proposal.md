# Each people card reads the field meant for it

## Why
Board DqZaakBetrokkenen draws one party twice with different words. Partijen prints the person's details: "Contact · Lindelaan 14 · ingelogd met DigiD", "Organisatie · KvK 00001005 · contactpersoon J. van Wijk". Rollen prints the role line: "Primaire partij · verzoeker", "Gemachtigde namens Aannemersbedrijf Van Wijk B.V. · Awb 2:1", "Belanghebbende · KvK 00001005". Both cards read the party link's note, so Rollen printed the Partijen words, and Partijen had no kind word.

## What changes
Config only, both structures (`src/manifest.json`, `src/menu-layout.simple.json`):
- the Rollen card declares `note: { from: "roles", field: "description" }`: it prints the role record's description, the field that describes the role assignment;
- the Partijen card declares `labels.kindLabels`: person and contact "Contact", organisation "Organisation", colleague and user "Colleague" (nl: Contact, Organisatie, Collega).

Needs nextcloud-vue `fix/dq-parties-role-line-and-kind-words` (parties widget `content.note`, contacts tab `kindLabels`, host label maps). Until it lands both keys are ignored.
