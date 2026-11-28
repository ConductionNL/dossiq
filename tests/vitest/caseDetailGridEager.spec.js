/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page's body grid mounts its widgets when they near the screen
 * (nextcloud-vue 2.212+, viewport-lazy detail grid), so opening a case fetches
 * for the cards in view only. Three placements opt out with `eager: true`
 * because the page needs them at once wherever the reader has scrolled:
 *   - presence-strip: it announces the reader to the other handlers on open;
 *   - case-banners: the flags (archived, claimed, attention) show at once;
 *   - the tab strip: the open tab's Actions menu is hoisted into the header.
 * Every other placement stays lazy, so the fan-out on open does not return.
 *
 * @spec openspec/specs/case-page/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const root = path.resolve(__dirname, '../../')
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'src/manifest.json'), 'utf8'))
const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
const layout = caseDetail.config.layout

describe('the case page grid mounts widgets as they near the screen', () => {
	it('opts only the presence strip, the banner row and the tab strip out', () => {
		const eager = layout.filter((item) => item.eager === true).map((item) => item.widgetId).sort()
		expect(eager).toEqual(['case-banner-stack', 'case-panels', 'case-presence'])
	})

	it('leaves every other placement lazy', () => {
		const lazy = layout.filter((item) => item.eager !== true)
		expect(lazy.length).toBe(layout.length - 3)
		expect(lazy.map((item) => item.widgetId)).toEqual(expect.arrayContaining([
			'case-payment-requests', 'case-contract', 'case-linked', 'case-merge-to-pdf', 'case-consents', 'case-tender-panel', 'goal-plan',
		]))
	})

	it('runs on a nextcloud-vue whose detail grid reads `eager` off the layout entry', () => {
		const lib = path.join(root, 'node_modules/@conduction/nextcloud-vue')
		const schema = JSON.parse(fs.readFileSync(path.join(lib, 'src/schemas/app-manifest-v2.schema.json'), 'utf8'))
		expect(schema.$defs.widgetEntry.properties.eager.type).toBe('boolean')
		const page = fs.readFileSync(path.join(lib, 'src/components/CnDetailPage/CnDetailPage.vue'), 'utf8')
		expect(page).toMatch(/gridCellEager\(item, section\)/)
		expect(page).toMatch(/item\.eager === true/)
	})
})
