// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
//
// Workflow-graph validation for a case type's workflow: nc-vue's
// validateStateMachine over `statusType` objects (with `isFinal`) as states
// and `workflowTemplate.transitions` as edges, in dossiq's words.
//
// Mirrors (client-side, for fast itemised feedback) the referential-
// integrity check `WorkflowDefinitionService::transitionsReferenceForeignStatuses()`
// enforces server-side at publish time (DANGLING_EDGE below); the backend
// call remains the authoritative backstop.
//
// @spec openspec/specs/visual-workflow-editor/spec.md#requirement-workflow-editor-validation

import { validateStateMachine } from '@conduction/nextcloud-vue'

/** Validation rule codes, as dossiq names them, mapped to the library's codes. */
export const RULES = {
	NO_FINAL_STATUS: 'NO_FINAL_STATE',
	UNREACHABLE_FINAL: 'UNREACHABLE_FINAL',
	DANGLING_EDGE: 'DANGLING_TRANSITION',
	DUPLICATE_TRANSITION: 'DUPLICATE_TRANSITION',
	ORPHAN_NODE: 'ORPHAN_STATE',
	CYCLE_NO_EXIT: 'CYCLE_NO_EXIT',
}

/** Where a status and a transition keep what the validator reads. */
export const WORKFLOW_GRAPH_KEYS = {
	label: 'name',
	final: 'isFinal',
	from: 'fromStatus',
	to: 'toStatus',
	transitionLabel: 'label',
}

/**
 * The messages, as English source strings in dossiq's domain. The case type
 * workflow widget's `content.editor.messages` in the manifest carries the same.
 */
export const WORKFLOW_GRAPH_MESSAGES = {
	NO_FINAL_STATE: 'Workflow has no final status defined',
	UNREACHABLE_FINAL: 'Final status "{name}" cannot be reached from any starting status',
	DANGLING_TRANSITION: 'Transition "{label}" references a status that no longer exists',
	DUPLICATE_TRANSITION: 'Duplicate transition from "{from}" to "{to}"',
	ORPHAN_STATE: 'Status "{name}" has no transitions connecting it to the rest of the workflow',
	CYCLE_NO_EXIT: 'Cycle detected with no exit to a final status: {names}',
}

/**
 * Validate a workflow graph against the constraints the publish engine
 * enforces, plus the editor's duplicate, orphan and cycle warnings.
 *
 * @param {object} graph The graph to validate
 * @param {Array|string} graph.statusNodes `statusType` objects: {id, name, isFinal}
 * @param {Array|string} graph.transitions Transition objects: {id, fromStatus, toStatus}
 * @return {Array<{type: 'error'|'warning', code: string, message: string}>} Issues found
 *
 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-workflow-editor-validation
 */
export function validateWorkflowGraph({ statusNodes, transitions } = {}) {
	return validateStateMachine(
		{ states: statusNodes, transitions },
		{
			keys: WORKFLOW_GRAPH_KEYS,
			messages: WORKFLOW_GRAPH_MESSAGES,
			translate: (text) => t('dossiq', text),
		},
	)
}
