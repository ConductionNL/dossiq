# Design: one-follow-control-in-the-header

## Decision: keep the library control, drop dossiq's

The library toggle is the fleet-wide control: every app's detail page gets the
same button, bell and follower popover from CnDetailPage without configuration,
and it writes the same `PUT`/`DELETE .../watch` routes the strip wrote. The strip
existed because the library could not do this when `case-followers` was built
(its own docblock said so). It can now, so the strip is the duplicate.

The strip is deleted rather than left registered. A registered widget type that
no page places is code with no call site, and its tests would keep passing over
a control nobody sees.

## What the board says

DqZaak draws one "Volgen" button in the header, beside the case title, and no
follow strip under it.
