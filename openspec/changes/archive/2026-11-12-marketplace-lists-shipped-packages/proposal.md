---
kind: code
---

# The marketplace lists the shipped packages

## Why
Board DqMarktplaats lists the packages dossiq ships, with their installed state and an install button, while no registry is set up. The page listed three hand-written cards with no state, and no store route could install a shipped package.

## What changes
- `GET /api/store/packages` lists every valid package with a `summary` for any signed-in user.
- `POST /api/store/packages/{id}/install` installs an on-request package, for administrators only, through CaseTypePackageChoice.
- Six packages gain a `summary`. The Store page declares `config.shipped`.
