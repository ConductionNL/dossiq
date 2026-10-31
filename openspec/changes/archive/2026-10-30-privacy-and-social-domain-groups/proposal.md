# Proposal: privacy officers and social-domain staff get their own groups

## Why

The admin data fix (#4091) left two open questions. Data breaches (`avgIncident`) could be read only by admin and beheerders, because dossiq had no privacy-officer group. Indications, case meetings and re-integration trajectories (`indicatiestelling`, `mdoOverleg`, `reIntegratieTraject`) kept the register block, so every member of staff could read and write them. Ruben decided on 19 Oct 2026 to add a group for each role.

## What changes

- Two groups are provisioned the way dossiq provisions its others (`ProvisionAssignedGroups`):
  - `dossiq-privacy`, the privacy officers;
  - `dossiq-sociaal-domein`, the social-domain staff.
- `register.d/92-admin-data-access.json`:
  - The privacy officers read and handle data breaches beside admin and beheerders. Any member of staff can still report one.
  - The social-domain staff read and write the three social-domain schemas beside admin and beheerders, in place of the all-staff register block.
  - The `dossiq-sensitive` property rules on those schemas stay as they are.
- `RolePermissions` gives the signed-in user the role permissions `privacy` and `sociaal-domein` from those groups. Admin and beheerders get both. The permissions travel as the `role_permissions` initial state. `src/utils/permissions.js` adds them to the permissions the menu, the page tabs and the route guard read, so these surfaces are offered to the role:
  - the data breach register;
  - the Privacy page's Data breaches tab;
  - the three social-domain pages.
- The register moves to 0.20.56.

## Open

The case page's Indication and Case meeting tabs cannot be gated on a permission yet: nextcloud-vue's detail tabs read `visibleWhen`, which has no permission term. For a user outside the role they show an empty list, because OpenRegister returns no rows. That is a library item.
