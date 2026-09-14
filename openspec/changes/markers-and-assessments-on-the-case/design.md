# Design: markers-and-assessments-on-the-case

## D-1. Clearing is the act worth governing

Raising a flag is easy to ask for and easy to give. The act that decides
whether the flag means anything is the clearing, because that is the one
somebody does to make a nuisance go away. So the reason and the name are
required there, and both are kept.

## D-2. The history is the signal

A case flagged once is a case with a problem. A case flagged four times by
four people is a different case, and the difference is only visible if the
earlier raisings were not overwritten. So each raising and clearing is a
row, not a field that toggles.

## D-3. A risk level is an assessment, so it carries its ground

A level with no ground is a number somebody can neither defend nor revise.
The assessment records what it rests on, who made it and when it should be
looked at again, so a stale assessment is visible as stale rather than as
current.

## D-4. The permission is declared, not coded

`CitizenLookupGuard` is what a guard in PHP looks like, and
`sensitive-fields-declared` is already moving that class of check into the
platform's declaration. A risk assessment behind a hand-written check would
be the next `CitizenLookupGuard`, so it uses the same vocabulary from the
start.

## D-5. The assessment informs priority, it does not become priority

Four priority vocabularies is the defect `case-priority-impact-urgency` D-6
names. A risk level is a fifth word unless it feeds the impact axis that
change already defines, so it feeds it.

## D-6. A system marker declares its own life

Every marker is a pair: the condition that raises it and the condition that
clears it. Written as code, that pair ends up in two files and drifts.
Declared together on the schema, an administrator can read the pair and a
test can check it.

## D-7. A marker points at a tab because that is where the work is

"Something needs attention" sends the handler through every tab. "The
documents tab needs attention" sends them to one. The marker therefore
names the surface, and the surface is the one the manifest already names,
so nothing invents a second vocabulary of tabs.

## D-8. Handling clears it, opening does not

This is the whole difference from the unread state. A failed virus scan is
still a failed virus scan after somebody has looked at it. The marker's
clearing condition is about the world, not about the reader, and it is not
per user.
