---
sidebar_position: 2
title: Installation
---

# Installation

This guide covers installing and configuring Procest in a Nextcloud environment.

## Prerequisites

- **Nextcloud 28 or higher**: Procest requires Nextcloud 28+ for full compatibility.
- **OpenRegister app**: Procest stores all case data (cases, tasks, decisions, case types) in OpenRegister. Install and enable OpenRegister before installing Procest.
- **PHP 8.1+**: Required by Nextcloud 28.
- **Database**: Any Nextcloud-supported database (PostgreSQL recommended for production).

## Installation from the App Store

1. Log in to Nextcloud as an administrator.
2. Open **Apps** from the top-right menu (or navigate to `/index.php/settings/apps`).
3. Search for **Procest** in the Apps catalog.
4. Click **Download and enable**.
5. Nextcloud installs the app and runs the initial repair step automatically. This repair step creates the `procest` register and imports the 12 base schemas (case, task, caseType, statusType, resultType, roleType, etc.) into OpenRegister.

## Post-Install Configuration

### Verify the Procest Register

After installation, confirm the register was created:

1. Navigate to **OpenRegister** in the left sidebar.
2. Under **Registers**, verify a register named `procest` exists with 12 schemas.
3. If the register is missing, re-run the repair step: go to **Admin settings** > **Procest** > click **Re-import configuration**.

### Case Type Configuration

Procest ships with seed data containing example case types. To configure your own:

1. Open **Procest** from the left sidebar.
2. In the left navigation, click **Case Types**.
3. Click **Add case type** to create a new type. Set:
   - **Name** and **Description**
   - **Processing deadline** (in days, auto-calculates the case due date)
   - **Allowed statuses** (ordered list of status types)
   - **Allowed roles** (initiator, handler, advisor)
4. Set the status for the type to **Published** when ready for use.

### ZGW API Endpoint Mapping

If your organization uses ZGW-compliant systems (OpenZaak, Rx.Mission, etc.):

1. Go to **Admin settings** > **Basic settings** > **Procest**.
2. Under **ZGW Configuration**, enter:
   - **Zaken API URL**, e.g. `https://api.example.nl/zaken/api/v1`
   - **Catalogi API URL**, e.g. `https://api.example.nl/catalogi/api/v1`
   - **Besluiten API URL**, e.g. `https://api.example.nl/besluiten/api/v1`
   - **API token / client credentials** as required by your ZGW provider.
3. Click **Save** and verify the connection using the **Test connection** button.

## Troubleshooting

### Register not found after installation

**Symptom**: Procest opens but lists show "No items found" and the admin settings report a missing register.

**Fix**: Re-run the repair step manually:

```bash
php occ maintenance:repair --include-expensive
```

Or from the admin UI: **Admin settings** > **Procest** > **Re-import configuration**.

### ZGW 400 / 422 errors

**Symptom**: Case creation or status updates fail with ZGW API errors.

**Causes and fixes**:
- **400 Bad Request**: Verify that the Catalogi API URL contains the `zaaktype` UUID you configured in the case type. The UUID must exist in the remote ZGW Catalogi.
- **422 Unprocessable Entity**: A required ZGW field (e.g., `bronorganisatie`, `verantwoordelijkeOrganisatie`) is missing. Check the ZGW field mapping in **Admin settings** > **Procest** > **ZGW mapping**.

### Missing case types after import

**Symptom**: The Case Types list is empty after installation.

**Fix**: The seed data import is triggered by the repair step. Run:

```bash
php occ maintenance:repair
```

If case types still do not appear, check OpenRegister logs at **OpenRegister** > **Logs** for import errors.

### App not visible in navigation

**Symptom**: Procest does not appear in the Nextcloud left sidebar.

**Fix**: Confirm the app is enabled for your account. An administrator can check this at **Admin settings** > **Apps** > **Procest**. If the app is restricted to a group, ensure your account is a member of that group.
