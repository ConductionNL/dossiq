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

import { fillTemplate } from '@conduction/nextcloud-vue/src/utils/fillTemplate.js'
import { evaluateVisibleWhenLocal } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it } from 'vitest'
import CaseTypePartyKindsWidget from '../../src/components/caseType/CaseTypePartyKindsWidget.vue'
import LogContactDialog from '../../src/dialogs/LogContactDialog.vue'
import { kindCode, refusalSentence } from '../../src/services/pipelinqCaseApi.js'

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
	const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
	const all = []
	const visit = (widget) => {
		if (widget && typeof widget === 'object') {
			all.push(widget)
			for (const section of widget.content?.sections || []) {
				visit(section.widget)
			}
		}
	}
	caseDetail.config.widgets.forEach(visit)
	const widget = (id) => all.find((entry) => entry.id === id)

	/**
	 * The sentence the library draws for a row: the first declared line whose
	 * condition holds and whose template fills (nextcloud-vue conditionalLine).
	 *
	 * @param {Array<object>} lines The declared lines.
	 * @param {object} row The row.
	 * @return {string} The sentence.
	 */
	const sentence = (lines, row) => {
		for (const line of lines) {
			if (line.when && !evaluateVisibleWhenLocal(line.when, row)) {
				continue
			}
			const text = fillTemplate(line.text, row)
			if (text !== '') {
				return text
			}
		}
		return ''
	}

	/**
	 * The count badges the library draws for a row (record-list `kind: count`).
	 *
	 * @param {object} row The row.
	 * @return {Array<string>} The badge texts.
	 */
	const countBadges = (row) => widget('linked-contact-moments').content.badges
		.filter((badge) => badge.kind === 'count')
		.map((badge) => {
			const raw = row[badge.field]
			const count = Array.isArray(raw) ? raw.length : Number(raw)
			return Number.isFinite(count) && count > 0 ? (count === 1 ? badge.one : badge.other).replace('{count}', String(count)) : ''
		})
		.filter(Boolean)

	it('counts a case the reader may not see, and never names it', () => {
		expect(countBadges({ alsoOnCases: [], alsoOnHiddenCount: 0 })).toEqual([])
		expect(countBadges({ alsoOnCases: ['a', 'b'], alsoOnHiddenCount: 0 })).toEqual(['Also on 2 other cases'])
		expect(countBadges({ alsoOnCases: [], alsoOnHiddenCount: 1 })).toEqual(['Also on 1 case you may not see'])
		expect(countBadges({ alsoOnCases: ['a', 'b'], alsoOnHiddenCount: 1 })).toEqual(['Also on 2 other cases', 'Also on 1 case you may not see'])
	})

	it('says an unset preference as unset, and an absent resolver as unreadable', () => {
		const lines = widget('case-party-roles').content.extraLines[0].lines
		expect(sentence(lines, { available: true, language: 'English', stated: true, rule: 'party' })).toBe('Writing language: English. Asked for by the party.')
		expect(sentence(lines, { available: true, language: 'Dutch', stated: false, rule: 'instanceDefault' })).toBe('Writing language: Dutch. No preference recorded, so the default language of this instance.')
		expect(sentence(lines, { available: false, language: 'Dutch', stated: false, rule: 'unavailable' })).toBe('Writing language: Dutch. No preference can be read on this instance.')
		expect(sentence(lines, {})).toBe('')
	})

	it('names the mode beside a figure, and never draws an uncomputable one as zero', () => {
		const lines = widget('case-programme').content.fields.find((field) => field.as === 'progress').lines
		expect(sentence(lines, { progress: { available: true, computable: true, progress: 40, mode: 'fromTasks' } })).toBe('40 percent done, measured by the closed tasks of the programme.')
		expect(sentence(lines, { progress: { available: true, computable: false, progress: null, mode: 'fromEffort', sentence: 'Nothing has been estimated, so progress cannot be computed.' } })).toBe('Progress cannot be computed: Nothing has been estimated, so progress cannot be computed.')
		expect(sentence(lines, { progress: { available: true, computable: false, progress: null, mode: 'fromTasks', sentence: '' } })).toBe('Progress cannot be computed.')
		expect(sentence(lines, { progress: { available: false } })).toBe('Progress cannot be read on this instance.')
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

	it('puts the customer record on the Communication tab and the programme on the Related tab, as library config', () => {
		// Renamed from case-pipelinq-contact-moments; RenamedLayoutIds rewrites stored layouts.
		expect(json).toContain('"id":"linked-contact-moments","type":"record-list"')
		expect(json).toContain('"id":"case-programme","type":"facts"')
		expect(registrySource).not.toContain("'case-pipelinq-contact-moments': {")
		expect(registrySource).not.toContain("'case-programme': {")
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

		// The ordered list is the library's CnOrderedListField: the declared
		// kinds are its rows in their order, every kind is a choice.
		const list = wrapper.findComponent({ name: 'CnOrderedListField' })
		expect(list.props('modelValue')).toEqual(['gemachtigde', 'aanvrager'])
		expect(list.props('options').map((o) => o.value)).toEqual([
			'aanvrager',
			'gemachtigde',
			'vereniging',
		])
		expect(list.props('disabled')).toBe(false)

		list.vm.$emit('update:modelValue', ['aanvrager', 'gemachtigde'])
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
			wrapper.findComponent({ name: 'CnOrderedListField' }).props('disabled'),
		).toBe(true)
		expect(
			wrapper.find('[data-testid="case-type-party-kinds-save"]').exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="case-type-party-kinds-source"]').text(),
		).toContain('Pipelinq is not installed')
	})
})
