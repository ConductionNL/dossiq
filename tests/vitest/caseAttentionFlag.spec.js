// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The flag on the case page, and the chip that finds the flagged ones.
 *
 * 🔴 THE BROWSER NEVER WRITES THE HISTORY ITSELF. `needsAttention` and
 * `attentionFlagHistory` are fields on the case and OpenRegister would take a
 * patch on either straight from here. What a patch cannot do is refuse a
 * clearing with no reason, and it cannot be trusted to send back a history
 * with every earlier row still in it: one stale copy in a component and three
 * raisings are gone with nothing failing anywhere. So the assertion that
 * matters here is the URL: both acts go to dossiq's own endpoint, and no
 * object write is made at all.
 *
 * 🔴 THE CHIP FILTERS ON THE STORED BOOLEAN, NOT ON `_unread`. Those are two
 * different facts, and a chip pointed at the wrong one would narrow the list
 * plausibly and answer a different question. The manifest is read here rather
 * than described, because a chip that quietly changed its filter is exactly
 * the kind of thing that reads fine in a diff.
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CaseAttentionPanel from '../../src/components/case/CaseAttentionPanel.vue'

const mockShowError = vi.fn()

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: (...a) => mockShowError(...a),
}))

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

const caseDetail = manifest.pages.find((p) => p.id === 'CaseDetail')
const cases = manifest.pages.find((p) => p.id === 'Cases')

/** Stubs that let the strip render and still expose what was clicked. */
const stubs = {
	NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
	NcTextField: {
		props: ['value'],
		template:
			'<input v-bind="$attrs" :value="value" @input="$emit(\'update:value\', $event.target.value)">',
	},
}

/**
 * Mount the strip over a flag the endpoint answers.
 *
 * @param {object} flag       What the attention endpoint answers.
 * @param {object} objectData The case as the page read it.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountStrip(flag, objectData = {}) {
	axios.get.mockResolvedValue({ data: flag })

	const wrapper = mount(CaseAttentionPanel, {
		props: { objectId: 'case-7', objectData },
		global: { stubs },
	})

	await wrapper.vm.$nextTick()
	await Promise.resolve()
	await Promise.resolve()
	await wrapper.vm.$nextTick()

	return wrapper
}

describe('the strip is declared on the case page', () => {
	it('sits under the unread strip, above the panels', () => {
		const unread = caseDetail.config.layout.find(
			(l) => l.widgetId === 'case-unread',
		)
		const attention = caseDetail.config.layout.find(
			(l) => l.widgetId === 'case-attention',
		)
		const panels = caseDetail.config.layout.find(
			(l) => l.widgetId === 'case-panels',
		)

		expect(
			attention,
			'the attention strip is missing from the layout',
		).toBeTruthy()
		expect(unread.gridY).toBeLessThan(attention.gridY)
		expect(attention.gridY).toBeLessThan(panels.gridY)
		expect(attention.gridWidth).toBe(12)
	})

	it('declares a widget whose type the registry answers', () => {
		// A layout grid item falls through to CnDetailWidgetHost, which
		// resolves a renderer from `cnRegistry[widget.type]` and renders
		// NOTHING, silently, when no key answers.
		const widget = caseDetail.config.widgets.find(
			(w) => w.id === 'case-attention',
		)
		expect(widget).toBeTruthy()
		expect(widget.type).toBe('case-attention')
		expect(registrySource).toContain("'case-attention': {")
		expect(registrySource).toContain('component: CaseAttentionPanel,')
	})

	it('carries the reason gate 29 asks a custom widget for', () => {
		const entry = registrySource.slice(
			registrySource.indexOf("'case-attention': {"),
			registrySource.indexOf("'case-notes-pane': {"),
		)
		expect(entry).toContain('_note:')
		expect(entry).toMatch(/@custom-widget-ratchet exclude \S+ \S+/)
	})
})

describe('the work list finds the flagged ones', () => {
	it('filters on the stored flag and not on the read state', () => {
		const chip = cases.config.quickFilters.find(
			(q) => q.label === 'Needs attention',
		)

		expect(chip, 'the Needs attention chip is missing').toBeTruthy()
		expect(chip.filter.needsAttention).toBe(true)
		expect(
			chip.filter._unread,
			'the flag is not the per-user read state',
		).toBeUndefined()
	})

	it('narrows the risk chip to the levels that are worth finding', () => {
		const chip = cases.config.quickFilters.find(
			(q) => q.label === 'Assessed high risk',
		)

		expect(chip).toBeTruthy()
		expect(chip.filter.riskLevel).toEqual(['high', 'critical'])
	})

	it('declares no risk column, because a column cannot be absent per reader', () => {
		// A header with nothing under it tells a reader without the permission
		// that an assessment exists, which is most of what the permission was
		// for. The chip narrows to nothing for them instead, which says no more
		// than that they have nothing to see.
		const keys = cases.config.columns.map((c) => c.key)
		expect(keys).not.toContain('riskLevel')
		expect(keys).not.toContain('riskAssessment')
	})
})

describe('raising and clearing go through dossiq, never through an object write', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		axios.put.mockReset()
		axios.patch.mockReset()
		mockShowError.mockReset()
	})

	it('raises with the reason that was written', async () => {
		const wrapper = await mountStrip({
			raised: false,
			flag: {},
			history: [],
			raisings: 0,
			clearings: 0,
		})

		axios.post.mockResolvedValue({
			data: {
				raised: true,
				flag: { reason: 'The applicant is in hospital', raisedBy: 'ahmed' },
				history: [
					{
						act: 'raised',
						reason: 'The applicant is in hospital',
						actor: 'ahmed',
						moment: '2026-03-01T09:00:00+00:00',
					},
				],
				raisings: 1,
				clearings: 0,
			},
		})

		await wrapper
			.find('[data-testid="case-attention-reason"]')
			.setValue('The applicant is in hospital')
		await wrapper.find('[data-testid="case-attention-raise"]').trigger('click')
		await Promise.resolve()
		await wrapper.vm.$nextTick()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post.mock.calls[0][0]).toBe(
			'/index.php/apps/dossiq/api/case/case-7/attention/raise',
		)
		expect(axios.post.mock.calls[0][1]).toEqual({
			reason: 'The applicant is in hospital',
		})
		expect(axios.put).not.toHaveBeenCalled()
		expect(axios.patch).not.toHaveBeenCalled()
	})

	it('offers clearing on a flagged case, and sends its own reason', async () => {
		const wrapper = await mountStrip({
			raised: true,
			flag: { reason: 'The applicant is in hospital', raisedBy: 'ahmed' },
			history: [
				{
					act: 'raised',
					reason: 'The applicant is in hospital',
					actor: 'ahmed',
					moment: '2026-03-01T09:00:00+00:00',
				},
			],
			raisings: 1,
			clearings: 0,
		})

		expect(wrapper.find('[data-testid="case-attention-raised"]').exists()).toBe(
			true,
		)
		expect(wrapper.text()).toContain('The applicant is in hospital')

		axios.post.mockResolvedValue({
			data: {
				raised: false,
				flag: {},
				history: [],
				raisings: 1,
				clearings: 1,
			},
		})

		await wrapper
			.find('[data-testid="case-attention-reason"]')
			.setValue('They are home and the file is complete')
		await wrapper.find('[data-testid="case-attention-clear"]').trigger('click')
		await Promise.resolve()
		await wrapper.vm.$nextTick()

		expect(axios.post.mock.calls[0][0]).toBe(
			'/index.php/apps/dossiq/api/case/case-7/attention/clear',
		)
		expect(axios.post.mock.calls[0][1]).toEqual({
			reason: 'They are home and the file is complete',
		})
	})

	it('will not send an act with nothing written', async () => {
		const wrapper = await mountStrip({
			raised: false,
			flag: {},
			history: [],
			raisings: 0,
			clearings: 0,
		})

		await wrapper.find('[data-testid="case-attention-reason"]').setValue('   ')
		await wrapper.find('[data-testid="case-attention-raise"]').trigger('click')
		await Promise.resolve()

		expect(axios.post).not.toHaveBeenCalled()
	})

	it('shows the server refusal rather than a sentence of its own', async () => {
		const wrapper = await mountStrip({
			raised: false,
			flag: {},
			history: [],
			raisings: 0,
			clearings: 0,
		})

		axios.post.mockRejectedValue({
			response: {
				status: 400,
				data: {
					error: 'Write down why, and the flag will change.',
					code: 'reason_required',
				},
			},
		})

		await wrapper.find('[data-testid="case-attention-reason"]').setValue('x')
		await wrapper.find('[data-testid="case-attention-raise"]').trigger('click')
		await Promise.resolve()
		await wrapper.vm.$nextTick()

		expect(mockShowError).toHaveBeenCalledWith(
			'Write down why, and the flag will change.',
		)
	})

	it('shows every raising and every clearing, oldest first', async () => {
		const wrapper = await mountStrip({
			raised: false,
			flag: {},
			history: [
				{
					act: 'raised',
					reason: 'Round one',
					actor: 'ahmed',
					moment: '2026-01-01T09:00:00+00:00',
				},
				{
					act: 'cleared',
					reason: 'Round one answered',
					actor: 'nadia',
					moment: '2026-01-14T09:00:00+00:00',
				},
				{
					act: 'raised',
					reason: 'Round two',
					actor: 'ahmed',
					moment: '2026-02-01T09:00:00+00:00',
				},
				{
					act: 'cleared',
					reason: 'Round two answered',
					actor: 'nadia',
					moment: '2026-02-14T09:00:00+00:00',
				},
			],
			raisings: 2,
			clearings: 2,
		})

		const rows = wrapper.findAll('[data-testid="case-attention-history"] li')
		expect(rows).toHaveLength(4)
		expect(rows[0].text()).toContain('Round one')
		expect(rows[3].text()).toContain('Round two answered')
		expect(rows.map((r) => r.attributes('data-act'))).toEqual([
			'raised',
			'cleared',
			'raised',
			'cleared',
		])
	})

	it('stays silent rather than erroring where the endpoint does not exist', async () => {
		axios.get.mockRejectedValue({ response: { status: 404, data: {} } })

		const wrapper = mount(CaseAttentionPanel, {
			props: { objectId: 'case-7', objectData: {} },
			global: { stubs },
		})
		await Promise.resolve()
		await Promise.resolve()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="case-attention"]').exists()).toBe(false)
		expect(mockShowError).not.toHaveBeenCalled()
	})
})

describe('the assessment is absent, not blank, for a reader without the permission', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		mockShowError.mockReset()
	})

	const flag = { raised: false, flag: {}, history: [], raisings: 0, clearings: 0 }

	it('draws the level and its ground for a reader who may see it', async () => {
		const wrapper = await mountStrip(flag, {
			riskAssessment: {
				level: 'high',
				ground: 'Two incidents at the address in twelve months',
				assessor: 'nadia',
				reviewDate: '2027-01-15',
			},
		})

		const block = wrapper.find('[data-testid="case-attention-risk"]')
		expect(block.exists()).toBe(true)
		expect(wrapper.find('[data-risk-level="high"]').exists()).toBe(true)
		expect(block.text()).toContain(
			'Two incidents at the address in twelve months',
		)
		expect(
			wrapper.find('[data-testid="case-attention-risk-stale"]').exists(),
		).toBe(false)
	})

	it('draws nothing at all where OpenRegister filtered the property out', async () => {
		// What arrives for a reader without the group is a case with no
		// `riskAssessment` key, which is the same thing a case nobody assessed
		// carries. There is deliberately no third state.
		const filtered = await mountStrip(flag, { title: 'Melding geluidsoverlast' })
		const never = await mountStrip(flag, {
			title: 'Melding geluidsoverlast',
			riskAssessment: {},
		})

		expect(filtered.find('[data-testid="case-attention-risk"]').exists()).toBe(
			false,
		)
		expect(never.find('[data-testid="case-attention-risk"]').exists()).toBe(
			false,
		)
		expect(filtered.text()).not.toContain('Assessed risk')
	})

	it('says an assessment past its review date is due for review', async () => {
		const wrapper = await mountStrip(flag, {
			riskAssessment: {
				level: 'medium',
				ground: 'One incident',
				assessor: 'nadia',
				reviewDate: '2020-01-01',
			},
		})

		expect(
			wrapper.find('[data-testid="case-attention-risk-stale"]').exists(),
		).toBe(true)
	})
})
