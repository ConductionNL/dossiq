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
