---
kind: code
---

# Proposal: setup-wizard-board-steps

The drawn board DqInstellen is the canon for the first-time setup wizard (decision 130: amend the spec to the board). The board walks five steps: Organisatie, Huisstijl, Zaaktypen, Voorbeeldgegevens, Klaar. The wizard had a welcome note, the example data step, the dwangsom secret and Done.

## What changes

1. **Organisation.** A `config-fields` step for the organisation's name and logo address. Both are written onto the active OpenRegister Organisation (`name`, `image`), which is the tenant; dossiq keeps no copy. A blank field keeps what is there.
2. **House style.** An `info` step that hands off to Thematiq, declared with `requires: ["thematiq"]`, so the wizard leaves it out and the summary names it as skipped where Thematiq is not installed.
3. **Case types.** A cards `choice` step over the valid on-request case definition packages, plus a "None for now" card. Each card's Load button installs its package through `PackageInstaller` (`install-case-types`); packages that install on every instance are already there and are not offered.
4. **Order.** Organisation, House style, Case types, Example data, then the dwangsom secret and Done. The welcome note goes; its sentence opens the Organisation step. The dwangsom secret is not on the board, and it stays because removing it would lose a prompt administrators have today.

## Not in this change

- The board's step 4 says example data "verdwijnen met een klik". Dossiq has no one-click removal, so the step does not claim it.
- The eyebrow reads "Dossiq instellen" only once nextcloud-vue's CnAdminSettingsShell passes `appName` to CnSetupWizard.
