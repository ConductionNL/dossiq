/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Publish (Woo) and Withdraw publication on the case header, and the Data
 * tab section that shows the state (woo-publish-decision-from-the-case D-4).
 *
 * Both actions call a route that must exist, gate on the case's own
 * `wooPublicationStatus`, and use icons the app registers, because a button
 * that 404s or renders without its icon looks fine in a manifest.
 *
 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
 */

import {
	interpolateActionTarget,
	isExternalActionTarget,
} from '@conduction/nextcloud-vue/src/utils/actionsDispatcher.js'
import { evaluateVisibleWhen } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import fs from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'

// `@me` resolves through getCurrentUser(); the coordinator in these tests is
// j.dejong.
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'j.dejong' }),
	getRequestToken: () => 'token',
}))

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const routes = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')
const icons = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')

const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
const action = (id) => caseDetail.config.headerActions.find((a) => a.id === id)

describe('the Woo publication on the case page', () => {
	it('offers Publish (Woo) on a case whose decision is ready', () => {
		const publish = action('woo-publish')
		expect(publish.type).toBe('api-call')
		expect(publish.method).toBe('POST')
		expect(publish.url).toBe('/apps/dossiq/api/cases/@objectId/woo/publish')
		expect(publish.confirm).toBe(true)
	})

	it('offers Withdraw publication only on a published case, after a confirmation', () => {
		const withdraw = action('woo-withdraw')
		expect(withdraw.url).toBe('/apps/dossiq/api/cases/@objectId/woo/withdraw')
		expect(withdraw.confirm).toBe(true)
	})

	it('calls routes that exist and icons the app registers', () => {
		expect(routes).toContain("'url' => '/api/cases/{id}/woo/publish'")
		expect(routes).toContain("'url' => '/api/cases/{id}/woo/withdraw'")
		for (const id of ['woo-publish', 'woo-withdraw', 'woo-publication-open']) {
			expect(icons).toMatch(new RegExp(`\\t${action(id).icon},`))
		}
	})

	it('shows the state and the link on the Data tab, only where there is one', () => {
		const panel = caseDetail.config.widgets.find(
			(w) => w.id === 'case-data-panel',
		)
		const section = panel.content.sections.find(
			(s) => s.widget.id === 'case-woo-publication',
		)
		expect(section.widget.content.include).toEqual([
			'wooPublicationStatus',
			'wooPublicationUrl',
		])
		expect(section.widget.content.hideEmpty).toBe(true)
	})
})

/**
 * Stub the admin probe the role gate asks, the endpoint
 * InspectController::availability answers.
 *
 * @param {boolean} isAdmin What the endpoint answers.
 */
function adminProbe(isAdmin) {
	globalThis.fetch = vi.fn(async (url) => {
		expect(String(url)).toContain('/apps/dossiq/api/inspect/availability')
		return { ok: true, json: async () => ({ isAdmin }) }
	})
}

/**
 * Whether an action shows on a case, through the library's own evaluator.
 *
 * @param {string} id The action id.
 * @param {object} object The case.
 * @return {Promise<boolean>} Visible or not.
 */
function shows(id, object) {
	return evaluateVisibleWhen(action(id).visibleWhen, {
		objectId: 'case-1',
		object,
	})
}

describe('only whoever may publish sees Publish (Woo), and only on a ready case', () => {
	afterEach(() => {
		delete globalThis.fetch
	})

	it('shows to the case handler on a ready case', async () => {
		adminProbe(false)
		expect(
			await shows('woo-publish', {
				wooPublicationStatus: 'ready',
				assignee: 'j.dejong',
			}),
		).toBe(true)
	})

	it('shows to an admin who does not handle the case', async () => {
		adminProbe(true)
		expect(
			await shows('woo-publish', {
				wooPublicationStatus: 'ready',
				assignee: 'someone.else',
			}),
		).toBe(true)
	})

	it('hides from a colleague who neither handles the case nor is an admin, as the API refuses them', async () => {
		adminProbe(false)
		expect(
			await shows('woo-publish', {
				wooPublicationStatus: 'ready',
				assignee: 'someone.else',
			}),
		).toBe(false)
		expect(
			await shows('woo-withdraw', {
				wooPublicationStatus: 'published',
				assignee: 'someone.else',
			}),
		).toBe(false)
	})

	it('hides on a case that is no Woo request, or whose decision is not ready', async () => {
		adminProbe(true)
		expect(await shows('woo-publish', { assignee: 'j.dejong' })).toBe(false)
		expect(
			await shows('woo-publish', {
				wooPublicationStatus: 'none',
				assignee: 'j.dejong',
			}),
		).toBe(false)
	})

	it('after publishing swaps Publish for the link to the publication', async () => {
		adminProbe(true)
		const published = {
			wooPublicationStatus: 'published',
			wooPublicationUrl:
				'https://example.org/apps/opencatalogi/publication/p-1',
			assignee: 'j.dejong',
		}
		expect(await shows('woo-publish', published)).toBe(false)
		expect(await shows('woo-withdraw', published)).toBe(true)
		expect(await shows('woo-publication-open', published)).toBe(true)

		const open = action('woo-publication-open')
		expect(open.type).toBe('navigate')
		const target = interpolateActionTarget(open.target, {
			objectId: 'case-1',
			object: published,
		})
		expect(target).toBe(published.wooPublicationUrl)
		// External, so the action bar renders an anchor rather than pushing a route.
		expect(isExternalActionTarget(target)).toBe(true)
	})

	it('shows no link before there is a publication', async () => {
		expect(
			await shows('woo-publication-open', { wooPublicationStatus: 'ready' }),
		).toBe(false)
	})
})
