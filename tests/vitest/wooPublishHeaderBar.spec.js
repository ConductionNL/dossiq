// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Publish (Woo) in the case header, tested through the component that draws
 * the header menu: the built CnActionButtons from
 * `@conduction/nextcloud-vue/dist`, the module the app bundle imports, fed
 * the real `#CaseDetail` headerActions.
 *
 * Why not the predicate alone: CnActionButtons evaluates the predicates one
 * after another and renders an action whose predicate has not answered yet
 * (`visibility[id] !== false`). A live check on 1 Oct 2026 found Publish (Woo)
 * on an already published case, because an endpoint-gated action earlier in
 * the list was still waiting for its request. `evaluateVisibleWhen` on its
 * own said "hidden" the whole time. So every case here holds the endpoint
 * requests open and reads the menu while they are pending.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publish-action-shows-only-to-whoever-may-publish-and-says-what-happened-req-wpi-009
 */

import CnActionButtons from '@conduction/nextcloud-vue/dist/esm/components/CnActionButtons/CnActionButtons.vue.js'
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'

// `@me` resolves through getCurrentUser(); the person in the header is j.dejong.
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'j.dejong' }),
	getRequestToken: () => 'token',
}))

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const headerActions = manifest.pages.find((page) => page.id === 'CaseDetail').config
	.headerActions
const WOO = ['woo-publish', 'woo-withdraw', 'woo-publication-open']

/**
 * The Woo entries the header menu offers for a case, read while every
 * endpoint request the menu sends is still pending.
 *
 * @param {object} object The case.
 * @return {Promise<object>} The Woo entries by id.
 */
async function wooMenu(object) {
	// Never answers: an endpoint-gated predicate stays pending throughout.
	globalThis.fetch = vi.fn(() => new Promise(() => {}))
	let entries = []
	mount(CnActionButtons, {
		props: { actions: headerActions, display: 'menu' },
		global: { provide: { cnObjectContext: { objectId: 'case-1', object } } },
		attrs: {
			onEntries: (list) => {
				entries = list
			},
		},
	})
	for (let i = 0; i < 10; i++) {
		await flushPromises()
	}
	return Object.fromEntries(
		entries.filter((e) => WOO.includes(e.id)).map((e) => [e.id, e]),
	)
}

describe('the case header offers the Woo actions to whoever may publish', () => {
	afterEach(() => {
		delete globalThis.fetch
	})

	it('offers Publish (Woo) to the handler of a ready case', async () => {
		const menu = await wooMenu({
			wooPublicationStatus: 'ready',
			assignee: 'j.dejong',
		})
		expect(Object.keys(menu)).toEqual(['woo-publish'])
	})

	it('shows only the link on a case someone else published, never Publish', async () => {
		const url = 'https://example.org/index.php/apps/opencatalogi/publication/p-1'
		const menu = await wooMenu({
			wooPublicationStatus: 'published',
			wooPublicationUrl: url,
			assignee: null,
		})
		expect(Object.keys(menu)).toEqual(['woo-publication-open'])
		// External, so the menu renders it as a link to the publication.
		expect(menu['woo-publication-open'].href).toBe(url)
	})

	it('swaps Publish for Withdraw and the link once the handler has published', async () => {
		const menu = await wooMenu({
			wooPublicationStatus: 'published',
			wooPublicationUrl: 'https://example.org/p/1',
			assignee: 'j.dejong',
		})
		expect(Object.keys(menu).sort()).toEqual([
			'woo-publication-open',
			'woo-withdraw',
		])
	})

	it('hides Publish from a colleague who does not handle the case', async () => {
		const menu = await wooMenu({
			wooPublicationStatus: 'ready',
			assignee: 'someone.else',
		})
		expect(menu).toEqual({})
	})

	it('hides every Woo action on a case that is no Woo request', async () => {
		expect(await wooMenu({ assignee: 'j.dejong' })).toEqual({})
		expect(
			await wooMenu({ wooPublicationStatus: 'none', assignee: 'j.dejong' }),
		).toEqual({})
	})

	it('keeps the Woo gates local and ahead of every endpoint-gated action', () => {
		const ids = headerActions.map((a) => a.id)
		const firstRemote = headerActions.findIndex((a) =>
			JSON.stringify(a.visibleWhen || {}).includes('"endpoint"'),
		)
		for (const id of WOO) {
			const action = headerActions.find((a) => a.id === id)
			expect(JSON.stringify(action.visibleWhen)).not.toContain('"endpoint"')
			expect(ids.indexOf(id)).toBeLessThan(firstRemote)
		}
	})
})
