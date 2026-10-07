# Design: archived-cases-leave-the-lenses

## D-1. One state, two names

The platform's archive marker is the state. `case.archiveStatus` is the
ZGW fact and keeps its ZGW meaning and its values. Archiving writes both:
the marker, and `archiveStatus` moving from `nog_te_archiveren` to
`gearchiveerd`. Reading asks the marker. Two stores, one writer, and the
ZGW consumers keep the field they read today.

## D-2. Archive is offered on a closed case only

`visibleIf` on the case being in a final status. Archiving a running case
would take it out of the list the handler is working from, which is the
one thing an archive must never do.

## D-3. The lens, not a filter people have to remember

Cases, the queue and the tiles exclude archived cases by default, because
the platform's query does. The Archived lens is the way back to them, and
it is one lens rather than a toggle on each page.

## D-4. Read-only is the platform's refusal, shown

dossiq does not implement the refusal. It renders the case without edit
affordances and shows the platform's message when a write is attempted
anyway, so the reason the user sees is the reason the server gave.

## D-5. Restore is one action and no wizard

Restore clears the marker and moves `archiveStatus` back. A case that was
archived by mistake should take one click to recover.
