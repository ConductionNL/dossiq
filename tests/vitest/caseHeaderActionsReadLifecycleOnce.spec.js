// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page's header actions ask `/api/case/{id}/lifecycle` once per case.
 * "Ask for missing information" shows only while the case is not suspended,
 * which the lifecycle endpoint answers. The page replaces its object context
 * when the case loads, when its visibility rows arrive and when the Edit
 * dialog takes the case's lock, and each refresh used to ask the endpoint
 * again: three reads of 0.55 s while Edit case opened (PERF-HANDOFF, measured
 * 24 Nov 2026). nextcloud-vue 2.218.4 reads a header action's remote
 * conditions once per record.
 *
 * @spec openspec/changes/remote-conditions-read-once-per-record/specs/action-buttons/spec.md#requirement-a-header-actions-remote-conditions-are-read-once-per-record
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import CnActionButtons from '@conduction/nextcloud-vue/src/components/CnActionButtons/CnActionButtons.vue'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const caseDetail = buildProfiledManifest(
	buildManifest,
	readJson('src', 'manifest.json'),
	fragments,
	readJson('src', 'menu-layout.simple.json'),
).pages.find((page) => page.id === 'CaseDetail').config
const askForInformation = caseDetail.headerActions.find((action) => action.id === 'request-information')

/**
 * Let the async visibility pass finish.
 *
 * @return {Promise<void>}
 */
const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * The case as the page holds it.
 *
 * @param {object} [self] Overrides of its `@self`.
 * @return {object} The case.
 */
function theCase (self = {}) {
  return {
	id: 'case-1',
	title: 'Woo request',
	isFinalStatus: false,
	'@self': { id: 'case-1', locked: null, updated: '2026-11-25T03:00:00Z', ...self },
}
}

/**
 * How often the lifecycle endpoint was asked.
 *
 * @return {number} The count.
 */
const lifecycleReads = () => global.fetch.mock.calls.filter(([url]) => String(url).includes('/api/case/case-1/lifecycle')).length

describe('the case page\'s header actions ask the lifecycle endpoint once per case', () => {
	beforeEach(() => {
		global.fetch = vi.fn(async (url) => ({
			ok: true,
			status: 200,
			json: async () => (String(url).includes('/lifecycle') ? { suspended: false } : { waiting: false }),
		}))
	})

	afterEach(() => {
		delete global.fetch
	})

	it('still gates Ask for missing information on the lifecycle endpoint', () => {
		expect(JSON.stringify(askForInformation.visibleWhen)).toContain('/apps/dossiq/api/case/@objectId/lifecycle')
	})

	it('reads it once while the case loads, its visibility rows arrive and Edit takes the lock', async () => {
		const context = ref({ objectId: 'case-1', object: theCase(), register: 'dossiq', schema: 'case' })
		const wrapper = mount(CnActionButtons, {
			props: { actions: [askForInformation], display: 'menu' },
			global: {
				provide: { cnObjectContext: context },
				mocks: { $router: { push: vi.fn() } },
			},
		})
		await flush()
		context.value = { ...context.value, context: { caseType: { id: 'type-1' } } }
		await flush()
		context.value = { ...context.value, object: theCase({ locked: { user: 'pieter', expiration: '2026-11-25T03:30:00Z' } }) }
		await flush()

		expect(lifecycleReads()).toBe(1)
		expect(wrapper.vm.visibleActions.map((action) => action.id)).toEqual(['request-information'])
	})
})
