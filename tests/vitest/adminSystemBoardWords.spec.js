/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Systeem tab reads as DqBeheerinstellingen: each card's line under its
 * title and its field labels in the board's words, the fields the board
 * leaves out behind the card's Geavanceerd disclosure rather than gone.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	aiFields,
	backgroundAccountFields,
	consultationFields,
	menuStructureFields,
	sharedMailboxFields,
} from '../../src/views/settings/adminFields.js'

const ROOT = path.resolve(__dirname, '../..')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const admin = fs.readFileSync(path.join(ROOT, 'src', 'views', 'settings', 'AdminRoot.vue'), 'utf8')

/**
 * Translate to Dutch, as the page does; a missing key stays itself.
 *
 * @param {string} app The app id.
 * @param {string} text The English key.
 * @param {object} [vars] Placeholders.
 * @return {string} The Dutch text.
 */
function t(app, text, vars = {}) {
	return (nl[text] ?? text).replace(/\{(\w+)\}/g, (_, name) => vars[name] ?? `{${name}}`)
}

/**
 * The Dutch description of one card on the page, read from AdminRoot.
 *
 * @param {string} id The card's id.
 * @return {string} The description.
 */
function description(id) {
	const card = admin.slice(admin.indexOf(`id="${id}"`))
	const key = /:description="\s*t\(\s*'dossiq',\s*'((?:[^'\\]|\\.)*)'/.exec(card)[1].replace(/\\'/g, "'")
	return t('dossiq', key)
}

const byKey = (fields) => Object.fromEntries(fields.map((field) => [field.key, field]))

describe('the Systeem cards in the board\'s words', () => {
	it.each([
		['section-menu-structure', 'Hoeveel het menu laat zien. Eenvoudig is de standaard.'],
		['section-background-service-account', 'Het account waarmee Dossiq werkt als er niemand is ingelogd, zoals bij termijnbewaking en mail ophalen.'],
		['section-mailbox', 'Mail aan de gedeelde mailbox komt binnen als zaak of bij een bestaande zaak.'],
		['section-ai', 'Samenvatten, classificeren en anonimiseren met een taalmodel dat jij kiest.'],
		['section-consultation', 'Standaarden voor adviesvragen aan andere afdelingen en organisaties.'],
		['section-search-index', 'Zaken, documenten en contacten in de zoekbalk van Dossiq.'],
	])('%s reads "%s"', (id, text) => {
		expect(description(id)).toBe(text)
	})

	it('names the menu choice Menu, each option by what it holds', () => {
		const [menu] = menuStructureFields(t)
		expect(menu.label).toBe('Menu')
		expect(menu.hideLabel).not.toBe(true)
		expect(menu.options.map((option) => option.description)).toEqual([
			'Dashboard, Mijn werk, zaken en relaties',
			'Ook rapportages, beheer en elke module',
		])
	})

	it('says under the user name which group the account joins', () => {
		const field = byKey(backgroundAccountFields(t)).userId
		expect(field.hint).toBe('Het account wordt lid van de groep dossiq-background-service. Kies een account waarmee niemand inlogt.')
		expect(admin.slice(admin.indexOf('id="section-background-service-account"'), admin.indexOf(':fields="backgroundAccountFields"'))).not.toContain(':detailedDescription')
	})

	it('labels the mailbox fields as the board, with the explanation behind Geavanceerd', () => {
		const fields = byKey(sharedMailboxFields(t))
		// Handed over as its key: the section translates it once (Folder, Map), not twice (Kaart).
		expect(fields.email_imap_folder.label).toBe('Folder')
		expect(t('dossiq', fields.email_imap_folder.label)).toBe('Map')
		expect(fields.email_poll_interval.label).toBe('Ophalen elke (seconden)')
		expect(fields.email_poll_batch_size.label).toBe('Berichten per keer')
		expect(fields.email_fallback_case_type.label).toBe('Zaaktype voor mail die niemand oppakt')
		expect(fields.email_fallback_case_type.placeholder).toBe('In de mailbox laten staan')
		expect(fields.email_case_matching_enabled.label).toBe('Mail op zaaknummer aan zaken koppelen')
		expect(fields['mailbox-explanation'].advanced).toBe(true)
	})

	it('words the AI card as the board and keeps the features behind Geavanceerd', () => {
		const fields = aiFields(t)
		const keyed = byKey(fields)
		expect(keyed.ai_enabled.label).toBe('AI-ondersteunde verwerking aanzetten')
		expect(keyed.ai_model_type.label).toBe('Soort model')
		expect(keyed.ai_model_type.options.map((option) => option.description)).toEqual([
			'Gegevens blijven op je eigen servers',
			'Een aanbieder buiten je organisatie',
		])
		expect(keyed.ai_model_url.label).toBe('Adres van het model')
		expect(keyed['heading-model']).toBeUndefined()
		for (const key of ['ai_feature_classification', 'ai_feature_summary', 'ai_pii_stripping', 'ai_dpia_acknowledged']) {
			expect(keyed[key].advanced).toBe(true)
		}
		expect(keyed['dpia-warning'].advanced).toBeUndefined()
	})

	it('labels the advice fields as the board, the rest behind Geavanceerd', () => {
		const fields = byKey(consultationFields(t))
		expect(fields.consultation_default_deadline_days.label).toBe('Standaardtermijn in dagen')
		expect(fields.consultation_warning_offset_days.label).toBe('Waarschuwen, dagen vooraf')
		expect(fields.consultation_external_response_url.label).toBe('Adres voor externe reacties')
		expect(fields.consultation_external_response_url.pattern).toBe('^https://')
		expect(fields.consultation_bottleneck_threshold.advanced).toBe(true)
	})

	it.each(['section-mailbox', 'section-consultation'])('%s saves with a plain Opslaan', (id) => {
		const card = admin.slice(admin.indexOf(`id="${id}"`))
		const end = card.indexOf('/>')
		expect(card.slice(0, end)).toContain(":saveLabel=\"t('dossiq', 'Save')\"")
	})
})
