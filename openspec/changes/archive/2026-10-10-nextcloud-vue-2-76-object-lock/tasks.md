# Tasks: nextcloud-vue-2-76-object-lock

## 1. Move to the library release with the lock fix

- [x] 1.1 `package.json` and `package-lock.json` on @conduction/nextcloud-vue ^2.76.0.
    - test: `tests/vitest/nextcloudVue276.spec.js` "puts the real register, schema and id in the URL when the page passes getters" (fails on 2.74.0 and 2.73.1: the URL carries the getter source)
- [x] 1.2 `tests/schemas/app-manifest-v2.schema.json` copied from the package (schema 2.73.0).
    - test: `tests/vitest/validateManifestLag.spec.js` "is at or ahead of the installed schema" (fails with the 2.72.0 copy)

## 2. Credentials text without em-dashes

- [x] 2.1 Comes with 2.76.0; dossiq ships the library's CnCredentials as it is.
    - test: `tests/vitest/nextcloudVue276.spec.js` "has no em-dashes in any English string it translates" and "in the Dutch text of those strings" (both fail on 2.74.0 and 2.73.1)

## 3. Live check

- [x] 3.1 On a case detail page the lock request carries the real register, schema and id and is not answered with 404.
- [x] 3.2 The settings dialog's Credentials text has no em-dashes.
