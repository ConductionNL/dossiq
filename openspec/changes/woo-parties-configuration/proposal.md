# Proposal: woo-parties-configuration

## Why

DqZaakBetrokkenen draws the parties of the Lindelaan Woo case from OpenRegister's party model, by role:
- Sanne de Vries as the requester and primary party;
- J. van Wijk as the authorised representative of Aannemersbedrijf Van Wijk, with a mandate until 31 December;
- Aannemersbedrijf Van Wijk as an interested party;
- Lars de Koning as adviser;
- the residents' association Lindelaan as a reporter without an account, carrying the indicator "meerdere meldingen".

Two pieces of configuration were missing:
- The Woo request case type had no role types, so the case schema's link roles offered nothing for a Woo case.
- dossiq's BRP person and KvK company records were not parties. Neither schema declared `x-openregister-party`, so the party model could not link them or read their indicators.

## What changes

- **`register.d/87-woo-role-types.json`**: five role types on the Woo case type, with fixed uuids:
  - Verzoeker (initiator)
  - Gemachtigde (gemachtigde)
  - Belanghebbende (stakeholder)
  - Adviseur (advisor)
  - Melder (contact)
- **`brpPerson`** declares `x-openregister-party: {kind: person, nameProperty: displayName}`. **`kvkCompany`** declares `{kind: organisation, nameProperty: tradeName}`. Both gain an `indicators` list (key, label, effect, note), which is the property the party model reads.
  - Secrecy is an indicator with the effect `refuse-publication`, as OpenRegister's party model already defines it.
  - The schema versions move to 1.4.0 and 1.3.0.
- **Lookup fix:** the set's lookup for Linda's role type also names the shipped generic role type's description. It would otherwise match the new Woo "Gemachtigde" as well.
- Configuration only, no code (decision 182).
- The example data that puts the parties on the case follows in portaliq's fragment, through OpenRegister's `parties` part (openregister `feat/example-set-parties`).
