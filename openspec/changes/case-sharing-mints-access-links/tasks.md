# Tasks: case-sharing-mints-access-links

## 1. The gateway

- [ ] 1.1 `OpenRegisterSharingGateway::accessLinkService()` resolves
      `OCA\OpenRegister\Service\Sharing\AccessLinkService`, duck-typed, and
      answers null when the method set is not there.
- [ ] 1.2 `accessLinkReader()` and `noteService()` beside it, same shape.
- [ ] 1.3 `caseTokenService()` goes, with `CaseTokenShareService`. Nothing else
      resolves it.

## 2. The case link

- [ ] 2.1 `CaseAccessLinkService::mintCaseLink()` mints over the case object
      with the declared capabilities, the expiry and the optional password.
- [ ] 2.2 `mintFileLink()` mints over `<objectUuid>/<fileId>`.
- [ ] 2.3 `revokeLink()` and `setPaused()` pass the caller through, and report
      a refusal as a refusal.
- [ ] 2.4 `stateOf()` reads revoked, paused, expired or live off the link row.
- [ ] 2.5 `holderPreview()` resolves the anchor, reads it and strips it.
- [ ] 2.6 `stripInternals()` keeps the eleven `@self` keys OpenRegister
      publishes and drops the rest.

## 3. The share

- [ ] 3.1 `CaseSharingService::createTokenShare()` mints a link, mints a file
      link per named document, and writes the `caseShare` record.
- [ ] 3.2 `revokeShare()` revokes the case link and every file link.
- [ ] 3.3 `linkBelongsToCase()` replaces `tokenBelongsToCase()` and reads the
      `caseShare` records of that case.
- [ ] 3.4 `caseShare` grows `accessLinkId`, `accessLinkUuid`, `accessLinkUrl`,
      `capabilities`, `status`, `sharedDocuments` and `advisoryBody`, and
      `token` leaves its required list.

## 4. The endpoints

- [ ] 4.1 `GET /api/shares/case/{caseId}` lists the case's links with state.
- [ ] 4.2 `PUT /api/shares/{shareId}` pauses and resumes.
- [ ] 4.3 `GET /api/shares/{shareId}/preview` answers what the holder reads.
- [ ] 4.4 Every one of the three guards the case first, then the share against
      the case.

## 5. The consultation

- [ ] 5.1 `ExternalConsultationLinkService::invite()` mints the link and names
      the advisory body on the share.
- [ ] 5.2 `collect()` reads the case's comments, keeps the ones written by the
      link, and records the newest as the response.
- [ ] 5.3 `POST /api/consultations/{id}/external-link` and
      `POST /api/consultations/{id}/advice` on `ConsultationController`.
- [ ] 5.4 `ConsultationPublicController`, its two routes, its contract test,
      `ExternalConsultationResponsePage.vue`, `consultation-public.json`, the
      `customComponents` registration and the e2e spec are deleted.
- [ ] 5.5 `findBySecureToken` goes from the service and the repository.

## 6. The tab

- [ ] 6.1 `ShareTab.vue` lists the links with state, capabilities, minter and
      use count.
- [ ] 6.2 `CaseSharingTab.vue` loads them from the new endpoint and wires
      revoke, pause and preview.
- [ ] 6.3 `CreateAccessLinkDialog.vue` asks for the capabilities, the expiry,
      the password and the documents.

## 7. Verification

- [ ] 7.1 Unit tests for the service, the consultation service and the
      controller, each mutation-checked.
- [ ] 7.2 `tests/e2e/case-sharing-mints-access-links.spec.ts` written and
      tagged, not run here.
- [ ] 7.3 `openspec validate case-sharing-mints-access-links --strict` is 0.
- [ ] 7.4 The diff check is green on new findings.
- [ ] 7.5 `composer check:strict` and `npm run lint` run once each.
