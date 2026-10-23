// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaaktypeFlow: the transition lines meet the nodes they name (the
 * line layer is drawn one to one with the nodes, not scaled into the canvas),
 * a status shows its own colour as a dot, and the page names the case type and
 * the version under its title.
 *
 * @spec openspec/changes/case-type-flow-board-look/specs/case-types/spec.md#requirement-the-case-type-flow-page-draws-board-dqzaaktypeflow
 */

import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it } from 'vitest'

const { default: WorkflowEditor } = await import('../../src/views/settings/WorkflowEditor.vue')
const { default: WorkflowNode } = await import('../../src/views/settings/components/WorkflowNode.vue')
const { default: CaseTypeFlowView } = await import('../../src/views/settings/CaseTypeFlowView.vue')
const { useWorkflowStore } = await import('../../src/store/modules/workflow.js')

describe('the transition layer', () => {
	it('is as large as its view box, so a line is not scaled away from its nodes', () => {
		const size = WorkflowEditor.computed.svgSize.call({})
		expect(WorkflowEditor.computed.svgViewBox.call({ svgSize: size })).toBe(`0 0 ${size.width} ${size.height}`)
	})

	it('joins nodes at the middle of their title row', () => {
		const centre = WorkflowEditor.methods.getNodeCenter.call({ nodePositions: { s: { x: 100, y: 40 } } }, 's')
		expect(centre).toEqual({ x: 200, y: 58 })
	})
})

describe('a status node', () => {
	it('shows its status colour as a dot, grey without one', () => {
		expect(WorkflowNode.computed.dotColour.call({ status: { colour: 'teal' } })).toContain('--nl-color-teal')
		expect(WorkflowNode.computed.dotColour.call({ status: {} })).toContain('--nl-color-grey')
	})
})

describe('the page subtitle', () => {
	it('names the case type and the version on screen', () => {
		setActivePinia(createPinia())
		const store = useWorkflowStore()
		const subtitle = () => CaseTypeFlowView.computed.subtitle.call({ caseTypeName: 'Omgevingsvergunning regulier' })
		store.currentTemplate = { version: 5, isDraft: true }
		expect(subtitle()).toBe('Omgevingsvergunning regulier · version 5 draft')
		store.currentTemplate = { version: 4, isDraft: false }
		expect(subtitle()).toBe('Omgevingsvergunning regulier · version 4')
		expect(CaseTypeFlowView.computed.subtitle.call({ caseTypeName: '' })).toBe('')
	})
})
