/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The workflow store's action dispatchers, each run for real: the e-mail
 * action posts its interpolated subject and template, the webhook posts the
 * case and transition summary, the sub-case and set-field actions save
 * through the object store, the notify action posts an OCS notification,
 * and a refused request throws so the transition reports the failed action.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const objects = vi.hoisted(() => ({ saveObject: vi.fn() }))
vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objects }))
vi.mock('../../src/store/modules/engineTask.js', () => ({ useEngineTaskStore: () => ({ create: vi.fn(), error: null }) }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

const { useWorkflowStore } = await import('../../src/store/modules/workflow.js')

const caseData = { id: 'case-1', title: 'Dakkapel Dorpsstraat 4', status: 'intake', priority: 'high', reference: 'Z-2026-9' }
const transition = { label: 'Accept', fromStatus: 'intake', toStatus: 'in-progress', actor: 'jan' }

let store
let fetchMock

beforeEach(() => {
	setActivePinia(createPinia())
	store = useWorkflowStore()
	objects.saveObject.mockReset()
	fetchMock = vi.fn(async () => ({ ok: true, status: 200, statusText: 'OK' }))
	vi.stubGlobal('fetch', fetchMock)
	vi.stubGlobal('OC', { requestToken: 'token-1' })
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.useRealTimers()
})

describe('workflow action dispatchers', () => {
	it('posts an e-mail with the subject and template filled from the case and transition', async () => {
		await store.dispatchEmailAction(
			{ recipient: 'aanvrager@example.org', subject: 'Zaak {{case.reference}}', template: '{{transition.label}} door {{transition.actor}}, {{case.missing}}' },
			caseData,
			transition,
		)

		const [url, init] = fetchMock.mock.calls[0]
		expect(url).toBe('/apps/dossiq/api/workflow/actions/email')
		expect(init.method).toBe('POST')
		expect(init.headers).toEqual({ 'Content-Type': 'application/json', requesttoken: 'token-1', 'OCS-APIREQUEST': 'true' })
		expect(JSON.parse(init.body)).toEqual({
			recipient: 'aanvrager@example.org',
			subject: 'Zaak Z-2026-9',
			template: 'Accept door jan, {{case.missing}}',
			caseId: 'case-1',
			caseTitle: 'Dakkapel Dorpsstraat 4',
		})
	})

	it('sends the e-mail to the action webhook when it names one, and throws when it is refused', async () => {
		fetchMock.mockResolvedValueOnce({ ok: false, statusText: 'Bad Gateway' })

		await expect(store.dispatchEmailAction({ webhookUrl: 'https://n8n.example.org/hook' }, caseData, null))
			.rejects.toThrow('Email action failed: Bad Gateway')
		expect(fetchMock.mock.calls[0][0]).toBe('https://n8n.example.org/hook')
		expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toMatchObject({ subject: '', template: '' })
	})

	it('creates a sub-case under the case, titled after it unless the action names a title', async () => {
		await store.dispatchCreateSubCaseAction({ caseTypeId: 'ct-9' }, caseData)
		await store.dispatchCreateSubCaseAction({ caseTypeId: 'ct-9', title: 'Welstandsadvies' }, { id: 'case-2', title: 'X' })

		expect(objects.saveObject.mock.calls).toEqual([
			['case', { title: 'Sub-case of Dakkapel Dorpsstraat 4', caseType: 'ct-9', parentCase: 'case-1', priority: 'high' }],
			['case', { title: 'Welstandsadvies', caseType: 'ct-9', parentCase: 'case-2', priority: 'normal' }],
		])
	})

	it('posts the case and a transition summary to the webhook', async () => {
		await store.dispatchWebhookAction({ url: 'https://hooks.example.org/case' }, caseData, transition)
		await store.dispatchWebhookAction({ url: 'https://hooks.example.org/case' }, caseData, null)

		const [url, init] = fetchMock.mock.calls[0]
		expect(url).toBe('https://hooks.example.org/case')
		expect(init.signal).toBeInstanceOf(AbortSignal)
		expect(JSON.parse(init.body)).toEqual({
			caseId: 'case-1',
			caseTitle: 'Dakkapel Dorpsstraat 4',
			status: 'intake',
			transition: { label: 'Accept', fromStatus: 'intake', toStatus: 'in-progress' },
		})
		expect(JSON.parse(fetchMock.mock.calls[1][1].body).transition).toBeNull()
	})

	it('throws on a refused webhook and clears its timeout either way', async () => {
		vi.useFakeTimers()
		fetchMock.mockResolvedValueOnce({ ok: false, status: 500 })

		await expect(store.dispatchWebhookAction({ url: 'https://hooks.example.org/case' }, caseData, transition))
			.rejects.toThrow('Webhook returned 500')
		expect(vi.getTimerCount()).toBe(0)

		await store.dispatchWebhookAction({ url: 'https://hooks.example.org/case' }, caseData, transition)
		expect(vi.getTimerCount()).toBe(0)
	})

	it('aborts a webhook that takes longer than ten seconds', async () => {
		vi.useFakeTimers()
		let signal
		fetchMock.mockImplementationOnce((url, init) => {
			signal = init.signal
			return new Promise((resolve, reject) => init.signal.addEventListener('abort', () => reject(new Error('aborted'))))
		})

		const pending = store.dispatchWebhookAction({ url: 'https://hooks.example.org/slow' }, caseData, null)
		const outcome = expect(pending).rejects.toThrow('aborted')
		vi.advanceTimersByTime(9999)
		expect(signal.aborted).toBe(false)
		vi.advanceTimersByTime(1)
		await outcome
		expect(signal.aborted).toBe(true)
	})

	it('sets one field on the case and keeps the rest', async () => {
		await store.dispatchSetFieldAction({ fieldName: 'priority', value: 'urgent' }, caseData)

		expect(objects.saveObject).toHaveBeenCalledWith('case', { ...caseData, priority: 'urgent' })
	})

	it('posts an admin notification with the message filled from the case', async () => {
		await store.dispatchNotifyAction({ message: 'Zaak {{case.reference}} wacht' }, caseData)
		await store.dispatchNotifyAction({}, caseData)

		const [url, init] = fetchMock.mock.calls[0]
		expect(url).toBe('/ocs/v2.php/apps/admin_notifications/api/v1/notifications/admin')
		expect(init.headers).toEqual({ 'Content-Type': 'application/json', requesttoken: 'token-1', 'OCS-APIREQUEST': 'true' })
		expect(JSON.parse(init.body)).toEqual({ shortMessage: 'Zaak Z-2026-9 wacht', longMessage: '' })
		expect(JSON.parse(fetchMock.mock.calls[1][1].body).shortMessage).toBe('')
	})
})
