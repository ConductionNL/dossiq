# Tasks: wizard-dataset-card-load

## 1. Manifest

- [x] 1.1 Merge the dataset choice step and its run-action load step into one cards step with `loadAction: load-demo-data`
- [x] 1.2 Validate the manifest against the `@conduction/nextcloud-vue` 2.65.0 schema (already required)

## 2. Server

- [x] 2.1 Accept `{ dataset }` on `load-demo-data`, refuse an unknown dataset, record the pick after a successful load
- [x] 2.2 Report every manifest step id from `/api/setup/status`
- [x] 2.3 Unit tests: the posted dataset, the refusal, a failed load stores nothing, status ids equal manifest ids

## 3. Admin settings

- [x] 3.1 Move `register-check` to the admin settings page (the existing Re-import configuration button, which runs the same forced import)
