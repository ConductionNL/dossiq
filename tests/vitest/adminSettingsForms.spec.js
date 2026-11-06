/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin forms that are declared as fields (CnSettingsFieldsSection): each
 * writes the key the server stores, in the form the server reads it, and the
 * page declares each one where its form used to be.
 *
 * @spec openspec/specs/admin-settings/spec.md
 * @spec openspec/specs/nav-dedup-and-grouping/spec.md#REQ-PNDG-007
 * @spec openspec/specs/archief-edepot-handover/spec.md
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { STRUCTURE_SETTING } from '../../src/utils/structureProfile.js'
import {
	aiFields,
	archivalFields,
	backgroundAccountFields,
	consultationFields,
	contactCentreFields,
	DEFAULT_CASE_PATTERN,
	ISO_DURATION_PATTERN,
	menuStructureFields,
	paymentCallbackFields,
	queueUrgencyFields,
	reviewReportFields,
	sharedMailboxFields,
	storeRegistryFields,
	workDigestFields,
} from '../../src/views/settings/adminFields.js'

const ROOT = path.resolve(__dirname, '../..')
const configKeys = fs.readFileSync(
	path.join(ROOT, 'lib', 'Service', 'Settings', 'ConfigKeys.php'),
	'utf8',
)
const userSettings = fs.readFileSync(
	path.join(ROOT, 'src', 'views', 'settings', 'UserSettings.vue'),
	'utf8',
)
const adminRoot = fs.readFileSync(
	path.join(ROOT, 'src', 'views', 'settings', 'AdminRoot.vue'),
	'utf8',
)

/**
 * The translate the fields call.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @return {string} The text.
 */
function t(app, text) {
	return text
}

/**
 * The fields of a list by key.
 *
 * @param {Array<object>} fields The fields.
 * @return {Record<string, object>} The fields by key.
 */
function byKey(fields) {
	return Object.fromEntries(fields.map((field) => [field.key, field]))
}

const VALUELESS = ['heading', 'status', 'action']

describe('admin forms saved through dossiq settings', () => {
	const sections = {
		menuStructureFields,
		queueUrgencyFields,
		consultationFields,
		reviewReportFields,
		paymentCallbackFields,
		contactCentreFields,
	}

	it.each(
		Object.entries(sections).flatMap(([name, build]) => build(t)
			.filter((field) => !VALUELESS.includes(field.type))
			.map((field) => [name, field.key])),
	)('%s: %s is a key the settings endpoint saves', (name, key) => {
		expect(configKeys).toContain(`'${key}',`)
	})

	it.each(Object.keys(sections))('%s is declared on the admin page', (name) => {
		expect(adminRoot).toContain(`:fields="${name}"`)
	})
})

describe('the menu structure', () => {
	it('chooses between the simple and the full menu under the boot code\'s key', () => {
		const [field] = menuStructureFields(t)
		expect(field.key).toBe(STRUCTURE_SETTING)
		expect(field.type).toBe('radio')
		expect(field.default).toBe('simple')
		expect(field.options.map((option) => option.value)).toEqual(['simple', 'full'])
		expect(field.options.every((option) => option.description)).toBe(true)
	})

	it('saves each choice at once and says when people see it', () => {
		const section = adminRoot.slice(adminRoot.indexOf('id="section-menu-structure"'))
		expect(section.slice(0, section.indexOf('/>'))).toContain(':autoSave="true"')
		expect(section.slice(0, section.indexOf('/>'))).toContain('People see the change the next time they open Dossiq.')
	})
})

describe('the queue urgency', () => {
	const fields = byKey(queueUrgencyFields(t))

	it('writes the four app config keys with the server bounds and defaults', () => {
		expect(fields.queue_critical_days).toMatchObject({ type: 'number', integer: true, min: 0, max: 60, default: 3 })
		expect(fields.queue_warning_days).toMatchObject({ type: 'number', integer: true, min: 0, max: 120, default: 7 })
		expect(fields.queue_priority_weight).toMatchObject({ type: 'number', integer: false, min: 0, max: 50, default: 10 })
		expect(fields.queue_idle_weight).toMatchObject({ type: 'number', integer: false, min: 0, max: 1.5, default: 0.5 })
	})
})

describe('the consultation behaviour', () => {
	it('shows the defaults the server uses for an unset value', () => {
		const consultation = byKey(consultationFields(t))
		expect(consultation.consultation_default_deadline_days.default).toBe(28)
		expect(consultation.consultation_warning_offset_days.default).toBe(5)
		expect(consultation.consultation_bottleneck_threshold).toMatchObject({ min: 0, max: 1, default: 0.2 })
	})
})

describe('the review reports (REQ-WRR-001)', () => {
	const fields = byKey(reviewReportFields(t))

	it('has both switches off unless the server stored an explicit yes, and saves them as true or false', () => {
		for (const key of ['reviewerThroughputReport', 'collectionPartiesReport']) {
			expect(fields[key]).toMatchObject({ type: 'switch', trueValue: 'true', falseValue: 'false' })
			expect(fields[key].default).toBeUndefined()
		}
	})

	it('names the reader group the server checks before the throughput switch goes on', () => {
		expect(fields.reviewerThroughputReaders.label).toBe('Reader group for the throughput report')
	})
})

describe('the payment callback', () => {
	const fields = byKey(paymentCallbackFields(t))

	it('shows the secret back to the administrator who hands it on', () => {
		expect(fields.payout_callback_secret.type).toBe('password')
	})

	it('warns while no secret is stored', () => {
		expect(fields['callback-secret-missing']).toMatchObject({ type: 'status', tone: 'warning', visibleWhen: { key: 'payout_callback_secret', empty: true } })
	})

	it('generates a 32-byte secret into the field', () => {
		expect(fields['generate-callback-secret']).toMatchObject({ type: 'action', fill: { key: 'payout_callback_secret', bytes: 32 }, placement: 'save' })
	})
})

describe('the store registry', () => {
	const fields = byKey(storeRegistryFields(t))

	it('keeps the token write-only and says whether one is stored', () => {
		expect(fields.registryToken).toMatchObject({ type: 'secret', setKey: 'registryTokenSet' })
	})

	it('reads and saves on the store settings endpoint', () => {
		expect(adminRoot).toContain('url="/apps/dossiq/api/store/settings"')
		expect(fields.registryRegister.default).toBe('dossiq')
	})
})

describe('archiving', () => {
	const [field] = archivalFields(t)
	const iso = new RegExp(ISO_DURATION_PATTERN)

	it('writes OpenRegister\'s reminder interval on its own endpoint', () => {
		expect(field.key).toBe('reviewReminderFrequency')
		expect(adminRoot).toContain('url="/apps/openregister/api/settings/archival"')
	})

	it('refuses a value that is not an ISO 8601 duration', () => {
		expect(['P7D', 'P1M', 'PT12H', 'P1Y2M3DT4H'].every((value) => iso.test(value))).toBe(true)
		expect(['', 'P', '7D', 'PT', 'weekly'].some((value) => iso.test(value))).toBe(false)
		expect(field.required).toBe(true)
	})
})

describe('the KCC bridge', () => {
	const fields = byKey(contactCentreFields(t))

	it('edits the trigger words one per line and stores them as JSON text', () => {
		expect(fields.sentiment_trigger_words).toMatchObject({ type: 'lines', saveAs: 'json' })
	})

	it('offers the three identification methods, both by default', () => {
		expect(fields.identification_method.options.map((option) => option.value)).toEqual(['digid', 'bsn_questions', 'both'])
		expect(fields.identification_method.default).toBe('both')
	})

	it('keeps the save button the e2e presses', () => {
		expect(adminRoot).toContain("t('dossiq', 'Save KCC settings')")
	})
})

describe('the background service account', () => {
	const fields = byKey(backgroundAccountFields(t))

	it('reads and saves on its own endpoint, and reads again for the warning', () => {
		const section = adminRoot.slice(adminRoot.indexOf('id="section-background-service-account"'))
		const tag = section.slice(0, section.indexOf('/>'))
		expect(tag).toContain('url="/apps/dossiq/api/settings/background-service-account"')
		expect(tag).toContain(':reloadAfterSave="true"')
		expect(tag).toContain('Background jobs now save as {userId}.')
	})

	it('puts Use this account beside the user name, as DqBeheerinstellingen draws it', () => {
		const section = adminRoot.slice(adminRoot.indexOf('id="section-background-service-account"'))
		expect(section.slice(0, section.indexOf('/>'))).toContain(':saveInline="true"')
	})

	it('names what is wrong with an unusable account, only while it is unusable', () => {
		expect(fields.reason).toMatchObject({ type: 'status', tone: 'warning', visibleWhen: { key: 'usable', equals: false } })
		expect(Object.keys(fields.reason.textMap)).toEqual(['unknown', 'disabled', 'not-in-group', '*'])
		expect(Object.values(fields.reason.textMap).every((text) => text.endsWith('nothing is saved.'))).toBe(true)
	})
})

describe('AI-assisted processing', () => {
	const fields = aiFields(t)
	const keyed = byKey(fields)

	it('draws a switch the server did not report as on as off: no switch defaults to on', () => {
		for (const field of fields.filter((entry) => entry.type === 'switch')) {
			expect(field.default).toBeUndefined()
		}
	})

	it('shows everything after the global switch only while it is on', () => {
		for (const field of fields.slice(1)) {
			const conditions = [].concat(field.visibleWhen)
			expect(conditions).toContainEqual({ key: 'ai_enabled', equals: true })
		}
	})

	it('asks a key and warns only for a cloud model', () => {
		expect(keyed.ai_api_key).toMatchObject({ type: 'secret', setKey: 'ai_api_key_set' })
		expect(keyed.ai_api_key.visibleWhen).toContainEqual({ key: 'ai_model_type', equals: 'cloud' })
		expect(keyed['cloud-warning'].visibleWhen).toContainEqual({ key: 'ai_model_type', equals: 'cloud' })
	})

	it('warns until the DPIA is acknowledged', () => {
		expect(keyed['dpia-warning'].visibleWhen).toContainEqual({ key: 'ai_dpia_acknowledged', equals: false })
	})

	it('tests the connection and reports a failure as an error', () => {
		expect(keyed['test-connection']).toMatchObject({ type: 'action', url: '/apps/dossiq/api/ai/health', okPath: 'healthy' })
	})
})

describe('the daily digest', () => {
	const fields = byKey(workDigestFields(t))

	it('sends the switch and the hour together, as the endpoint replaces both', () => {
		expect(userSettings).toContain(':saveAll="true"')
		expect(userSettings).toContain(':autoSave="true"')
		expect(userSettings).toContain(':bare="true"')
	})

	it('asks the hour only while the digest is on', () => {
		expect(fields.hour).toMatchObject({ type: 'select', visibleWhen: { key: 'enabled', equals: true } })
	})

	it('offers the 24 hours as a select, as the board draws "08.00 uur"', () => {
		expect(fields.hour.options).toHaveLength(24)
		expect(fields.hour.options[8]).toEqual({ value: 8, label: '{hour}:00' })
	})

	it('sets a section description 16px above its content (24px gap less 8)', () => {
		const rule = userSettings.slice(userSettings.indexOf('.dossiq-user-settings__intro {'), userSettings.indexOf('}', userSettings.indexOf('.dossiq-user-settings__intro {')))
		expect(rule).toContain('margin-block: 0 -8px;')
	})

	it('names who decided the setting', () => {
		expect(Object.keys(fields.source.textMap)).toEqual(['user-override', 'group-default', 'schema-default', '*'])
	})
})

describe('the shared mailbox', () => {
	const fields = sharedMailboxFields(t)
	const keyed = byKey(fields)
	const matchingUrl = '/apps/dossiq/api/settings/email-case-matching/instance'

	it('saves the intake keys on the mailbox endpoint, all of them at once', () => {
		const section = adminRoot.slice(adminRoot.indexOf('id="section-mailbox"'))
		const tag = section.slice(0, section.indexOf('/>'))
		expect(tag).toContain('url="/apps/dossiq/api/settings/email"')
		expect(tag).toContain(':saveAll="true"')
		expect(tag).toContain(':hintsToggle="true"')
	})

	it('saves matching on its own endpoint, which checks the pattern, as yes or no', () => {
		expect(keyed.email_case_matching_enabled).toMatchObject({ url: matchingUrl, saveMethod: 'PUT', trueValue: 'yes', falseValue: 'no' })
		expect(keyed.email_case_matching_pattern).toMatchObject({ url: matchingUrl, saveMethod: 'PUT' })
	})

	it.each(['email_from_address', 'email_from_name', 'email_recipient_allowlist'])('saves %s through dossiq settings, which stores it', (key) => {
		expect(keyed[key]).toMatchObject({ url: '/apps/dossiq/api/settings', valuePath: `config.${key}`, saveMethod: 'PUT' })
		expect(configKeys).toContain(`'${key}',`)
	})

	it('offers the Mail accounts and warns when Mail is missing', () => {
		expect(keyed.email_mail_account_id).toMatchObject({ optionsUrl: '/apps/dossiq/api/settings/email/mail-accounts', optionsPath: 'accounts', optionDetail: 'email' })
		expect(keyed['mail-app-missing']).toMatchObject({ type: 'status', tone: 'warning', valuePath: 'available' })
	})

	it('shows the board\'s six fields first and the rest behind Advanced, without losing one', () => {
		// DqBeheerinstellingen Zaak-e-mail: account, folder, interval, batch
		// size, the case type for unclaimed mail and the case-number switch.
		const inputs = fields.filter((field) => field.type !== 'status')
		expect(inputs.filter((field) => !field.advanced).map((field) => field.key)).toEqual([
			'email_mail_account_id',
			'email_imap_folder',
			'email_poll_interval',
			'email_poll_batch_size',
			'email_fallback_case_type',
			'email_case_matching_enabled',
		])
		expect(inputs.filter((field) => field.advanced).map((field) => field.key)).toEqual([
			'email_transport',
			'email_case_matching_pattern',
			'email_from_address',
			'email_from_name',
			'email_recipient_allowlist',
			'email_intake_role',
			'email_intake_blocklist',
			'email_intake_junk_rules',
		])
	})

	it('names the default case number pattern as the server has it', () => {
		const recognizer = fs.readFileSync(path.join(ROOT, 'lib', 'Service', 'Email', 'CaseNumberRecognizer.php'), 'utf8')
		expect(recognizer).toContain(`DEFAULT_PATTERN = '${DEFAULT_CASE_PATTERN}'`)
	})
})

describe('the AI card layout', () => {
	it('keeps a switch or a choice on a row of its own in the two-column card', async () => {
		const { aiFields } = await import('../../src/views/settings/adminFields.js')
		const fields = aiFields((app, text) => text)
		for (const field of fields.filter((f) => f.type === 'switch' || f.type === 'radio')) {
			expect(field.wide, field.key).toBe(true)
		}
		expect(fields.find((f) => f.key === 'ai_model_url').wide).toBeUndefined()
	})
})
