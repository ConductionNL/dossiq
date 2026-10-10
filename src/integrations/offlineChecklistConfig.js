// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// dossiq's `offlineConfig` for nextcloud-vue's offline checklist leaf
// (`field-inspection`). The leaf is generic: it downloads today's planned
// items, caches the checklist templates they name, lets the inspector fill a
// run with no signal and replays it on reconnect. This file only says where
// dossiq keeps those things.
//
// Since inspection-checklists-onto-task (decision 175) a run is an
// OpenRegister task, created and completed by dossiq's own endpoint after it
// checks the required items and the photo gate. So the leaf reads the one
// template schema (items inside `sections[]`) and replays each finished run
// as ONE POST to that endpoint, never as an object create. Needs
// nextcloud-vue with `resultEndpoint` (ConductionNL/nextcloud-vue#1454).

/**
 * The leaf's offline config for dossiq.
 *
 * @type {object}
 * @spec openspec/changes/inspection-checklists-onto-task/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage
 */
export const OFFLINE_CHECKLIST_CONFIG = Object.freeze({
	// Schema holding today's planned inspections.
	plannedSchema: 'fieldInspection',
	// The one template schema; a planned item names its template by id.
	referenceSchema: 'inspectionChecklistTemplate',
	templateRefField: 'checklistTemplateRef',
	// Planning filter: assignee uid property + the scheduled-date field.
	assigneeField: 'inspectorRef',
	dateField: 'scheduledAt',
	// Property on a planned item used as its display title.
	titleField: 'caseRef',
	// How a template's items are read.
	sectionsField: 'sections',
	itemKeyField: 'id',
	itemTextField: 'label',
	itemTypeField: 'responseType',
	itemTypeMap: Object.freeze({ yes_no_na: 'yes_no', photo: 'photo_required' }),
	photoRequiredField: 'photoRequired',
	photoRequiredValue: 'altijd',
	// Where a finished run goes: the case's inspection result endpoint, which
	// creates and completes the task. The endpoint names the template as
	// `checklistId` and keeps the planned item in the task's metadata as
	// `inspection`.
	resultEndpoint: '/apps/dossiq/api/vth/cases/{caseRef}/inspection-result',
	resultTemplateParam: 'checklistId',
	resultPlannedItemParam: 'inspection',
	// Nothing is written as a result object any more.
	resultSchema: '',
})
