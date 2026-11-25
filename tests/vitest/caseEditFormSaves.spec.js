// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The case page's Edit dialog saves.
 *
 * Reported on the demo: pick a handler in Edit case, press Save, and the
 * footer says "Saved just now" (the local draft) while Save keeps spinning,
 * the dialog never closes, no write reaches the server and the case keeps
 * its edit lock. This mounts the library's CnDetailPage with the case page's
 * OWN manifest config (form fields, overrides, draft keys, the lock) over a
 * case whose case type has property definitions and whose record carries
 * stored answers and a requester, the shape a live case has. It then picks a
 * handler the way the user picker does and presses Save.
 *
 * It asserts what the report says did not happen: one PATCH carrying the new
 * handler, the dialog closing, Save no longer spinning and the lock handed
 * back. If the library's save path stops doing any of that for this page,
 * this file goes red before a person sees it on the demo.
 *
 * The demo's hang came from the re-read after the lock: it lands after the
 * dialog has seeded its form, and nextcloud-vue before 2.204.1 re-seeded the
 * form from it and dropped the stored property answers, so a required
 * question read empty and Save stayed disabled. One definition is required
 * here and the re-read lands 50 ms late, so this file fails on 2.203.0 and
 * passes from 2.204.1 on.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CnDetailPage from '@conduction/nextcloud-vue/src/components/CnDetailPage/CnDetailPage.vue'

const toasts = vi.hoisted(() => [])

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (message) => toasts.push(['success', message]),
	showError: (message) => toasts.push(['error', message]),
	showWarning: () => {},
	showInfo: () => {},
	getFilePickerBuilder: () => ({}),
}))
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'admin', displayName: 'Admin' }),
	getRequestToken: () => 'token',
	onRequestTokenUpdate: () => {},
}))
vi.mock('@nextcloud/password-confirmation', () => ({
	addPasswordConfirmationInterceptors: () => {},
	confirmPassword: async () => {},
	PwdConfirmationMode: {},
}))
vi.mock('axios-retry', () => {
	const retry = Object.assign(() => {}, { exponentialDelay: () => 0, isNetworkOrIdempotentRequestError: () => false })
	return { default: retry, exponentialDelay: () => 0, isNetworkOrIdempotentRequestError: () => false }
})
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) => url.replace(/\{(\w+)\}/g, (match, key) => (params || {})[key]),
	generateOcsUrl: (url) => url,
	generateRemoteUrl: (url) => url,
	imagePath: (app, file) => file,
	linkTo: (app, file) => file,
}))

const ROOT = path.resolve(__dirname, '../..')
const register = JSON.parse(fs.readFileSync(path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'), 'utf8'))
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const pageConfig = manifest.pages.find((page) => page.id === 'CaseDetail').config

const SCHEMA = { ...register.components.schemas.case, id: 5, slug: 'case' }
const CASE = {
	id: 'case-1',
	'@self': { id: 'case-1', register: 'dossiq', schema: 'case' },
	title: 'Permit for a dormer',
	caseType: 'type-1',
	assignee: 'admin',
	isDraft: false,
	requester: 'person-1',
	initiatorType: 'person',
	impact: 'medium',
	urgency: 'medium',
	properties: [{ propertyDefinition: 'def-1', name: 'Area', value: '10' }],
}
const DEFINITIONS = [
	{ id: 'def-1', name: 'Area', propertyType: 'string', caseType: 'type-1', isRequired: true },
	{ id: 'def-2', name: 'Floors', propertyType: 'integer', caseType: 'type-1' },
]

globalThis.ResizeObserver = globalThis.ResizeObserver || class {

	observe() {}
	unobserve() {}
	disconnect() {}

}

/** Whether the server holds the edit lock for the signed-in user. */
let locked = false
/** Every fetch the page made, as `METHOD url` with its body. */
let requests = []

/**
 * The case as the server returns it, with the lock while one is held.
 *
 * @param {object} record The case.
 * @return {object} The case with `@self.locked` when locked.
 */
function withLock(record) {
	if (!locked) {
		return record
	}
	return { ...record, '@self': { ...record['@self'], locked: { user: 'admin', expiresAt: new Date(Date.now() + 900000).toISOString() } } }
}

/**
 * Answer the page's reads and the PATCH like OpenRegister does.
 *
 * @param {string} url The url.
 * @param {object} init The fetch options.
 * @return {Promise<Response>} The response.
 */
async function fakeFetch(url, init = {}) {
	const method = init.method || 'GET'
	requests.push({ method, url, body: init.body ? JSON.parse(init.body) : null })
	let body = { results: [] }
	if (/\/schemas\//.test(url)) {
		body = SCHEMA
	} else if (/propertyDefinition/.test(url)) {
		body = { results: DEFINITIONS }
	} else if (/\/objects\/dossiq\/case\/case-1(\?|$)/.test(url)) {
		body = method === 'PATCH'
			? withLock({ ...CASE, ...JSON.parse(init.body) })
			: withLock(CASE)
		if (method === 'GET' && locked) {
			// The re-read after the lock lands late on the demo, after the
			// dialog has seeded its form from the case it already had.
			await new Promise((resolve) => setTimeout(resolve, 50))
		}
	}
	return new Response(JSON.stringify(body), { status: 200, headers: { 'content-type': 'application/json' } })
}

/**
 * Let the page's reads and the dialog's lookups settle.
 *
 * @return {Promise<void>}
 */
async function settle() {
	await flushPromises()
	await new Promise((resolve) => setTimeout(resolve, 200))
	await flushPromises()
}

/**
 * Mount the case page with its manifest config and open Edit case.
 *
 * @return {Promise<object>} The wrapper.
 */
async function openEditCase() {
	const pinia = createPinia()
	setActivePinia(pinia)
	const { headerActions, widgets, layout, sidebar, ...config } = pageConfig
	const wrapper = mount(CnDetailPage, {
		props: { ...config, objectId: 'case-1', showEditAction: true, subscribe: true },
		global: { plugins: [pinia], stubs: { CnDetailGrid: true, CnObjectSidebar: true, CnDashboardGrid: true } },
		attachTo: document.body,
	})
	await settle()
	wrapper.vm.openEditForm()
	await settle()
	return wrapper
}

describe('Edit case saves the case', () => {
	beforeEach(() => {
		locked = false
		requests = []
		toasts.length = 0
		globalThis.fetch = vi.fn(fakeFetch)
		axios.post.mockReset()
		axios.post.mockImplementation(async (url) => {
			locked = /\/lock$/.test(url)
			return { data: {} }
		})
	})

	it('opens the case form and takes the lock', async () => {
		const wrapper = await openEditCase()

		expect(wrapper.vm.$refs.editFormDialog, 'the edit dialog is open').toBeTruthy()
		expect(axios.post).toHaveBeenCalledWith('/apps/openregister/api/objects/dossiq/case/case-1/lock', expect.anything())
		expect(wrapper.vm.lockState.lockedByMe.value).toBe(true)
		wrapper.unmount()
	})

	it('🔴 picking a handler and pressing Save sends the PATCH, closes the dialog and hands back the lock', async () => {
		const wrapper = await openEditCase()
		const dialog = wrapper.vm.$refs.editFormDialog
		const assignee = dialog.visibleFields.find((field) => field.key === 'assignee')
		expect(assignee, 'the handler field is on the form').toBeTruthy()

		// What NcSelectUsers emits when Thomas Brouwer is picked.
		dialog.onEffectiveSelectChange(assignee, { id: 'thomas', displayName: 'Thomas Brouwer' })
		await flushPromises()
		// The dialog is teleported to the body, so its buttons are read there.
		const save = [...document.body.querySelectorAll('button')].find((button) => button.textContent.trim() === dialog.resolvedConfirmLabel)
		expect(save, 'the Save button renders').toBeTruthy()
		expect(save.disabled).toBe(false)
		save.click()
		await settle()

		const writes = requests.filter((request) => request.method !== 'GET')
		expect(writes).toHaveLength(1)
		expect(writes[0].method).toBe('PATCH')
		expect(writes[0].url).toBe('/apps/openregister/api/objects/dossiq/case/case-1')
		expect(writes[0].body).toEqual({ assignee: 'thomas' })
		expect(dialog.loading, 'Save stops spinning').toBe(false)
		expect(wrapper.vm.editFormOpen, 'the dialog closes').toBe(false)
		expect(wrapper.vm.$refs.editFormDialog).toBeFalsy()
		expect(axios.post).toHaveBeenCalledWith('/apps/openregister/api/objects/dossiq/case/case-1/unlock')
		expect(toasts.filter(([kind]) => kind === 'error')).toEqual([])
		wrapper.unmount()
	})
})
