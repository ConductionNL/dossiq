// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * dossiq's mapping for the generic offline `field-inspection` leaf.
 *
 * The leaf (a nextcloud-vue builtin) owns the offline planning list, the
 * checklist, the mutation queue and the reconnect replay; this config points
 * it at dossiq's schemas and its result endpoint.
 *
 * - Planning: `fieldInspection`, filtered by `inspectorRef` + `scheduledAt`,
 *   as `DailySyncService` does.
 * - Templates: the one template schema `inspectionChecklistTemplate`, whose
 *   items sit inside ordered `sections`, keyed by the item's stable `id`.
 * - Results: a finished run replays as ONE POST to
 *   `/api/cases/{caseRef}/checklist-results`, which stores it as a completed
 *   inspection task after the required and photo gates; the template is
 *   named as `checklistId` (the endpoint's required key) and the planned
 *   inspection as `inspection` (kept in the task's metadata). No
 *   `checklistResult` object is written any more.
 *
 * The photo gate here mirrors only `photoRequired: altijd`; `if_no` depends on
 * the answer, which the leaf cannot express, so the server's gate decides it
 * on replay.
 *
 * - Conflicts: a replay that collides with an office edit is filed in the
 *   register as a `conflictRecord` (with `syncQueueRef` and `conflictFields`,
 *   which the library writes since 2.213.0), so the case page's conflict card
 *   and the resolve endpoint see it. Without `conflictSchema` the clash stayed
 *   on the device and nobody at the office heard of it.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/tasks.md
 * @spec openspec/changes/archive/2026-11-24-inspection-conflict-card/design.md (open decision 4)
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
 */
export const DOSSIQ_FIELD_INSPECTION_CONFIG = Object.freeze({
	plannedSchema: 'fieldInspection',
	referenceSchema: 'inspectionChecklistTemplate',
	templateRefField: 'checklistTemplateRef',
	resultSchema: '',
	assigneeField: 'inspectorRef',
	dateField: 'scheduledAt',
	titleField: 'caseRef',
	sectionsField: 'sections',
	itemKeyField: 'id',
	itemTextField: 'label',
	itemTypeField: 'responseType',
	itemTypeMap: Object.freeze({ yes_no_na: 'yes_no', photo: 'photo_required' }),
	photoRequiredField: 'photoRequired',
	photoRequiredValue: 'altijd',
	resultEndpoint: '/apps/dossiq/api/cases/{caseRef}/checklist-results',
	resultTemplateParam: 'checklistId',
	resultPlannedItemParam: 'inspection',
	register: 'dossiq',
	conflictSchema: 'conflictRecord',
})

export default DOSSIQ_FIELD_INSPECTION_CONFIG
