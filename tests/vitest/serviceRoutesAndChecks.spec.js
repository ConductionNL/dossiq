// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The route contract and error handling of the small service helpers
 * (starter, relations, AI, substitution, bulk jobs, duplicates, terms,
 * intake triage, StUF), the OpenRegister status check and its message, and
 * following a case.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = []
const watcher = { isFollowing: vi.fn(), objectIdOf: vi.fn(), setFollowing: vi.fn() }
const changed = []
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('../../src/services/watcherApi.js', () => watcher)
vi.mock('../../src/utils/casesChanged.js', () => ({ announceCasesChanged: (id) => changed.push(id) }))

const starter = await import('../../src/services/starterApi.js')
const relations = await import('../../src/services/caseRelationApi.js')
const ai = await import('../../src/services/aiApi.js')
const substitution = await import('../../src/services/substitutionApi.js')
const bulk = await import('../../src/services/bulkJobApi.js')
const duplicates = await import('../../src/services/duplicateCheckApi.js')
const terms = await import('../../src/services/caseTermsApi.js')
const triage = await import('../../src/services/intakeTriageApi.js')
const stuf = await import('../../src/services/stufApi.js')
const { checkOpenRegisterStatus, getStatusMessage } = await import('../../src/utils/openregisterCheck.js')
const { toggleCaseFollow } = await import('../../src/utils/caseFollow.js')
const { noticeRowOf } = await import('../../src/utils/noticePayout.js')

const D = '/index.php/apps/dossiq'
const OR = '/index.php/apps/openregister/api/objects/dossiq'
const BODY = { echo: 1 }

/**
 * Make every axios verb answer `data`.
 *
 * @param {*} data The response body.
 */
function answer(data) {
	for (const verb of ['get', 'post', 'put', 'delete']) {
		axios[verb].mockResolvedValue({ data })
	}
}

beforeEach(() => {
	vi.clearAllMocks()
	for (const verb of ['get', 'post', 'put', 'delete']) {
		axios[verb].mockReset()
	}
	toasts.length = 0
	changed.length = 0
	globalThis.OC = { requestToken: 'tok' }
})

afterEach(() => {
	vi.unstubAllGlobals()
})

// [label, call, verb, url, second argument]: each passes the body through.
const ROUTES = [
	['shipped list', () => starter.listShipped('caseType'), 'get', `${D}/api/starter/shipped/caseType`, undefined],
	['adopt shipped', () => starter.adoptShipped('caseType', 'x', BODY), 'post', `${D}/api/starter/shipped/caseType/x/adopt`, BODY],
	['role set', () => starter.readRoleSet(), 'get', `${D}/api/starter/roles`, undefined],
	['adopt roles', () => starter.adoptRoleSet(), 'post', `${D}/api/starter/roles/adopt`, undefined],
	['undo roles', () => starter.undoRoleSet(), 'post', `${D}/api/starter/roles/undo`, undefined],
	['retire case type', () => starter.retireCaseType('ct'), 'post', `${D}/api/starter/case-types/ct/retire`, undefined],
	['restore case type', () => starter.restoreCaseType('ct'), 'post', `${D}/api/starter/case-types/ct/restore`, undefined],
	['copy domain', () => starter.copyDomain('d', 'New'), 'post', `${D}/api/starter/domains/d/copy`, { name: 'New' }],
	['step used by', () => starter.stepUsedBy('s'), 'get', `${D}/api/starter/steps/s/used-by`, undefined],
	['classify', () => ai.classifyDocument('c', 'd'), 'post', `${D}/api/ai/classify`, { caseId: 'c', documentId: 'd' }],
	['extract', () => ai.extractData('c'), 'post', `${D}/api/ai/extract`, { caseId: 'c', documentId: null }],
	['ask', () => ai.askQuestion('c', 'Why?'), 'post', `${D}/api/ai/ask`, { caseId: 'c', question: 'Why?' }],
	['summarize', () => ai.summarize('c'), 'post', `${D}/api/ai/summarize`, { caseId: 'c', type: 'case', documentId: null }],
	['routing', () => ai.suggestRouting('c'), 'post', `${D}/api/ai/suggest-routing`, { caseId: 'c' }],
	['next', () => ai.suggestNext('c'), 'post', `${D}/api/ai/suggest-next`, { caseId: 'c' }],
	['ai audit', () => ai.getAuditLog({ user: 'u' }), 'get', `${D}/api/ai/audit`, { params: { user: 'u' } }],
	['create substitution', () => substitution.createSubstitution(BODY), 'post', `${D}/api/substitutions`, BODY],
	['revoke substitution', () => substitution.revokeSubstitution('a b'), 'post', `${D}/api/substitutions/a%20b/revoke`, undefined],
	['execute reassignment', () => substitution.executeReassignment('ann', 'bob', 'ct'), 'post', `${D}/api/reassignments/execute`, { fromUser: 'ann', toUser: 'bob', caseType: 'ct' }],
	['bulk preview', () => bulk.previewBulkJob({ action: bulk.ACTION_REASSIGN, selection: { ids: [1] } }), 'post', `${D}/api/cases/bulk-jobs`, { action: 'dossiq:reassign-cases', parameters: {}, selection: { ids: [1] }, justification: '' }],
	['refuse case', () => triage.refuseCase('c 1', 'spam'), 'post', `${D}/api/cases/c%201/refuse`, { reason: 'spam' }],
	['sleep triage', () => triage.sleepTriageItem('e', '2026-06-01', 'wait'), 'post', `${D}/api/intake/triage/e/sleep`, { until: '2026-06-01', reason: 'wait' }],
	['fan out', () => triage.fanOutSubmission('ct', BODY, 's1'), 'post', `${D}/api/intake/fan-out`, { caseTypeId: 'ct', submission: BODY, submissionId: 's1' }],
	['stuf endpoints', () => stuf.listEndpoints(), 'get', `${D}/api/stuf/endpoints`, undefined],
	['stuf messages', () => stuf.listMessages({ status: 'x' }), 'get', `${D}/api/stuf/messages`, { params: { status: 'x' } }],
	['stuf outbound', () => stuf.sendVrijBericht('e', 'Bericht', BODY), 'post', `${D}/api/stuf/outbound`, { endpointId: 'e', berichtNaam: 'Bericht', payload: BODY }],
]

describe('route contract', () => {
	it.each(ROUTES)('%s', async (_label, call, verb, url, second) => {
		answer({ reply: 1 })
		expect(await call()).toEqual({ reply: 1 })
		expect(axios[verb]).toHaveBeenCalledTimes(1)
		const args = axios[verb].mock.calls[0]
		expect(args[0]).toBe(url)
		expect(args[1]).toEqual(second)
	})
})

describe('starter, substitution and triage readers', () => {
	it('counts objects from the list total, null when unknown or unreadable', async () => {
		axios.get.mockResolvedValueOnce({ data: { total: '12' } })
		expect(await starter.countObjects('caseType')).toBe(12)
		expect(axios.get.mock.calls[0]).toEqual([`${OR}/caseType`, { params: { _limit: 1 } }])
		axios.get.mockResolvedValueOnce({ data: { total: 'many' } })
		expect(await starter.countObjects('x')).toBeNull()
		axios.get.mockRejectedValueOnce(new Error('x'))
		expect(await starter.countObjects('x')).toBeNull()
		expect(Object.keys(starter.default)).toContain('stepUsedBy')
		expect(Object.keys(stuf.default)).toEqual(['listEndpoints', 'listMessages', 'sendVrijBericht'])
	})

	it('lists substitutions and their actions, empty without results', async () => {
		answer({ results: [1] })
		expect(await substitution.listSubstitutions()).toEqual([1])
		expect(await substitution.fetchSubstitutionActions('s')).toEqual([1])
		expect(axios.get.mock.calls[1][0]).toBe(`${D}/api/substitutions/s/actions`)
		answer(null)
		expect(await substitution.listSubstitutions()).toEqual([])
		expect(await substitution.fetchSubstitutionActions('s')).toEqual([])
	})

	it('previews a reassignment as cases and tasks, empty when the server sends none', async () => {
		answer({ cases: [1], tasks: [2] })
		expect(await substitution.previewReassignment('ann', 'ct')).toEqual({ cases: [1], tasks: [2] })
		expect(axios.post.mock.calls[0]).toEqual([`${D}/api/reassignments/preview`, { fromUser: 'ann', caseType: 'ct' }])
		answer(null)
		expect(await substitution.previewReassignment('ann', 'ct')).toEqual({ cases: [], tasks: [] })
	})

	it('falls back to empty triage answers', async () => {
		answer(null)
		expect(await triage.fetchTriageQueue()).toEqual({ results: [], sleeping: 0 })
		expect(await triage.fanOutSubmission('ct', {}, 's')).toEqual({ created: [], failed: [] })
		expect(await triage.refuseCase('c', 'r')).toEqual({})
		expect(await triage.sleepTriageItem('e', 'u', 'r')).toEqual({})
	})

	it('reads the intake requirements of a case type, marking an unreadable one', async () => {
		const empty = await triage.fetchIntakeRequirements('')
		expect(axios.get).not.toHaveBeenCalled()
		axios.get.mockRejectedValueOnce(new Error('x'))
		const unreadable = await triage.fetchIntakeRequirements('ct 1')
		expect(axios.get.mock.calls[0][0]).toBe(`${D}/api/intake/case-types/ct%201/requirements`)
		expect(unreadable).toEqual({ ...empty, unreadable: true })
	})
})

describe('relations', () => {
	it('lists, adds and removes relations, answering the refusal or an unknown error', async () => {
		answer({ results: [{ id: 'r' }] })
		expect(await relations.fetchRelations('c 1')).toEqual([{ id: 'r' }])
		expect(axios.get.mock.calls[0][0]).toBe(`${D}/api/cases/c%201/relations`)
		answer({})
		expect(await relations.fetchRelations('c')).toEqual([])
		axios.post.mockResolvedValueOnce({ data: { ok: true } })
		expect(await relations.addRelation('c', { targetId: 't', aardRelatie: 'vervolg', toelichting: 'n' })).toEqual({ ok: true })
		expect(axios.post.mock.calls[0][1]).toEqual({ targetId: 't', aardRelatie: 'vervolg', notes: 'n' })
		axios.post.mockRejectedValueOnce({ response: { data: { ok: false, reason: 'self' } } })
		expect(await relations.addRelation('c', {})).toEqual({ ok: false, reason: 'self' })
		axios.post.mockRejectedValueOnce(new Error('x'))
		expect(await relations.addRelation('c', {})).toEqual({ ok: false, reason: 'unknown_error' })
		axios.delete.mockResolvedValueOnce({ data: { ok: true } })
		expect(await relations.removeRelation('c', 't/1', 'a b')).toEqual({ ok: true })
		expect(axios.delete.mock.calls[0][0]).toBe(`${D}/api/cases/c/relations/t%2F1/a%20b`)
		axios.delete.mockRejectedValueOnce({ response: { data: { ok: false, reason: 'gone' } } })
		expect(await relations.removeRelation('c', 't', 'a')).toEqual({ ok: false, reason: 'gone' })
		axios.delete.mockRejectedValueOnce(new Error('x'))
		expect(await relations.removeRelation('c', 't', 'a')).toEqual({ ok: false, reason: 'unknown_error' })
	})
})

describe('bulk jobs', () => {
	it('reads a refusal into reason, message and details', () => {
		expect(bulk.readRefusal({ response: { data: { reason: 'too_many', error: 'Max 500', details: { max: 500 } } } })).toEqual({ reason: 'too_many', message: 'Max 500', details: { max: 500 } })
		expect(bulk.readRefusal(null)).toEqual({ reason: '', message: '', details: {} })
	})

	it('counts the cases a filter matches, zero when it cannot', async () => {
		axios.get.mockResolvedValueOnce({ data: { total: 42 } })
		expect(await bulk.countMatchingCases({ status: 'open' })).toBe(42)
		expect(axios.get.mock.calls[0]).toEqual([`${OR}/case`, { params: { status: 'open', _limit: 1 } }])
		axios.get.mockResolvedValueOnce({ data: {} })
		expect(await bulk.countMatchingCases(null)).toBe(0)
		axios.get.mockRejectedValueOnce(new Error('x'))
		expect(await bulk.countMatchingCases({})).toBe(0)
	})
})

describe('duplicate check', () => {
	it('checks a candidate and normalises the answer', async () => {
		axios.post.mockResolvedValueOnce({ data: { matches: [{ id: 1 }], total: 3, threshold: 0.8 } })
		expect(await duplicates.checkForDuplicates({ title: 'x' })).toEqual({ matches: [{ id: 1 }], total: 3, threshold: 0.8, checked: true })
		expect(axios.post.mock.calls[0][0]).toBe(`${OR}/case/dedup-check`)
		axios.post.mockResolvedValueOnce({ data: { matches: [{ id: 1 }, { id: 2 }] } })
		expect(await duplicates.checkForDuplicates({})).toEqual({ matches: [{ id: 1 }, { id: 2 }], total: 2, threshold: 0, checked: true })
	})

	it('reports nothing checked for no candidate or a failure', async () => {
		const empty = { matches: [], total: 0, threshold: 0, checked: false }
		expect(await duplicates.checkForDuplicates(null)).toEqual(empty)
		axios.post.mockRejectedValueOnce(new Error('x'))
		expect(await duplicates.checkForDuplicates({})).toEqual(empty)
	})

	it('reads the matched cases one by one, dropping the unreadable ones', async () => {
		axios.get.mockImplementation(async (url) => {
			if (url.endsWith('/bad')) {
				throw new Error('x')
			}
			return { data: { id: url.split('/').pop() } }
		})
		expect(await duplicates.fetchMatchedCases(['a', '', 'bad', 'b'])).toEqual([{ id: 'a' }, { id: 'b' }])
		expect(axios.get.mock.calls[0][1]).toEqual({ params: { _extend: 'caseType' } })
		expect(await duplicates.fetchMatchedCases(null)).toEqual([])
	})
})

describe('case terms', () => {
	it('reads terms and the workload age, null for a non-object or a failure', async () => {
		axios.get.mockResolvedValueOnce({ data: { terms: [] } })
		expect(await terms.fetchCaseTerms('c')).toEqual({ terms: [] })
		expect(axios.get.mock.calls[0][0]).toBe(`${D}/api/cases/c/terms`)
		axios.get.mockResolvedValueOnce({ data: 'text' })
		expect(await terms.fetchOpenWorkloadAge()).toBeNull()
		axios.get.mockRejectedValueOnce(new Error('x'))
		expect(await terms.fetchCaseTerms('c')).toBeNull()
	})

	it('requests and receives information, answering ok with the body either way', async () => {
		axios.post.mockResolvedValueOnce({ data: { id: 'r' } })
		expect(await terms.requestInformation('c', { items: ['a'] })).toEqual({ ok: true, body: { id: 'r' } })
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'closed' } } })
		expect(await terms.requestInformation('c', {})).toEqual({ ok: false, body: { error: 'closed' } })
		axios.post.mockResolvedValueOnce({})
		expect(await terms.receiveInformation('c', ['a'])).toEqual({ ok: true, body: {} })
		expect(axios.post.mock.calls[2]).toEqual([`${D}/api/cases/c/information-request/received`, { items: ['a'] }])
		axios.post.mockRejectedValueOnce(new Error('x'))
		expect(await terms.receiveInformation('c', [])).toEqual({ ok: false, body: {} })
	})
})

describe('OpenRegister check', () => {
	it('reports available and configured from the settings', async () => {
		const fetchSpy = vi.fn(async () => ({ ok: true, json: async () => ({ openRegisters: true, config: { register: 'r', case_schema: 's' } }) }))
		vi.stubGlobal('fetch', fetchSpy)
		expect(await checkOpenRegisterStatus()).toEqual({ available: true, configured: true, error: null })
		expect(fetchSpy.mock.calls[0][1].headers.requesttoken).toBe('tok')
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ openRegisters: true, config: { register: 'r' } }) })))
		expect(await checkOpenRegisterStatus()).toEqual({ available: true, configured: false, error: null })
	})

	it('reports an HTTP or network failure', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 503 })))
		expect(await checkOpenRegisterStatus()).toEqual({ available: false, configured: false, error: 'HTTP 503' })
		vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('offline') }))
		expect(await checkOpenRegisterStatus()).toEqual({ available: false, configured: false, error: 'offline' })
	})

	it('explains each status, and says nothing when all is well', () => {
		expect(getStatusMessage({ error: 'HTTP 503' })).toBe('Could not check OpenRegister status: HTTP 503')
		expect(getStatusMessage({ available: false })).toContain('not installed or enabled')
		expect(getStatusMessage({ available: true, configured: false })).toContain('not configured')
		expect(getStatusMessage({ available: true, configured: true })).toBe('')
	})
})

describe('following a case', () => {
	it('follows or unfollows and announces the change', async () => {
		watcher.objectIdOf.mockReturnValue('c1')
		watcher.isFollowing.mockReturnValueOnce(false).mockReturnValueOnce(true)
		await toggleCaseFollow({ item: {} })
		await toggleCaseFollow({ item: {} })
		expect(watcher.setFollowing.mock.calls).toEqual([['c1', true], ['c1', false]])
		expect(toasts).toEqual([['success', 'You follow this case now.'], ['success', 'You no longer follow this case.']])
		expect(changed).toEqual(['c1', 'c1'])
	})

	it('does nothing without a case id, and shows the refusal or a retry hint', async () => {
		watcher.objectIdOf.mockReturnValue('')
		await toggleCaseFollow({ item: {} })
		expect(watcher.setFollowing).not.toHaveBeenCalled()
		watcher.objectIdOf.mockReturnValue('c1')
		watcher.isFollowing.mockReturnValue(false)
		watcher.setFollowing.mockRejectedValueOnce({ response: { data: { message: 'Not allowed' } } })
		await toggleCaseFollow({ item: {} })
		watcher.setFollowing.mockRejectedValueOnce(new Error('x'))
		await toggleCaseFollow({ item: {} })
		expect(toasts).toEqual([['error', 'Not allowed'], ['error', 'This did not work. Try again.']])
		expect(changed).toEqual([])
	})
})

describe('notice payout', () => {
	it('takes the last object argument, or its item', () => {
		expect(noticeRowOf([null, 'x', { id: 1 }, { item: { id: 2 } }])).toEqual({ id: 2 })
		expect(noticeRowOf([{ id: 3 }, 7])).toEqual({ id: 3 })
		expect(noticeRowOf([])).toBeNull()
	})
})
