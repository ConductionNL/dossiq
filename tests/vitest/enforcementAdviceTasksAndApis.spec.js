// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The enforcement and advice stores (intervention lookup, matrix load and
 * save, action and request lifecycles with their follow-up tasks), the task
 * helpers (overdue, due today, sort order), the sub-case and notification
 * routing API helpers, and the bulk job notice.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const objectStore = { fetchCollection: vi.fn(), saveObject: vi.fn() }
const engineTasks = { create: vi.fn() }
const bus = []

vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore }))
vi.mock('../../src/store/modules/engineTask.js', () => ({ useEngineTaskStore: () => engineTasks }))
vi.mock('@nextcloud/event-bus', () => ({ emit: (name, payload) => bus.push([name, payload]) }))

const { useEnforcementStore } = await import('../../src/store/modules/enforcement.js')
const { useAdviceStore } = await import('../../src/store/modules/advice.js')
const tasks = await import('../../src/utils/taskHelpers.js')
const deelzaak = await import('../../src/services/deelzaakApi.js')
const routing = await import('../../src/services/notificationRoutingApi.js')
const { noticeOfJob, raiseJobNotice } = await import('../../src/utils/caseBulkActions.js')

beforeEach(() => {
	setActivePinia(createPinia())
	globalThis.OC = { requestToken: 'tok' }
	bus.length = 0
	vi.clearAllMocks()
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.useRealTimers()
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('enforcement store', () => {
	it('looks up the intervention for a severity and behaviour, null outside the matrix', () => {
		const store = useEnforcementStore()
		expect(store.lookupLhs('ernstig', 'calculerend')).toBe('PV + Bestuursdwang')
		expect(store.lookupLhs('ernstig', 'onbekend')).toBeNull()
		expect(store.lookupLhs('onbekend', 'goedwillend')).toBeNull()
		expect(store.ernstLevels).toEqual(['gering', 'aanzienlijk', 'ernstig'])
		expect(store.gedragLevels).toEqual(['goedwillend', 'onverschillig', 'calculerend', 'crimineel'])
		store.lhsMatrix = {}
		expect(store.gedragLevels).toEqual([])
	})

	it('finds the imposed action and totals the forfeited penalties', () => {
		const store = useEnforcementStore()
		store.actions = [{ status: 'verbeurd', penaltyPaymentAmount: 500 }, { status: 'verbeurd' }, { id: 'a', status: 'opgelegd' }, { status: 'verbeurd', penaltyPaymentAmount: 250 }]
		expect([store.activeAction.id, store.totalVerbeurd]).toEqual(['a', 750])
		store.actions = []
		expect(store.activeAction).toBeNull()
	})

	it('loads the configured matrix once, from text or an object, keeping the default otherwise', async () => {
		const fetchSpy = vi.fn(async () => ({ ok: true, json: async () => ({ config: { lhsMatrix: '{"x":{"y":"Z"}}' } }) }))
		vi.stubGlobal('fetch', fetchSpy)
		const store = useEnforcementStore()
		await store.loadLhsMatrix()
		await store.loadLhsMatrix()
		expect(fetchSpy).toHaveBeenCalledTimes(1)
		expect(fetchSpy.mock.calls[0][1].headers.requesttoken).toBe('tok')
		expect(store.lookupLhs('x', 'y')).toBe('Z')

		setActivePinia(createPinia())
		const other = useEnforcementStore()
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ config: {} }) })))
		await other.loadLhsMatrix()
		expect([other.matrixLoaded, other.lookupLhs('gering', 'goedwillend')]).toEqual([true, 'Waarschuwing'])
	})

	it('keeps the default matrix when the settings cannot be read', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false })))
		const store = useEnforcementStore()
		await store.loadLhsMatrix()
		expect([store.matrixLoaded, store.ernstLevels.length]).toEqual([true, 3])
		setActivePinia(createPinia())
		const broken = useEnforcementStore()
		vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('offline') }))
		await broken.loadLhsMatrix()
		expect([broken.matrixLoaded, broken.ernstLevels.length]).toEqual([true, 3])
	})

	it('saves the matrix as text and adopts it only when the server accepts', async () => {
		const fetchSpy = vi.fn(async () => ({ ok: true }))
		vi.stubGlobal('fetch', fetchSpy)
		const store = useEnforcementStore()
		expect(await store.saveLhsMatrix({ a: { b: 'C' } })).toBe(true)
		expect(fetchSpy.mock.calls[0][1]).toMatchObject({ method: 'PUT', body: JSON.stringify({ lhsMatrix: '{"a":{"b":"C"}}' }) })
		expect(store.lookupLhs('a', 'b')).toBe('C')
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false })))
		expect(await store.saveLhsMatrix({ q: {} })).toBe(false)
		vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('x') }))
		expect(await store.saveLhsMatrix({ q: {} })).toBe(false)
		expect(store.lookupLhs('a', 'b')).toBe('C')
	})

	it('fetches the actions of a case from an envelope or a list, and reports a failure', async () => {
		const store = useEnforcementStore()
		objectStore.fetchCollection.mockResolvedValueOnce({ results: [{ id: 1 }] })
		expect(await store.fetchActions('c1')).toEqual([{ id: 1 }])
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('handhavingsactie', { case: 'c1', limit: 100 })
		objectStore.fetchCollection.mockResolvedValueOnce([{ id: 2 }])
		expect(await store.fetchActions('c1')).toEqual([{ id: 2 }])
		objectStore.fetchCollection.mockRejectedValueOnce(new Error('down'))
		expect(await store.fetchActions('c1')).toEqual([])
		expect([store.error, store.loading]).toEqual(['down', false])
	})

	it('creates an action with the looked-up intervention, imposed by default', async () => {
		objectStore.saveObject.mockImplementation(async (type, data) => ({ id: 'n', ...data }))
		const store = useEnforcementStore()
		const saved = await store.createAction({ severity: 'gering', behaviour: 'crimineel' })
		expect(objectStore.saveObject).toHaveBeenCalledWith('handhavingsactie', { severity: 'gering', behaviour: 'crimineel', intervention: 'PV + Last', status: 'opgelegd' })
		expect(store.actions).toEqual([saved])
		await store.createAction({ severity: 'gering', behaviour: 'crimineel', intervention: 'Own', status: 'concept' })
		expect(objectStore.saveObject.mock.calls[1][1]).toMatchObject({ intervention: 'Own', status: 'concept' })
		objectStore.saveObject.mockRejectedValueOnce(new Error('refused'))
		expect(await store.createAction({})).toBeNull()
		expect(store.error).toBe('refused')
	})

	it('updates the status of a known action in place', async () => {
		const store = useEnforcementStore()
		store.actions = [{ id: 'a', status: 'opgelegd' }, { id: 'b' }]
		objectStore.saveObject.mockImplementationOnce(async (type, data) => ({ ...data, saved: true }))
		expect(await store.updateStatus('a', 'verbeurd')).toEqual({ id: 'a', status: 'verbeurd', saved: true })
		expect(store.actions[0]).toEqual({ id: 'a', status: 'verbeurd', saved: true })
		expect(await store.updateStatus('zz', 'x')).toBeNull()
		objectStore.saveObject.mockRejectedValueOnce(new Error('locked'))
		expect(await store.updateStatus('b', 'x')).toBeNull()
		expect([store.error, store.loading]).toEqual(['locked', false])
	})

	it('creates the recheck task when the compliance period ends', async () => {
		engineTasks.create.mockResolvedValueOnce({ id: 't' })
		const store = useEnforcementStore()
		expect(await store.createBegunstigingTask('c1', { id: 'a', compliance_period: 14, intervention: 'Last' })).toEqual({ id: 't' })
		expect(engineTasks.create).toHaveBeenCalledWith({
			case: 'c1', title: 'Hercontrole uitvoeren', status: 'available', relatedObject: 'a',
			description: 'Begunstigingstermijn van 14 dagen is verlopen. Voer hercontrole uit voor Last.',
		})
		engineTasks.create.mockRejectedValueOnce(new Error('x'))
		expect(await store.createBegunstigingTask('c1', {})).toBeNull()
	})
})

describe('advice store', () => {
	it('groups requests by state and knows when all advice is in', () => {
		vi.useFakeTimers({ now: new Date('2026-05-10T12:00:00Z'), toFake: ['Date'] })
		const store = useAdviceStore()
		expect(store.allAdviceReceived).toBe(false)
		store.requests = [
			{ id: 1, status: 'requested', deadline: '2026-05-01' },
			{ id: 2, status: 'requested', deadline: '2026-06-01' },
			{ id: 3, status: 'requested' },
			{ id: 4, status: 'received' },
		]
		expect(store.pendingRequests.map((r) => r.id)).toEqual([1, 2, 3])
		expect(store.overdueRequests.map((r) => r.id)).toEqual([1])
		expect(store.receivedRequests.map((r) => r.id)).toEqual([4])
		expect(store.allAdviceReceived).toBe(false)
		store.requests = [{ status: 'received' }, { status: 'expired' }]
		expect(store.allAdviceReceived).toBe(true)
		expect(store.getDaysToDeadline({ deadline: '2026-05-13T12:00:00Z' })).toBe(3)
		expect(store.getDaysToDeadline({})).toBeNull()
	})

	it('fetches the requests of a case and reports a failure', async () => {
		const store = useAdviceStore()
		objectStore.fetchCollection.mockResolvedValueOnce(null)
		expect(await store.fetchRequests('c1')).toEqual([])
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('adviesAanvraag', { case: 'c1', limit: 100 })
		objectStore.fetchCollection.mockRejectedValueOnce(new Error('down'))
		await store.fetchRequests('c1')
		expect(store.error).toBe('down')
	})

	it('requests advice, and gives an internal advisor a task', async () => {
		objectStore.saveObject.mockImplementation(async (type, data) => ({ id: 'r', ...data }))
		const store = useAdviceStore()
		await store.createRequest({ case: 'c1', type: 'intern', advisor: 'ann', subject: 'Geluid', questions: 'Q?', deadline: '2026-06-01' })
		expect(objectStore.saveObject.mock.calls[0][1]).toMatchObject({ status: 'requested', advisor: 'ann' })
		expect(objectStore.saveObject.mock.calls[0][1].requestedAt).toMatch(/^\d{4}-/)
		expect(engineTasks.create).toHaveBeenCalledWith({ case: 'c1', title: 'Advies uitbrengen: Geluid', description: 'Q?', assignee: 'ann', status: 'available', dueDate: '2026-06-01' })
		await store.createRequest({ case: 'c1', type: 'extern', advisor: 'org' })
		await store.createRequest({ case: 'c1', type: 'intern' })
		expect(engineTasks.create).toHaveBeenCalledTimes(1)
		expect(store.requests).toHaveLength(3)
		objectStore.saveObject.mockRejectedValueOnce(new Error('refused'))
		expect(await store.createRequest({})).toBeNull()
		expect(store.error).toBe('refused')
	})

	it('marks a request received with its document, keeping the old one when none is given', async () => {
		objectStore.saveObject.mockImplementation(async (type, data) => data)
		const store = useAdviceStore()
		store.requests = [{ id: 'r', status: 'requested', adviceDocument: 'old' }]
		const first = await store.markReceived('r', 'doc')
		expect([first.status, first.adviceDocument]).toEqual(['received', 'doc'])
		const second = await store.markReceived('r')
		expect(second.adviceDocument).toBe('doc')
		expect(await store.markReceived('zz')).toBeNull()
		objectStore.saveObject.mockRejectedValueOnce(new Error('locked'))
		expect(await store.markReceived('r')).toBeNull()
		expect(store.error).toBe('locked')
	})

	it('expires a request and gives the case a task to decide without it', async () => {
		objectStore.saveObject.mockImplementation(async (type, data) => data)
		const store = useAdviceStore()
		store.requests = [{ id: 'r', case: 'c1', status: 'requested', advisor: 'ann' }]
		expect((await store.markExpired('r')).status).toBe('expired')
		expect(engineTasks.create).toHaveBeenCalledWith({
			case: 'c1', title: 'Advies verlopen: ann', status: 'available',
			description: 'Advies van ann is verlopen. Beoordeel of procedure kan doorgaan zonder dit advies.',
		})
		expect(store.requests[0].status).toBe('expired')
		expect(await store.markExpired('zz')).toBeNull()
		objectStore.saveObject.mockRejectedValueOnce(new Error('locked'))
		expect(await store.markExpired('r')).toBeNull()
		expect([store.error, store.loading]).toEqual(['locked', false])
	})
})

describe('task helpers', () => {
	beforeEach(() => {
		vi.useFakeTimers({ now: new Date(2026, 4, 10, 15, 0, 0), toFake: ['Date'] })
	})

	it('calls an open task overdue or due today by its date, never a finished one', () => {
		expect(tasks.isOverdue({ dueDate: new Date(2026, 4, 8).toISOString(), status: 'active' })).toBe(true)
		expect(tasks.isOverdue({ dueDate: new Date(2026, 4, 10, 1).toISOString(), status: 'active' })).toBe(false)
		expect(tasks.isOverdue({ dueDate: new Date(2026, 4, 8).toISOString(), status: 'completed' })).toBe(false)
		expect(tasks.isOverdue({ status: 'active' })).toBe(false)
		expect(tasks.isDueToday({ dueDate: new Date(2026, 4, 10, 9).toISOString(), status: 'available' })).toBe(true)
		expect(tasks.isDueToday({ dueDate: new Date(2026, 4, 11).toISOString(), status: 'available' })).toBe(false)
		expect(tasks.isDueToday({ dueDate: new Date(2026, 4, 10).toISOString(), status: 'terminated' })).toBe(false)
		expect(tasks.isDueToday({})).toBe(false)
	})

	it('says how many days a task is overdue', () => {
		expect(tasks.getOverdueText({ dueDate: new Date(2026, 4, 9).toISOString(), status: 'active' })).toBe('1 day overdue')
		expect(tasks.getOverdueText({ dueDate: new Date(2026, 4, 5).toISOString(), status: 'active' })).toBe('5 days overdue')
		expect(tasks.getOverdueText({ dueDate: new Date(2026, 4, 11).toISOString(), status: 'active' })).toBeNull()
	})

	it('names the priority levels and weighs unknown ones as normal', () => {
		const levels = tasks.getPriorityLevels()
		expect(Object.keys(levels)).toEqual(['urgent', 'high', 'normal', 'low'])
		expect([levels.urgent.cssVar, levels.normal.cssVar]).toEqual(['--color-error', null])
		expect(['urgent', 'low', 'whatever'].map(tasks.prioritySortWeight)).toEqual([1, 4, 3])
		expect(tasks.formatDueDate('')).toBe('—')
		expect(tasks.formatDueDate('2026-05-10')).toBe(new Date('2026-05-10').toLocaleDateString(undefined, { month: 'short', day: 'numeric' }))
	})

	it('sorts by status group, then priority, then due date with undated last', () => {
		const list = [
			{ id: 'done', status: 'completed' },
			{ id: 'low', status: 'active', priority: 'low' },
			{ id: 'undated', status: 'active', priority: 'urgent' },
			{ id: 'later', status: 'active', priority: 'urgent', dueDate: '2026-06-01' },
			{ id: 'sooner', status: 'active', priority: 'urgent', dueDate: '2026-05-20' },
			{ id: 'odd', status: 'weird' },
			{ id: 'avail', status: 'available' },
			{ id: 'undated2', status: 'active', priority: 'urgent' },
		]
		expect(tasks.sortTasks(list).map((x) => x.id)).toEqual(['sooner', 'later', 'undated', 'undated2', 'low', 'avail', 'done', 'odd'])
		expect(list[0].id).toBe('done')
	})
})

describe('sub-case API', () => {
	it('reads children, counts and the parent of a case', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [{ id: 1 }] } })
		expect(await deelzaak.fetchSubCases('a b')).toEqual([{ id: 1 }])
		expect(axios.get.mock.calls[0][0]).toBe('/index.php/apps/dossiq/api/deelzaken/a%20b/children')
		axios.get.mockResolvedValueOnce({ data: {} })
		expect(await deelzaak.fetchSubCases('p')).toEqual([])
		axios.get.mockResolvedValueOnce({ data: { counts: { p: 2 } } })
		expect(await deelzaak.fetchSubCaseCounts(['p', 'q'])).toEqual({ p: 2 })
		expect(axios.get.mock.calls[2][1]).toEqual({ params: { ids: 'p,q' } })
		expect(await deelzaak.fetchSubCaseCounts([])).toEqual({})
		expect(await deelzaak.fetchSubCaseCounts(null)).toEqual({})
		axios.get.mockResolvedValueOnce({ data: { id: 'parent' } })
		expect(await deelzaak.fetchParentCase('c')).toEqual({ id: 'parent' })
	})

	it('treats a missing parent as none, but passes other failures on', async () => {
		axios.get.mockRejectedValueOnce({ response: { status: 404 } })
		expect(await deelzaak.fetchParentCase('c')).toBeNull()
		axios.get.mockRejectedValueOnce({ response: { status: 500 } })
		await expect(deelzaak.fetchParentCase('c')).rejects.toEqual({ response: { status: 500 } })
	})

	it('validates and creates a sub-case, answering the server refusal or an unknown error', async () => {
		axios.post.mockResolvedValueOnce({ data: { ok: true } })
		expect(await deelzaak.validateSubCase({ parentCaseUuid: 'p', childCaseTypeId: 'ct' })).toEqual({ ok: true })
		expect(axios.post.mock.calls[0]).toEqual(['/index.php/apps/dossiq/api/deelzaken/validate', { parentCaseUuid: 'p', childCaseTypeId: 'ct' }])
		axios.post.mockRejectedValueOnce({ response: { data: { ok: false, reason: 'depth' } } })
		expect(await deelzaak.validateSubCase({})).toEqual({ ok: false, reason: 'depth' })
		axios.post.mockRejectedValueOnce(new Error('x'))
		expect(await deelzaak.validateSubCase({})).toEqual({ ok: false, reason: 'unknown_error' })
		axios.post.mockResolvedValueOnce({ data: { ok: true, uuid: 'n' } })
		expect(await deelzaak.createSubCase({ parentCaseUuid: 'p', childCaseTypeId: 'ct', object: { a: 1 } })).toEqual({ ok: true, uuid: 'n' })
		expect(axios.post.mock.calls[3]).toEqual(['/index.php/apps/dossiq/api/deelzaken', { parentCaseUuid: 'p', childCaseTypeId: 'ct', object: { a: 1 } }])
		axios.post.mockRejectedValueOnce({ response: { data: { ok: false, reason: 'type' } } })
		expect(await deelzaak.createSubCase({})).toEqual({ ok: false, reason: 'type' })
		axios.post.mockRejectedValueOnce(new Error('x'))
		expect(await deelzaak.createSubCase({})).toEqual({ ok: false, reason: 'unknown_error' })
	})

	it('unlinks children and normalises the summary', async () => {
		axios.post.mockResolvedValueOnce({ data: { unlinked: 2, total: 3, complete: true } })
		expect(await deelzaak.unlinkSubCases('p')).toEqual({ unlinked: 2, failed: 0, total: 3, complete: true })
		axios.post.mockResolvedValueOnce({ data: null })
		expect(await deelzaak.unlinkSubCases('p')).toEqual({ unlinked: 0, failed: 0, total: 0, complete: false })
	})
})

describe('notification routing API', () => {
	const url = '/index.php/apps/openregister/api/notification-preferences'

	it('reads only the preferences of dossiq schemas, per scope', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [{ schema: 'case' }, { schema: 'invoice' }, null, { schema: 'queueMention' }] } })
		expect(await routing.fetchPreferences('team')).toEqual([{ schema: 'case' }, { schema: 'queueMention' }])
		expect(axios.get.mock.calls[0]).toEqual([url, { params: { scope: 'team' } }])
		axios.get.mockResolvedValueOnce({ data: {} })
		expect(await routing.fetchPreferences()).toEqual([])
		expect(axios.get.mock.calls[1][1]).toEqual({ params: {} })
	})

	it('saves, clears and defaults preferences with only the given optional fields', async () => {
		axios.put.mockResolvedValue({ data: { ok: true } })
		await routing.savePreference({ schema: 'case', notification: 'assigned', enabled: false })
		await routing.savePreference({ schema: 'case', notification: 'assigned', enabled: true, channels: ['mail'], scope: 'me' })
		await routing.clearPreference({ schema: 'case', notification: 'assigned' })
		await routing.clearPreference({ schema: 'case', notification: 'assigned', scope: 'me' })
		await routing.saveGroupDefault({ group: 'g', schema: 'case', notification: 'n', enabled: true })
		await routing.saveGroupDefault({ group: 'g', schema: 'case', notification: 'n', enabled: true, scope: 's' })
		expect(axios.put.mock.calls.map((c) => c[1])).toEqual([
			{ schema: 'case', notification: 'assigned', enabled: false },
			{ schema: 'case', notification: 'assigned', enabled: true, channels: ['mail'], scope: 'me' },
			{ schema: 'case', notification: 'assigned', reset: true },
			{ schema: 'case', notification: 'assigned', reset: true, scope: 'me' },
			{ group: 'g', schema: 'case', notification: 'n', enabled: true },
			{ group: 'g', schema: 'case', notification: 'n', enabled: true, scope: 's' },
		])
		expect(axios.put.mock.calls[4][0]).toBe('/index.php/apps/openregister/api/notification-group-preferences')
	})
})

describe('bulk job notice', () => {
	it('reads the notice from the job or its outcome, ignoring a non-object', () => {
		expect(noticeOfJob({ notice: { text: 'a' } })).toEqual({ text: 'a' })
		expect(noticeOfJob({ outcome: { notice: { text: 'b' } } })).toEqual({ text: 'b' })
		expect(noticeOfJob({ notice: ['x'] })).toBeNull()
		expect(noticeOfJob({ notice: 'text' })).toBeNull()
		expect(noticeOfJob(null)).toBeNull()
	})

	it('raises the notice on the page only when there is one', () => {
		raiseJobNotice({ notice: { text: 'a' } })
		raiseJobNotice({})
		expect(bus).toEqual([['cn:page:notice', { notice: { text: 'a' } }]])
	})
})
