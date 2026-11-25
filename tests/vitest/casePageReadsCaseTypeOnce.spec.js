// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The case page reads its case type a bounded number of times on open.
 *
 * Measured on the demo (24 Nov 2026): opening a case read caseType/{id} six
 * times. Mounting the case page's own manifest config with the app's widget
 * registry and recording every read showed seven reads of the case type, five
 * of them by the data cards, each reading the related record by itself.
 * nextcloud-vue 2.218.1 shares that read across the cards, which leaves three:
 * the cards' shared read, the visibility rows and the object store. If a
 * widget or a library change starts reading the case type per card again,
 * this file goes red.
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
const register = JSON.parse(fs.readFileSync(path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'), 'utf8'))
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const pageConfig = manifest.pages.find((page) => page.id === 'CaseDetail').config

const SCHEMA = { ...register.components.schemas.case, id: 5, slug: 'case' }
const CASE = {
	id: 'case-1',
	'@self': { id: 'case-1', register: 'dossiq', schema: 'case' },
	title: 'Woo request',
	caseType: 'type-1',
	assignee: 'admin',
	isDraft: false,
}
const CASE_TYPE = { id: 'type-1', title: 'Woo request', '@self': { id: 'type-1', register: 'dossiq', schema: 'caseType' } }
const CASE_TYPE_READ = /\/objects\/dossiq\/caseType\/type-1(\?|$)/

globalThis.ResizeObserver = globalThis.ResizeObserver || class {

	observe() {}
	unobserve() {}
	disconnect() {}

}

/** Every read the page made, as its url. */
let reads = []

/**
 * Answer the page's fetch reads like OpenRegister does.
 *
 * @param {string} url The url.
 * @return {Promise<Response>} The response.
 */
async function fakeFetch(url) {
	reads.push(String(url))
	let body = { results: [] }
	if (/\/schemas\/case(\?|$)/.test(url)) {
		body = SCHEMA
	} else if (CASE_TYPE_READ.test(url)) {
		body = CASE_TYPE
	} else if (/\/objects\/dossiq\/case\/case-1(\?|$)/.test(url)) {
		body = CASE
	}
	return new Response(JSON.stringify(body), { status: 200, headers: { 'content-type': 'application/json' } })
}

describe('the case page on open', () => {
	beforeEach(() => {
		reads = []
		globalThis.fetch = vi.fn(fakeFetch)
		for (const verb of ['get', 'post', 'put', 'patch', 'delete']) {
			axios[verb].mockReset()
			axios[verb].mockImplementation(async (url) => {
				reads.push(String(url))
				return { data: CASE_TYPE_READ.test(String(url)) ? CASE_TYPE : { results: [] } }
			})
		}
	})

	it('reads its case type at most three times, once for all its data cards', async () => {
		const pinia = createPinia()
		setActivePinia(pinia)
		const wrapper = mount(CnDetailPage, {
			props: { ...pageConfig, objectId: 'case-1' },
			global: { plugins: [pinia], provide: { cnRegistry: registry } },
			attachTo: document.body,
		})
		for (let round = 0; round < 4; round++) {
			await flushPromises()
			await new Promise((resolve) => setTimeout(resolve, 250))
		}

		const caseTypeReads = reads.filter((url) => CASE_TYPE_READ.test(url))
		expect(caseTypeReads.length, caseTypeReads.join('\n')).toBeGreaterThan(0)
		expect(caseTypeReads.length, caseTypeReads.join('\n')).toBeLessThanOrEqual(3)
		wrapper.unmount()
	}, 60000)
})
