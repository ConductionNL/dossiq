# Design: lifecycle-dialog-title-clears-the-close-button

## Decision: a wider dialog, not a shorter title or a CSS override

The title is the sentence the dialog answers and is translated; shortening it
would only move the overlap to the next language that runs longer. Padding the
title from dossiq would mean styling NcDialog internals from outside the
component, which breaks on the next library release. `size` is NcDialog's own
prop, and the content (seven acts, each with a reason) reads better wider.

Measured live before the change: title box 532 to 920px, close button 882 to
916px, on a 1440px viewport.
