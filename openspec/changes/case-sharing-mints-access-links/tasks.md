# Tasks: case-sharing-mints-access-links

## 1. The gateway

- [x] 1.1 `OpenRegisterSharingGateway::accessLinkService()` resolves
      `OCA\OpenRegister\Service\Sharing\AccessLinkService`, duck-typed, and
      answers null when the method set is not there.
- [x] 1.2 `accessLinkReader()` and `noteService()` beside it, same shape.
- [x] 1.3 `caseTokenService()` goes, with `CaseTokenShareService`. Nothing else
      resolves it.

## 2. The case link

- [x] 2.1 `CaseAccessLinkService::mintCaseLink()` mints over the case object
      with the declared capabilities, the expiry and the optional password.
- [x] 2.2 `mintFileLink()` mints over `<objectUuid>/<fileId>`.
- [x] 2.3 `revokeLink()` and `setPaused()` pass the caller through, and report
      a refusal as a refusal.
- [x] 2.4 `stateOf()` reads revoked, paused, expired or live off the link row.
- [x] 2.5 `holderPreview()` resolves the anchor, reads it and strips it.
- [x] 2.6 `stripInternals()` keeps the eleven `@self` keys OpenRegister
      publishes and drops the rest.

## 3. The share

- [x] 3.1 `CaseSharingService::createTokenShare()` mints a link, mints a file
      link per named document, and writes the `caseShare` record.
- [x] 3.2 `revokeShare()` revokes the case link and every file link.
- [x] 3.3 `linkBelongsToCase()` replaces `tokenBelongsToCase()` and reads the
      `caseShare` records of that case.
- [x] 3.4 `caseShare` grows `accessLinkId`, `accessLinkUuid`, `accessLinkUrl`,
      `capabilities`, `status`, `sharedDocuments`, `advisoryBody`,
      `consultationId` and `lastCollectedNote`, and `shareType` gains `link`.
      Declared in `lib/Settings/register.d/39-case-access-links.json` rather
      than in the monolith, per ADR-037, because several lanes build against
      that file at once.
- [ ] 3.5 `token` leaves the schema's required list. NOT DONE, and inherited:
      partner shares have been written without a token since they were built,
      so the list is already wrong for a reason this change did not create.
      ADR-037 concatenates list values, so a fragment cannot shorten it; it
      needs an edit to the monolith and a lane that owns that file.

## 4. The endpoints

Minting stays on `caseSharing#createShare`, beside the partner and federated
ways of sharing a case, because that is the choice between them. Everything
done to a link that already exists lives on `CaseAccessLinkController`.

- [x] 4.1 `GET /api/access-links/case/{caseId}` lists the case's links with state.
- [x] 4.2 `PUT /api/access-links/{linkId}` pauses and resumes.
- [x] 4.3 `GET /api/access-links/{linkId}/preview` answers what the holder reads.
- [x] 4.4 `DELETE /api/access-links/{linkId}` revokes one.
- [x] 4.5 Every one of the four guards the case first, then the link against
      the case.

## 5. The consultation

- [x] 5.1 `ExternalConsultationLinkService::invite()` mints the link and names
      the advisory body on the share.
- [x] 5.2 `collect()` reads the case's comments, keeps the ones written by the
      link, and records the newest as the response.
- [x] 5.3 `POST /api/consultations/{id}/external-link` and
      `POST /api/consultations/{id}/advice` on `ConsultationLinkController`.
- [x] 5.4 `ConsultationPublicController`, its two routes, its contract test,
      `ExternalConsultationResponsePage.vue`, `consultation-public.json`, the
      `customComponents` registration and the e2e spec are deleted.
- [x] 5.5 `findBySecureToken` goes from the service and the repository.

## 6. The tab

- [x] 6.1 `ShareTab.vue` lists the links with state, capabilities, minter,
      expiry and the advisory body where there is one.
- [x] 6.2 `CaseSharingTab.vue` loads them from the new endpoint and wires
      revoke, pause and preview.
- [x] 6.3 `CreateAccessLinkDialog.vue` asks for the capabilities, the expiry,
      the password and the documents.

## 7. Verification

- [x] 7.1 Unit tests for the service, the consultation service and the
      controller, each mutation-checked.
- [x] 7.2 `tests/e2e/case-sharing-mints-access-links.spec.ts` written and
      tagged, not run here.
- [x] 7.3 `openspec validate case-sharing-mints-access-links --strict` is 0.
- [x] 7.4 The diff check is green on new findings. Its phpcs reads tests/,
      which is outside phpcs.xml's `lib` scope, so those findings are not what
      `check:strict` measures and are reported as such.
- [x] 7.5 `composer check:strict` and `npm run lint` run once each, and what
      the first run found was fixed rather than argued with.

## 8. What check:strict found, and what it cost

- [x] 8.1 phpmd refused six shapes, all of them this change's own: the base is
      clean on the same files. `CaseSharingService` had become the store for
      one of the three sharing modes as well as the seam between them, so the
      `caseShare` record keeping moved to `CaseLinkShares`; the link actions
      moved off `CaseSharingController` to `CaseAccessLinkController` and off
      `ConsultationController` to `ConsultationLinkController`; the holder
      projection moved to `AccessLinkProjection`.
- [x] 8.2 The catch-return-null ratchet named five swallowing catches. Four are
      degradations of an OpenRegister that may predate #3817 and are written
      down with a reason; the fifth was converted instead. Reading the comments
      on a case now throws when the read cannot be made, because answering
      "nothing new" would tell a handler the advisory body had not replied when
      its advice was sitting there unread.
- [x] 8.3 Two allowlist entries left with the token surface, so the ceiling
      went 233 to 235 for four sites and nothing else.
