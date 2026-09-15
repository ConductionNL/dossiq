/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Where a person sets how dossiq opens for them, and the promise that there
 * is only one such place.
 *
 * Two halves, and they are easy to get out of step. The per-person options
 * this cluster names — the landing page, how dates read, the view each list
 * reopens in, the pinned menu, the held row order — are declared as APP
 * DEFAULTS in the manifest's `personalisation` block, which `CnAppRoot` turns
 * into the preference group every surface then reads. The options that need
 * their own screen (substitution, linking your own mail) are mounted from
 * `src/personalSettings.js` into Nextcloud's own personal settings.
 *
 * What must never happen is a third place. A preference offered in two
 * screens is a preference that disagrees with itself, and neither screen will
 * say so.
 *
 * @spec openspec/changes/case-page-and-list-as-a-place/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const personalSettings = fs.readFileSync(
	path.join(ROOT, 'src', 'personalSettings.js'),
	'utf8',
)
const webpack = fs.readFileSync(path.join(ROOT, 'webpack.config.js'), 'utf8')

describe('the app defaults the personal layer starts from', () => {
	it('declares every option this cluster names, and no more', () => {
		expect(Object.keys(manifest.personalisation).sort()).toEqual([
			'dateDisplay',
			'enabled',
			'landingPage',
			'pinnedMenu',
			'rememberLastView',
		])
	})

	it('reopens each list in the view that person last used', () => {
		expect(manifest.personalisation.rememberLastView).toBe(true)
	})

	it('leaves the personal layer on, and so needs no reason for switching it off', () => {
		// `disabledReason` is what a person is told when an administrator
		// collapses the layer. Shipping one while the layer is on would be a
		// sentence nobody can ever read, so it stays absent until it is true.
		expect(manifest.personalisation.enabled).toBe(true)
		expect(manifest.personalisation.disabledReason).toBeUndefined()
	})
})

describe('the one personal settings screen', () => {
	it('is reachable, because webpack builds it', () => {
		// An entry point that stops being built is a settings section that
		// renders an empty div: the PHP still registers the mount point and
		// the bundle behind it is simply not there.
		expect(webpack).toContain("'personalSettings.js'")
	})

	it('is registered with Nextcloud rather than being an app page', () => {
		expect(
			fs.existsSync(path.join(ROOT, 'lib', 'Settings', 'PersonalSettings.php')),
			'dossiq registers no personal settings section, so nothing mounts the bundle',
		).toBe(true)
	})

	it('mounts substitution and mail matching, the two options that need a screen', () => {
		expect(personalSettings).toContain('SubstitutionSettings')
		expect(personalSettings).toContain('CaseEmailMatchSettings')
	})

	it('offers no second place to set a per-person preference', () => {
		// The cluster's options are chosen where the thing lives — the view
		// toggle on a list, the pin on a menu entry, the drag on a row — and
		// held against this person by `CnAppRoot`'s preference group. A page
		// that also offered them would be the second place, and the two would
		// drift.
		const preferencePages = (manifest.pages || []).filter((page) =>
			/personal|preference/i.test(`${page.id} ${page.title}`),
		)
		expect(preferencePages.map((page) => page.id)).toEqual([])
	})
})
