# Design: one-term-engine

## D-1. The instance writes, a pre-persist listener keeps

OpenRegister recomputes a materialised calculation on every save of the case.
Writing `deadline` once would therefore last only until the next save of the
case by anybody. Two parts make the instance the source:

- **Write-back.** `TermijnService` is the one writer of a term instance
  (create, `saveTermInstance()`, `updateTermijnInstance()`; pause, resume,
  extension, rearm and the fixed closing date all go through it). After it
  saves a statutory instance it asks `CaseDeadlineMirror::follow()` to bring the
  case's `deadline` in line. That reads the case and saves it only when the
  date differs.
- **Keep.** `CaseDeadlineFollowsTermListener` runs on OpenRegister's
  `ObjectUpdatingEvent` for a case, after the calculation listener and after
  `CaseInheritedDeadlineListener` (priority -110). When the case has a
  statutory instance it puts that instance's `endDateCurrent` into the save
  through `setModifiedData()`. A stale full-object save by any other listener,
  and the calculation itself, are corrected in the same save.

Which instance: the newest statutory instance that is not `completed`; when
every statutory instance is completed, the newest one (its date is the date the
case was decided against, and the list keeps showing it). Planned, internal and
phase instances never write `deadline`.

The `ObjectCreatingEvent` is not handled: no instance exists before the case
does, and the post-create bind writes the value back right after.

## D-2. The calculation stays as the fallback, and REQ-TERM-001 stops blocking

A case type with no term definition and no fixed closing date has no statutory
instance. Blocking the creation of such a case (REQ-TERM-001 as written) would
lose real applications for every case type an administrator has not configured
yet, and the code has only ever warned. So:

- Creation is not blocked. The warning stays, naming the case type.
- The case then keeps the calculated `deadline` (start date plus the case
  type's `processingDeadline`, or the inherited one from
  `CaseInheritedDeadlineListener`). It is not a statutory term and no timer
  runs for it.

Removing the calculation outright would leave those cases without any
deadline, and every list filters on it.

## D-3. The term counts from `termStartsAt`

`DeadlineCaseCreatedListener` starts the statutory instance, and the clocks
`CaseTermsService::bindForCase()` binds, at:

1. the case's `termStartsAt`, when the intake stamp is already on the payload;
2. otherwise `IntakeTermStart::stampFor(arrivalOf(case))[termStartsAt]`, the
   same calendar call the stamp uses, so the two cannot disagree;
3. otherwise the arrival moment itself (`receivedAt`, `registrationDate`,
   `requestedDate`, `@self.created`), when no calendar answers.

Both listeners run on the same create event and the stamp listener runs
second, so the bind cannot rely on reading the stamp back.

## D-4. Woo is a case type with a definition, nothing more

`td-woo-verzoek` (28 days, one extension of 14 days, Woo art. 4.4) is seeded.
`WOODeadlineService::calculate()` asks the definitions for the end date instead
of adding 28. `extendDeadline()` finds the case's statutory instance and calls
`DeadlineExtensionService::requestExtension()` with `endDateCurrent` plus the
definition's `extensionCapacity` (14 when it declares none); the one-extension
cap is the definition's `countExtensions`, read from the instance, so it holds
whatever the case schema drops. The response keeps `expectedResolution` as a
read-only alias of the new `deadline` for one release, so the dialog keeps
working; nothing writes the three undeclared keys any more. The T-7 warning
reads `deadline` only.

## D-5. Working days through `WorkingDayRoll`, with a logged fallback

`WorkingDayRoll` is the one reader of the administered calendar. Three callers
move onto it:

- `CaseTermsService::endAfter()` gains a mode. The planned end and the internal
  target pass `workingDays`; phase terms, remedies and information requests
  keep calendar days, which is what their declarations say. When the calendar
  does not answer, the count falls back to calendar days and logs a warning,
  exactly like `TermDefinitions::counted()`.
- `WorkQueueService::businessDaysBetween()` asks `daysBetween(workingDays)` and
  keeps its Monday-to-Friday walk only as the fallback when no calendar answers.
- `DeadlineExtensionService` rolls the requested end date with
  `rollTermEndFor()` and the instance's definition, and keeps the requested date
  as `endDateBeforeRoll`.

## D-6. Late means the day after the last day, and the timer agrees

The rule is REQ-TERM-DAY-001's: a term is overdue only from the day after its
end day, and only while its clock runs. Three places broke it:

- **The beslistermijn timer.** It was anchored at the start moment (say 14:00)
  with an SLA spanning to the end date, so it breached at 14:00 on the last
  day and the fired listener set `exceeded` then. It is now anchored at the
  start of the start day, with the SLA one day longer, so the breach lands at
  the start of the day after the end day (the next working day for a
  working-day term). The repair step re-arms the beslistermijn timer of every
  running (`lopend`, `verlengd`) instance once, marked by
  `timerBreachesAfterLastDay`.
- **The simple structure's manifest.** The list column, the board's `dueRule`
  and the week strip's `lateWhen` read `lte 0` as late. They become `lt 0` for
  the error variant; the last day reads as the warning variant.
- **The My Work card** compared the deadline instant with `Date.now()`, which is
  late from midnight UTC of the last day. It uses the shared helper.

## D-7. One helper in the front end

`src/utils/deadlineCountdown.js` already does the date-only arithmetic in the
reader's local day. It becomes the one helper: `daysUntilDeadline()`,
`isOverdue()` (days < 0), and `deadlineCountdown()` for the text. `caseHelpers`,
`caseTerms`, `dashboardHelpers`, `WooDeadlinePanel` and `MyWorkCaseCard` call
it instead of their own subtraction.

## D-8. The work-queue reads every open term

`nearestActiveTermDeadline()` filters on `lopend` only. A paused term's end
date has already moved by the pause, and an extended term's by the extension,
so both are the dates a handler is judged on. The query reads `lopend`,
`verlengd`, `paused` and `exceeded`.

## D-9. The repair step

`ReconcileCaseDeadlinesWithTerms` (post-migration, idempotent, under the system
identity like `ArmTermijnEngineTimers`):

1. for every statutory instance, the case's `deadline` is set from it through
   `CaseDeadlineMirror::follow()` (a no-op when it already agrees);
2. for every running instance not yet marked `timerBreachesAfterLastDay`, the
   beslistermijn timer is cancelled and armed again, and the mark is written.

It reports counts (followed, unchanged, rearmed, failed) and never fails the
upgrade.

## Risks

- **A save per term change.** A pause now saves the case too. That is one
  extra write on a human action, not on a hot path.
- **A read per case save.** The keep listener reads the case's term instances
  on every update of a case. One indexed search by `case`.
- **Parallel work in `WorkQueueService`.** Kept to two methods and an optional
  constructor argument at the end.
