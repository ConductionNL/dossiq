# Design: AI features on the case, consuming hermiq

## D-1. The declaration is on the case type, and it is a list of surfaces

A gemeente does not switch on "AI". It switches on summarising for bezwaren and
leaves it off for meldingen, because those are different files read by different
people under different duties.

So the unit of declaration is the case type, and the value is not a boolean but
where the feature appears. Three surfaces exist and each answers a different
question:

| surface | who sees it | what it is for |
|---|---|---|
| `case` | a handler, on the case detail page | reading what is already filed |
| `intake` | a citizen or a frontoffice, on the create form | getting something filed correctly |
| `none` | nobody | declared, known, and off |

A boolean would have forced a fourth screen somewhere to answer "where", and a
feature that is on but appears nowhere is a support ticket rather than a setting.

## D-2. The provider is shown, and cannot be set here

hermiq answers `GET /apps/hermiq/api/ai-features/residency` with one row per
feature: the provider, the model, where that provider runs and where the binding
came from. The case type screen renders those rows beside the features it has
switched on.

There is deliberately no write path. A provider field on a dossiq case type would
be a second place to answer "which model saw this case, and in which
jurisdiction", and an FG who gets two answers has none. When hermiq is absent the
column reads that the AI features are unavailable, not that they are local.

## D-3. The document reference travels, and the refusal is rendered

hermiq's redaction gate is on the document, not on the run: a feature declaring
`requiresRedaction` refuses a document filinq has not redacted, and still runs on
typed text.

That shape only works if dossiq actually passes the reference. A request that
quietly omits it gets a run that was never checked, which is the failure mode this
whole boundary exists to prevent, and it would look exactly like success. So the
reference is required by the request shape rather than added when convenient, and
its absence is a client error rather than an unchecked run.

A refusal comes back as a 422 naming the step that refused. dossiq renders it as a
sentence with the feature and the reason, because "AI error" sends a handler to
the wrong person.

## D-4. Grouping is asked per report, and its meaning stays here

hermiq answers which group a report belongs to, with a count, the near-duplicates,
the terms, the window and what decided each membership. It creates nothing.

What a group means is statutory and stays in dossiq: whether two hundred reports
become one case with two hundred reporters or two hundred cases under a parent is
a question about acknowledgement duties and archiving. This change renders the
group and leaves that decision where it is.

One thing is stated rather than assumed, because the mistake is natural: **a group
does not reduce the confirmations of receipt owed.** Awb 4:3a owes every
electronic request a confirmation, and a handler seeing one item where two hundred
people wrote will find it obvious that one confirmation went out.

## D-5. The intake tool is a declaration over the path that already exists

hermiq's intake grant covers tools an owning app annotated `citizenIntake` **and**
whose id ends in `create`. It checks the verb as well as the annotation, because
an annotation is a claim.

dossiq's half is therefore small on purpose: declare `dossiq.case.create` with
that annotation, and have it call the same creation path the create form calls.
The temptation is a second, looser path, because a conversation has not collected
every field the form asks for. The answer to a missing field is a refusal hermiq
turns into a handover to a person, not a second definition of a valid case.

The read tools gain `outsideAgent` so the same catalogue serves hermiq's outbound
surface, where the caller's own rights still decide per call.

## D-6. Two drifts fixed on the way past

Both are the same class of defect: a test-time copy of something real that has
quietly stopped matching it.

- `tests/Stubs/Db/AuditTrailMapper::createAuditTrailEntry()` returns `object`
  where OpenRegister returns `AuditTrail`. A test asserting on the return value
  passes against a shape the real class never produces.
- `AiSettingsControllerShapeTest::SWITCH_KEYS` restates the feature list that
  `ConfigKeys` already holds. Adding a feature to one leaves the other claiming to
  cover "every on/off setting the admin tab draws" while quietly not.

Neither is caused by this change. Both are touched by it, which is the rule for
fixing inherited debt rather than filing it.
