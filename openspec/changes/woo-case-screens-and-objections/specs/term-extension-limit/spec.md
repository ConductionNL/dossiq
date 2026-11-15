## ADDED Requirements

### Requirement: A case type states how often its term may be extended (REQ-TEL-001)

A case type MAY state `maxExtensions`, a whole number of 1 or more. A case SHALL NOT be extended
more often than its case type states. `GET /api/case/{caseId}/lifecycle` SHALL answer
`maxExtensions` (the number, or null when the case type states none) and SHALL answer
`canExtend: false` once the case used them all. `POST /api/case/{caseId}/extend` past the limit SHALL
answer 409 with the code `extension_limit_reached`, and SHALL NOT move the term. The bulk Extend
term action SHALL skip such a case with the same code.

The Next step dialog (board DqVolgendeStap) SHALL show Extend term greyed out on such a case, with
the sentence that the term was already extended as often as the case type allows. Before the limit
is reached the entry SHALL say that an extension needs a reason and that the requester gets a
message, and SHALL say "once" when the case type allows one.

A case type that states no `maxExtensions` keeps the behaviour it had: the number of extensions is
bounded only by the term's own rule. An empty, zero or unreadable value is no limit, because
`extensionAllowed` already says "never".

Nothing in this capability names a procedure. The limit is configuration on the case type.

#### Scenario: One extension allowed, one used
- **GIVEN** a case type with `extensionAllowed` true and `maxExtensions` 1, and a case of it with `extensionCount` 1
- **WHEN** the handler opens the Next step dialog
- **THEN** Extend term SHALL be greyed out with the limit sentence
- **AND** POST `/api/case/{caseId}/extend` SHALL answer 409 `extension_limit_reached`

#### Scenario: No limit stated
- **GIVEN** a case type with `extensionAllowed` true and no `maxExtensions`
- **WHEN** a case of it is extended a second time
- **THEN** the lifecycle service SHALL NOT refuse it for a limit

### Requirement: An extension answers whether the requester was told (REQ-TEL-002)

`POST /api/case/{caseId}/extend` SHALL answer, beside the lifecycle state, a `notice` object with
`noticeStatus`, `noticeChannel`, `noticeReasonCode` and `noticeReason`, as the term engine answered
them. The extension stands whatever became of the notice. A case without a statutory term SHALL
answer `noticeStatus: not-sent` with the reason code `no-statutory-term`, so a missing notice is
never a missing key.

After an extension the Next step dialog SHALL say in one sentence whether the requester was told.
A notice that did not go out SHALL be shown as a warning with its reason, so the handler can tell
the requester another way before the first term ends.

#### Scenario: Extended, and the requester was not told
- **GIVEN** a case with a running statutory term and a requester with no reachable channel
- **WHEN** the handler extends the term with a reason
- **THEN** the answer SHALL carry `notice.noticeStatus` `not-sent` with the reason
- **AND** the dialog SHALL warn that the requester was not told, with that reason

#### Scenario: Extended, and the requester was told
- **GIVEN** a case with a running statutory term and a requester who can be reached by e-mail
- **WHEN** the handler extends the term with a reason
- **THEN** the dialog SHALL say that the term is extended and the requester was told
