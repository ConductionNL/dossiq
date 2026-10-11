# Design: lifecycle-gesture-saves-the-case-as-stored

## Decision: read the case again, rather than drop readOnly fields

Two fixes would stop the refusal. Dropping the readOnly fields from the payload
before saving removes `deadline`, but still writes every other field of the stale
read back, including `statutoryDeadline`, which the mirror had just moved and
which is not readOnly. That would silently undo the term's new end on the case.

Reading the case again after the term gesture, and merging only the fields the
gesture owns, saves exactly what the store holds plus the gesture. The checks a
gesture makes (is the case suspended, is the status final, what is the current
end date) still run on the first read, before anything is written, so a refused
gesture writes nothing.

The cost is one extra read per gesture, on a write path a person triggers by hand.

## Decision: a code, not a translated sentence, from the controller

The controller's refusals already carry a static `code` the page turns into a
sentence (`suspension_not_allowed`, `already_suspended`, ...). The unexpected
failure now follows the same rule with `change_failed`. The server does not
translate it: the page owns the sentences and the Dutch text.
