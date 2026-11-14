// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The branches of four service modules the other specs leave open.
 *
 *  - caseAccessApi: the catalogue, role and deny reads hit their own paths
 *    with the case register, and a scopes answer without the case row is
 *    "could not be read" (null), never the first row.
 *  - schemaRules: a vocabulary of the wrong shape is the empty sets, a trial
 *    posts the register and object to the rule's own path, and a refused
 *    schema read names no properties.
 *  - caseTypeRules: a blueprint without both lists is unreadable, every action
 *    kind has its sentence, a rule on creation says so, and a new rule id
 *    skips one already taken.
 *  - formatters: the delegating column formatters hand their value to the
 *    right helper, the confidentiality labels, and one batched sub-case count
 *    for rows rendered in the same tick.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockGet = vi.fn()
const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))

const deelzaakStore = { subCaseCounts: {}, fetchSubCaseCounts: vi.fn() }
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => deelzaakStore }))

const {
	CASE_REGISTER,
	CASE_SCHEMA,
	fetchCallerScope,
	fetchDenyRules,
	fetchPermissionCatalogue,
	fetchRoleGrants,
} = await import('../../src/services/caseAccessApi.js')
const { evaluateRule, fetchRuleVocabulary, fetchSchemaProperties } = await import('../../src/services/schemaRules.js')
const { actionSentence, caseTypeRuleRow, fetchBlueprintCatalogue, newRuleId } = await import('../../src/services/caseTypeRules.js')
const { default: formatters } = await import('../../src/services/formatters.js')
const { approvalMarkerLabel } = await import('../../src/services/approvalMarker.js')
const { caseAnswerText } = await import('../../src/services/caseAnswer.js')
const { coveredForLabel } = await import('../../src/services/coveredFor.js')
const { routingNoteLabel } = await import('../../src/services/routingNote.js')
const { scanVerdictLabel } = await import('../../src/services/scanVerdict.js')

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

beforeEach(() => {
	mockGet.mockReset()
	mockPost.mockReset()
	deelzaakStore.subCaseCounts = {}
	deelzaakStore.fetchSubCaseCounts.mockReset()
})

describe('caseAccessApi reads', () => {
	it('reads the permission catalogue from its own path and hands the body on', async () => {
		const body = { permissions: [{ verb: 'read' }], denyEnforcement: 'enforce' }
		mockGet.mockResolvedValueOnce({ status: 200, data: body })

		expect(await fetchPermissionCatalogue()).toEqual(body)
		expect(mockGet).toHaveBeenCalledWith('/apps/openregister/api/permissions', { params: {} })
	})

	it('reads the role grants for the case register', async () => {
		const body = { register: CASE_REGISTER, roles: [] }
		mockGet.mockResolvedValueOnce({ status: 200, data: body })

		expect(await fetchRoleGrants()).toEqual(body)
		expect(mockGet).toHaveBeenCalledWith(
			'/apps/openregister/api/permissions/compare-roles',
			{ params: { register: CASE_REGISTER } },
		)
	})

	it('reads the deny rules for the case register and schema, and null when that read fails', async () => {
		const body = { denyEnforcement: 'log', enforcing: false, ruleCount: 0, rules: [] }
		mockGet.mockResolvedValueOnce({ status: 200, data: body })
		mockGet.mockRejectedValueOnce(Object.assign(new Error('http'), { response: { status: 403 } }))

		expect(await fetchDenyRules()).toEqual(body)
		expect(mockGet).toHaveBeenCalledWith(
			'/apps/openregister/api/permissions/deny-preview',
			{ params: { register: CASE_REGISTER, schema: CASE_SCHEMA } },
		)
		expect(await fetchDenyRules()).toBeNull()
	})

	it('answers null when the scopes carry no case row, rather than another schema\'s row', async () => {
		mockGet.mockResolvedValueOnce({
			status: 200,
			data: { user: 'henk', isAdmin: false, groups: [], scopes: [{ schema: 'something-else', verbs: ['read'] }] },
		})

		expect(await fetchCallerScope()).toBeNull()
	})

	it('answers null when the scopes read fails or carries no scopes list', async () => {
		mockGet.mockRejectedValueOnce(new Error('openregister is down'))
		mockGet.mockResolvedValueOnce({ status: 200, data: { user: 'henk' } })

		expect(await fetchCallerScope()).toBeNull()
		expect(await fetchCallerScope()).toBeNull()
	})
})

describe('schemaRules reads and trials', () => {
	it('answers the empty sets for a vocabulary of the wrong shape', async () => {
		mockGet.mockResolvedValueOnce({ data: { kinds: [{ kind: 'x' }] } })

		expect(await fetchRuleVocabulary()).toEqual({ kinds: [], verdicts: [], actions: [] })
	})

	it('answers the empty sets when the vocabulary read is refused', async () => {
		mockGet.mockRejectedValueOnce(new Error('404'))

		expect(await fetchRuleVocabulary()).toEqual({ kinds: [], verdicts: [], actions: [] })
	})

	it('posts the register and object to the rule\'s own evaluate path', async () => {
		const result = { trace: { verdict: 'pass' } }
		mockPost.mockResolvedValueOnce({ data: result })

		expect(await evaluateRule('case', 'rule:1', 'dossiq', 'obj-1')).toEqual(result)
		expect(mockPost).toHaveBeenCalledWith(
			'/apps/openregister/api/schemas/case/rules/rule%3A1/evaluate',
			{ register: 'dossiq', objectId: 'obj-1' },
		)
	})

	it('answers an empty trial result when the trial returns no body', async () => {
		mockPost.mockResolvedValueOnce({ data: null })

		expect(await evaluateRule('case', 'r-1', 'dossiq', 'obj-1')).toEqual({})
	})

	it('names no properties when the schema read is refused', async () => {
		mockGet.mockRejectedValueOnce(new Error('forbidden'))

		expect(await fetchSchemaProperties('case')).toEqual({})
	})
})

describe('caseTypeRules', () => {
	it('answers null for a blueprint that lacks one of its two lists', async () => {
		mockGet.mockResolvedValueOnce({ data: { propertyDefinitions: [] } })

		expect(await fetchBlueprintCatalogue('ct-1')).toBeNull()
		expect(mockGet).toHaveBeenCalledWith('/apps/dossiq/api/case-types/ct-1/blueprint')
	})

	it('answers null when the blueprint read fails', async () => {
		mockGet.mockRejectedValueOnce(new Error('500'))

		expect(await fetchBlueprintCatalogue('ct 1')).toBeNull()
		expect(mockGet).toHaveBeenCalledWith('/apps/dossiq/api/case-types/ct%201/blueprint')
	})

	it('says what a required document and a set field do, by name when one is known', () => {
		const names = { documentTypes: { 'dt-1': 'Bouwtekening' }, fields: { fee: 'Leges' } }

		expect(actionSentence({ action: 'requireDocument', documentType: 'dt-1' }, names)).toBe('document Bouwtekening is required')
		expect(actionSentence({ action: 'requireDocument', documentType: 'dt-2' }, names)).toBe('document dt-2 is required')
		expect(actionSentence({ action: 'setField', field: 'fee', value: 120 }, names)).toBe('Leges becomes 120')
		expect(actionSentence({ action: 'setField', field: 'note' }, names)).toBe('note becomes ')
	})

	it('names an unknown action by its own kind, and nothing for no action', () => {
		expect(actionSentence({ action: 'archiveCase' })).toBe('archiveCase')
		expect(actionSentence(null)).toBe('')
	})

	it('opens a rule on creation with "a case is created"', () => {
		const row = caseTypeRuleRow({ id: 'r-1', when: { event: 'created' }, actions: [{ action: 'archiveCase' }] }, [])

		expect(row).toEqual({ id: 'r-1', title: 'If a case is created', line: 'then archiveCase', pill: 'on' })
	})

	it('skips ids already taken when minting a new rule id', () => {
		expect(newRuleId([{ id: 'rule-3' }, { id: 'rule-4' }, { id: 'x' }])).toBe('rule-5')
		expect(newRuleId([{ id: 'rule-2' }])).toBe('rule-3')
		expect(newRuleId()).toBe('rule-1')
	})
})

describe('formatters', () => {
	it('hand each delegating column to its helper', () => {
		const coveredFor = { name: 'Fatima' }
		const note = { kind: 'handover-refused', team: 'Vergunningen', reason: 'vol' }
		const answers = [{ name: 'parking', value: 'Ja' }]
		const marker = { routed: true, cleared: true }

		expect(formatters.coveredFor(coveredFor)).toBe(coveredForLabel(coveredFor))
		expect(formatters.coveredFor(coveredFor)).toBe('For Fatima')
		expect(formatters.routingNote(note)).toBe(routingNoteLabel(note))
		expect(formatters.routingNote(note)).toBe('Assigning to team Vergunningen refused: vol')
		expect(formatters.fieldTitle('ignored', { name: 'fee', title: 'Leges' })).toBe('Leges')
		expect(formatters.caseAnswer(answers, {}, {}, { name: 'parking' })).toBe(caseAnswerText(answers, { name: 'parking' }))
		expect(formatters.caseAnswer(answers, {}, {}, { name: 'parking' })).toBe('Ja')
		expect(formatters.scanVerdict('infected')).toBe(scanVerdictLabel('infected'))
		expect(formatters.approvalChain(marker)).toBe(approvalMarkerLabel(marker))
		expect(formatters.approvalChain(marker)).toBe('Approved')
	})

	it('shows a case address once its lookup lands', async () => {
		mockGet.mockResolvedValueOnce({ data: { results: [{ label: 'Markt 1, Utrecht' }] } })

		expect(formatters.caseAddress('case-addr-1')).toBe('')
		await flush()

		expect(formatters.caseAddress('case-addr-1')).toBe('Markt 1, Utrecht')
		expect(mockGet).toHaveBeenCalledTimes(1)
	})

	it('labels every confidentiality level, and nothing for an unknown one', () => {
		expect(formatters.confidentialityLabel('openbaar')).toBe('Public')
		expect(formatters.confidentialityLabel('beperkt_openbaar')).toBe('Restricted public')
		expect(formatters.confidentialityLabel('intern')).toBe('Internal')
		expect(formatters.confidentialityLabel('zaakvertrouwelijk')).toBe('Case confidential')
		expect(formatters.confidentialityLabel('vertrouwelijk')).toBe('Confidential')
		expect(formatters.confidentialityLabel('confidentieel')).toBe('Classified confidential')
		expect(formatters.confidentialityLabel('geheim')).toBe('Secret')
		expect(formatters.confidentialityLabel('zeer_geheim')).toBe('Top secret')
		expect(formatters.confidentialityLabel('topgeheim')).toBe('')
		expect(formatters.confidentialityLabel(null)).toBe('')
	})

	it('counts the sub-cases of rows rendered in one tick in a single batch', async () => {
		deelzaakStore.fetchSubCaseCounts.mockResolvedValue(undefined)

		expect(formatters.subCaseCount(undefined, { id: 'p-1' })).toBe('')
		expect(formatters.subCaseCount(undefined, { id: 'p-2' })).toBe('')
		await flush()

		expect(deelzaakStore.fetchSubCaseCounts).toHaveBeenCalledTimes(1)
		expect(deelzaakStore.fetchSubCaseCounts).toHaveBeenCalledWith(['p-1', 'p-2'])
	})
})
