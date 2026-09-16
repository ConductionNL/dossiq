// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the case page says a status asks of the fields.
 *
 * The assertions that earn their place are about where the answer comes FROM.
 *
 *  - it rides on the case object the page already holds, so the strip says what
 *    this status requires even on an instance whose transition engine refuses.
 *    Gating it on that round trip would have made the half needing no call
 *    depend on the half that does;
 *  - it is read and never recomputed. `@self.fieldRules` is decided per user
 *    and per state on the render path, so a strip that evaluated the
 *    declarations itself would be a second evaluator, and the one on the screen
 *    is the one that would be wrong;
 *  - it is silent on a case whose read carries no answer, rather than rendering
 *    a heading over three empty lines.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CaseStatusDeclarationPanel from '../../src/components/case/CaseStatusDeclarationPanel.vue'

/**
 * Mount the strip over one case object, with the engine answering nothing.
 *
 * @param {object} objectData The case as OpenRegister answered it.
 * @param {boolean} engineAnswers Whether the transition endpoint answers at all.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountWith(objectData, engineAnswers = false) {
	if (engineAnswers) {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { current: {} } })
	} else {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('no engine here'))
	}

	const wrapper = mount(CaseStatusDeclarationPanel, {
		props: { objectId: 'case-1', objectData },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	vi.restoreAllMocks()
})

describe('what this status asks of the case', () => {
	it('names the fields to fill in', async () => {
		const wrapper = await mountWith({
			'@self': {
				fieldRules: {
					state: 's2',
					hidden: [],
					readOnly: [],
					required: ['motivering'],
				},
			},
		})

		const lines = wrapper.findAll('[data-testid="case-status-field-rule"]')
		expect(lines).toHaveLength(1)
		expect(lines[0].text()).toContain('motivering')
	})

	it('tells the three kinds apart on one case', async () => {
		const wrapper = await mountWith({
			'@self': {
				fieldRules: {
					state: 's2',
					hidden: ['qualityScore'],
					readOnly: ['confidentiality'],
					required: ['motivering'],
				},
			},
		})

		const lines = wrapper
			.findAll('[data-testid="case-status-field-rule"]')
			.map((line) => line.text())

		expect(lines).toHaveLength(3)
		expect(lines.join(' ')).toContain('qualityScore')
		expect(lines.join(' ')).toContain('confidentiality')
		expect(lines.join(' ')).toContain('motivering')
	})

	it('still says so when the transition engine refuses', async () => {
		const wrapper = await mountWith({
			'@self': {
				fieldRules: {
					state: 's2',
					hidden: [],
					readOnly: [],
					required: ['motivering'],
				},
			},
		})

		expect(
			wrapper.find('[data-testid="case-status-field-rules"]').exists(),
		).toBe(true)
	})

	it('says nothing about a case whose read carries no answer', async () => {
		const wrapper = await mountWith({ '@self': {} })

		expect(
			wrapper.find('[data-testid="case-status-field-rules"]').exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="case-status-declaration"]').exists(),
		).toBe(false)
	})

	it('says nothing when every published list is empty', async () => {
		const wrapper = await mountWith({
			'@self': {
				fieldRules: { state: 's2', hidden: [], readOnly: [], required: [] },
			},
		})

		expect(
			wrapper.find('[data-testid="case-status-field-rules"]').exists(),
		).toBe(false)
	})
})
