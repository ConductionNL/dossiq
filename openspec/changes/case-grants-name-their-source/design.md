# Design: case-grants-name-their-source

## D-1. dossiq computes no permission, and that is the whole design

D22's argument is that a filter running after the query has already leaked
the count. The same argument forbids dossiq computing an effective grant:
a second evaluator is a second answer, and the first time the two disagree
is a disclosure.

So dossiq declares the matrix, reads the answer, and renders it. A method
in dossiq that decides who may see a case is a finding.

## D-2. A refusal names the rule

C-access-and-privacy-79's clause: "the alternative is a UI that guesses
and a 403 the user discovers by clicking". dossiq already does better than
that: `CaseActionProvider.php:178` answers available actions per calling
user with the guards that refused each one.

What it does not do is name the grant rule, because it only knows its own
guards. When openregister can say which rule denied, dossiq shows that
sentence instead of a code.

## D-3. Confidentiality is a dimension, not a role

xxllnc sets case-type rights as department by role, separately per
confidentiality level. Folding confidentiality into the role name gives a
role per level per department, which is the combinatorial explosion every
mandate matrix eventually becomes.

So it is a third axis on the declaration, and `register.d/61-mandaat-matrix.json`
grows an axis rather than a set of rows.

## D-4. A group of case types is granted once

C-access-and-privacy-47 is a gemeenschappelijke regeling: thirty case
types that all belong to one partner. Granting per type means thirty
grants that drift. The group is the unit, and a type inherits from its
group, which is exactly what `rbac-inherits-to-children` gives us.

## D-5. Provenance is read, never summarised

"Who could open this dossier in March" is answered by openregister's
record of the grant and its source. dossiq renders that list. It does not
store a copy, because a copy of an access decision is a second access
decision that nobody updates.

## D-6. The object's own endpoint replaces four reads, and the four stay

openregister#3744 added `GET /api/objects/{r}/{s}/{id}/permissions`, which
answers in one read what the panel was assembling from shares, roles,
scopes and the deny preview. The panel now asks that first.

It keeps the four older reads beside it rather than deleting them. An
instance running an openregister from before #3744 answers 404 to the new
one, and a panel that had dropped the old reads would render an empty
table on every such instance. An empty access table is the one answer an
auditor must never be handed by accident.

So the new read is preferred where it answers, and the four are the
fallback. Rows from the two paths carry the same keys, so nothing
downstream has to know which path produced them.

## D-7. A refusal to review access is an answer, not a failed read

The new endpoint refuses a caller who may open the case but holds no
`manage` on it. That refusal is deliberate in openregister: enumerating
the case workers on a dossier is a second right.

The panel's existing reader turns every non-200 into `null`, and the panel
renders `null` as "openregister could not be asked". For a 403 that
sentence is wrong twice: openregister answered, and the reader is not
missing data, they are not entitled to it. So the reader now carries the
status, and 403 gets its own sentence.

## D-8. The end and the area are rendered, never evaluated

openregister#3750 put `until` and `scopedTo` on a grant, and reads both in
`resolveAuthorization()`, which every path takes. A grant that has run out
is therefore already absent from the verdict.

dossiq shows both on the row and computes neither. Comparing `until` to
the clock here would be a second evaluator of the question D-1 puts in
openregister, and the two clocks would disagree first on the day the grant
expires, which is the day somebody looks.

The case type's rights matrix declares its end under the same key, `until`,
in the same ISO-8601 spelling. A second spelling would need a translation
step, and a translation step is where an end quietly stops arriving.

## D-9. `DossiqCaseAuthorizer::canReadCase()` stays, and here is why

The architecture test from #2799 names it as the one to retire, in favour
of reading openregister's answer. Read again against #3744 and #3750, it
cannot move here without changing behaviour.

`canReadCase()` narrows an MCP read to the case's assignee, a holder of a
role record on the case, or an administrator. openregister's answer is
wider: everybody the RBAC rules grant `read`. Both of its callers would
therefore hand an assistant cases it does not answer for today.
`handleGetProcessDetails()` would return one, and `handleListProcesses()`
would stop filtering the list at all.

`@self.actions` does not close that gap either. openregister#3744 writes it
in `ObjectsController::show()`, so it rides a single object read over HTTP.
The MCP reader resolves cases through `ObjectService` and never sees the
field, and a list carries no per-row actions at all.

The widening is real work with a real owner: `dossiq-mcp-adoption` already
records it as a breaking change to the access model, with the argument for
it. It belongs there, on a change that can announce it, and not as a side
effect of a panel that renders grants.

The architecture test keeps the entry and now carries this reason, so the
next reader finds the answer instead of the intention.
