// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The case sidebar reads a tab's data when the tab is opened, not before.
 *
 * Measured on the demo (24 Nov 2026): opening a case read its audit trail,
 * shares, access links, permissions and grants before anything was clicked,
 * because every sidebar tab mounted on open. From nextcloud-vue 2.218.2 a tab
 * mounts when it is first opened. This mounts the case page's own sidebar
 * config with the app's widget registry and records every read.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CnObjectSidebar from '@conduction/nextcloud-vue/src/components/CnObjectSidebar/CnObjectSidebar.vue'
import registry from '../../src/registry.js'

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: () => {},
	showError: () => {},
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
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const sidebar = manifest.pages.find((page) => page.id === 'CaseDetail').config.sidebar
/** What only the Sharing and Access tabs read. */
const TAB_ONLY = /\/(shares|caseShare|caseFederatedShare|access-links|permissions|grants)(\/|\?|$)/

globalThis.ResizeObserver = globalThis.ResizeObserver || class {

	observe() {}
	unobserve() {}
	disconnect() {}

}

/** Every read the sidebar made, as its url. */
let reads = []

/**
 * Answer every read with an empty list.
 *
 * @param {string} url The url.
 * @return {Promise<Response>} The response.
 */
async function fakeFetch(url) {
	reads.push(String(url))
	return new Response(JSON.stringify({ results: [] }), { status: 200, headers: { 'content-type': 'application/json' } })
}

/**
 * Let the tabs' reads settle.
 *
 * @return {Promise<void>}
 */
async function settle() {
	for (let round = 0; round < 3; round++) {
		await flushPromises()
		await new Promise((resolve) => setTimeout(resolve, 200))
	}
}

describe('the case sidebar', () => {
	beforeEach(() => {
		reads = []
		globalThis.fetch = vi.fn(fakeFetch)
		for (const verb of ['get', 'post', 'put', 'patch', 'delete']) {
			axios[verb].mockReset()
			axios[verb].mockImplementation(async (url) => {
				reads.push(String(url))
				return { data: { results: [] } }
			})
		}
	})

	it('reads nothing of the Sharing and Access tabs until one is opened', async () => {
		setActivePinia(createPinia())
		const wrapper = mount(CnObjectSidebar, {
			props: { objectType: 'case', objectId: 'case-1', register: 'dossiq', schema: 'case', tabs: sidebar.tabs },
			global: { plugins: [createPinia()], provide: { cnRegistry: registry } },
			attachTo: document.body,
		})
		await settle()
		expect(wrapper.vm.activeTab).toBe(sidebar.tabs[0].id)
		const early = reads.filter((url) => TAB_ONLY.test(url))
		expect(early, early.join('\n')).toEqual([])

		wrapper.vm.activeTab = 'sharing'
		await settle()
		expect(reads.filter((url) => TAB_ONLY.test(url)).length, 'the Sharing tab reads once opened').toBeGreaterThan(0)
		wrapper.unmount()
	}, 60000)
})
