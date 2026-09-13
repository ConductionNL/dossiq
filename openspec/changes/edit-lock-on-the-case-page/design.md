# Design: edit-lock-on-the-case-page

## D-1. Take on open, release on close

The edit form's `mounted` takes the lock through the store's lock call;
`beforeDestroy` and the save path release it. A navigation guard releases
on route leave.

## D-2. The header reads `locked`

The header widget renders the built-in `locked` field: holder and time.
The Edit action carries `visibleIf: locked empty or locked.user == @me`.

## D-3. The refusal is shown, not retried

A 423 from the platform is shown with its message; the form keeps the
input.
