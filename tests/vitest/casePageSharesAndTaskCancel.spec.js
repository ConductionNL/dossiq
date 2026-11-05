/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page cards that read the case page backend: the shares of the case
 * outside the organisation (DqZaakBetrokkenen, nextcloud-vue share-list over
 * GET /api/cases/{id}/shares) and Cancel on the Tasks card through dossiq's
 * cancel route (DqZaakTaken).
 *
 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-share-via-shares-leaf/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const page = read('src/menu-layout.simple.json').pages.find((entry) => entry.id === 'CaseDetail')
const widget = (id) => page.configAppend.widgets.find((entry) => entry.id === id)
const tabs = page.configPatch.widgets['case-panels'].content.tabs
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')

describe('sharing outside the organisation (DqZaakBetrokkenen)', () => {
	const card = widget('case-shares-card')

	it('sits on the People tab after Access', () => {
		const people = tabs.find((entry) => entry.label === 'People')
		expect(people.widgetIds.slice(-2)).toEqual(['case-access-card', 'case-shares-card'])
	})

	it('lists the shares of this case from the route dossiq answers', () => {
		expect(card.type).toBe('share-list')
		expect(card.content.url).toBe('/apps/dossiq/api/cases/{objectId}/shares')
		expect(routes).toContain("'url' => '/api/cases/{caseId}/shares',")
	})

	it('revokes one share of this case, never another case\'s', () => {
		expect(card.content.revoke.method).toBe('DELETE')
		expect(card.content.revoke.url).toBe('/apps/dossiq/api/cases/@objectId/shares/{id}')
		expect(routes).toContain("'url' => '/api/cases/{caseId}/shares/{shareId}', 'verb' => 'DELETE'")
	})

	it('names every status the route answers', () => {
		expect(Object.keys(card.content.statusLabels).sort()).toEqual(['active', 'expired', 'paused', 'revoked'])
	})

	it('opens the sidebar share flow for a new share', () => {
		expect(card.headerActions[0].label).toBe('New share')
		expect(card.headerActions[0].action).toEqual({ type: 'open-sidebar-tab', tab: 'sharing' })
	})
})

describe('cancel on the Tasks card (DqZaakTaken)', () => {
	it('cancels through dossiq\'s route, which asks the task engine', () => {
		const tasks = widget('case-tasks-card')
		expect(tasks.content.cancel).toBe(true)
		expect(tasks.content.cancelUrl).toBe('/apps/dossiq/api/case-tasks/{id}/cancel')
		expect(routes).toMatch(/'url' => '\/api\/case-tasks\/\{taskId\}\/cancel'/)
	})
})
