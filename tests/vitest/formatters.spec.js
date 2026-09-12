/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The `caseTitle` cell formatter the Objects index reads its Case column
 * with.
 *
 * A formatter that cannot resolve its reference has two failure shapes and
 * only one of them is acceptable. Falling back to the raw uuid keeps the row
 * readable and keeps the View case action working; returning an empty string
 * would leave a blank cell that reads as "this object is on no case", which
 * is never true — `case` is required on the schema. So the fallback is
 * asserted, not assumed.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

/** The case rows the stubbed object store holds. */
let cases = []

/** The collections the formatter asked the store to fetch. */
const fetched = []

const storeStub = {
	get collections() {
		return { case: cases }
	},
	objectTypeRegistry: { case: { schema: 1, register: 1 } },
	async fetchCollection(type) {
		fetched.push(type)
	},
}

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeStub,
}))

vi.mock('../../src/store/modules/deelzaak.js', () => ({
	useDeelzaakStore: () => ({ subCaseCounts: {}, async fetchSubCaseCounts() {} }),
}))

const { default: formatters } = await import('../../src/services/formatters.js')

describe('the caseTitle formatter', () => {
	beforeEach(() => {
		cases = []
		fetched.length = 0
	})

	it('resolves a case id to its title', () => {
		cases = [{ id: 'case-1', title: 'Dormer window: Lindenstraat 8' }]
		expect(formatters.caseTitle('case-1')).toBe('Dormer window: Lindenstraat 8')
	})

	it('resolves a case carried under @self.id too', () => {
		cases = [{ '@self': { id: 'case-2' }, title: 'Tree felling permit' }]
		expect(formatters.caseTitle('case-2')).toBe('Tree felling permit')
	})

	it('falls back to the id when the case is not in the collection', () => {
		cases = [{ id: 'case-1', title: 'Dormer window: Lindenstraat 8' }]
		expect(formatters.caseTitle('case-unknown')).toBe('case-unknown')
	})

	it('shows a dash rather than an empty cell for a missing reference', () => {
		expect(formatters.caseTitle('')).toBe('-')
		expect(formatters.caseTitle(undefined)).toBe('-')
	})

	it('keeps the raw id, so the View case action still has a uuid to push', () => {
		// The Objects index resolves `{case}` off the ROW, not off the cell.
		// This asserts the formatter is a read, not a rewrite: an unresolved
		// reference must not become a label the router cannot navigate to.
		cases = []
		expect(formatters.caseTitle('case-3')).toBe('case-3')
	})
})

/**
 * The two formatters the Integrations page reads its Status and Settings
 * columns with.
 *
 * Both have a fallback that decides whether the page tells the truth. A status
 * outside the four the schema declares must render ITSELF rather than an empty
 * cell — an unnamed status is still a status the admin has to see, and a blank
 * cell reads as "nothing is wrong". The settings label must render the EMPTY
 * string when there is no destination, because that is what makes CnCellRenderer
 * fall through from an anchor to plain text and stop offering a link into a
 * section that does not exist.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
describe('the integration status formatter', () => {
	it('names each of the five states', () => {
		expect(formatters.integrationStatus('configured')).toBe('Configured')
		expect(formatters.integrationStatus('unconfigured')).toBe('Not configured')
		expect(formatters.integrationStatus('unavailable')).toBe('Not available')
		expect(formatters.integrationStatus('simulated')).toBe('Simulated')
		expect(formatters.integrationStatus('error')).toBe('Error')
	})

	// Simulated is the state the page did not have and needed. A seam bound to
	// a mock adapter answers, succeeds and returns an id, so it is neither
	// unavailable nor configured, and rendering it as either is the claim this
	// page exists to stop the app from making.
	it('does not let a mock adapter read as a configured channel', () => {
		expect(formatters.integrationStatus('simulated')).not.toBe(
			formatters.integrationStatus('configured'),
		)
		expect(formatters.integrationStatus('simulated')).not.toBe(
			formatters.integrationStatus('unavailable'),
		)
	})

	it('renders an unknown value as itself, not as an empty cell', () => {
		expect(formatters.integrationStatus('degraded')).toBe('degraded')
	})

	it('renders a missing value as empty rather than as the word undefined', () => {
		expect(formatters.integrationStatus(undefined)).toBe('')
		expect(formatters.integrationStatus(null)).toBe('')
	})
})

describe('the integration settings-link formatter', () => {
	it('labels a link when there is somewhere to go', () => {
		expect(
			formatters.integrationSettingsLabel(
				'/settings/admin/dossiq#section-stuf',
			),
		).toBe('Open settings')
	})

	it('offers nothing when the connection has no settings section', () => {
		expect(formatters.integrationSettingsLabel('')).toBe('')
		expect(formatters.integrationSettingsLabel(undefined)).toBe('')
		expect(formatters.integrationSettingsLabel(null)).toBe('')
	})
})
