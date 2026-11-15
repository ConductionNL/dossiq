# personal-settings-surface Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [views-in-my-menu-settings](../../)

## ADDED Requirements

### Requirement: The personal settings open with the views in my menu

The user settings dialog SHALL draw the library's Views in my menu section (`views`) first, before dossiq's own
sections, where the case type picker was.

#### Scenario: DqPersoonlijkeInstellingen

- **GIVEN** a user opens Persoonlijke instellingen
- **THEN** the first section is "Weergaven in mijn menu", listing their pinned views with "Uit menu halen" and a
  "Weergave toevoegen" picker
