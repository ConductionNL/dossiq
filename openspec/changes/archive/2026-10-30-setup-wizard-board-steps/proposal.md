---
kind: code
---

# Proposal: setup-wizard-board-steps

The drawn board DqInstellen is the canon for the first-time setup wizard (decision 130: amend the spec to the board). The board walks five steps: Organisatie, Huisstijl, Zaaktypen, Voorbeeldgegevens, Klaar. The wizard had a welcome note, the example data step, the dwangsom secret and Done.

## What changes

1. **Organisation.** A `config-fields` step for the organisation's name and logo address. Both are written onto the active OpenRegister Organisation (`name`, `image`), which is the tenant; dossiq keeps no copy. A blank field keeps what is there.
2. **House style.** An `info` step that hands off to Thematiq, declared with `requires: ["thematiq"]`, so the wizard leaves it out and the summary names it as skipped where Thematiq is not installed.
3. **Case types.** A cards `choice` step over the valid on-request case definition packages, plus a "None for now" card. Each card's Load button installs its package through `PackageInstaller` (`install-case-types`); packages that install on every instance are already there and are not offered.
4. **Order and count.** Organisation, House style, Case types, Example data, Done: the board's five. The welcome note goes; its sentence opens the Organisation step. The dwangsom callback secret leaves the wizard: it is a procedure-specific setting and stays where it already lives, admin settings > Financial integration (`FinancialIntegrationTab`), with the go-live flag `dwangsom_callback_secret_configured` still reported by the status.
5. **Example data loads on Next.** The step offers "Load example data" first and "Start empty" second, with no Load button per choice. Pressing Next posts the pick through `POST /api/setup/config`, which imports the set at once or records the decline, behind the same `#[AuthorizedAdminSetting]` as before. A failed import answers 422 and keeps the wizard on the step.

## Not in this change

- The board's step 4 says example data "verdwijnen met een klik". Dossiq has no one-click removal, so the step does not claim it.
- The eyebrow reads "Dossiq instellen" only once nextcloud-vue's CnAdminSettingsShell passes `appName` to CnSetupWizard.
