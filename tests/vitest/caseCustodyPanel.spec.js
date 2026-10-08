// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Custody tab: the chain, the requests, and the two answers.
 *
 * THE FIRST THING ASSERTED IS THAT A REFUSAL IS NOT AN EMPTY CHAIN. Reading
 * the custody needs read access to the case, so a reader without it gets 403.
 * Drawn as "this case has not changed hands yet", that is a claim about the
 * history nobody made to this reader, and it is invisible: an empty chain and
 * a refused one render identically unless something keeps them apart.
 *
 * THE SECOND IS THAT AN ANSWER RE-READS THE CASE. An accept opens a holding
 * this panel did not write, so a panel that only rewrote the request row in
 * place would show an answered request beside a chain that had not moved. The
 * test watches the SECOND read, not the POST, because the POST landing proves
 * only that the server was called.
 *
 * THE THIRD IS THAT A REFUSAL CARRIES A REASON. The button is disabled until
 * there is one and the panel refuses to send an empty one, because the server
 * answers 400 and meeting that refusal after the click teaches the holder
 * nothing.
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CaseCustodyPanel from '../../src/components/case/CaseCustodyPanel.vue'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const register = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'),
		'utf8',
	),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

const caseDetail = manifest.pages.find((p) => p.id === 'CaseDetail')

/** One holding, in the shape the endpoint answers. */
const HOLDINGS = [
	{
		id: 'h-1',
		sequence: 1,
		organisationUnit: 'vergunningen',
		handler: 'jan',
		from: '2026-03-03T09:00:00+01:00',
		until: '2026-04-12T14:30:00+02:00',
		open: false,
		reason: 'Registered',
	},
	{
		id: 'h-2',
		sequence: 2,
		organisationUnit: 'toezicht',
		handler: '',
		from: '2026-04-12T14:30:00+02:00',
		until: '',
		open: true,
		reason: 'Dit is handhaving',
	},
]

/** One pending request, in the shape the endpoint answers. */
const PENDING = {
	id: 't-1',
	requestedBy: 'sofie',
	reason: 'Dit dossier hoort bij mijn wijk',
	status: 'pending',
}

const stubs = {
	NcLoadingIcon: { template: '<span class="loading" />' },
	NcEmptyContent: {
		props: {
			name: { type: String, default: '' },
			description: { type: String, default: '' },
		},
		template:
			'<div class="empty"><span class="empty__name">{{ name }}</span><span class="empty__description">{{ description }}</span></div>',
	},
	NcButton: {
		// NO EXPLICIT `$emit('click')`. The parent's `@click` already falls
		// through to the root element with `$attrs`, so emitting as well fires
		// the handler TWICE, and the test then counts two posts for one press.
		props: { disabled: { type: Boolean, default: false } },
		template: '<button v-bind="$attrs" :disabled="disabled"><slot /></button>',
	},
	NcTextField: {
		// `modelValue` and `update:modelValue`, because the panel binds with
		// `v-model` rather than the deprecated `.sync`. A stub that listened on
		// the old pair would leave the reason empty and the refuse button
		// disabled forever, which is a green test over a dead field.
		props: {
			modelValue: { type: String, default: '' },
			label: { type: String, default: '' },
		},
		template:
			'<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
}

/**
 * Mount the panel over one case.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountPanel(caseId = 'case-1') {
	const wrapper = mount(CaseCustodyPanel, {
		props: { objectId: caseId },
		global: { stubs },
	})
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()

	return wrapper
}

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

describe('the Custody tab', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.get.mockImplementation((url) => {
			if (String(url).endsWith('/takeovers')) {
				return Promise.resolve({ data: { requests: [PENDING] } })
			}

			return Promise.resolve({
				data: { holdings: HOLDINGS, open: HOLDINGS[1], total: 2 },
			})
		})
		axios.post.mockResolvedValue({ data: { ...PENDING, status: 'refused' } })
	})

	it('draws every holding with both of its ends', async () => {
		const wrapper = await mountPanel()

		const rows = wrapper.findAll('[data-testid="case-custody-holding"]')
		expect(rows).toHaveLength(2)

		// The closed holding names a period with two ends. A row that showed
		// only the start would look complete on a chain with a gap in it.
		expect(rows[0].text()).toContain('vergunningen')
		expect(rows[0].text()).toMatch(/2026/)
		expect(rows[0].text()).toContain('Registered')

		// The open one is the only holding allowed to have no end, and it says
		// so in words rather than by a blank.
		expect(rows[1].classes()).toContain('case-custody__holding--open')
		expect(rows[1].text()).toContain('toezicht')
	})

	it('says a refusal is a refusal, not a case that never moved', async () => {
		axios.get.mockRejectedValue({ response: { status: 403 } })

		const wrapper = await mountPanel()

		expect(wrapper.find('.empty__name').text()).toContain('who has held it')
		expect(
			wrapper.findAll('[data-testid="case-custody-holding"]'),
			'A refused read draws no chain at all, because it has none to draw.',
		).toHaveLength(0)
	})

	it('draws a failed read apart from a refused one', async () => {
		axios.get.mockRejectedValue({ response: { status: 500 } })

		const wrapper = await mountPanel()

		expect(wrapper.find('.empty__name').text()).toContain('could not read')
		expect(wrapper.find('.empty__description').text()).toContain('has not moved')
	})

	it('will not send a refusal without a reason', async () => {
		const wrapper = await mountPanel()

		const refuse = wrapper.find('[data-testid="case-custody-refuse"]')
		expect(
			refuse.attributes('disabled'),
			'An empty reason is refused by the server with a 400, so the button does not offer it.',
		).toBeDefined()

		expect(axios.post).not.toHaveBeenCalled()
	})

	it('offers the refusal once a reason has been typed', async () => {
		const wrapper = await mountPanel()

		await wrapper
			.find('[data-testid="case-custody-refusal-reason"]')
			.setValue('Ik ben er al mee bezig')
		await wrapper.vm.$nextTick()

		const refuse = wrapper.find('[data-testid="case-custody-refuse"]')
		expect(
			refuse.attributes('disabled'),
			'A field the panel does not read leaves this button disabled forever, which looks the same as a missing reason.',
		).toBeUndefined()

		await refuse.trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(String(axios.post.mock.calls[0][0])).toContain('/refuse')
		expect(axios.post.mock.calls[0][1]).toEqual({
			reason: 'Ik ben er al mee bezig',
		})
	})

	it('re-reads the case after an answer, rather than rewriting the row', async () => {
		const wrapper = await mountPanel()
		const readsBefore = axios.get.mock.calls.length

		await wrapper.find('[data-testid="case-custody-accept"]').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(String(axios.post.mock.calls[0][0])).toContain('/accept')
		expect(
			axios.get.mock.calls.length,
			'An accept opens a holding this panel did not write, so the chain has to be read again.',
		).toBeGreaterThan(readsBefore)
	})

	it('names each request status in a sentence, including the one that escalated', async () => {
		axios.get.mockImplementation((url) => {
			if (String(url).endsWith('/takeovers')) {
				return Promise.resolve({
					data: {
						requests: [{ ...PENDING, id: 't-2', status: 'escalated' }],
					},
				})
			}

			return Promise.resolve({ data: { holdings: HOLDINGS } })
		})

		const wrapper = await mountPanel()
		const request = wrapper.find('[data-testid="case-custody-request"]')

		expect(request.attributes('data-status')).toBe('escalated')
		expect(
			request.text(),
			'Escalated is the ABSENCE of an answer, so it still offers accept and refuse.',
		).toContain('Nobody answered')
		expect(wrapper.find('[data-testid="case-custody-accept"]').exists()).toBe(
			true,
		)
	})
})

describe('the tab the panel is drawn in', () => {
	it('is a tab on the case page, rendered by type', () => {
		const tabs = caseDetail.config.widgets.find((w) => w.id === 'case-panels')
			.content.tabs
		expect(tabs.map((t) => t.widgetId)).toContain('case-custody-panel')

		const widget = caseDetail.config.widgets.find(
			(w) => w.id === 'case-custody-panel',
		)
		expect(
			widget.type,
			'A tab child resolves from cnRegistry[widget.type]; a page slots map never reaches it.',
		).toBe('case-custody-pane')
	})

	it('carries the reason gate 29 asks a custom widget for', () => {
		const entry = registrySource.slice(
			registrySource.indexOf("'case-custody-pane': {"),
			registrySource.indexOf("'case-archival-pane': {"),
		)

		expect(entry).toContain('@custom-widget-ratchet exclude')
	})
})

describe('the records the tab reads', () => {
	it('declares a holding with both ends and the move that opened it', () => {
		const custody = register.components.schemas.caseCustody
		expect(Object.keys(custody.properties)).toEqual(
			expect.arrayContaining([
				'caseId',
				'organisationUnit',
				'handler',
				'from',
				'until',
				'open',
				'reason',
				'movedBy',
				'sequence',
			]),
		)
		expect(
			custody.required,
			'A holding with no case or no start cannot take part in a chain.',
		).toEqual(expect.arrayContaining(['caseId', 'organisationUnit', 'from']))
	})

	it('declares a takeover whose refusal can carry a reason', () => {
		const takeover = register.components.schemas.caseTakeover
		expect(takeover.properties.status.enum).toEqual([
			'pending',
			'accepted',
			'refused',
			'escalated',
		])
		expect(takeover.properties.refusalReason).toBeDefined()
		expect(
			takeover.properties.holdingUnit,
			'The escalation goes to the unit, so the request has to remember which one it was.',
		).toBeDefined()
	})

	it('lets a case type say how long the holder has, and whether an internal move needs consent', () => {
		const caseType = register.components.schemas.caseType.properties
		expect(caseType.takeoverAnswerPeriodDays.type).toBe('integer')
		expect(caseType.consentRequiredInsideOrganisation.type).toBe('boolean')
	})

	it('lets the share carry the scope its consent names', () => {
		const share = register.components.schemas.caseShare.properties
		expect(share.consentId).toBeDefined()
		expect(share.consentScope.type).toBe('array')
		expect(share.consentUntil).toBeDefined()
	})
})
