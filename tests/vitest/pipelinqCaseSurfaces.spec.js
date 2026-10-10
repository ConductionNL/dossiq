// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The case surfaces that read and act through pipelinq (boards
 * DqZaakContactmomenten, DqZaakPartijen and DqZaaktype).
 *
 * The rule every test here keeps: an absent pipelinq is a different answer
 * from an empty one, an uncomputable figure is never zero, an unset language
 * preference is never a choice, and a refusal reaches the handler with its
 * reason.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-one-seam-names-pipelinq-and-an-absent-pipelinq-is-a-different-answer-from-an-empty-one-req-plq-01
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it } from 'vitest'
import CasePartiesWidget from '../../src/components/case/CasePartiesWidget.vue'
import CasePipelinqContactMoments from '../../src/components/case/CasePipelinqContactMoments.vue'
import CaseProgrammeSection from '../../src/components/case/CaseProgrammeSection.vue'
import CaseTypePartyKindsWidget from '../../src/components/caseType/CaseTypePartyKindsWidget.vue'
import LogContactDialog from '../../src/dialogs/LogContactDialog.vue'
import {
	kindCode,
	languageLine,
	progressLine,
	refusalSentence,
	sharedLine,
} from '../../src/services/pipelinqCaseApi.js'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

const stubs = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: { template: '<button @click="$emit(\'click\')"><slot /></button>' },
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'value'],
		template: '<label><slot /></label>',
	},
	NcTextField: true,
	NcTextArea: true,
	NcDateTimePicker: true,
	NcEmptyContent: { props: ['name'], template: '<p class="empty">{{ name }}</p>' },
	NcLoadingIcon: true,
	NcActions: { template: '<div><slot /></div>' },
	NcActionButton: {
		template: '<button @click="$emit(\'click\')"><slot /></button>',
	},
	RouterLink: { props: ['to'], template: '<a><slot /></a>' },
	FileContactMomentDialog: true,
	LinkProgrammeDialog: true,
}

const route = (id) => ({ params: { id } })

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	axios.put.mockReset()
	axios.delete.mockReset()
})

describe('the sentences', () => {
	it('counts a case the reader may not see, and never names it', () => {
		expect(sharedLine({ shared: false })).toBe('')
		expect(
			sharedLine({
				shared: true,
				alsoOnCases: ['a', 'b'],
				alsoOnHiddenCount: 0,
			}),
		).toBe('Also on 2 other cases')
		expect(
			sharedLine({ shared: true, alsoOnCases: [], alsoOnHiddenCount: 1 }),
		).toBe('Also on 1 case you may not see')
		expect(
			sharedLine({
				shared: true,
				alsoOnCases: ['a', 'b'],
				alsoOnHiddenCount: 1,
			}),
		).toBe('Also on 2 other cases and 1 you may not see')
	})

	it('says an unset preference as unset, and an absent resolver as unreadable', () => {
		expect(
			languageLine({
				available: true,
				language: 'en',
				stated: true,
				rule: 'party',
			}),
		).toBe('Writing language: English. Asked for by the party.')
		expect(
			languageLine({
				available: true,
				language: 'nl',
				stated: false,
				rule: 'instanceDefault',
			}),
		).toBe(
			'Writing language: Dutch. No preference recorded, so the default language of this instance.',
		)
		expect(
			languageLine({
				available: false,
				language: 'nl',
				stated: false,
				rule: 'unavailable',
			}),
		).toBe(
			'Writing language: Dutch. No preference can be read on this instance.',
		)
		expect(languageLine(null)).toBe('')
	})

	it('names the mode beside a figure, and never draws an uncomputable one as zero', () => {
		expect(
			progressLine({
				available: true,
				computable: true,
				progress: 40,
				mode: 'fromTasks',
			}),
		).toBe('40 percent done, measured by the closed tasks of the programme.')
		expect(
			progressLine({
				available: true,
				computable: false,
				progress: null,
				mode: 'fromEffort',
				sentence:
					'Nothing has been estimated, so progress cannot be computed.',
			}),
		).toBe(
			'Progress cannot be computed: Nothing has been estimated, so progress cannot be computed.',
		)
		expect(
			progressLine({
				available: true,
				computable: false,
				progress: null,
				mode: 'fromTasks',
				sentence: '',
			}),
		).not.toContain('0 percent')
		expect(progressLine({ available: false })).toBe(
			'Progress cannot be read on this instance.',
		)
	})

	it('names the indicator in a refusal and says the moment is kept', () => {
		expect(refusalSentence('', [])).toBe('')
		expect(
			refusalSentence('An indicator blocks outbound contact.', [
				{ code: 'X', label: 'Geen uitgaand contact' },
			]),
		).toBe(
			'Indicator Geen uitgaand contact: An indicator blocks outbound contact. The contact moment is kept in dossiq.',
		)
	})

	it("reads a kind by pipelinq's code or dossiq's key", () => {
		expect(kindCode({ code: 'aanvrager' })).toBe('aanvrager')
		expect(kindCode({ key: 'person' })).toBe('person')
	})
})

describe('the declarations', () => {
	const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
	const caseTypeDetail = manifest.pages.find(
		(page) => page.id === 'CaseTypeDetail',
	)
	const json = JSON.stringify(caseDetail)

	it('puts the customer record on the Communication tab and the programme on the Related tab, by type', () => {
		expect(json).toContain('"type":"case-pipelinq-contact-moments"')
		expect(json).toContain('"type":"case-programme"')
		expect(registrySource).toContain("'case-pipelinq-contact-moments': {")
		expect(registrySource).toContain("'case-programme': {")
	})

	it('puts the party kinds on the case type page through a slot', () => {
		expect(caseTypeDetail.slots['widget-case-type-party-kinds']).toBe(
			'CaseTypePartyKindsWidget',
		)
		expect(caseTypeDetail.config.layout.map((cell) => cell.widgetId)).toContain(
			'case-type-party-kinds',
		)
		expect(registrySource).toContain('CaseTypePartyKindsWidget: {')
	})
})

describe('the Log contact dialog', () => {
	it("posts to the case route and keeps pipelinq's refusal on screen", async () => {
		axios.post.mockResolvedValue({
			data: {
				contactmoment: { id: 'cm-1' },
				pipelinqRefusal: 'An indicator blocks outbound contact.',
				pipelinqIndicators: [{ label: 'Geen uitgaand contact' }],
			},
		})
		const wrapper = mount(LogContactDialog, {
			props: { caseId: '@objectId' },
			global: { stubs, mocks: { $route: route('case-1') } },
		})
		wrapper.vm.summary = 'Teruggebeld over de planning.'
		wrapper.vm.direction = 'outbound'

		await wrapper.vm.save()
		await flushPromises()

		const [url, body] = axios.post.mock.calls[0]
		expect(url, 'the @objectId token is never posted; the route answers').toBe(
			'/index.php/apps/dossiq/api/cases/case-1/pipelinq/contact-moments',
		)
		expect(body.direction).toBe('outbound')
		expect(body.summary).toBe('Teruggebeld over de planning.')
		expect(wrapper.find('[data-testid="log-contact-saved"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="log-contact-refusal"]').text()).toContain(
			'Geen uitgaand contact',
		)
		expect(
			wrapper.emitted('close'),
			'a refusal does not close the dialog',
		).toBeFalsy()
	})

	it("shows the server's sentence when the save itself is refused", async () => {
		axios.post.mockRejectedValue({
			response: {
				status: 403,
				data: { error: 'You do not have access to this case.' },
			},
		})
		const wrapper = mount(LogContactDialog, {
			props: { caseId: 'case-1' },
			global: { stubs, mocks: { $route: route('case-1') } },
		})
		wrapper.vm.summary = 'x'

		await wrapper.vm.save()
		await flushPromises()

		expect(wrapper.find('[data-testid="log-contact-error"]').text()).toBe(
			'You do not have access to this case.',
		)
		expect(wrapper.find('[data-testid="log-contact-saved"]').exists()).toBe(
			false,
		)
	})
})

describe('the customer record section', () => {
	it('says pipelinq is absent rather than drawing an empty list', async () => {
		axios.get.mockResolvedValue({
			data: { available: false, moments: [], indicators: [] },
		})
		const wrapper = mount(CasePipelinqContactMoments, {
			props: { objectId: 'case-1' },
			global: { stubs },
		})
		await flushPromises()

		expect(
			wrapper.find('[data-testid="case-pipelinq-moments-absent"]').exists(),
		).toBe(true)
		expect(
			wrapper.find('[data-testid="case-pipelinq-moments-empty"]').exists(),
		).toBe(false)
	})

	it('draws a real empty when pipelinq answered nothing', async () => {
		axios.get.mockResolvedValue({
			data: { available: true, moments: [], indicators: [] },
		})
		const wrapper = mount(CasePipelinqContactMoments, {
			props: { objectId: 'case-1' },
			global: { stubs },
		})
		await flushPromises()

		expect(
			wrapper.find('[data-testid="case-pipelinq-moments-empty"]').exists(),
		).toBe(true)
	})

	it('shows the shared line and takes a moment off this case through pipelinq', async () => {
		axios.get.mockImplementation((url) =>
			url.includes('/pipelinq/contact-moments')
				? Promise.resolve({
						data: {
							available: true,
							moments: [
								{
									id: 'm-1',
									channel: 'phone',
									direction: 'inbound',
									summary: 'Vraag',
									shared: true,
									alsoOnCases: [],
									alsoOnHiddenCount: 1,
								},
							],
						},
					})
				: Promise.resolve({ data: {} }),
		)
		axios.delete.mockResolvedValue({ data: { filed: true } })
		const wrapper = mount(CasePipelinqContactMoments, {
			props: { objectId: 'case-1' },
			global: { stubs },
		})
		await flushPromises()

		expect(
			wrapper.find('[data-testid="case-pipelinq-moment-shared"]').text(),
		).toBe('Also on 1 case you may not see')

		await wrapper
			.find('[data-testid="case-pipelinq-moment-unfile"]')
			.trigger('click')
		await flushPromises()
		expect(axios.delete).toHaveBeenCalledWith(
			'/index.php/apps/dossiq/api/cases/case-1/pipelinq/contact-moments/m-1',
		)
	})
})

describe('the programme section', () => {
	it('draws no bar for an uncomputable figure and says why', async () => {
		axios.get.mockResolvedValue({
			data: {
				available: true,
				programme: {
					id: 'p-1',
					name: 'Lindelaan leefbaar 2026',
					progress: {
						available: true,
						computable: false,
						progress: null,
						mode: 'fromTasks',
						sentence:
							'This programme has no tasks to derive progress from.',
					},
				},
			},
		})
		const wrapper = mount(CaseProgrammeSection, {
			props: { objectId: 'case-1' },
			global: { stubs },
		})
		await flushPromises()

		expect(wrapper.text()).toContain('Lindelaan leefbaar 2026')
		expect(
			wrapper.find('[role="progressbar"]').exists(),
			'an empty bar reads as nothing done',
		).toBe(false)
		expect(
			wrapper.find('[data-testid="case-programme-progress"]').text(),
		).toContain('no tasks to derive progress from')
	})

	it('offers to link a case under no programme', async () => {
		axios.get.mockResolvedValue({ data: { available: true, programme: null } })
		const wrapper = mount(CaseProgrammeSection, {
			props: { objectId: 'case-1' },
			global: { stubs },
		})
		await flushPromises()

		expect(wrapper.find('[data-testid="case-programme-none"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="case-programme-link"]').exists()).toBe(
			true,
		)
	})
})

describe('the case type party kinds', () => {
	it('puts the declared kinds first in their order, and saves the new order to pipelinq', async () => {
		axios.get.mockResolvedValue({
			data: {
				source: 'pipelinq',
				available: true,
				kinds: [
					{ code: 'aanvrager', label: 'Aanvrager' },
					{ code: 'gemachtigde', label: 'Gemachtigde' },
					{ code: 'vereniging', label: 'Vereniging' },
				],
				accepted: ['gemachtigde', 'aanvrager'],
			},
		})
		axios.put.mockResolvedValue({
			data: { declared: true, accepted: ['aanvrager', 'gemachtigde'] },
		})
		const wrapper = mount(CaseTypePartyKindsWidget, {
			global: { stubs, mocks: { $route: route('ct-1') } },
		})
		await flushPromises()

		expect(wrapper.vm.rows.map((row) => [row.code, row.accepted])).toEqual([
			['gemachtigde', true],
			['aanvrager', true],
			['vereniging', false],
		])

		wrapper.vm.move('aanvrager', -1)
		await wrapper.vm.save()

		expect(axios.put).toHaveBeenCalledWith(
			'/index.php/apps/dossiq/api/case-types/ct-1/pipelinq/party-kinds',
			{ kinds: ['aanvrager', 'gemachtigde'] },
		)
	})

	it("without pipelinq lists dossiq's kinds read only and offers no save", async () => {
		axios.get.mockResolvedValue({
			data: {
				source: 'dossiq',
				available: false,
				kinds: [{ key: 'person', label: 'Person' }],
				accepted: null,
			},
		})
		const wrapper = mount(CaseTypePartyKindsWidget, {
			global: { stubs, mocks: { $route: route('ct-1') } },
		})
		await flushPromises()

		expect(wrapper.vm.editable).toBe(false)
		expect(
			wrapper.find('[data-testid="case-type-party-kinds-save"]').exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="case-type-party-kinds-source"]').text(),
		).toContain('Pipelinq is not installed')
	})
})

describe('the Roles section', () => {
	it('lists what the case type accepts, marks a party outside it, and gives each party its writing language', async () => {
		axios.get.mockImplementation((url) => {
			const asked = String(url)
			if (asked.includes('/pipelinq/party-kinds')) {
				return Promise.resolve({
					data: {
						source: 'pipelinq',
						available: true,
						kinds: [
							{ code: 'aanvrager', label: 'Aanvrager' },
							{ code: 'gemachtigde', label: 'Gemachtigde' },
						],
					},
				})
			}
			if (asked.includes('/pipelinq/parties/party-sanne/language')) {
				return Promise.resolve({
					data: {
						available: true,
						language: 'nl',
						rule: 'instanceDefault',
						stated: false,
					},
				})
			}
			if (asked.includes('/pipelinq/parties/')) {
				return Promise.resolve({
					data: {
						available: true,
						language: 'en',
						rule: 'party',
						stated: true,
					},
				})
			}
			if (asked.includes('/parties')) {
				return Promise.resolve({
					data: {
						primary: 'party-sanne',
						roles: [{ key: 'aanvrager', label: 'Requester' }],
						kinds: [],
						byRole: {
							aanvrager: [
								{
									partyUuid: 'party-sanne',
									displayName: 'Sanne de Vries',
									partyKind: 'aanvrager',
								},
								{
									partyUuid: 'party-bv',
									displayName: 'Bewonersvereniging',
									partyKind: 'vereniging',
								},
							],
						},
						results: [
							{
								partyUuid: 'party-sanne',
								displayName: 'Sanne de Vries',
								partyKind: 'aanvrager',
							},
							{
								partyUuid: 'party-bv',
								displayName: 'Bewonersvereniging',
								partyKind: 'vereniging',
							},
						],
					},
				})
			}
			return Promise.resolve({ data: { indicators: [] } })
		})
		const wrapper = mount(CasePartiesWidget, {
			props: { objectId: 'case-1' },
			global: {
				stubs: {
					NcEmptyContent: true,
					NcLoadingIcon: true,
					NcNoteCard: { template: '<div><slot /></div>' },
				},
				mocks: { $route: route('case-1') },
			},
		})
		await flushPromises()

		expect(
			wrapper
				.findAll('[data-testid="case-parties-accepted-kind"]')
				.map((el) => el.text()),
		).toEqual(['1. Aanvrager', '2. Gemachtigde'])
		const parties = wrapper.findAll('[data-testid="case-parties-party"]')
		const sanne = parties.find((el) => el.text().includes('Sanne de Vries'))
		const vereniging = parties.find((el) =>
			el.text().includes('Bewonersvereniging'),
		)
		expect(
			sanne.find('[data-testid="case-parties-not-accepted"]').exists(),
		).toBe(false)
		expect(
			vereniging.find('[data-testid="case-parties-not-accepted"]').exists(),
		).toBe(true)
		expect(sanne.find('[data-testid="case-parties-language"]').text()).toBe(
			'Writing language: Dutch. No preference recorded, so the default language of this instance.',
		)
		expect(vereniging.find('[data-testid="case-parties-language"]').text()).toBe(
			'Writing language: English. Asked for by the party.',
		)
	})
})
