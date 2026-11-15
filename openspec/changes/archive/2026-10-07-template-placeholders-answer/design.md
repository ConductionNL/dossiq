# Design: template-placeholders-answer

## D-1. Aliases for what is already stored, canonical names for what is authored next

There are two populations of template, and one fix does not serve both.

The templates dossiq SHIPS are ours to rewrite, so their bodies take the
canonical English names.

The templates an instance HOLDS are not. They were seeded in June carrying
`{{behandelaar}}` and administrators have edited them since. Renaming only the
shipped bodies would leave every one of those broken, and a migration that
rewrote stored bodies would be editing somebody's letter behind their back.

So the map ANSWERS the Dutch names as well, pointing at the same case fields.
Existing templates work again with nobody touching them.

The catalogue the editor renders lists only the canonical names, so the aliases
are a door out of the past rather than a second vocabulary to keep. An
administrator writing a template tomorrow is offered `handler` and never
`behandelaar`.

## D-2. The gate compares two derived sets, never a hand-written list

A list of expected placeholders in a test is a third place for the same
knowledge to drift from, and it would have been written in June and renamed in
August exactly as the map was.

So the test derives BOTH sides: every `{{name}}` in every shipped template, and
every key `buildVariableMap()` returns for a case. It fails when the first set
is not contained in the second, and it names the template and the placeholder.

## D-3. It asks the renderer's own question

`collectUnresolved()` already answers "which placeholders does this text name
that this map cannot fill". It has existed since the templates did, it returns
exactly the three broken names, and nothing ever asked it about a shipped
template. The gate calls THAT rather than re-implementing the match, so the
test cannot drift from the renderer it is protecting.

## D-4. The renderer keeps leaking, and that is still right

Leaving `{{behandelaar}}` in the text is what made this visible at all. The
alternative — blanking it — would have produced "Met vriendelijke groet," over
an empty line for a month, and nobody would have reported it either. The
renderer is unchanged; what changes is that a shipped template can no longer
reach a recipient with an unanswered name in it.

## D-5. What the build found: the aliases nearly made the gate a decoration

The first version of the gate compared the shipped templates against the whole
map — aliases included. It passed, and the mutation check showed why that was
worthless: putting `{{behandelaar}}` back into a shipped body reddened nothing,
because the map now answers it.

A gate that cannot fail on the exact defect it was written for is a decoration.
So the aliases are named as `EmailTemplateService::DEPRECATED_ALIASES` and
SUBTRACTED before the shipped templates are checked. Two different questions
with two different answers, which is the whole shape of this change:

- *May a STORED template use it?* Yes. That is what the alias is for.
- *May a SHIPPED template use it?* No. It is deprecated, and shipping one is
  how the fleet grows a fourth spelling.

With the subtraction, reintroducing the August defect reddens
`testEveryShippedPlaceholderIsAnswerable` and names the template and the
placeholder.

## D-6. The seeded copies reach a fresh install; the aliases reach the rest

The three broken bodies in `register.d/35-email-templates.json` are
`components.objects` seeds. Correcting them fixes a fresh install. It does not
follow that an instance which already imported them gets the correction, and
this change does not claim it does — that is precisely why the aliases exist and
why they are not optional.
