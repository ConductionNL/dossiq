# Design: woo-request-from-a-portal-dossier

Read at dossiq `development` `55cb03c67` on 2026-09-30, against the contract in
hydra `openspec/changes/woo-citizen-journey/design.md` (C1, C5).

## D1. The case type is seeded, not only templated

`register.d/81-woo-verzoek.json` seeds the Woo request case type as objects, the
way `50-subsidie.json` and `46-demo-cases-english.json` seed theirs:

- `caseType` slug and `identifier` `woo-verzoek`, fixed uuid
  `3c0f5a00-0000-4000-a000-00000000a001`, `processingDeadline` `P28D`,
  `extensionAllowed` with `extensionPeriod` `P14D`, `publicationRequired`,
  `initialStatus` the first status.
- The eight statuses of the template, `Ontvangst` to `Afgehandeld`.
- Four result types, which `woo-case-type` requires and the template lacks:
  openbaar gemaakt, deels openbaar gemaakt, niet openbaar gemaakt, ingetrokken.
- The intake property definitions of the template.
- Portal windows (`portalAmendmentWindow`, `portalDocumentWindow`,
  `portalWithdrawal`) open in the first two statuses, so a resident can correct
  or withdraw a request before it is assessed.

The template stays in the template library, so an admin can still activate a
customised copy. `woo_verzoek.json` is a byte-identical duplicate and goes.

## D2. One creation path

`OCA\Dossiq\Woo\WooRequestIntake::start(array $request): array` takes the C5
shape `{subjectRef, collectionId?, onderwerp, omschrijving, periodeVan,
periodeTot, origin, originReference}` and returns `{caseId, caseUrl}`.

1. Validate: `subjectRef` and `onderwerp` required; `origin` is `portal` or
   `pipelinq`; the periods are dates and `periodeVan` is not after
   `periodeTot`. A refusal throws `WooRequestRefused` with a code; the caller
   maps it to 400.
2. When `collectionId` is given, read the opencatalogi `collection` as the
   system and compare its `owner` to `subjectRef`. Absent or someone else's
   both throw `not_found`, so the caller answers 404 either way.
3. Write the case: `title` = `onderwerp`, `description` = `omschrijving`,
   `caseType` the seeded uuid, `status` the type's `initialStatus`,
   `startDate` today (so `deadline` = startDate + P28D), `portalSubject` =
   `subjectRef`, `intakeChannel` = `portal` or `pipelinq`, and `wooRequest` =
   `{onderwerp, omschrijving, periodeVan, periodeTot, origin, originReference,
   collectionId}`.
4. One `caseObject` per dossier item: `objectType` `opencatalogi.publication`,
   `objectUrl` the absolute public publication URL, `objectIdentification` the
   JSON string `{"publication": ..., "attachment": ...}` (the schema declares a
   string), `description` the item's note.
5. Append `dossiq:case:{caseUuid}` to the collection's `sourceOf`, once. Items
   are never touched.

All reads and writes run as the system (`runAsSystem`). The caller is a portal
subject or a pipelinq service call, not a Nextcloud user; authorization is the
ownership check in step 2.

## D3. The case carries its request

`case.wooRequest` (object) is declared in the same fragment. It is what the
publish side reads to find the source dossier (C6) and the period for the
publication. Keeping it on the case, not in `caseProperty` rows, makes it one
read.

## D4. The portal action is an endpoint action

A portaliq `create` action writes one object through its flat writer. This
request writes a case, case objects and a collection update, so it is an
endpoint action:

- `startWooVerzoek`, `endpoint: /index.php/apps/dossiq/api/portal/woo-verzoek`,
  `method: POST`, `fields: [collectionId, onderwerp, omschrijving, periodeVan,
  periodeTot]`, offered to `citizen` and `client` (both read the citizen
  manifest). No `type`, `register` or `schema`: that is the vocabulary of the
  fleet's reference endpoint action (petstore `renamePet`), and it keeps
  portaliq's flat writer from ever taking the action for a create of its own.
  It declares `attachTo: {app: "opencatalogi", schema: "collection"}` and
  `rowField: "collectionId"` (hydra C7), so portaliq shows it on the dossier
  page, proves the dossier is the resident's through opencatalogi's own scope
  and forwards it with `collectionId` set. dossiq still checks ownership.
- `PortalWooRequestController::start` is `#[PublicPage]` and
  `#[NoCSRFRequired]`: the caller is portaliq's server. The `X-Portal-Subject`
  assertion is the authentication, verified by `PortalAssertionVerifier`
  (copied from the fleet reference in petstore, with portaliq's current secret
  rule: the dedicated `jwt_signing_secret`, no fallback). `sub` is the
  `subjectRef`; the audience must be `citizen` or `client`.
- Answers: 201 `{caseId, caseUrl}`, 401 for a bad assertion, 403 for another
  audience, 400 for an
  unusable request, 404 for a dossier that is not the resident's, 503 when
  OpenRegister or the case type is missing.

## Deviations from the contract

- `objectIdentification` is a JSON-encoded string, because the `caseObject`
  schema declares a string (C5 draws it as an object).
- The `sourceOf` entry is `dossiq:case:{uuid}`. C1 says "case references"
  without a format.
- The portal action is an endpoint action with its own receiver (C5 names only
  the action).

## Risks

- opencatalogi's `collection` lands in parallel. The intake reads it by
  register `publication`, schema `collection`, both overridable
  (`woo_collection_register`, `woo_collection_schema`). Tests use a fixture in
  the C1 shape; the live check follows opencatalogi's merge.
- A missing portaliq secret makes every forward 401. That is the same failure
  every A6 receiver has, and fail-closed.
