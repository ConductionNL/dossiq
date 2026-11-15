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
		// Filling CnAppRoot's #user-settings-sections slot is what puts the
		// sections in the dialog, beside the library's own.
		expect(app).toContain('<template #user-settings-sections>')
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
		'SubstitutionSettings',
		'CaseEmailMatchSettings',
		'NotificationRoutingSettings',
	])('keeps a home for %s in the dialog', (section) => {
		expect(userSettings).toContain(`<${section} />`)
	})

	it('keeps a home for the daily digest in the dialog, declared as fields', () => {
		expect(userSettings).toContain(':fields="workDigestFields"')
		expect(userSettings).toContain('url="/apps/dossiq/api/personal-queue/digest"')
	})

	it('keeps the library sections beside its own, except the notification pane it replaces', () => {
		// The credentials, the guided tour and the integrations are the
		// library's sections; dossiq's Notices section is the screen over the
		// notification preferences, so the library's pane stays out.
		expect(app).toContain(
			"return ['views', 'app', 'credentials', 'walkthrough', 'integrations']",
		)
		expect(app).toContain(':userSettingsSections="userSettingsSections"')
		expect(userSettings).not.toContain('<CnCredentials')
		expect(userSettings).not.toContain('id="cn-walkthrough"')
	})

	it('gives every section a nav icon', () => {
		const sections = userSettings.match(/<NcAppSettingsSection/g) ?? []
		const icons = userSettings.match(/<template #icon>/g) ?? []
		expect(sections.length).toBeGreaterThan(0)
		expect(icons).toHaveLength(sections.length)
	})

	it('has no case type picker: a pinned case type is a view, added to the menu from the list', () => {
		expect(userSettings).not.toContain('menu-case-types')
	})

	it('opens with the library\'s Views in my menu, where the case type picker was (board DqPersoonlijkeInstellingen)', () => {
		// @spec openspec/specs/personal-settings-surface/spec.md#requirement-the-personal-settings-open-with-the-views-in-my-menu
		expect(app).toMatch(/return \['views', 'app',/)
	})

	it('opens on its own hash', () => {
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
