---
kind: code
depends_on: [nextcloud-vue-2-76-object-lock]
---

# Proposal: nextcloud-vue-2-78-presence-and-draft

## Summary

dossiq moves from @conduction/nextcloud-vue ^2.77.0 to ^2.78.0.

On 2.77.0 a case page that closed sent its presence beacon with no body. A
beacon cannot set headers, so the CSRF token never reached OpenRegister. On
cloud.conduction.nl every dossiq case page logged a 405 for it. 2.78.0 puts
the token in a FormData body, and OpenRegister's POST route on `/presence`
(openregister#4562) answers it.

On 2.77.0 a form that failed to save could still say "Saved" next to the
server error. 2.78.0 says "Draft kept on this device" there when a local copy
is stored, and nothing otherwise.

The same release also:

- shows `x-enum-labels` in badge and link cells, not the raw value;
- draws the boolean check mark in the success text colour;
- makes no AI companion health probe when hermiq is not installed.

## Why

A person who closed a case left a red 405 in the console, and stayed on other
people's presence list for a whole window. A person whose save failed read
"Saved" and could think the case was stored.

## Dependencies

@conduction/nextcloud-vue 2.78.0 (on npm). Its dependencies are the same as
2.77.0, so the lockfile changes in one entry. The manifest schema went from
2.75.0 to 2.76.0 (adds the `purple` and `teal` pill tones and a column
`colorMap`), so `tests/schemas/app-manifest-v2.schema.json` is copied from the
package again.
