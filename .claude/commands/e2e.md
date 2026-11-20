---
description: Run the dossiq Playwright end-to-end suite against your own Nextcloud
---

The suite seeds and deletes OpenRegister objects, so it has no default target. Ask for the URL of an instance that is not shared if `PLAYWRIGHT_BASE_URL` is not set, and never point it at the shared development container on port 8080. Read the header of `tests/e2e/base-url.ts` before changing the target.

```bash
npm run test:e2e:install                                   # one-time Chromium download
PLAYWRIGHT_BASE_URL=<your instance> npm run test:e2e       # tests/e2e
npm run check:types-e2e                                    # type-check the e2e sources
```

$ARGUMENTS
