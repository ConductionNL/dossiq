<!--
SPDX-FileCopyrightText: 2026 Dossiq Contributors
SPDX-License-Identifier: EUPL-1.2
-->

# Running the dossiq e2e suite

The suite seeds OpenRegister objects, drives the UI, and deletes what it seeded.
Pick the instance it does that to, deliberately. There is no default.

## Your own rig

```bash
PLAYWRIGHT_BASE_URL=http://localhost:8095 \
DOSSIQ_E2E_CONTAINER=<your nextcloud container> \
npx playwright test
```

Any port that is not 80 or 8080 counts as yours. Nothing else is needed.

`DOSSIQ_E2E_CONTAINER` is how the suite reaches `occ`. It needs `occ` because
`dossiq/case` is an archival schema, and OpenRegister refuses an archival record
on every HTTP delete route. Without `occ` the suite cannot remove a single case,
so it stops before it seeds one.

## The shared development container

`http://localhost:8080` is the container everybody on the box shares. It mounts
the host checkouts under `apps-extra/` and holds data your colleagues are
working on. Aiming a base URL at it is an error.

To go there on purpose, name the origin you permit:

```bash
DOSSIQ_E2E_ALLOW_SHARED_INSTANCE=http://localhost:8080 \
PLAYWRIGHT_BASE_URL=http://localhost:8080 \
DOSSIQ_E2E_CONTAINER=nextcloud \
npx playwright test
```

The flag holds an origin, not `1`. A `1` left in a shell profile keeps
permitting every shared instance the suite ever meets. An origin permits the one
you typed, and stops matching the moment you point somewhere else.

### What the flag changes

| | Your rig | Shared container |
|---|---|---|
| Teardown deletes | the ids this run created | the ids this run created |
| Leftovers from older runs | swept at start | listed at start, never deleted |
| `case-flow-live-journeys` | runs | refuses, with the reason |
| Bundle | built here if missing | never built here |
| `occ` on the wrong instance | warning | fatal |

Teardown deletes ids, not text matches. Every seeded object still carries the
run prefix in its title so you can recognise residue by eye, but the prefix no
longer decides a delete. A row that carries the prefix and is not in this run's
ledger gets named in the output and left alone. `fixture-cleanup-scope.spec.ts`
is the test that holds that property.

### You are not testing your branch

The container serves the host checkout, on whatever branch that checkout is on.
Your clone is not in the picture. The suite prints both bundle fingerprints at
startup, so read the banner before you believe a green run:

```
served bundle 200 text/javascript 5981885 bytes sha256:e129b29018d9
this checkout feat/my-branch @ 5c5890886  /home/you/work/dossiq
local bundle  5981885 bytes sha256:e129b29018d9
```

Two different digests means the instance is serving somebody else's code. Build
in the checkout the container mounts, or move to your own rig.

## Cleaning up after a crashed run

A run that is interrupted never reaches teardown, and its rows then belong to no
ledger. On the shared container the suite lists them and stops. Check the list,
recognise your own, and remove them:

```bash
docker exec -u www-data nextcloud php occ \
  openregister:objects:purge <uuid>... --force --apply
```

## Next

Start your own rig with `bash clean-env.sh`, point `PLAYWRIGHT_BASE_URL` at it,
and keep the shared container for reading the demo caseload.
