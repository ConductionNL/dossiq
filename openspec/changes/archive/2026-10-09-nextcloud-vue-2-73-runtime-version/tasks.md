# Tasks: nextcloud-vue-2-73-runtime-version

## 1. Move to the library release with the helpers
- [x] 1.1 `package.json` and `package-lock.json` on @conduction/nextcloud-vue ^2.73.1. The vendored manifest schema is unchanged.
  - test: `tests/vitest/appVersion.spec.js` "builds against a library that has the helper" (fails today: range ^2.71.0)

## 2. The build always reads the installed version
- [x] 2.1 `scripts/appVersion.js` requires `@conduction/nextcloud-vue/webpack` and throws when `appVersionDefine()` is missing; no info.xml literal fallback.
  - test: `tests/vitest/appVersion.spec.js` "stops the build on a library without the helper" (fails today) and "uses the real library helper" (fails on a 2.71.0 install, where the old code took the literal)
