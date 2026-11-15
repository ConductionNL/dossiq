// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The conversions behind the Rules tab, case by case: a stored condition as
 * an editor row and back, the fields a condition can name, and the role
 * names per group a rule's group is labelled with.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockGet = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => mockGet(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))

const { fetchRoleNamesByGroup, fromEditorCondition, ruleFields, toEditorCondition } = await import('../../src/services/caseTypeRules.js')

describe('toEditorCondition', () => {
	it.each([
		[{ kind: 'fieldCompare', field: 'a', op: 'gt', value: 3 }, { field: 'a', operator: 'gt', value: 3 }],
		[{ kind: 'fieldCompare', field: 'a', value: 3 }, { field: 'a', operator: 'eq', value: 3 }],
		[{ kind: 'fieldEquals', field: 'a', value: true }, { field: 'a', operator: 'eq', value: true }],
		[{ kind: 'fieldPresent', field: 'a' }, { field: 'a', operator: 'present' }],
		[{ kind: 'statusIs', status: 's-1' }, { field: 'status', operator: 'eq', value: 's-1' }],
		[{ kind: 'documentPresent', documentType: 'd-1' }, { field: 'document:d-1', operator: 'present' }],
		[{ kind: 'toString' }, { field: '', operator: 'eq', value: '' }],
		[{ kind: 'other' }, { field: '', operator: 'eq', value: '' }],
		[null, { field: '', operator: 'eq', value: '' }],
	])('reads %j as %j', (condition, row) => {
		expect(toEditorCondition(condition)).toEqual(row)
	})
})

describe('fromEditorCondition', () => {
	it.each([
		[null, null],
		[{ field: '' }, null],
		[{ field: 'document:d-1', operator: 'eq' }, { kind: 'documentPresent', documentType: 'd-1' }],
		[{ field: 'status', operator: 'eq', value: 's-1' }, { kind: 'statusIs', status: 's-1' }],
		[{ field: 'status', operator: 'eq' }, { kind: 'statusIs', status: '' }],
		[{ field: 'status', operator: 'present' }, { kind: 'fieldPresent', field: 'status' }],
		[{ field: 'a', operator: 'present' }, { kind: 'fieldPresent', field: 'a' }],
		[{ field: 'a', operator: '', value: 2 }, { kind: 'fieldCompare', field: 'a', op: 'eq', value: 2 }],
		[{ field: 'a', operator: 'lt', value: 2 }, { kind: 'fieldCompare', field: 'a', op: 'lt', value: 2 }],
		[{ field: 7, operator: 'gt', value: 1 }, { kind: 'fieldCompare', field: '7', op: 'gt', value: 1 }],
	])('writes %j as %j', (row, condition) => {
		expect(fromEditorCondition(row)).toEqual(condition)
	})
})

describe('ruleFields', () => {
	it('adds the status only with statuses, and documents only with an id', () => {
		expect(ruleFields()).toEqual([])
		expect(ruleFields({ statusTypes: [] })).toEqual([])
		const fields = ruleFields({ statusTypes: [{ id: 's-1', name: 'Open' }], documentTypes: [{ id: 'd-1', name: 'Plan' }, { name: 'No id' }, null] })
		expect(fields.map((field) => field.id)).toEqual(['status', 'document:d-1'])
	})
})

describe('fetchRoleNamesByGroup', () => {
	beforeEach(() => {
		mockGet.mockReset()
	})

	it('groups the trimmed role names by their group, skipping rows without either', async () => {
		mockGet.mockResolvedValue({
			data: {
				results: [
					{ ncGroupId: 'g-1', name: ' Behandelaar ' },
					{ ncGroupId: 'g-1', name: 'Coordinator' },
					{ ncGroupId: 'g-2', name: '  ' },
					{ ncGroupId: 3, name: 'Number group' },
					{ ncGroupId: 'g-3', name: 4 },
					{ name: 'No group' },
					null,
				],
			},
		})
		expect(await fetchRoleNamesByGroup('ct-1')).toEqual({ 'g-1': ['Behandelaar', 'Coordinator'] })
		expect(mockGet).toHaveBeenCalledWith('/apps/openregister/api/objects/dossiq/roleType', { params: { caseType: 'ct-1', _limit: 100 } })
	})

	it('answers an empty map for an answer without results, and when the read fails', async () => {
		mockGet.mockResolvedValue({ data: { results: 'none' } })
		expect(await fetchRoleNamesByGroup('ct-1')).toEqual({})
		mockGet.mockResolvedValue({})
		expect(await fetchRoleNamesByGroup('ct-1')).toEqual({})
		mockGet.mockRejectedValue(new Error('down'))
		expect(await fetchRoleNamesByGroup('ct-1')).toEqual({})
	})
})
