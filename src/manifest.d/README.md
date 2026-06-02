# Modular manifest fragments (ADR-037)

Drop `*.json` fragment files in this directory to extend the Procest frontend
manifest **without editing the monolithic `../manifest.json`**.

## Why

When several builds touch the same app concurrently, they all edit the single
`manifest.json` and conflict on merge. ADR-037 lets each build instead add its
pages/menu entries as an isolated fragment file here, eliminating the shared-file
contention.

## How it works

`src/main.js` imports `manifest.json` (the base), then merges every
`manifest.d/*.json` file on top via webpack's `require.context`, in **sorted
filename order**, before building the vue-router routes and mounting the app.

## Merge semantics

- `pages` and `menu` arrays are **concatenated** (base first, then fragments in
  filename order).
- Any other top-level key on a fragment **overrides** the base value.

## Conventions

- Name fragments with an ordering prefix when order matters, e.g.
  `10-leges.json`, `20-bezwaar.json`.
- A fragment looks like `{ "pages": [ ... ], "menu": [ ... ] }`.
- `_placeholder.json` exists so webpack's `require.context` always has at least
  one match; leave it in place. It contributes no pages or menu entries.
- This `README.md` is not bundled (only `*.json` matches the context).
