/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTenant's Status card names the organisation's package (Pakket Standaard)
 * and its contract reference (TEN-0014), read from the organisation's
 * subscription record. Its status, start and slug stay the organisation's.
 *
 * @spec openspec/changes/tenant-subscription/specs/tenant-subscription/spec.md#requirement-a-tenant-has-a-subscription-with-a-package-and-a-reference
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const config = read('src/manifest.d/67-environment-organisations.json').pages.find((entry) => entry.id === 'EnvironmentOrganisationDetail').config
const status = config.sideColumn.find((entry) => entry.id === 'organisation-side-status').content
const nl = read('l10n/nl.json').translations

describe('organisation subscription (DqTenant)', () => {
	it('reads the subscription of this organisation', () => {
		expect(status.source).toEqual({ register: 'dossiq', schema: 'tenantSubscription', filter: { tenantRef: '@objectId' } })
	})

	it('names the package and the contract reference', () => {
		const package_ = status.fields.find((field) => field.path === 'packageName')
		const reference = status.fields.find((field) => field.path === 'reference')
		expect(nl[package_.label]).toBe('Pakket')
		expect(nl[reference.label]).toBe('Kenmerk van het contract')
		expect(package_.from).toBeUndefined()
	})

	it('keeps the organisation\'s own facts on the organisation', () => {
		status.fields.filter((field) => ['status', 'created', 'slug'].includes(field.path)).forEach((field) => {
			expect(field.from).toBe('object')
		})
		expect(status.fields.find((field) => field.as === 'line').from).toBe('object')
	})
})
