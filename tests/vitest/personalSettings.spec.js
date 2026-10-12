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
 * their own screen (substitution, linking your own mail, the work digest,
 * which notices reach you, the case types in your menu) are sections of the
 * in-app user settings dialog, drawn by `src/views/settings/UserSettings.vue`.
 * Nextcloud's personal settings carry no dossiq page (Ruben, 11 Oct 2026:
 * user configuration goes into the user settings dialog).
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
const userSettings = fs.readFileSync(
	path.join(ROOT, 'src', 'views', 'settings', 'UserSettings.vue'),
	'utf8',
)
const app = fs.readFileSync(path.join(ROOT, 'src', 'App.vue'), 'utf8')
const webpack = fs.readFileSync(path.join(ROOT, 'webpack.config.js'), 'utf8')
const infoXml = fs.readFileSync(path.join(ROOT, 'appinfo', 'info.xml'), 'utf8')

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
	it('is the user settings dialog, which App.vue fills', () => {
		// Filling CnAppRoot's #user-settings slot is what puts the sections
		// in the dialog. Without it the dialog shows the library's fallback
		// and every dossiq preference has nowhere to live.
		expect(app).toContain('<template #user-settings>')
		expect(app).toContain('<UserSettings')
	})

	it('is not a Nextcloud personal settings page', () => {
		expect(infoXml).not.toMatch(/<personal>/)
		expect(infoXml).not.toMatch(/<personal-section>/)
		expect(
			fs.existsSync(
				path.join(ROOT, 'lib', 'Settings', 'PersonalSettings.php'),
			),
		).toBe(false)
		expect(
			fs.existsSync(path.join(ROOT, 'lib', 'Sections', 'PersonalSection.php')),
		).toBe(false)
		expect(fs.existsSync(path.join(ROOT, 'src', 'personalSettings.js'))).toBe(
			false,
		)
		expect(webpack).not.toContain('personalSettings.js')
	})

	it.each([
		'MenuCaseTypesSettings',
		'SubstitutionSettings',
		'CaseEmailMatchSettings',
		'WorkDigestSettings',
		'NotificationRoutingSettings',
	])('keeps a home for %s in the dialog', (section) => {
		expect(userSettings).toContain(`<${section} />`)
	})

	it('keeps the sections the library draws when the slot is empty', () => {
		// The slot replaces CnAppRoot's fallback wholesale, credentials and
		// the walkthrough replay included, and nothing reports the loss.
		expect(userSettings).toContain('<CnCredentials')
		expect(userSettings).toContain('id="cn-walkthrough"')
		expect(app).toContain('continueWalkthroughFromSettings()')
		expect(app).toContain('restartWalkthroughFromSettings()')
	})

	it('opens on the hash the My case types pencil links to', () => {
		expect(app).toContain("const USER_SETTINGS_HASH = '#user-settings'")
		expect(app).toContain("addEventListener('hashchange'")
	})

	it('offers no second place to set a per-person preference', () => {
		// The cluster's options are chosen where the thing lives — the view
		// toggle on a list, the pin on a menu entry, the drag on a row — and
		// held against this person by `CnAppRoot`'s preference group. A page
		// that also offered them would be the second place, and the two would
		// drift.
		// The pattern was `/personal|preference/i` and matched PersonalQueue
		// (one-personal-queue, #2842), which is a WORK list and not a place to
		// set anything: it holds what is waiting on the reader across four
		// stores. `settings` keeps the guard's teeth on the page that would
		// actually be the second place, whatever it is called, without firing
		// on every page whose name starts with Personal.
		const preferencePages = (manifest.pages || []).filter((page) =>
			/preference|settings/i.test(`${page.id} ${page.title}`),
		)
		expect(preferencePages.map((page) => page.id)).toEqual([])
	})
})
