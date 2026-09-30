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

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

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
		expect(publish.visibleWhen).toEqual({
			field: 'wooPublicationStatus',
			op: 'eq',
			value: 'ready',
		})
	})

	it('offers Withdraw publication only on a published case, after a confirmation', () => {
		const withdraw = action('woo-withdraw')
		expect(withdraw.url).toBe('/apps/dossiq/api/cases/@objectId/woo/withdraw')
		expect(withdraw.confirm).toBe(true)
		expect(withdraw.visibleWhen).toEqual({
			field: 'wooPublicationStatus',
			op: 'eq',
			value: 'published',
		})
	})

	it('calls routes that exist and icons the app registers', () => {
		expect(routes).toContain("'url' => '/api/cases/{id}/woo/publish'")
		expect(routes).toContain("'url' => '/api/cases/{id}/woo/withdraw'")
		for (const id of ['woo-publish', 'woo-withdraw']) {
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
