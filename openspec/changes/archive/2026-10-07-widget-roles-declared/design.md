# Design: widget-roles-declared

## D-1. The declaration is on the widget, in the manifest

dossiq already declares its pages and widgets in `src/manifest.json`, and
every other property of a widget lives there. Roles belong in the same
place, so the question "who sees this figure" is answered by reading the
declaration rather than by reading three components.

Valtimo scopes the composed dashboard to roles
(`_round4/discovery/candidates.json`, C-reporting-22). dossiq's version is
one level finer, because a dossiq page mixes a handler's own work with a
teamleider's figures, and scoping the page would take the handler's own
work away too.

## D-2. Enforcement is on the read, and the browser is not the check

ADR-004's hard rule is that a frontend route is not an access check. A
widget hidden with a `v-if` is exactly that defect: the data still arrives
in the page's payload, and anyone who opens the network tab reads it.

So the widget's data endpoint checks the declared roles and answers
nothing to a reader who holds none. The page then lays out without the
widget, rather than drawing an empty box, because an empty box tells a
reader there is a figure they are not allowed to see, which is itself
information.

## D-3. An unresolvable role hides the widget

Per ADR-102, absence fails closed. A widget declaring a role that does not
resolve, after a rename or a misconfiguration, is not rendered and the
failure is reported. The alternative, defaulting to visible, means a
configuration mistake becomes a disclosure.

## D-4. A widget with no declaration fails the build

Making the declaration optional means the next widget ships without it and
nobody notices, which is how the current state arrived. So a structural
test lists every dossiq widget declaration and fails when one declares no
roles and carries no reason-bearing allowlist entry.

A widget genuinely visible to everyone is a legitimate allowlist entry
with that as its reason. What is not legitimate is silence.
