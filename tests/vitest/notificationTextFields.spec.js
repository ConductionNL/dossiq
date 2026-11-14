/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An administrator edits the text of each notification (REQ-CRS-13, row
 * 11.24). The admin settings carry one field per notification kind, keyed as
 * the settings service stores it and the Notifier reads it.
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/admin-settings/spec.md#requirement-an-administrator-edits-the-text-of-each-notification-req-crs-13
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { notificationTextFields } from '../../src/views/settings/adminFields.js'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Translate stub that marks what went through it.
 *
 * @param {string} app The app id.
 * @param {string} text The source text.
 * @return {string} The marked text.
 */
function t(app, text) {
	return `[${app}] ${text}`
}

/**
 * The keys ConfigKeys::NOTIFICATION_TEXT_KEYS lists, read from the PHP source.
 *
 * @return {Array<string>} The keys.
 */
function phpTextKeys() {
	const source = fs.readFileSync(path.join(ROOT, 'lib', 'Service', 'Settings', 'ConfigKeys.php'), 'utf8')
	const block = source.slice(source.indexOf('public const NOTIFICATION_TEXT_KEYS = ['))
	const list = block.slice(0, block.indexOf('];'))
	return [...list.matchAll(/'(notification_text_[a-z_]+)'/g)].map((match) => match[1])
}

describe('the notification texts section', () => {
	it('offers one text field per key the settings service stores', () => {
		const fields = notificationTextFields(t).filter((field) => field.type === 'textarea')
		const keys = phpTextKeys()
		expect(keys.length).toBeGreaterThan(20)
		expect(fields.map((field) => field.key)).toEqual(keys)
	})

	it('labels every field with a translated kind and says what empty means', () => {
		const fields = notificationTextFields(t)
		expect(fields[0]).toMatchObject({ type: 'note', label: '[dossiq] Notification texts' })
		expect(fields[0].text).toContain('Empty keeps the standard text.')
		for (const field of fields.slice(1)) {
			expect(field.label.startsWith('[dossiq] ')).toBe(true)
		}
		expect(fields.find((field) => field.key === 'notification_text_case_role_notified').label)
			.toBe('[dossiq] A case you handle needs your attention')
	})

	it('is placed on the admin page as its own section', () => {
		const admin = fs.readFileSync(path.join(ROOT, 'src', 'views', 'settings', 'AdminRoot.vue'), 'utf8')
		expect(admin).toMatch(/id="section-notification-texts"[\s\S]*?:fields="notificationTextFields"/)
	})
})
