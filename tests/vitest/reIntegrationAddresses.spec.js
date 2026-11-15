// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The re-integration list is a view of the case list; its addresses and menu
 * entry still lead there, and an old trajectory link opens the case it moved to.
 *
 * @spec openspec/specs/dossiq-sociaal-domein-participatiewet/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

const { routesFromManifest } = await import('../../src/utils/manifestRoutes.js')

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const VIEW = 'default:re-integration-pathways'

/**
 * A router over the shipped manifest.
 *
 * @return {Promise<object>} The router.
 */
async function router() {
	const { createMemoryHistory, createRouter } = await import('vue-router')
	return createRouter({ history: createMemoryHistory(), routes: routesFromManifest(manifest, {}) })
}

describe('the retired re-integration addresses', () => {
	beforeEach(() => {
		get.mockReset()
	})

	it('opens the re-integration view for the old list', async () => {
		const r = await router()
		await r.push('/settings/re-integratietrajecten')
		expect(r.currentRoute.value.name).toBe('Cases')
		expect(r.currentRoute.value.query.savedView).toBe(VIEW)
	})

	it('opens the case a trajectory moved to, found by its earlier reference', async () => {
		get.mockResolvedValue({ data: { results: [{ id: 'case-9', externalReference: 'traj-1' }] } })
		const r = await router()
		await r.push('/settings/re-integratietrajecten/traj-1')
		expect(get).toHaveBeenCalledWith('/apps/openregister/api/objects/dossiq/case', { params: { externalReference: 'traj-1', _limit: 1 } })
		expect(r.currentRoute.value.name).toBe('CaseDetail')
		expect(r.currentRoute.value.params.id).toBe('case-9')
	})

	it('follows the trajectory\'s own stamp when no case carries its id', async () => {
		get.mockImplementation(async (url) => (String(url).endsWith('/case')
			? { data: { results: [] } }
			: { data: { id: 'traj-4', migratedTo: 'case-4' } }))
		const r = await router()
		await r.push('/settings/re-integratietrajecten/traj-4')
		expect(get).toHaveBeenCalledWith('/apps/openregister/api/objects/dossiq/reIntegratieTraject/traj-4')
		expect(r.currentRoute.value.params.id).toBe('case-4')
	})

	it('opens the view when no case carries the reference, or the lookup fails', async () => {
		get.mockResolvedValue({ data: { results: [] } })
		let r = await router()
		await r.push('/settings/re-integratietrajecten/traj-2')
		expect(r.currentRoute.value.query.savedView).toBe(VIEW)

		get.mockImplementation(async () => { throw new Error('offline') })
		r = await router()
		await r.push('/settings/re-integratietrajecten/traj-3')
		expect(r.currentRoute.value.query.savedView).toBe(VIEW)
	})
})

describe('re-integration as a view like Woo', () => {
	it('has no page and no menu entry of its own (Ruben, 23 Oct)', () => {
		expect(manifest.pages.find((page) => page.id === 'ReIntegratieTrajecten')).toBeUndefined()
		expect(manifest.menu.find((item) => item.id === 'ReIntegratieTrajectenMenu')).toBeUndefined()
	})

	it('is a secondary default view with an icon and a description, as the Woo requests view', () => {
		const views = manifest.pages.find((page) => page.id === 'Cases').config.defaultSavedViews
		const view = views.find((entry) => entry.slug === 're-integration-pathways')
		expect(view).toMatchObject({ icon: 'BriefcaseAccountOutline', description: 'Case type re-integration pathway', secondary: true })
	})
})
