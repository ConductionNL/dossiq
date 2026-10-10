# Tasks: nextcloud-vue-2-78-presence-and-draft

## 1. Move to the library release

- [x] 1.1 `package.json` and `package-lock.json` on @conduction/nextcloud-vue ^2.78.0.
    - test: `tests/vitest/nextcloudVue278.spec.js` (fails on 2.77.0, see 2.1 and 3.1)
- [x] 1.2 `tests/schemas/app-manifest-v2.schema.json` copied from the package (schema 2.76.0).
    - test: `tests/vitest/validateManifestLag.spec.js` "is at or ahead of the installed schema" (fails with the 2.75.0 copy)

## 2. The presence beacon carries the CSRF token

- [x] 2.1 Comes with 2.78.0; dossiq uses the library's presence on the case detail page as it is.
    - test: `tests/vitest/nextcloudVue278.spec.js` "posts to the departure route with the CSRF token in a form body" (fails on 2.77.0: the beacon has no body)

## 3. No "Saved" beside a failed save

- [x] 3.1 Comes with 2.78.0; dossiq uses the library's CnFormDialog and CnFormPage as they are.
    - test: `tests/vitest/nextcloudVue278.spec.js` "says the draft is kept on this device, never Saved" and "says nothing while a draft is being written after a failed save" (both fail on 2.77.0)

## 4. Live check

- [x] 4.1 Leaving a case page sends the presence beacon with the token in a form body, and OpenRegister (3a987bf, #4562) answers 200, not 405.
- [x] 4.2 Case list and case detail render on the new build; a forced failed save shows "Draft kept on this device", not "Saved".
