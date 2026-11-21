/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The example set's access link on the Lindelaan case, the row the Toegang
 * card on the simple People tab draws (people-tab-access-card task 2.1).
 *
 * @spec openspec/changes/people-tab-access-card/specs/case-page/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const SETTINGS = path.resolve(__dirname, '../../lib/Settings')
const read = (file) => JSON.parse(fs.readFileSync(path.join(SETTINGS, file), 'utf8'))

const fragment = read('example-sets/zuiddrecht.d/67-lindelaan-access-link.json')
const link = fragment.objects.find((object) => object.key === 'woo-fietspad-besluitklaar-link-sanne')
const caseShare = read('dossiq_register.json').components.schemas.caseShare
const linkFragment = read('register.d/39-case-access-links.json').components.schemas.caseShare

describe('the Lindelaan access link example', () => {
	it('is a link share on the open Lindelaan Woo request', () => {
		const cases = read('example-sets/zuiddrecht.json').objects
		const lindelaan = cases.find((object) => object.key === 'woo-fietspad-besluitklaar')
		expect(lindelaan.data.title).toBe('Openbare verlichting fietspad Lindelaan')
		expect(link.schema).toBe('caseShare')
		expect(link.data.caseId).toBe('{{object:woo-fietspad-besluitklaar.id}}')
		expect(link.data.shareType).toBe('link')
	})

	it('is read only, for Sanne de Vries, made by the case assignee', () => {
		const cases = read('example-sets/zuiddrecht.json').objects
		const lindelaan = cases.find((object) => object.key === 'woo-fietspad-besluitklaar')
		expect(link.data.label).toBe('Sanne de Vries')
		expect(link.data.capabilities).toBe('read')
		expect(link.data.status).toBe('active')
		expect(link.data.createdBy).toBe(lindelaan.data.assignee)
	})

	it('expires after the day the set is loaded, so it reads as a live link', () => {
		const match = /^\{\{datetime:(\d+) \d{2}:\d{2}\}\}$/.exec(link.data.expiresAt)
		expect(match).not.toBeNull()
		expect(Number(match[1])).toBeGreaterThan(0)
	})

	it('writes only fields the caseShare schema declares, with its required ones', () => {
		const declared = { ...caseShare.properties, ...linkFragment.properties }
		expect(Object.keys(link.data).filter((field) => !(field in declared))).toEqual([])
		for (const field of caseShare.required) {
			expect(link.data[field]).toBeTruthy()
		}
		expect(linkFragment.properties.shareType.enum).toContain('link')
		expect(linkFragment.properties.status.enum).toContain(link.data.status)
	})
})
