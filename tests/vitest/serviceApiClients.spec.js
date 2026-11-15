/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The thin API clients in src/services: the url each call reaches, the body it
 * sends, and the shape it answers when the server omits fields.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => (k in params ? encodeURIComponent(params[k]) : m)),
}))

const assistant = await import('../../src/services/assistantApi.js')
const queue = await import('../../src/services/personalQueueApi.js')
const doorlooptijd = await import('../../src/services/doorlooptijdApi.js')
const berichtenbox = await import('../../src/services/berichtenboxApi.js')
const custody = await import('../../src/services/custodyApi.js')
const readState = await import('../../src/services/readStateApi.js')
const pipelinq = await import('../../src/services/pipelinqCaseApi.js')

beforeEach(() => {
	for (const fn of Object.values(http)) {
		fn.mockReset()
	}
})

describe('assistantApi', () => {
	it('is available only when the server says true', async () => {
		http.get.mockResolvedValueOnce({ data: { available: true } })
		await expect(assistant.fetchAssistantAvailability()).resolves.toBe(true)
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/dossiq/api/assistant/availability')

		http.get.mockResolvedValueOnce({ data: { available: 'yes' } })
		await expect(assistant.fetchAssistantAvailability()).resolves.toBe(false)
	})

	it('reads a failing availability call as unavailable', async () => {
		http.get.mockRejectedValueOnce(new Error('503'))
		await expect(assistant.fetchAssistantAvailability()).resolves.toBe(false)
	})

	it('posts the case and message and answers the reply', async () => {
		http.post.mockResolvedValueOnce({ data: { reply: 'hi' } })
		await expect(assistant.converse('c1', 'hello')).resolves.toEqual({ reply: 'hi' })
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/dossiq/api/assistant/converse', { caseId: 'c1', message: 'hello' })
	})

	it('lets a failing conversation reach the caller', async () => {
		http.post.mockRejectedValueOnce(new Error('boom'))
		await expect(assistant.converse('c1', 'x')).rejects.toThrow('boom')
	})
})

describe('personalQueueApi', () => {
	it('saves the grouping and falls back to source', async () => {
		http.post.mockResolvedValueOnce({ data: { groupBy: 'stage' } })
		await expect(queue.saveGrouping('stage')).resolves.toBe('stage')
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/dossiq/api/personal-queue/grouping', { groupBy: 'stage' })

		http.post.mockResolvedValueOnce({ data: {} })
		await expect(queue.saveGrouping('stage')).resolves.toBe('source')
	})

	it('reads and writes a stage under the encoded case id', async () => {
		http.get.mockResolvedValueOnce({ data: { stage: 'later' } })
		await expect(queue.fetchPersonalStage('a/b')).resolves.toBe('later')
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/dossiq/api/personal-queue/stages/a%2Fb')

		http.get.mockResolvedValueOnce({ data: null })
		await expect(queue.fetchPersonalStage('c1')).resolves.toBe('')

		http.post.mockResolvedValueOnce({ data: { stage: 'now' } })
		await expect(queue.savePersonalStage('c1', 'now')).resolves.toBe('now')
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/dossiq/api/personal-queue/stages/c1', { stage: 'now' })

		http.post.mockResolvedValueOnce({ data: {} })
		await expect(queue.savePersonalStage('c1', 'now')).resolves.toBe('')
	})
})

describe('doorlooptijdApi', () => {
	it('sends the defaults and leaves out an empty case type', async () => {
		http.get.mockResolvedValueOnce({ data: { median: 3 } })
		await expect(doorlooptijd.fetchMetrics()).resolves.toEqual({ median: 3 })
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/dossiq/api/doorlooptijd/metrics', { params: { period: '12m', atRiskDays: 5 } })
	})

	it('passes a case type and overrides', async () => {
		http.get.mockResolvedValueOnce({ data: {} })
		await doorlooptijd.fetchMetrics({ caseType: 'vergunning', period: '3m', atRiskDays: 2 })
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/dossiq/api/doorlooptijd/metrics', { params: { period: '3m', atRiskDays: 2, caseType: 'vergunning' } })
	})
})

describe('berichtenboxApi', () => {
	it('sends a message and lists a case\'s messages', async () => {
		http.post.mockResolvedValueOnce({ data: { id: 'm1' } })
		await expect(berichtenbox.sendMessage({ caseId: 'c1' })).resolves.toEqual({ id: 'm1' })
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/dossiq/api/berichtenbox/send', { caseId: 'c1' })

		http.get.mockResolvedValueOnce({ data: [{ id: 'm1' }] })
		await expect(berichtenbox.listMessages('c1')).resolves.toEqual([{ id: 'm1' }])
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/dossiq/api/berichtenbox/messages', { params: { caseId: 'c1' } })
	})
})

describe('custodyApi', () => {
	it('answers an empty chain when the server sends nothing', async () => {
		http.get.mockResolvedValueOnce({ data: null })
		await expect(custody.readChain('c 1')).resolves.toEqual({ holdings: [], open: null, total: 0 })
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/dossiq/api/case/c%201/custody')
	})

	it('reads the holder on a date, null when there is none', async () => {
		http.get.mockResolvedValueOnce({ data: { holding: { holder: 'anna' } } })
		await expect(custody.readHolderOn('c1', '2026-01-01')).resolves.toEqual({ holder: 'anna' })
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/dossiq/api/case/c1/custody/holder', { params: { on: '2026-01-01' } })

		http.get.mockResolvedValueOnce({ data: {} })
		await expect(custody.readHolderOn('c1', '2026-01-01')).resolves.toBeNull()
	})

	it('reads takeovers, empty by default', async () => {
		http.get.mockResolvedValueOnce({ data: {} })
		await expect(custody.readTakeovers('c1')).resolves.toEqual([])
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/dossiq/api/case/c1/takeovers')
	})

	it('asks, accepts and refuses a takeover at its own encoded url', async () => {
		http.post.mockResolvedValue({ data: null })
		await expect(custody.askForCase('c1', 'holiday')).resolves.toEqual({})
		await expect(custody.acceptTakeover('c1', 't/1')).resolves.toEqual({})
		await expect(custody.refuseTakeover('c1', 't1', 'no')).resolves.toEqual({})

		expect(http.post.mock.calls).toEqual([
			['/index.php/apps/dossiq/api/case/c1/takeover', { reason: 'holiday' }],
			['/index.php/apps/dossiq/api/case/c1/takeover/t%2F1/accept', {}],
			['/index.php/apps/dossiq/api/case/c1/takeover/t1/refuse', { reason: 'no' }],
		])
	})
})

describe('readStateApi', () => {
	const URL = '/index.php/apps/openregister/api/objects/dossiq/case/c1/read-state'

	it('normalises a read state with nothing in it', async () => {
		http.get.mockResolvedValueOnce({ data: null })
		await expect(readState.fetchReadState('c1')).resolves.toEqual({ unread: false, lastSeenAt: null, subSeen: {}, unreadCounts: {} })
		expect(http.get).toHaveBeenCalledWith(URL)
	})

	it('reads another register and schema', async () => {
		http.get.mockResolvedValueOnce({ data: { unread: true, lastSeenAt: 't', subSeen: { a: 1 }, unreadCounts: { notes: 2 } } })
		await expect(readState.fetchReadState('x 1', 'reg', 'sch')).resolves.toEqual({ unread: true, lastSeenAt: 't', subSeen: { a: 1 }, unreadCounts: { notes: 2 } })
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/reg/sch/x%201/read-state')
	})

	it('marks read with or without a sub-resource', async () => {
		http.put.mockResolvedValueOnce({ data: { unread: false, notificationsCleared: '3' } })
		await expect(readState.markRead('c1', 'notes')).resolves.toEqual({ unread: false, notificationsCleared: 3 })
		http.put.mockResolvedValueOnce({ data: null })
		await expect(readState.markRead('c1')).resolves.toEqual({ unread: false, notificationsCleared: 0 })

		expect(http.put.mock.calls).toEqual([[URL, { subResource: 'notes' }], [URL, {}]])
	})

	it('marks unread, unread unless the server says false', async () => {
		http.delete.mockResolvedValueOnce({ data: null })
		await expect(readState.markUnread('c1')).resolves.toEqual({ unread: true })
		http.delete.mockResolvedValueOnce({ data: { unread: false } })
		await expect(readState.markUnread('c1')).resolves.toEqual({ unread: false })
		expect(http.delete).toHaveBeenCalledWith(URL)
	})

	it('reads the row marks', () => {
		expect(readState.isUnread({ '@self': { unread: true } })).toBe(true)
		expect(readState.isUnread({ '@self': { unread: 'true' } })).toBe(false)
		expect(readState.isUnread(null)).toBe(false)
		expect(readState.unreadCountsOf({ '@self': { unreadCounts: { notes: 1 } } })).toEqual({ notes: 1 })
		expect(readState.unreadCountsOf({ '@self': { unreadCounts: 4 } })).toBeNull()
		expect(readState.unreadCountsOf({})).toBeNull()
	})
})

describe('pipelinqCaseApi', () => {
	it('reaches each case-scoped endpoint with its body', async () => {
		http.post.mockResolvedValue({ data: { ok: true } })
		await expect(pipelinq.logContactMoment('c1', { channel: 'phone' })).resolves.toEqual({ ok: true })
		await pipelinq.fileContactMoment('c1', 'm/1', 'c2')
		await pipelinq.linkCaseProgramme('c1', 'p1')
		await pipelinq.linkCaseProgramme('c1', 'p2', 'Programme')

		expect(http.post.mock.calls).toEqual([
			['/index.php/apps/dossiq/api/cases/c1/pipelinq/contact-moments', { channel: 'phone' }],
			['/index.php/apps/dossiq/api/cases/c1/pipelinq/contact-moments/m%2F1/file', { targetCaseId: 'c2' }],
			['/index.php/apps/dossiq/api/cases/c1/pipelinq/programme', { programmeId: 'p1', title: '' }],
			['/index.php/apps/dossiq/api/cases/c1/pipelinq/programme', { programmeId: 'p2', title: 'Programme' }],
		])
	})

	it('reads programmes and reads and declares party kinds', async () => {
		http.get.mockResolvedValue({ data: ['x'] })
		http.put.mockResolvedValue({ data: { saved: 2 } })
		await expect(pipelinq.fetchProgrammes()).resolves.toEqual(['x'])
		await expect(pipelinq.fetchCaseTypePartyKinds('t1')).resolves.toEqual(['x'])
		await expect(pipelinq.declareCaseTypePartyKinds('t1', ['a', 'b'])).resolves.toEqual({ saved: 2 })

		expect(http.get.mock.calls).toEqual([
			['/index.php/apps/dossiq/api/pipelinq/programmes'],
			['/index.php/apps/dossiq/api/case-types/t1/pipelinq/party-kinds'],
		])
		expect(http.put).toHaveBeenCalledWith('/index.php/apps/dossiq/api/case-types/t1/pipelinq/party-kinds', { kinds: ['a', 'b'] })
	})

	it('takes the server\'s refusal text, else the fallback', () => {
		expect(pipelinq.refusalOf({ response: { data: { error: 'No access' } } }, 'fb')).toBe('No access')
		expect(pipelinq.refusalOf({ response: { data: { error: '' } } }, 'fb')).toBe('fb')
		expect(pipelinq.refusalOf({ response: { data: { error: 42 } } }, 'fb')).toBe('fb')
		expect(pipelinq.refusalOf(undefined, 'fb')).toBe('fb')
	})

	it('names a kind by code, then key', () => {
		expect(pipelinq.kindCode({ code: 'a', key: 'b' })).toBe('a')
		expect(pipelinq.kindCode({ key: 'b' })).toBe('b')
		expect(pipelinq.kindCode(null)).toBe('')
	})

	it('words a refusal with and without the indicators it names', () => {
		expect(pipelinq.refusalSentence('', [])).toBe('')
		expect(pipelinq.refusalSentence('Refused.', null)).toBe('Refused. The contact moment is kept in dossiq.')
		expect(pipelinq.refusalSentence('Refused.', [{ label: '' }])).toBe('Refused. The contact moment is kept in dossiq.')
		expect(pipelinq.refusalSentence('Refused.', [{ label: 'Geheim' }, { code: 'vip' }, {}])).toBe('Indicator Geheim, vip: Refused. The contact moment is kept in dossiq.')
	})
})
