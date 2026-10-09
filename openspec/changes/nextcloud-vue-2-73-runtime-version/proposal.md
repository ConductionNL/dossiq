---
kind: code
depends_on: [notification-labels-and-tour-titles]
---

# Proposal: nextcloud-vue-2-73-runtime-version

## Summary

`notification-labels-and-tour-titles` wired dossiq for two library features
that @conduction/nextcloud-vue 2.71.0 did not have yet: the
`notificationLabels` prop on CnAppRoot and `appVersionDefine()` in
`@conduction/nextcloud-vue/webpack`. dossiq still built against 2.71.0, so the
labels were ignored and the build quietly took the info.xml literal for the
footer version.

dossiq moves to @conduction/nextcloud-vue ^2.73.1, which carries both. The
fallback in `scripts/appVersion.js` goes: a library without
`appVersionDefine()` now stops the build with an error, instead of shipping
the build-time version that read "dossiq 0.4.47-unstable" on 0.4.48-beta.

## Why

A fallback that hides a missing helper is how the wrong version shipped. The
build must fail loudly when the runtime reader is missing.

## Dependencies

@conduction/nextcloud-vue 2.73.1 (on npm). The app-manifest schema did not
change between 2.71.0 and 2.73.1, so `tests/schemas/app-manifest-v2.schema.json`
stays as it is.
