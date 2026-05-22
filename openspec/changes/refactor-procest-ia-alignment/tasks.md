# Tasks: Refactor Procest IA Alignment

These tasks are scoped to the single drift (`task-management`). Each task is
self-contained and should take under 15 minutes.

## 1. Manifest menu edits

- [ ] 1.1 Open `src/manifest.json` and locate the menu entry
      `{ "id": "Tasks", "label": "Tasks", ..., "route": "Tasks", "order": 50 }`.
- [ ] 1.2 Delete that entry from the `menu[]` array. Do NOT delete the
      corresponding `pages[]` entry with `id: "Tasks"` (route `/tasks`) — that
      page must keep working for deep links and for `CaseTasksTab` navigation.
- [ ] 1.3 In the same file, find the `MyWork` menu entry (order 20) and
      verify it remains a top-level entry (no `section`, no `permission`).
- [ ] 1.4 Save the file and re-run `node validate-manifest.js` (or the
      project's manifest validator) to confirm the JSON is still valid against
      `https://raw.githubusercontent.com/ConductionNL/nextcloud-vue/main/src/schemas/app-manifest.schema.json`.

## 2. Surface Tasks from inside MyWork

- [ ] 2.1 Open `src/views/MyWork.vue` and locate the `my-work__tabs`
      block (the filter-tabs row).
- [ ] 2.2 Immediately after the existing tabs, add a `<router-link>` (or
      `<NcButton>` with `@click="$router.push({ name: 'Tasks' })"`) labelled
      `{{ t('procest', 'All tasks') }}` (NL: `Alle taken`). The link MUST be
      visible without scrolling.
- [ ] 2.3 Run `npm run lint` and `npm run dev` from the procest repo root;
      open `http://localhost:3000/apps/procest/my-work` and confirm the new
      affordance renders and navigates to `/apps/procest/tasks`.
- [ ] 2.4 Confirm the left-nav no longer shows a "Tasks" top-level entry
      (only `MyWork`, `Dashboard`, `Werkvoorraad`, etc.).

## 3. Translations

- [ ] 3.1 Add the new string `All tasks` to `l10n/en.json` (and any other
      English source the build uses).
- [ ] 3.2 Add the Dutch translation `Alle taken` to `l10n/nl.json`.
- [ ] 3.3 Rebuild translation bundles if the app uses a build step
      (`npm run build:l10n` or equivalent — check `package.json`).

## 4. Update the spec

- [ ] 4.1 After this change is approved and the manifest edit is merged,
      update `openspec/specs/task-management/spec.md` to reflect the new
      placement: remove any wording asserting that Tasks is a top-level menu
      entry; replace with "the global task list is reached via Mijn werk".
- [ ] 4.2 Search the spec for the phrase `top-level` and adjust any
      paragraph that asserts top-level navigation for Tasks.

## 5. Verify, then archive

- [ ] 5.1 Run `openspec validate refactor-procest-ia-alignment --strict`.
- [ ] 5.2 Browser-verify: navigate Procest end-to-end (Dashboard → Mijn werk
      → Tasks via the new affordance → CaseDetail → CaseTasksTab) and
      screenshot the new nav for the PR.
- [ ] 5.3 Run `composer check:strict` (no PHP changes are expected, but the
      gate must stay green).
- [ ] 5.4 Run `npm run test` to confirm no Vue tests break.
- [ ] 5.5 Open a PR titled `refactor(procest): align Tasks placement with IA
      (Mijn werk › Taken)` targeting `development`.
