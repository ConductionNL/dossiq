---
kind: code
depends_on: [woo-requester-notices-really-go-out, woo-term-is-computed-and-reported-right, intake-says-when-the-term-starts, woo-request-from-a-portal-dossier]
---

# Proposal: woo-request-takes-over-from-opencatalogi

Woo capability programme, round 1, wave 2. Supporting change: it closes no row by itself. It
keeps rows 7.1 to 7.6 and 10.8 yes while the Woo request moves from opencatalogi to dossiq.

| row | text | our rating today | what this change must keep |
| --- | --- | --- | --- |
| 7.1 | A Woo request can be registered | yes | registered in dossiq, through every channel opencatalogi took |
| 7.2 | The request gets a reference the requester can quote | yes | the dossiq case number, and the old `WOO-` reference still finds the case |
| 7.3 | The decision term starts when the request is received (statutory) | yes | the term counts from the moment the requester sent it, not from delivery |
| 7.4 | The term is four weeks (statutory) | yes | P28D, rolled under the Awt |
| 7.5 | The term can be extended once by two weeks, with reasons (statutory) | yes | one P14D extension, a second refused |
| 7.6 | The term is suspended while the requester is asked to complete the request (statutory) | yes | suspended only by a request that went out |
| 10.8 | Terms are tracked and reported: met, missed, running, suspended (statutory) | yes | the same four counts from dossiq's report |

Implements Ruben's decisions **D1** (dossiq owns the Woo request, its intake and its statutory
term) and **D12** (Woo requests require dossiq, with no fallback). This is step 1 of the plan's
section "The Woo request moves to dossiq": dossiq accepts opencatalogi's request shape, proves term
parity against opencatalogi's own fixtures, and ships an idempotent import of every stored
opencatalogi `wooRequest`. Steps 2 and 3, `opencatalogi/woo-request-intake-hands-over-to-dossiq` and
`portaliq/woo-intake-delivers-to-dossiq`, call what this change ships.

## The law

- **Woo art. 4.1 lid 1** and **Awb art. 4:1**: a Woo request is an application, received on the day
  it reaches the body.
- **Woo art. 4.4 lid 1**: the decision is taken within four weeks after receipt.
- **Woo art. 4.4 lid 2**: the term may be extended once, by at most two weeks, with reasons.
- **Awb art. 4:15 lid 1 sub a**: the term is suspended from the day the body asks the requester to
  complete the request until the day it is completed or the set period has passed.
- **Algemene termijnenwet art. 1 lid 1**: a term ending on a Saturday, Sunday or recognised holiday
  ends on the next working day.

## Why

Two intakes take Woo requests today, and they do not know about each other.

opencatalogi, read on `development` at 35999c29:

- `OCA\OpenCatalogi\Portal\PortalContributionProvider::receiveWooRequest(array $answers, string $receivedAt = ''): array`
  delegates to `OCA\OpenCatalogi\Service\Woo\WooRequestIntake::receive()`. It reads the answer keys
  `requestedInformation`, `requesterName`, `requesterEmail`, `requesterPhone` and
  `requesterAddress`, and answers `{outcome, requestId, reference, dueAt, message}`, where `outcome`
  is `armed`, `not-armed`, `refused` or `unavailable`.
- `WooRequestController::receive()` is the officer's REST intake, and `WooRequestService::receive()`
  builds the record: a `WOO-yyyy-XXXXXX` reference, `receivedAt`, `channel` (`web`, `email`,
  `post`, `counter`, `phone`), `status` (`received`, `in_progress`, `awaiting_clarification`,
  `decided`, `withdrawn`), `termTimer`, `termBasis`, `extensionCount` and `extensionReason`.
- `StatutoryTerm` arms the term on OpenRegister's term engine, and `WooRequestService::termsReport()`
  answers met, missed, running and suspended. That is what made 7.3 to 7.6 and 10.8 statutory yes.

dossiq, read on `development` at 55bbc761:

- `OCA\Dossiq\Woo\WooRequestIntake::start(array $request): array` is the one path a Woo case is
  opened by (portal dossier and pipelinq). It requires `subjectRef`, accepts only the origins
  `portal` and `pipelinq`, and answers `{caseId, caseUrl, identifier?, deadline?}`.
- `OCA\Dossiq\Portal\PortalContributionProvider` exists and portaliq's `PortalProviderLocator`
  finds it, but it has no `receiveWooRequest()`. So portaliq's form delivery
  (`PortalWooRequestDelivery`) cannot be pointed at dossiq today.
- Its term machinery is sound only after the two wave 1 defect changes land: notices that are
  really sent (`woo-requester-notices-really-go-out`) and a deadline that is rolled and reported per
  case type (`woo-term-is-computed-and-reported-right`). This change hands every request to that
  code, so it starts after both.

## What changes

1. **dossiq receives opencatalogi's request shape.** A new
   `WooRequestIntake::receive(array $answers, string $receivedAt = '', string $origin = 'portal-form'): array`
   takes opencatalogi's answer keys and answers opencatalogi's keys, plus `caseUrl`. dossiq's
   `PortalContributionProvider` gains `receiveWooRequest(array $answers, string $receivedAt = ''): array`
   with the exact signature and return keys opencatalogi's has, so portaliq swaps one app id. A
   request without a resident subject (an anonymous portal form, an officer's registration, a
   forwarded request) is accepted on the requester's name and contact details.
2. **`armed` means a term runs.** `receive()` answers `armed` only when the case's statutory term
   instance exists, its FlowTimer is armed, and its rolled `endDateCurrent` is the `dueAt` it
   answers. The term counts from `receivedAt`, the moment the requester sent the request.
3. **Parity, proven against opencatalogi's fixtures.** A test suite replays opencatalogi's term
   scenarios against dossiq: the start told at intake, four weeks, one two-week extension and no
   second, the suspension while clarification is awaited, the Awt roll on the engine calendar, and
   the report's met, missed, running and suspended.
4. **An idempotent import.** `OCA\Dossiq\Woo\OpenCatalogiWooImport::run()` (and
   `occ dossiq:woo:import-opencatalogi`) turns every stored opencatalogi `wooRequest` into a dossiq
   Woo case: status mapped, start date, suspensions, extension and deadline carried, the term re-armed
   on a FlowTimer with its remaining time exactly once (the REQ-TOT-006 pattern), and the old
   reference kept as a searchable alias. It answers the count still unmigrated.
5. **The decision draft comes from filinq's template service** (row 7.9's dossiq half), through one
   adapter, so a besluit and an inventory are generated from the organisation's templates instead
   of being a record only.

## What does not change

- opencatalogi's own code. Its forward, its repair step, the read-only schema and the removal are
  `opencatalogi/woo-request-intake-hands-over-to-dossiq` (wave 3). That change calls
  `WooRequestIntake::receive()` and `OpenCatalogiWooImport::run()` and tests its side of each call.
- portaliq's code. Repointing `PortalWooRequestDelivery` is `portaliq/woo-intake-delivers-to-dossiq`
  (wave 3).
- `WooRequestIntake::start()` and its two origins, `portal` and `pipelinq`. The dossier rules
  (REQ-WRI-003) stay exactly as they are.
- The Woo case type's eight stages and its four results.

## Dependencies

- Planned, dossiq, wave 1: `woo-requester-notices-really-go-out`, `woo-term-is-computed-and-reported-right`.
- Open, dossiq, outside the plan: `intake-says-when-the-term-starts` (5/6), `woo-request-from-a-portal-dossier` (4/5).
- Planned, filinq, wave 1: `woo-request-workflow` (amended to keep the template service only).
  When filinq is absent, the decision draft is not generated; see REQ-WTO-006.
- Called by, wave 3: `opencatalogi/woo-request-intake-hands-over-to-dossiq`, `portaliq/woo-intake-delivers-to-dossiq`.

**App absent.** Without opencatalogi the import answers that there is nothing to import and changes
nothing. Without dossiq there is no Woo request intake at all (D12); that is the callers' half and
is specified there. Without OpenRegister's term engine `receive()` answers `unavailable` and mints
nothing, because a request without a term is the state this whole move exists to prevent.

## Wave and done

Wave 2. Done means merged on `development` with CI green. The supported rows stay yes; they move to
`production` for dossiq only once a dossiq store release carries this change.
