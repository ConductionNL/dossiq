// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Case and case type validation (draft, superseded, validity window), decision
 * validity, the inspection store (templates, runs, follow-up tasks, photos),
 * the field role rules, and starting a case conversation.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const objectStore = { fetchCollection: vi.fn(), uploadFiles: vi.fn() }
const engineTasks = { create: vi.fn() }
const toasts = []
const bus = []
const location = { caseId: 'c1' }

vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore }))
vi.mock('../../src/store/modules/engineTask.js', () => ({ useEngineTaskStore: () => engineTasks }))
vi.mock('@nextcloud/dialogs', () => ({ showError: (m) => toasts.push(m) }))
vi.mock('@nextcloud/event-bus', () => ({ emit: (name) => bus.push(name) }))
vi.mock('../../src/utils/savedMail.js', () => ({ caseIdFromLocation: () => location.caseId }))

const validation = await import('../../src/utils/caseValidation.js')
const decisions = await import('../../src/utils/decisionHelpers.js')
const { flattenTemplate, useInspectionStore } = await import('../../src/store/modules/inspection.js')
const roles = await import('../../src/utils/fieldRoleRules.js')
const conversation = await import('../../src/utils/caseConversation.js')

beforeEach(() => {
	setActivePinia(createPinia())
	vi.clearAllMocks()
	for (const verb of ['get', 'post']) {
		axios[verb].mockReset()
	}
	toasts.length = 0
	bus.length = 0
	location.caseId = 'c1'
	vi.useFakeTimers({ now: new Date(2026, 4, 10, 12), toFake: ['Date'] })
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.useRealTimers()
	vi.restoreAllMocks()
	vi.unstubAllGlobals()
})

describe('case type usability', () => {
	it('accepts a published, current case type inside its validity window', () => {
		const ct = { validFrom: '2026-01-01', validUntil: '2026-12-31', supersededBy: '' }
		expect(validation.isCaseTypeUsable(ct)).toBe(true)
		expect(validation.getCaseTypeUnusableReason(ct)).toBeNull()
		expect(validation.isCaseTypeUsable({ validUntil: '2026-05-10' })).toBe(true)
	})

	it('refuses a missing, draft, superseded, future or expired case type, and says why', () => {
		const cases = [
			[null, 'Case type not found'],
			[{ isDraft: 'true' }, 'Cannot create a case with a draft case type. The case type must be published first.'],
			[{ supersededBy: { uuid: 'v2' } }, 'This version of the case type has been replaced. Cases already running on it continue. New cases go on the current version.'],
			[{ validFrom: '2026-06-01T00:00:00' }, 'Cannot create a case with a case type that is not yet valid. The case type is valid from 2026-06-01.'],
			[{ validUntil: '2026-05-09T00:00:00' }, 'Cannot create a case with an expired case type. The case type was valid until 2026-05-09.'],
		]
		for (const [ct, reason] of cases) {
			expect(validation.isCaseTypeUsable(ct)).toBe(false)
			expect(validation.getCaseTypeUnusableReason(ct)).toBe(reason)
		}
		expect(validation.isCurrentCaseTypeVersion(null)).toBe(false)
		expect(validation.isCurrentCaseTypeVersion({ supersededBy: '  ' })).toBe(true)
		expect(validation.isCurrentCaseTypeVersion({ supersededBy: 7 })).toBe(true)
	})

	it('validates a new case: a title, a case type, and a usable one', () => {
		expect(validation.validateCaseCreate({ title: ' ' })).toEqual({ valid: false, errors: { title: 'Title is required', caseType: 'Case type is required' } })
		const types = [{ id: 'ok' }, { id: 'draft', isDraft: true }]
		expect(validation.validateCaseCreate({ title: 'T', caseType: { id: 'draft' } }, types).errors.caseType).toContain('draft case type')
		expect(validation.validateCaseCreate({ title: 'T', caseType: 'ok' }, types)).toEqual({ valid: true, errors: {} })
		expect(validation.validateCaseCreate({ title: 'T', caseType: 'unknown' }, types)).toEqual({ valid: true, errors: {} })
	})

	it('validates an update and a status change against the case type statuses', () => {
		expect(validation.validateCaseUpdate({ title: '' }).errors).toEqual({ title: 'Title is required' })
		expect(validation.validateCaseUpdate({ title: 'T' }).valid).toBe(true)
		const statuses = [{ id: 's1' }]
		expect(validation.validateStatusChange(null, {}, statuses)).toEqual({ valid: false, error: 'Target status is required' })
		expect(validation.validateStatusChange({ id: 's2', name: 'Closed' }, {}, statuses)).toEqual({ valid: false, error: "Status 'Closed' is not defined for this case type" })
		expect(validation.validateStatusChange({ id: 's1' }, {}, statuses)).toEqual({ valid: true, error: null })
	})
})

describe('decision validity', () => {
	it('names each validity state by its dates', () => {
		expect(decisions.getDecisionValidity({})).toEqual({ status: 'unknown', label: '', style: '', remaining: null })
		expect(decisions.getDecisionValidity({ effectiveDate: '2026-06-01' })).toMatchObject({ status: 'not_effective', label: 'Not yet effective', style: 'validity--pending' })
		expect(decisions.getDecisionValidity({ expiryDate: '2026-05-01' })).toMatchObject({ status: 'expired', label: 'Expired', remaining: null })
		expect(decisions.getDecisionValidity({ expiryDate: '2026-05-25' })).toMatchObject({ status: 'expiring_soon', label: 'Expires in 15 days', style: 'validity--warning' })
		expect(decisions.getDecisionValidity({ expiryDate: '2026-09-01' })).toMatchObject({ status: 'active', style: 'validity--active' })
		expect(decisions.getDecisionValidity({ expiryDate: '2026-09-01' }).remaining).toMatch(/^Valid until /)
		expect(decisions.getDecisionValidity({ effectiveDate: '2026-01-01' }).remaining).toMatch(/^From /)
		expect(decisions.formatDecisionDate('')).toBe('—')
	})

	it('requires a title and an expiry after the effective date', () => {
		expect(decisions.validateDecision({ title: '', effectiveDate: '2026-05-01', expiryDate: '2026-05-01' }).errors).toEqual({
			title: 'Title is required',
			expiryDate: 'Expiry date must be after effective date',
		})
		expect(decisions.validateDecision({ title: 'B', effectiveDate: '2026-05-01', expiryDate: '2026-05-02' })).toEqual({ valid: true, errors: {} })
	})
})

describe('inspection store', () => {
	it('flattens a template into items with their type and photo rule', () => {
		const template = { id: 't', sections: [{ items: [{ id: 1, responseType: 'yesno', photoRequired: 'if_no' }, { id: 2, type: 'text', photoRequired: 'nooit' }] }, { items: 'x' }, null] }
		expect(flattenTemplate(template).items).toEqual([
			{ id: 1, responseType: 'yesno', type: 'yesno', photoRequired: true },
			{ id: 2, type: 'text', photoRequired: false },
		])
		expect(flattenTemplate(null).items).toEqual([])
	})

	it('fetches the checklists of a case type and keeps only active ones in the getter', async () => {
		objectStore.fetchCollection.mockResolvedValueOnce({ results: [{ status: 'active', sections: [] }, { status: 'retired' }] })
		const store = useInspectionStore()
		expect(await store.fetchChecklists('ct')).toHaveLength(2)
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('inspectionChecklistTemplate', { caseType: 'ct', limit: 100 })
		expect(store.activeChecklists).toHaveLength(1)
		objectStore.fetchCollection.mockRejectedValueOnce(new Error('down'))
		expect(await store.fetchChecklists('ct')).toEqual([])
		expect(store.error).toBe('down')
	})

	it('fetches the reports of a case and counts the non-conform ones', async () => {
		axios.get.mockResolvedValueOnce({ data: [{ result: 'conform' }, { result: 'non_conform' }, { result: 'partly_conform' }] })
		const store = useInspectionStore()
		await store.fetchReports('c 1')
		expect(axios.get.mock.calls[0][0]).toBe('/index.php/apps/dossiq/api/cases/c%201/checklist-results')
		expect([store.completedReportsCount, store.nonConformReports.length]).toEqual([3, 2])
		axios.get.mockResolvedValueOnce({ data: 'x' })
		expect(await store.fetchReports('c')).toEqual([])
		axios.get.mockRejectedValueOnce(new Error('down'))
		expect(await store.fetchReports('c')).toEqual([])
		expect(store.error).toBe('down')
	})

	it('records a run and plans a follow-up for the failed points', async () => {
		axios.post.mockResolvedValueOnce({ data: { id: 'r1', failedItems: 2 } })
		engineTasks.create.mockResolvedValueOnce({ id: 't' })
		const store = useInspectionStore()
		const saved = await store.createReport({ case: 'c1', checklist: 'cl', items: [1], remarks: 'R', location: 'L', inspectionDate: '2026-05-01' })
		expect(saved).toEqual({ id: 'r1', failedItems: 2 })
		expect(axios.post.mock.calls[0]).toEqual(['/index.php/apps/dossiq/api/cases/c1/checklist-results', { checklistId: 'cl', items: [1], remarks: 'R', location: 'L', inspectionDate: '2026-05-01' }])
		expect(engineTasks.create).toHaveBeenCalledWith({
			case: 'c1', status: 'available', relatedObject: 'r1',
			title: 'Opvolging vereist: 2 afwijkingen geconstateerd',
			description: 'Inspectierapport bevat 2 niet-conforme punten. Beoordeel de afwijkingen en plan opvolging.',
		})
		axios.post.mockResolvedValueOnce({ data: { id: 'r2', failedItems: 0 } })
		await store.createReport({ case: 'c1' })
		expect(engineTasks.create).toHaveBeenCalledTimes(1)
		expect(axios.post.mock.calls[1][1]).toMatchObject({ items: [], inspectionDate: new Date(2026, 4, 10, 12).toISOString() })
		expect(store.reports).toHaveLength(2)
	})

	it('shows the server refusal of a run, or the error itself', async () => {
		const store = useInspectionStore()
		axios.post.mockRejectedValueOnce({ response: { data: { message: 'Checklist retired' } } })
		expect(await store.createReport({ case: 'c1' })).toBeNull()
		expect(store.error).toBe('Checklist retired')
		axios.post.mockRejectedValueOnce(new Error('offline'))
		await store.createReport({ case: 'c1' })
		expect([store.error, store.loading]).toEqual(['offline', false])
		engineTasks.create.mockRejectedValueOnce(new Error('x'))
		expect(await store.createFollowUpTask('c1', 1, 'r')).toBeNull()
	})

	it('uploads a photo and answers the file id from any result shape', async () => {
		const store = useInspectionStore()
		objectStore.uploadFiles.mockResolvedValueOnce({ results: [{ id: 'f1' }] })
		expect(await store.uploadPhoto('c1', new Blob(['x']))).toBe('f1')
		expect(objectStore.uploadFiles.mock.calls[0].slice(0, 2)).toEqual(['case', 'c1'])
		objectStore.uploadFiles.mockResolvedValueOnce([{ id: 'f2' }])
		expect(await store.uploadPhoto('c1', new Blob(['x']))).toBe('f2')
		objectStore.uploadFiles.mockResolvedValueOnce({ id: 'f3' })
		expect(await store.uploadPhoto('c1', new Blob(['x']))).toBe('f3')
		objectStore.uploadFiles.mockResolvedValueOnce({})
		expect(await store.uploadPhoto('c1', new Blob(['x']))).toBeNull()
		objectStore.uploadFiles.mockRejectedValueOnce(new Error('x'))
		expect(await store.uploadPhoto('c1', new Blob(['x']))).toBeNull()
	})
})

describe('field role rules', () => {
	it('keeps valid rules with unique trimmed names, and marks the ones that apply to me', () => {
		const declared = [
			{ field: ' bsn ', rule: 'hidden', groups: ['Front', 'Front', ' ', null], heldBy: ['Privacy'], reason: ' AVG ' },
			{ field: 'amount', rule: 'readOnly' },
			{ field: '', rule: 'hidden' },
			{ field: 'x', rule: 'other' },
			null,
		]
		expect(roles.fieldRoleRows(declared, { hidden: ['bsn'] })).toEqual([
			{ field: 'bsn', rule: 'hidden', groups: ['Front'], heldBy: ['Privacy'], reason: 'AVG', appliesToMe: true },
			{ field: 'amount', rule: 'readOnly', groups: [], heldBy: [], reason: '', appliesToMe: false },
		])
		expect(roles.fieldRoleRows('x', {})).toEqual([])
		expect(roles.roleRuleLabels()).toEqual({ hidden: 'Not shown', readOnly: 'Cannot be changed' })
	})

	it('says in a sentence who may not see or change a field', () => {
		expect(roles.roleRuleSentence({ rule: 'hidden', groups: ['A', 'B'], heldBy: ['C'] })).toBe('A, B do not see this field. C do.')
		expect(roles.roleRuleSentence({ rule: 'hidden', groups: ['A'], heldBy: [] })).toBe('A do not see this field.')
		expect(roles.roleRuleSentence({ rule: 'readOnly', groups: ['A'], heldBy: ['C'] })).toBe('A cannot change this field. C can.')
		expect(roles.roleRuleSentence({ rule: 'readOnly', groups: ['A'] })).toBe('A cannot change this field.')
	})
})

describe('case conversation', () => {
	beforeEach(() => {
		vi.stubGlobal('open', vi.fn())
	})

	it('starts a conversation and opens its room, refreshing the page', async () => {
		axios.post.mockResolvedValueOnce({ data: { conversation: { roomUrl: '/call/abc' } } })
		await conversation.startCaseConversation()
		expect(axios.post.mock.calls[0][0]).toBe('/index.php/apps/dossiq/api/cases/c1/conversations')
		expect(window.open).toHaveBeenCalledWith('/call/abc', '_blank', 'noopener')
		expect(bus).toEqual(['cn:page:refresh'])
		axios.post.mockResolvedValueOnce({ data: {} })
		expect(await conversation.startConversation('c2')).toBeNull()
		expect(window.open).toHaveBeenCalledTimes(1)
	})

	it('declares a major incident and opens its channel', async () => {
		axios.post.mockResolvedValueOnce({ data: { channel: { roomUrl: '/call/major' } } })
		await conversation.declareCaseMajor()
		expect(axios.post.mock.calls[0][0]).toBe('/index.php/apps/dossiq/api/cases/c1/major')
		expect(window.open).toHaveBeenCalledWith('/call/major', '_blank', 'noopener')
		axios.post.mockResolvedValueOnce({ data: {} })
		expect(await conversation.declareMajor('c1')).toBe('')
	})

	it('does nothing outside a case, and names unresolved responders or the reason', async () => {
		location.caseId = ''
		await conversation.startCaseConversation()
		await conversation.declareCaseMajor()
		expect(axios.post).not.toHaveBeenCalled()
		location.caseId = 'c1'
		axios.post.mockRejectedValueOnce({ response: { data: { unresolved: ['ann', 'bob'] } } })
		await conversation.startCaseConversation()
		axios.post.mockRejectedValueOnce({ response: { data: { reason: 'Talk is off' } } })
		await conversation.declareCaseMajor()
		axios.post.mockRejectedValueOnce({})
		await conversation.startCaseConversation()
		expect(toasts).toEqual(['We could not find these responders: ann, bob', 'Talk is off', 'Unknown error'])
		expect(bus).toEqual([])
	})
})
