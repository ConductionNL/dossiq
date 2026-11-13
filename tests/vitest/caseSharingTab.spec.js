// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case sharing tab: partner shares, access links (create, pause, preview,
 * revoke), federated shares and their activity, transfers and the sub-case
 * warning, mounted with axios, the dialogs and the helpers doubled.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const stub = (name) => ({ name, render: () => null })
const toasts = []
vi.mock('@nextcloud/vue', () => ({ NcButton: stub('NcButton'), NcNoteCard: stub('NcNoteCard') }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
for (const name of ['CaseTransferDialog', 'CreateAccessLinkDialog', 'CreateFederatedShareDialog', 'CreateShareDialog', 'FederatedActivityPanel']) {
	vi.doMock(`../../src/dialogs/${name}.vue`, () => ({ default: stub(name) }))
}
vi.mock('../../src/views/cases/components/ShareTab.vue', () => ({ default: stub('ShareTab') }))
vi.mock('../../src/utils/federatedShareHelpers.js', () => ({
	createFederatedShareEndpoint: () => '/apps/dossiq/api/federated',
	federatedActivityEndpoint: (id) => `/apps/dossiq/api/federated/${id}/activity`,
	federatedSharesListEndpoint: () => '/apps/dossiq/api/federated',
	revokeFederatedShareEndpoint: (id) => `/apps/dossiq/api/federated/${id}`,
}))
const partners = { load: vi.fn() }
vi.mock('../../src/utils/partnerOrganisations.js', () => ({ loadPartnerOrganisations: () => partners.load() }))
const objectStore = { fetchCollection: vi.fn() }
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore }))

const { default: CaseSharingTab } = await import('../../src/views/cases/components/CaseSharingTab.vue')

const D = '/index.php/apps/dossiq/api'

globalThis.n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))

/**
 * Route axios GETs by URL ending.
 *
 * @param {object} answers URL suffix to response data (or an Error to reject).
 */
function routeGets(answers) {
	axios.get.mockImplementation(async (url) => {
		const key = Object.keys(answers).find((suffix) => url.endsWith(suffix))
		const answer = key === undefined ? {} : answers[key]
		if (answer instanceof Error || answer?.response) {
			throw answer
		}
		return { data: answer }
	})
}

/**
 * Mount the tab for case c1 and let it load.
 *
 * @param {object} [props] Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountTab(props = { objectId: 'c1' }) {
	const w = shallowMount(CaseSharingTab, { props })
	await flushPromises()
	return w
}

beforeEach(() => {
	toasts.length = 0
	for (const verb of ['get', 'post', 'put', 'delete']) {
		axios[verb].mockReset()
	}
	vi.clearAllMocks()
	partners.load.mockResolvedValue([{ id: 'p' }])
	objectStore.fetchCollection.mockResolvedValue([{ id: 'sub1' }, { id: 'sub2' }])
})

describe('loading', () => {
	it('loads shares, links, federated shares, partners, documents and sub-cases for the case', async () => {
		routeGets({
			'/caseShare': { results: [{ id: 's' }] },
			'/access-links/case/c1': { results: [{ accessLinkId: 'l' }] },
			'/federated': { results: [{ id: 'f' }] },
			'/case/c1': { documents: ['d1'] },
		})
		const w = await mountTab()
		expect([w.vm.shares, w.vm.links, w.vm.federatedShares, w.vm.partners, w.vm.caseDocuments]).toEqual([
			[{ id: 's' }], [{ accessLinkId: 'l' }], [{ id: 'f' }], [{ id: 'p' }], [{ id: 'd1', name: 'd1' }],
		])
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('case', { parentCase: 'c1', _limit: 100 })
		expect(w.vm.inheritanceWarning).toBe('This case has 2 sub-cases. Sharing this case lets the holder read them too.')
		expect(axios.get.mock.calls.find((c) => c[0].endsWith('/caseShare'))[1]).toEqual({ params: { caseId: 'c1', shareType: 'partner' } })
	})

	it('loads nothing case-bound without a case', async () => {
		const w = await mountTab({})
		expect(axios.get).not.toHaveBeenCalled()
		expect(objectStore.fetchCollection).not.toHaveBeenCalled()
		expect(w.vm.partners).toEqual([{ id: 'p' }])
	})

	it('treats a forbidden link list quietly and says when other loads fail', async () => {
		routeGets({ '/access-links/case/c1': { response: { status: 403 } }, '/caseShare': new Error('x'), '/federated': new Error('x'), '/case/c1': new Error('x') })
		partners.load.mockRejectedValueOnce(new Error('x'))
		objectStore.fetchCollection.mockRejectedValueOnce(new Error('x'))
		const w = await mountTab()
		expect([w.vm.linksForbidden, w.vm.links, w.vm.federatedShares, w.vm.caseDocuments, w.vm.deelzaken, w.vm.partners]).toEqual([true, [], [], [], [], []])
		expect(toasts.map((e) => e[1]).sort()).toEqual(['Could not load partner organisations', 'Could not load partner shares'])
		routeGets({ '/access-links/case/c1': { response: { status: 500 } } })
		await w.vm.loadLinks()
		expect([w.vm.linksForbidden, toasts.at(-1)[1]]).toEqual([false, 'Could not load the links on this case'])
	})
})

describe('access links', () => {
	it('creates a link and shows its address, or the refusal', async () => {
		routeGets({})
		const w = await mountTab()
		axios.post.mockResolvedValueOnce({ data: { url: 'https://x/l' } })
		w.vm.createAccessLinkDialogOpen = true
		await w.vm.createLink({ caseId: 'c1' })
		expect(axios.post).toHaveBeenCalledWith(`${D}/shares`, { caseId: 'c1' })
		expect([w.vm.createAccessLinkDialogOpen, toasts.at(-1)]).toEqual([false, ['success', 'The link is ready: https://x/l']])
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Expiry too far' } } })
		await w.vm.createLink({})
		axios.post.mockRejectedValueOnce({})
		await w.vm.createLink({})
		expect(toasts.slice(-2).map((e) => e[1])).toEqual(['Expiry too far', 'Could not create the link'])
	})

	it('revokes, pauses and resumes a link for this case', async () => {
		routeGets({})
		const w = await mountTab()
		await w.vm.revokeLink({ accessLinkId: 'l 1' })
		expect(axios.delete).toHaveBeenCalledWith(`${D}/access-links/l%201`, { params: { caseId: 'c1' } })
		await w.vm.pauseLink({ accessLinkId: 'l1', state: 'active' })
		await w.vm.pauseLink({ accessLinkId: 'l1', state: 'paused' })
		expect(axios.put.mock.calls.map((c) => c[1])).toEqual([{ caseId: 'c1', disabled: true }, { caseId: 'c1', disabled: false }])
		axios.delete.mockRejectedValueOnce({})
		await w.vm.revokeLink({ accessLinkId: 'l1' })
		axios.put.mockRejectedValueOnce({ response: { data: { error: 'Gone' } } })
		await w.vm.pauseLink({ accessLinkId: 'l1' })
		expect(toasts.slice(-2).map((e) => e[1])).toEqual(['Could not revoke the link', 'Gone'])
	})

	it('previews what a link opens, and says when it opens nothing', async () => {
		routeGets({ '/preview': { preview: { fields: ['title'] } } })
		const w = await mountTab()
		await w.vm.previewLink({ accessLinkId: 'l1', label: 'Neighbour' })
		expect([w.vm.preview, w.vm.previewOf]).toEqual([{ fields: ['title'] }, 'Neighbour'])
		routeGets({ '/preview': { response: { data: {} } } })
		await w.vm.previewLink({ accessLinkId: 'l1' })
		expect([w.vm.preview, toasts.at(-1)[1]]).toEqual([null, 'That link opens nothing any more'])
	})
})

describe('shares, transfers and federation', () => {
	it('creates and revokes partner shares', async () => {
		routeGets({})
		const w = await mountTab()
		w.vm.createShareDialogOpen = true
		await w.vm.createShare({ partner: 'p' })
		expect(w.vm.createShareDialogOpen).toBe(false)
		await w.vm.revokeShare('s 1')
		expect(axios.delete).toHaveBeenCalledWith(`${D}/shares/s%201`)
		axios.post.mockRejectedValueOnce({})
		await w.vm.createShare({})
		axios.delete.mockRejectedValueOnce({})
		await w.vm.revokeShare('s')
		expect(toasts.map((e) => e[1])).toEqual(['Share created', 'Share revoked', 'Could not create share', 'Could not revoke share'])
	})

	it('submits a transfer request, or shows the refusal', async () => {
		routeGets({})
		const w = await mountTab()
		w.vm.transferDialogOpen = true
		await w.vm.initiateTransfer({ to: 'org' })
		expect(axios.post).toHaveBeenCalledWith(`${D}/transfers`, { to: 'org' })
		expect(w.vm.transferDialogOpen).toBe(false)
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Case is locked' } } })
		await w.vm.initiateTransfer({})
		expect(toasts.map((e) => e[1])).toEqual(['Transfer request submitted', 'Case is locked'])
	})

	it('creates and revokes federated shares', async () => {
		routeGets({})
		const w = await mountTab()
		await w.vm.createFederatedShare({ remote: 'r' })
		expect(axios.post).toHaveBeenCalledWith(`${D}/federated`, { remote: 'r' })
		await w.vm.revokeFederatedShare('f1')
		expect(axios.delete).toHaveBeenCalledWith(`${D}/federated/f1`)
		axios.post.mockRejectedValueOnce({})
		await w.vm.createFederatedShare({})
		axios.delete.mockRejectedValueOnce({})
		await w.vm.revokeFederatedShare('f1')
		expect(toasts.map((e) => e[1])).toEqual(['Case shared with remote organisation', 'Federated share revoked', 'Could not create federated share', 'Could not revoke federated share'])
	})

	it('opens the activity of a federated share and posts to it', async () => {
		routeGets({ '/activity': { entries: [{ id: 'e' }] } })
		const w = await mountTab()
		await w.vm.openActivity('f1')
		expect([w.vm.activityPanelOpen, w.vm.activeFederatedShareId, w.vm.activityEntries, w.vm.activityLoading]).toEqual([true, 'f1', [{ id: 'e' }], false])
		await w.vm.postActivity({ federatedShareId: 'f1', message: 'Hello' })
		expect(axios.post).toHaveBeenCalledWith(`${D}/federated/f1/activity`, { message: 'Hello' })
		axios.post.mockRejectedValueOnce({})
		await w.vm.postActivity({ federatedShareId: 'f1' })
		routeGets({ '/activity': new Error('x') })
		await w.vm.openActivity('f1')
		expect(toasts.map((e) => e[1])).toEqual(['Could not post activity', 'Could not load activity'])
	})

	it('warns about a single sub-case in the singular', async () => {
		routeGets({})
		objectStore.fetchCollection.mockResolvedValueOnce([{ id: 'only' }])
		const w = await mountTab()
		expect(w.vm.inheritanceWarning).toBe('This case has one sub-case. Sharing this case lets the holder read it too.')
	})
})
