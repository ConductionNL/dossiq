// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for GatherDocumentsDialog's `searchOne` and
 * `loadPlan` (src/dialogs/GatherDocumentsDialog.vue): the request each source
 * kind makes, the group it answers, and how the recorded plan prefills the
 * search.
 *
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import GatherDocumentsDialog from '../../src/dialogs/GatherDocumentsDialog.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

const UUID_A = '11111111-1111-4111-8111-111111111111'

/**
 * A stand-in for the dialog instance, carrying every method.
 *
 * @param {object} state Fields to set.
 * @return {object} The instance.
 */
function instance(state = {}) {
	return {
		...GatherDocumentsDialog.methods,
		targetCaseId: 'case 1',
		searchedTerms: 'weg',
		from: '',
		to: '',
		terms: '',
		custodians: null,
		custodian: null,
		planLoaded: false,
		recordQuery: vi.fn().mockResolvedValue(undefined),
		...state,
	}
}

const INTEGRIQ = { id: 'm365', label: 'M365', kind: 'integriq' }

/**
 * A platform source.
 *
 * @param {object} search The search block.
 * @return {object} The source.
 */
function platform(search) {
	return { id: 'cases', label: 'Cases', kind: 'platform', search }
}

describe('gather documents search golden', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('searches integriq through dossiq and keeps its rows, remainder and notices', async () => {
		axios.post.mockResolvedValue({ data: { rows: [{ key: 'r' }], remaining: '4', notices: ['n'] } })
		const vm = instance({ from: '2025-01-01', to: '2025-12-31' })
		const group = await vm.searchOne(INTEGRIQ)
		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/dossiq/api/cases/case%201/woo/sources/search',
			{ source: 'm365', terms: 'weg', from: '2025-01-01', to: '2025-12-31' },
		)
		expect(group).toEqual({ id: 'm365', label: 'M365', rows: [{ key: 'r' }], remaining: 4, notices: ['n'], error: '' })
		expect(vm.recordQuery).not.toHaveBeenCalled()
	})

	it('reads an integriq answer without lists as empty', async () => {
		axios.post.mockResolvedValue({ data: { rows: 'x', notices: null } })
		const group = await instance().searchOne(INTEGRIQ)
		expect(group).toEqual({ id: 'm365', label: 'M365', rows: [], remaining: 0, notices: [], error: '' })
	})

	it.each([
		[{ response: { data: { message: 'Not connected.' } } }, 'Not connected.'],
		[new Error('net'), 'This source could not be searched.'],
	])('keeps an integriq failure as the group error (%j)', async (failure, sentence) => {
		axios.post.mockRejectedValue(failure)
		const group = await instance().searchOne(INTEGRIQ)
		expect(group).toEqual({ id: 'm365', label: 'M365', rows: [], remaining: 0, notices: [], error: sentence })
	})

	it('searches a platform source through unified search with its filters and period', async () => {
		axios.get.mockResolvedValue({
			data: {
				ocs: {
					data: {
						entries: [
							{ title: 'Doc', subline: 'sub', resourceUrl: `/x/${UUID_A}` },
							{ title: 'No link', resourceUrl: '/x/none' },
						],
					},
				},
			},
		})
		const vm = instance({ from: '2025-01-01', to: '2025-12-31' })
		const source = platform({ provider: 'openregister objects', filters: { register: 'r', limit: 9 } })
		const group = await vm.searchOne(source)
		expect(axios.get).toHaveBeenCalledWith(
			'/ocs/v2.phpsearch/providers/openregister%20objects/search',
			{ params: { term: 'weg', limit: 9, register: 'r', since: '2025-01-01', until: '2025-12-31' } },
		)
		expect(group).toEqual({
			id: 'cases',
			label: 'Cases',
			rows: [{ key: UUID_A, name: 'Doc', location: 'sub', date: '', snippet: 'sub' }],
			remaining: 0,
			notices: [],
			error: '',
		})
		expect(vm.recordQuery).toHaveBeenCalledWith(source, group.rows)
	})

	it('leaves the period out when none is set, and reads a source without search as no provider', async () => {
		axios.get.mockResolvedValue({ data: {} })
		const vm = instance()
		const group = await vm.searchOne(platform(null))
		expect(axios.get).toHaveBeenCalledWith(
			'/ocs/v2.phpsearch/providers//search',
			{ params: { term: 'weg', limit: 50 } },
		)
		expect(group.rows).toEqual([])
		expect(vm.recordQuery).toHaveBeenCalledWith(platform(null), [])
	})

	it('keeps a platform failure as the group error', async () => {
		axios.get.mockRejectedValue({ response: { data: { message: 'Provider gone.' } } })
		const vm = instance()
		const group = await vm.searchOne(platform({ provider: 'files' }))
		expect(group.error).toBe('Provider gone.')
		expect(vm.recordQuery).not.toHaveBeenCalled()
	})

	it('prefills the search from a recorded plan, keeping terms and period already typed', async () => {
		axios.get.mockResolvedValue({
			data: {
				recorded: true,
				plan: {
					custodians: [{ name: 'A' }, { name: '' }, null, { name: 'B' }],
					terms: 'Stationsweg',
					periodFrom: '2025-01-01',
					periodTo: 7,
				},
			},
		})
		const vm = instance({ terms: 'typed' })
		await vm.loadPlan()
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/dossiq/api/cases/case%201/woo/plan')
		expect(vm.custodians).toEqual(['A', 'B'])
		expect(vm.custodian).toBe('A')
		expect(vm.terms).toBe('typed')
		expect(vm.from).toBe('2025-01-01')
		expect(vm.to).toBe('7')
		expect(vm.planLoaded).toBe(true)
	})

	it.each([
		[{ recorded: false, plan: { custodians: [{ name: 'A' }], terms: 'x' } }],
		[null],
		[{ recorded: true, plan: {} }],
	])('reads no custodians and no prefill without a recorded plan (%j)', async (data) => {
		axios.get.mockResolvedValue({ data })
		const vm = instance({ from: 'kept' })
		await vm.loadPlan()
		expect(vm.custodians).toEqual([])
		expect(vm.custodian).toBe('')
		expect(vm.terms).toBe('')
		expect(vm.from).toBe('kept')
		expect(vm.to).toBe('')
		expect(vm.planLoaded).toBe(true)
	})

	it('reads no custodians when the plan does not load', async () => {
		axios.get.mockRejectedValue(new Error('net'))
		const vm = instance()
		await vm.loadPlan()
		expect(vm.custodians).toEqual([])
		expect(vm.custodian).toBeNull()
		expect(vm.planLoaded).toBe(true)
	})
})
