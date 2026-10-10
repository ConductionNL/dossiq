// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Related cases card holds cases, and only cases a handler can name.
 *
 * Round 5 read the card on a case and found its case type, its status type,
 * its workflow template and a bare uuid ("8afb946b-…") listed as related
 * cases. All four came from the library widget's Objects group, which lists
 * every object OpenRegister's `/uses` and `/used` answer. The card now turns
 * that group off, adds the parent case under its own name, and leaves out any
 * row whose far case has no readable title.
 *
 * The suite stubs `@conduction/nextcloud-vue`, so the last test reads the
 * library's own source to pin that `showObjects` is what gates the `/uses` and
 * `/used` reads; a stub honouring a prop the library ignored would prove
 * nothing.
 *
 * @spec openspec/changes/r6-dossiq-titles-related-cases-requests/specs/related-case-linking/spec.md
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/** What the object store answers per case id. Replaced per test. */
let cases = {}

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		fetchObject: async (_type, id) => cases[id] ?? null,
	}),
}))

const { default: CasePlannedWidget } =
	await import('../../src/components/case/CasePlannedWidget.vue')
const { relationSections, isBareUuid } =
	await import('../../src/utils/caseRelationHelpers.js')

const here = dirname(fileURLToPath(import.meta.url))
const UUID = '8afb946b-68b4-4618-940e-fecb640fdc41'

/**
 * Mount the card on a case.
 *
 * @param {object} objectData The loaded case.
 * @param {object} router     A router stand-in, or undefined.
 * @return {object} The wrapper.
 */
function mountCard(objectData = null, router = undefined) {
	return mount(CasePlannedWidget, {
		props: { objectId: 'case-1', objectData, register: 'dossiq', schema: 'case' },
		global: {
			mocks: router ? { $router: router } : {},
			stubs: {
				NcButton: { template: '<button><slot /></button>' },
				CalendarClock: true,
				CasePlanFollowUpDialog: true,
			},
		},
	})
}

/**
 * Answer the two reads the card makes.
 *
 * @param {Array} relations Rows from `/api/cases/{id}/relations`.
 * @return {void}
 */
function answer(relations) {
	axios.get.mockImplementation((url) =>
		Promise.resolve({
			data: {
				results: String(url).includes('/relations') ? relations : [],
			},
		}),
	)
}

beforeEach(() => {
	axios.get.mockReset()
	cases = {}
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('Related cases card', () => {
	it('does not list the objects a case uses as related cases', async () => {
		answer([])
		const wrapper = mountCard()
		await flushPromises()

		const widget = wrapper.findComponent({ name: 'CnRelatedObjectsWidget' })
		expect(widget.props('showObjects')).toBe(false)
	})

	it('shows the parent case by its title', async () => {
		answer([])
		cases = { 'parent-1': { id: 'parent-1', title: 'Omgevingsvergunning Kerkstraat' } }
		const wrapper = mountCard({ id: 'case-1', parentCase: 'parent-1' })
		await flushPromises()

		const parent = wrapper
			.findComponent({ name: 'CnRelatedObjectsWidget' })
			.props('extraSections')
			.find((section) => section.key === 'parent')
		expect(parent.label).toBe('Parent case')
		expect(parent.items).toEqual([
			{ id: 'parent-1', label: 'Omgevingsvergunning Kerkstraat' },
		])
	})

	it('leaves out a parent case it cannot name', async () => {
		answer([])
		cases = { 'parent-1': { id: 'parent-1', title: UUID } }
		const wrapper = mountCard({ id: 'case-1', parentCase: 'parent-1' })
		await flushPromises()

		expect(wrapper.text()).not.toContain(UUID)
		expect(wrapper.text()).not.toContain('Parent case')
	})

	it('never shows a related case as its bare uuid', async () => {
		answer([
			{ caseId: 'b', title: 'Bezwaar Dakkapel', displayLabel: 'Follow-up' },
			{ caseId: UUID, title: '', displayLabel: 'Follow-up' },
			{ caseId: 'c', title: UUID, displayLabel: 'Follow-up' },
		])
		const wrapper = mountCard()
		await flushPromises()

		expect(wrapper.text()).toContain('Bezwaar Dakkapel')
		expect(wrapper.text()).not.toContain(UUID)
		expect(wrapper.findAll('li')).toHaveLength(1)
	})

	it('opens the case a row names, and nothing for a planned row', async () => {
		answer([])
		const router = { push: vi.fn() }
		const wrapper = mountCard(null, router)
		await flushPromises()

		const widget = wrapper.findComponent({ name: 'CnRelatedObjectsWidget' })
		widget.vm.$emit('select-extra', {
			section: 'relation-Follow-up',
			item: { id: 'b', label: 'Bezwaar' },
		})
		widget.vm.$emit('select-extra', {
			section: 'planned',
			item: { id: 'flow-1', label: 'Controle' },
		})

		expect(router.push).toHaveBeenCalledTimes(1)
		expect(router.push).toHaveBeenCalledWith({
			name: 'CaseDetail',
			params: { id: 'b' },
		})
	})

	it('relies on a rule the library still has: showObjects gates the /uses and /used reads', () => {
		const source = readFileSync(
			resolve(
				here,
				'../../node_modules/@conduction/nextcloud-vue/src/components/CnRelatedObjectsWidget/CnRelatedObjectsWidget.vue',
			),
			'utf8',
		)
		expect(source).toMatch(
			/const objectSuffixes = this\.showObjects\s*\?\s*\['uses', 'used'/,
		)
	})
})

describe('relationSections', () => {
	it('drops a row with no title or a uuid for a title', () => {
		const sections = relationSections([
			{ caseId: 'b', title: 'Besluit', displayLabel: 'Follow-up' },
			{ caseId: UUID, displayLabel: 'Follow-up' },
			{ caseId: 'c', title: UUID, displayLabel: 'Subject' },
		])
		expect(sections).toEqual([
			{
				key: 'relation-Follow-up',
				label: 'Follow-up',
				icon: 'LinkVariant',
				items: [{ id: 'b', label: 'Besluit' }],
			},
		])
	})

	it('tells a uuid from a name', () => {
		expect(isBareUuid(UUID)).toBe(true)
		expect(isBareUuid(` ${UUID.toUpperCase()} `)).toBe(true)
		expect(isBareUuid('Bezwaar 8afb946b')).toBe(false)
		expect(isBareUuid('')).toBe(false)
	})
})
