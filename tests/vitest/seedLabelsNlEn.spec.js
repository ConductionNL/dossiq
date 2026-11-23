/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case types the demo shows, and their statuses, carry Dutch and English
 * labels in the register seed, so OpenRegister answers each reader in their
 * language and a re-import keeps the English (the board's English, e.g.
 * "Received", "Complaint handling").
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const objectsOf = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, 'lib', 'Settings', file), 'utf8')).components.objects

describe('seeded case type and status labels in nl and en', () => {
	const files = {
		'dossiq_register.json': ['klacht-behandeling', 'omgevingsvergunning', 'subsidieaanvraag'],
		'register.d/85-permits.json': ['parkeervergunning-bewoners'],
		'register.d/81-woo-verzoek.json': ['woo-verzoek'],
		'register.d/81-woo-verzoek-version-2.json': ['woo-verzoek-v2'],
	}

	it('gives every such case type a title in both languages', () => {
		for (const [file, slugs] of Object.entries(files)) {
			for (const slug of slugs) {
				const type = objectsOf(file).find((row) => row['@self']?.schema === 'caseType' && row['@self']?.slug === slug)
				expect(type, slug).toBeDefined()
				expect(Object.keys(type.title).sort(), slug).toEqual(['en', 'nl'])
			}
		}
	})

	it('names their statuses in both languages', () => {
		const ontvangen = objectsOf('register.d/85-permits.json').find((row) => row['@self']?.slug === 'parkeervergunning-bewoners-ontvangen')
		expect(ontvangen.name).toEqual({ nl: 'Ontvangen', en: 'Received' })
		expect(ontvangen.publicLabel).toEqual({ nl: 'Ontvangen', en: 'Received' })
	})
})
