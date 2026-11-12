// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Golden tests for the access-panel reads in src/services/caseAccessApi.js:
 * the status that travels with a read (an answer, a refusal, a non-JSON body,
 * a transport failure), grant and refusal rows with their source, the set as
 * of a moment, and the two reads behind the field rules.
 *
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockGet = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => mockGet(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))

const {
	asOfRows,
	fetchAccessHistory,
	fetchFieldRoleRules,
	fetchObjectPermissions,
	objectPermissionRows,
} = await import('../../src/services/caseAccessApi.js')

const httpError = (status) => Object.assign(new Error('http'), { response: { status } })

describe('the status that travels with a read', () => {
	beforeEach(() => {
		mockGet.mockReset()
	})

	it.each([
		['an answer', () => mockGet.mockResolvedValue({ status: 200, data: { holders: [] } }), { status: 200, set: { holders: [] } }],
		['an answer without a status', () => mockGet.mockResolvedValue({ data: { holders: [] } }), { status: 200, set: { holders: [] } }],
		['an answer without holders', () => mockGet.mockResolvedValue({ status: 200, data: { other: 1 } }), { status: 200, set: null }],
		['a non-JSON body', () => mockGet.mockResolvedValue({ status: 200, data: '<html>' }), { status: 200, set: null }],
		['a null body without a status', () => mockGet.mockResolvedValue({ data: null }), { status: 0, set: null }],
		['no response at all', () => mockGet.mockResolvedValue(undefined), { status: 0, set: null }],
		['a refusal', () => mockGet.mockImplementation(() => Promise.reject(httpError(403))), { status: 403, set: null }],
		['a transport failure', () => mockGet.mockImplementation(() => Promise.reject(new Error('offline'))), { status: 0, set: null }],
	])('reports %s', async (name, arrange, expected) => {
		arrange()
		expect(await fetchObjectPermissions('c-1')).toEqual(expected)
		expect(mockGet).toHaveBeenCalledWith('/apps/openregister/api/objects/dossiq/case/c-1/permissions', { params: {} })
	})

	it('asks the history as of the moment and keeps its status', async () => {
		mockGet.mockResolvedValue({ status: 200, data: { changes: [] } })
		expect(await fetchAccessHistory('c-1', '2026-10-01')).toEqual({ status: 200, history: { changes: [] } })
		expect(mockGet).toHaveBeenCalledWith('/apps/openregister/api/objects/dossiq/case/c-1/permissions/history', { params: { at: '2026-10-01' } })
		mockGet.mockImplementation(() => Promise.reject(httpError(404)))
		expect(await fetchAccessHistory('c-1', '2026-10-01')).toEqual({ status: 404, history: null })
	})
})

describe('grant and refusal rows', () => {
	const set = (denyEnforcement) => ({
		denyEnforcement,
		holders: [{ rules: [{ principal: 'user:anna', action: 'read', level: 'schema', role: 'behandelaar' }, { action: 'update' }] }, null],
		denied: [{ principal: 'group:extern', action: 'read' }],
	})
	const pick = (rows) => rows.map((row) => [row.holder, row.right, row.source, row.detail])

	it('keeps an enforced refusal as a deny, beside the grants', () => {
		expect(pick(objectPermissionRows(set('enforcing')))).toEqual([
			['user:anna', 'read', 'schema', 'behandelaar'],
			['', 'update', 'object', ''],
			['group:extern', 'read', 'deny', ''],
		])
	})

	it('keeps a refusal that is not enforced as a staged deny', () => {
		expect(pick(objectPermissionRows(set('logging'))).map((row) => row[2])).toEqual(['schema', 'object', 'staged-deny'])
		expect(pick(objectPermissionRows({ holders: [] }))).toEqual([])
	})

	it('draws no rows for no set', () => {
		expect(objectPermissionRows(null)).toEqual([])
		expect(objectPermissionRows('x')).toEqual([])
	})
})

describe('asOfRows', () => {
	it('reads an answered moment, taking the moment from the history when the answer has none', () => {
		const rows = asOfRows({ at: '2026-09-01', asOf: { holders: [{ rules: [{ principal: 'user:anna', action: 'read' }] }] } })
		expect(rows.answered).toBe(true)
		expect(rows.at).toBe('2026-09-01')
		expect(rows.setBy).toBe('')
		expect(rows.changedAfterwardsBy).toBe(null)
		expect(rows.rows.map((row) => [row.holder, row.source])).toEqual([['user:anna', 'object']])
		expect(asOfRows({ asOf: { at: '2026-08-01', setBy: 'admin', changedAfterwardsBy: { user: 'bob' } } }))
			.toMatchObject({ answered: true, at: '2026-08-01', rows: [], setBy: 'admin', changedAfterwardsBy: { user: 'bob' } })
		expect(asOfRows({ asOf: {} }).at).toBe('')
	})

	it('reads no moment, or one that is not an object, as unanswered', () => {
		expect(asOfRows(null).answered).toBe(false)
		expect(asOfRows({ asOf: null }).answered).toBe(false)
		expect(asOfRows({ asOf: 'then' }).answered).toBe(false)
		expect(asOfRows({ asOf: 0 }).answered).toBe(false)
	})
})

describe('fetchFieldRoleRules', () => {
	beforeEach(() => {
		mockGet.mockReset()
	})

	const answer = (byPath) => mockGet.mockImplementation((url) => (url in byPath
		? Promise.resolve({ status: 200, data: byPath[url] })
		: Promise.reject(httpError(404))))
	const casePath = '/apps/openregister/api/objects/dossiq/case/c-1'
	const typePath = '/apps/openregister/api/objects/dossiq/caseType/ct-1'

	it('answers null twice without a case id or a readable case', async () => {
		expect(await fetchFieldRoleRules('')).toEqual({ decided: null, declared: null })
		expect(mockGet).not.toHaveBeenCalled()
		answer({})
		expect(await fetchFieldRoleRules('c-1')).toEqual({ decided: null, declared: null })
	})

	it.each([
		['a uuid string', 'ct-1'],
		['an expanded object', { '@self': { id: 'ct-1' } }],
		['an object with an id', { id: 'ct-1' }],
		['an object with a uuid', { uuid: 'ct-1' }],
	])('reads the case type by %s', async (name, caseType) => {
		answer({ [casePath]: { '@self': { fieldRules: { bsn: 'hidden' } }, caseType }, [typePath]: { fieldRoleRules: [{ field: 'bsn' }] } })
		expect(await fetchFieldRoleRules('c-1')).toEqual({ decided: { bsn: 'hidden' }, declared: [{ field: 'bsn' }] })
	})

	it('answers the decision alone without a case type, or when it cannot be read', async () => {
		answer({ [casePath]: { caseType: null } })
		expect(await fetchFieldRoleRules('c-1')).toEqual({ decided: null, declared: null })
		answer({ [casePath]: { caseType: {} } })
		expect(await fetchFieldRoleRules('c-1')).toEqual({ decided: null, declared: null })
		answer({ [casePath]: { '@self': { fieldRules: [] }, caseType: 'ct-1' } })
		expect(await fetchFieldRoleRules('c-1')).toEqual({ decided: [], declared: null })
	})

	it('answers no declared rules for a case type without a list', async () => {
		answer({ [casePath]: { caseType: 'ct-1' }, [typePath]: { fieldRoleRules: 'none' } })
		expect(await fetchFieldRoleRules('c-1')).toEqual({ decided: null, declared: [] })
	})
})
