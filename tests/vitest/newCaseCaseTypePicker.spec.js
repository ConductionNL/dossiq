// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The New case form must not offer a draft case type.
 *
 * Where the scoping has to live, and why it is not the manifest. The design
 * sketched `headerActions[new-case].fieldOverrides.caseType.filter`. Read
 * against the installed nextcloud-vue: `fieldsFromSchema` copies an override
 * onto the field object wholesale, and `CnFormDialog.fetchReferenceOptions`
 * then builds its request from `{ _limit: 100 }` plus the SCHEMA property's
 * `x-relation-filter`. It never looks at the field. A `filter` written as a
 * field override is therefore accepted by the manifest schema, shipped, and
 * ignored, with nothing logged: the silent no-op this file exists to refuse.
 *
 * So the scoping is declared on `case.caseType` in
 * `lib/Settings/dossiq_register.json`, the spelling the picker actually reads
 * and the one `status` already uses. A register property is inert until
 * `occ upgrade` runs the import, and the import fast-skips a schema whose
 * `version` has not moved, so the `case` schema version is bumped with it and
 * this file holds that bump to the property.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
import { describe, expect, it } from 'vitest'
import register from '../../lib/Settings/dossiq_register.json'
import manifest from '../../src/manifest.json'

const caseSchema = register.components.schemas.case
const caseTypeProperty = caseSchema.properties.caseType

/**
 * The `new-case` header action on the Dashboard page.
 *
 * @return {object} The manifest action entry.
 */
export function newCaseAction() {
	const dashboard = manifest.pages.find((p) => p.id === 'Dashboard')
	return dashboard.config.headerActions.find((a) => a.id === 'new-case')
}

describe('New case, case type picker', () => {
	it('scopes the picker on the schema property, where the form reads it', () => {
		expect(caseTypeProperty['x-relation-filter']).toEqual({ isDraft: false })
	})

	it('ships no field override the form would silently ignore', () => {
		const override = newCaseAction().fieldOverrides.caseType || {}
		// `label`, `widget`, `hidden`, `readOnly` and `order` are honoured.
		// `filter` is not, for a reference picker, so shipping one would claim
		// a behaviour the form does not have.
		expect(override.filter).toBeUndefined()
	})

	it('still asks for the case type on the form', () => {
		expect(newCaseAction().includeFields).toContain('caseType')
	})

	it('seeds every required property the form does not ask for', () => {
		// An `open-form` action writes straight to the object API, so a
		// required property missing from both `includeFields` and `props`
		// fails validation server-side with nothing on screen to fix.
		const action = newCaseAction()
		const asked = new Set([
			...(action.includeFields || []),
			...Object.keys(action.props || {}),
		])
		const missing = (caseSchema.required || []).filter((k) => !asked.has(k))
		expect(missing).toEqual([])
	})

	it('bumps the case schema version, or the import skips the change', () => {
		// OpenRegister fast-skips a schema whose version has not moved, so a
		// property added without a bump never reaches the instance.
		const [major, minor] = caseSchema.version.split('.').map(Number)
		expect(Number.isInteger(major)).toBe(true)
		expect(major * 1000 + minor).toBeGreaterThanOrEqual(1 * 1000 + 15)
	})
})
