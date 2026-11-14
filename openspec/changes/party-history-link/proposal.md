# Proposal: party-history-link

## Why

Boards DqContact and DqOrganisatie end the side "Historie" card with "Historie bekijken" and a history icon. The card
said when the record last changed but offered no way to the history itself, which sits in the page's sidebar.

## What changes

The side "Historie" card on the contact and organisation pages gets a foot link "View history" (nl "Historie
bekijken") that opens the sidebar on its History tab (`audit`). It needs nextcloud-vue
feat/facts-link-opens-sidebar-tab (24t1g); before that release the card shows no link, as now.

## Scope

Config (`src/manifest.json`, ContactDetail and OrganisationDetail `side-history`) and one translation key.
