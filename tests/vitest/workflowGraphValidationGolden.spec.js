// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
//
// Characterisation tests for `validateWorkflowGraph`
// (src/utils/workflowGraphValidation.js): the exact issues, in order, for
// graphs that trip several rules at once. Snapshots taken on the code before
// it was split.
//
// @spec openspec/specs/visual-workflow-editor/spec.md#requirement-workflow-editor-validation

import { describe, expect, it } from 'vitest'
import { validateWorkflowGraph } from '../../src/utils/workflowGraphValidation.js'

describe('workflow graph validation golden', () => {
	it('reports every rule in order', () => {
		expect(validateWorkflowGraph({
			statusNodes: [
				{ id: 'a', name: 'A' },
				{ id: 'b', name: 'B' },
				{ id: 'c', name: 'C' },
				{ id: 'd', name: 'D' },
				{ id: 'e' },
				{ id: 'f', name: 'F', isFinal: true },
				{ id: 'g', name: 'G', isFinal: true },
			],
			transitions: [
				{ id: 1, fromStatus: 'a', toStatus: 'b' },
				{ id: 2, fromStatus: 'a', toStatus: 'b' },
				{ id: 3, fromStatus: 'b', toStatus: 'c' },
				{ id: 4, fromStatus: 'c', toStatus: 'b' },
				{ id: 5, fromStatus: 'x', toStatus: 'a', label: 'Gone' },
				{ id: 6, fromStatus: 'a', toStatus: 'y' },
				{ id: 7, fromStatus: 'a', toStatus: 'f' },
				{ id: 8, fromStatus: 'g', toStatus: 'g' },
				{ id: 9, fromStatus: 'c', toStatus: 'b' },
			],
		})).toMatchInlineSnapshot(`
			[
			  {
			    "code": "DANGLING_EDGE",
			    "message": "Transition "Gone" references a status that no longer exists",
			    "type": "error",
			  },
			  {
			    "code": "DANGLING_EDGE",
			    "message": "Transition "a → y" references a status that no longer exists",
			    "type": "error",
			  },
			  {
			    "code": "DUPLICATE_TRANSITION",
			    "message": "Duplicate transition from "A" to "B"",
			    "type": "warning",
			  },
			  {
			    "code": "DUPLICATE_TRANSITION",
			    "message": "Duplicate transition from "C" to "B"",
			    "type": "warning",
			  },
			  {
			    "code": "ORPHAN_NODE",
			    "message": "Status "D" has no transitions connecting it to the rest of the workflow",
			    "type": "warning",
			  },
			  {
			    "code": "ORPHAN_NODE",
			    "message": "Status "undefined" has no transitions connecting it to the rest of the workflow",
			    "type": "warning",
			  },
			  {
			    "code": "UNREACHABLE_FINAL",
			    "message": "Final status "G" cannot be reached from any starting status",
			    "type": "error",
			  },
			  {
			    "code": "CYCLE_NO_EXIT",
			    "message": "Cycle detected with no exit to a final status: B → C",
			    "type": "warning",
			  },
			]
		`)
	})

	it('reports a graph without a final status and a cycle over everything', () => {
		expect(validateWorkflowGraph({
			statusNodes: JSON.stringify([{ id: 'a', name: 'A' }, { id: 'b', name: 'B' }, { id: 'c', name: 'C' }]),
			transitions: JSON.stringify([
				{ fromStatus: 'a', toStatus: 'b' },
				{ fromStatus: 'b', toStatus: 'a' },
				{ fromStatus: 'b', toStatus: 'c' },
				{ fromStatus: 'c', toStatus: 'b' },
			]),
		})).toMatchInlineSnapshot(`
			[
			  {
			    "code": "NO_FINAL_STATUS",
			    "message": "Workflow has no final status defined",
			    "type": "error",
			  },
			  {
			    "code": "CYCLE_NO_EXIT",
			    "message": "Cycle detected with no exit to a final status: A → B",
			    "type": "warning",
			  },
			  {
			    "code": "CYCLE_NO_EXIT",
			    "message": "Cycle detected with no exit to a final status: B → C",
			    "type": "warning",
			  },
			]
		`)
	})

	it('answers nothing without nodes, and for unreadable input', () => {
		expect(validateWorkflowGraph()).toEqual([])
		expect(validateWorkflowGraph({ statusNodes: 'not json', transitions: [{ fromStatus: 'a' }] })).toEqual([])
		expect(validateWorkflowGraph({ statusNodes: '{"id": 1}' })).toEqual([])
	})
})
