# Proposal: align-claims-and-licence

kind: honesty/metadata alignment — small change; no feature work. Every finding below was verified
against the working tree on 2026-07-05.

## Why

Procest's public claims contradict its code and its own licence file:

1. **Licence contradiction.** `LICENSE` is EUPL-1.2 (header: "EUROPEAN UNION PUBLIC LICENCE
   v. 1.2") and README §License (line 275) says EUPL-1.2 — but `appinfo/info.xml:52` declares
   `<licence>agpl</licence>`, the info.xml EN/NL descriptions (lines 26/47) say "Free and open
   source under the AGPL license", and the README badge (line 13) says AGPL-3.0. Conduction apps
   are EUPL-1.2 by company decision.
   - Constraint (re-verified upstream 2026-07-05): Nextcloud's `resources/app-info.xsd` licence
     enum **DOES accept `EUPL-1.2`** on current releases. Added by nextcloud/server PR #60212
     ("feat(app-licenses): Add further compatible licenses for apps to use", master commit
     `fff79031`, 2026-05-07; stable31 backport `9b18e93b`), enum block annotated
     `<!-- Requires Nextcloud minVersion >= 31 -->`. Verified present in tagged releases
     v33.0.5/v33.0.6/v34.0.1 and on the stable31/stable32 branch heads (v32.0.8 and older tags
     predate the backport). The App Store also accepts it (`EUPL-1.2` — "European Union Public
     Licence 1.2" in nextcloudappstore `core/fixtures/licenses.json`). The local dev checkout's
     xsd predates the backport, which caused the earlier "not an allowed value" finding.
2. **Unified Search** (README line 67) is claimed as a procest feature; procest has no
   `lib/Search/` — the search provider is OpenRegister's `lib/Search/ObjectsProvider.php`
   (verified on OR origin/development). Reword to "provided centrally via OpenRegister".
3. **DMN** (README line 254 lists "BPMN 2.0, DMN for task and decision logic") — no DMN engine or
   DMN artifact exists in procest; remove or mark roadmap.
4. **Pipelinq Bridge** (README line 68) — advertised, but no code implements it; the new
   `semantic-case-intake` change is authored to back it. Re-point the claim at that change as
   roadmap until it ships.
5. **Dead doc links** — README references `docs/FEATURES.md` (lines 107, 247),
   `docs/ARCHITECTURE.md` (lines 108, 248), `docs/development.md` (line 249); none of the three
   files exists (verified `ls`).
6. **Platform matrix drift** — README says "Nextcloud 28 – 33" (line 162) and "PHP 8.1+"
   (line 236); `appinfo/info.xml:71-72` declares `min-version="28" max-version="34"` and PHP
   `min-version="8.3"`. README must say NC 28–34 / PHP 8.3+.
7. **Overstated feature maturity** in `openspec/features.overlay.json`:
   - `archief-edepot-handover` ("Archivering naar e-Depot") is `stable`, but submission runs
     against a log adapter (`LogEDepotSubmissionAdapter` bound in
     `lib/AppInfo/Application.php:350-351`) — nothing is ever transmitted to an e-Depot.
   - `multi-tenancy` ("Multi-tenant SaaS") is `stable`, but the tenant stack (tenant,
     tenantConfiguration, tenantQuota, tenantUser, tenantMandate, tenantBillingEvent schemas +
     services) is app-local and not yet on the OR boundary.

## What Changes

- info.xml: `<licence>EUPL-1.2</licence>` — the SPDX enum value Nextcloud accepts since the
  May-2026 xsd update (PO decision 2026-07-05: "NC now supports EUPL so let's use that"); fix
  EN + NL description licence sentences. Fallback ONLY for NC versions whose xsd predates the
  EUPL value (tags ≤ v32.0.8 at verification time): keep `agpl` + XML comment stating the actual
  licence is EUPL-1.2.
- README: badge → EUPL-1.2; Unified Search reworded to "provided centrally via OpenRegister";
  DMN removed from standards claims (or marked roadmap); Pipelinq Bridge re-pointed at
  `semantic-case-intake` as roadmap; three dead links fixed (point at the real docs/ pages or
  remove); NC 28–34 / PHP 8.3+.
- features.overlay.json: `archief-edepot-handover` → `beta` (reason: e-Depot submission adapter is
  mock/log — no real transmission path); `multi-tenancy` → `beta` (reason: tenant stack not yet on
  the OpenRegister boundary).

## Out of Scope

- Implementing the bridge (that is `semantic-case-intake`), the archival migration
  (`migrate-archival-to-or`), or any code change under `lib/`/`src/` beyond metadata files.
- Re-licensing history or LICENSE file changes (LICENSE is already correct).

## Open Questions

- ~~info.xml `<licence>` enum~~ **RESOLVED 2026-07-05 (PO decision: "NC now supports EUPL so
  let's use that")**: `<licence>EUPL-1.2</licence>` is the specced outcome — the token was added
  to the upstream xsd enum (nextcloud/server PR #60212, 2026-05-07) and to the App Store's
  accepted-licenses fixtures. The previous default (keep `agpl` + XML comment) is now only the
  documented fallback for NC versions whose xsd predates the EUPL value; tasks pin the minimum
  version. Fleet-wide follow-up (out of scope here): openregister and the other Conduction apps
  carry the same `agpl` value and need the identical flip — track as a separate fleet change.
