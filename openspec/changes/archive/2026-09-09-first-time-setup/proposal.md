# Proposal: first-time-setup

kind: feature — adopts the **abstract first-time setup wizard** (hydra ADR-04x, `@conduction/nextcloud-vue` `CnSetupWizard` + manifest `setup` block) for dossiq. This change is the **per-app spec written first** to surface the concrete requirements the central nc-vue feature must provide (it does not build the central component — that is a separate hydra + nextcloud-vue change).

## Summary

dossiq needs configuration + seed data before it is usable (OpenRegister register/schemas initialised; bezwaar/beroep + ~17 other repair seeds). Two problems today:

1. There is **no UI to drive or check setup** — initialisation rides on install-time repair steps that silently skip when OpenRegister is not resolvable in their session-less context (we hit exactly this: bezwaar/beroep never seeded on the CLI/automated install).
2. **The browser cannot seed.** OpenRegister enforces RBAC on `saveObject`; a normal user/admin clicking "seed" gets *"User 'Anonymous' does not have permission to 'create' objects in schema 'Case Type'"*. Seeding MUST run server-side with system privileges (the `_rbac:false` / admin-context path the `dossiq:bezwaar:seed` command already proves).

This change declares dossiq's first-time-setup flow as a manifest `setup` block plus a server-side **setup-action + setup-status contract**, so the abstract `CnSetupWizard` can render it and gate the app until required setup is complete.

**What changes (dossiq side):**

1. **`manifest.setup`** — declare dossiq's steps: `welcome` (info), `register-check` (config-fields/health — REQUIRED), `seed` (run-action — optional), `done` (summary + health). `completionConfigKey: setup_completed_version`.
2. **`SetupController`** exposing `GET /apps/dossiq/api/setup/status` and `POST /apps/dossiq/api/setup/action/{actionId}` (admin-only). The `seed` action runs `SeedDataService::seedBezwaarBeroepData()` (and the other seed repair steps) **server-side, privileged** — reusing the admin-impersonation / `_rbac:false` path so it works regardless of the requesting user's RBAC.
3. **Completion flag** in app config so the wizard stops gating once required setup is done.

This change depends on, and is the requirements source for, the central change `hydra/openspec/changes/manifest-setup-wizard` + `nextcloud-vue` `cn-setup-wizard`.
