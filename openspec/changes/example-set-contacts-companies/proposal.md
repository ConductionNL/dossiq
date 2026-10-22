# Proposal: example-set-contacts-companies

## Why

DqContacten shows Pieter Bos, DqOrganisaties shows Aannemersbedrijf Van Wijk B.V., and the duplicate warning on DqNieuweZaak offers Henk Visser of Beukenlaan 3 and Visser Bouw B.V. dossiq's contact registers held none of them.

## What changes

- Two `brpPerson` records (Pieter Bos, Henk Visser) and two `kvkCompany` records (Visser Bouw, Van Wijk), each quoting the cast.
- Depends on the cast entries in openregister `feat/zuiddrecht-cast-contacts-tenant`. Without them the placeholders do not resolve and the set refuses to install.
- For the parties on the Lindelaan Woo case (DqZaakBetrokkenen): the person J. van Wijk, and the company record of Bewonersvereniging Lindelaan carrying the indicator "Meerdere meldingen" (effect \`warn\`). The indicator needs the \`indicators\` property from \`feat/woo-role-types-and-party-schemas\`.
