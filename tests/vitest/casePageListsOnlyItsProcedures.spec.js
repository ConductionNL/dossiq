// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Opening a case reads no list of a procedure its case type does not run.
 *
 * Measured on the demo (24 Nov 2026, PERF-HANDOFF): opening a Woo case listed
 * hearingSession, advisoryReport, indicatiestelling, mdoOverleg,
 * tenderQuestion, bid, subsidieBeschikking and subsidieUitvoering, each once,
 * while a Woo case shows none of them. Every widget and tab that lists one of
 * those is gated on the case type (`@context.caseType.category`,
 * `tenderChain`, a family plan), and the tab strip counts a tab only once its
 * own condition holds. This mounts the assembled case page (manifest,
 * fragments and the simple profile) with the app's widget registry on a case
 * of a case type with none of those procedures and records every request, its
 * url and its body. If a widget, a tab count or a library change lists one of
 * those schemas again on such a case, this file goes red.
 *
 * @spec openspec/specs/case-page/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CnDetailPage from '@conduction/nextcloud-vue/src/components/CnDetailPage/CnDetailPage.vue'
import registry from '../../src/registry.js'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

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
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const register = readJson('lib', 'Settings', 'dossiq_register.json')
const fragments = fs.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const pageConfig = buildProfiledManifest(
	buildManifest,
	readJson('src', 'manifest.json'),
	fragments,
	readJson('src', 'menu-layout.simple.json'),
).pages.find((page) => page.id === 'CaseDetail').config

/** The lists of procedures a Woo case does not run. */
const OTHER_PROCEDURES = [
	'hearingSession', 'advisoryReport', 'indicatiestelling', 'mdoOverleg', 'gezinsplan', 'toestemming',
	'tenderQuestion', 'bid', 'evaluation', 'subsidieBeschikking', 'subsidieUitvoering',
]

const SCHEMA = { ...register.components.schemas.case, id: 5, slug: 'case' }
const CASE = {
	id: 'case-1',
	'@self': { id: 'case-1', register: 'dossiq', schema: 'case' },
	title: 'Woo request',
	caseType: 'type-1',
	assignee: 'admin',
	isDraft: false,
}
const CASE_TYPE = {
	id: 'type-1',
	title: 'Woo request',
	category: 'Woo',
	tenderChain: false,
	grantChain: false,
	'@self': { id: 'type-1', register: 'dossiq', schema: 'caseType' },
}
const CASE_TYPE_READ = /\/objects\/dossiq\/caseType\/type-1(\?|$)/

globalThis.ResizeObserver = globalThis.ResizeObserver || class {

	observe() {}
	unobserve() {}
	disconnect() {}

}

/** Every request the page made: its url and its body. */
let requests = []

/**
 * Answer the page's fetch reads like OpenRegister does.
 *
 * @param {string} url The url.
 * @param {object} [init] The request options.
 * @return {Promise<Response>} The response.
 */
async function fakeFetch(url, init) {
	requests.push(`${url} ${init && init.body ? String(init.body) : ''}`)
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

/**
 * The requests that name one of the other procedures' schemas.
 *
 * @return {string[]} The offending requests.
 */
function otherProcedureRequests() {
	const named = new RegExp(`(/|"|\\b)(${OTHER_PROCEDURES.join('|')})(/|\\?|"|\\b)`)
	return requests.filter((request) => named.test(request))
}

describe('opening a case of a case type without those procedures', () => {
	beforeEach(() => {
		requests = []
		globalThis.fetch = vi.fn(fakeFetch)
		for (const verb of ['get', 'post', 'put', 'patch', 'delete']) {
			axios[verb].mockReset()
			axios[verb].mockImplementation(async (url, data) => {
				requests.push(`${url} ${data && typeof data === 'object' ? JSON.stringify(data) : ''}`)
				return { data: CASE_TYPE_READ.test(String(url)) ? CASE_TYPE : { results: [] } }
			})
		}
	})

	it('lists no hearing, advice, social, tender or grant records', async () => {
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

		expect(requests.some((request) => CASE_TYPE_READ.test(request.split(' ')[0])), 'the case type is read').toBe(true)
		expect(otherProcedureRequests(), otherProcedureRequests().join('\n')).toEqual([])
		wrapper.unmount()
	}, 60000)
})
