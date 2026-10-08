// @vitest-environment jsdom
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
 * The Integrations page reads its Status and Settings columns with the
 * `connectionStatus` and `connectionSettingsLabel` formatters that
 * nextcloud-vue ships built in. CnAppRoot spreads the app's own registry OVER
 * the built-ins, so a local formatter under either name silently wins. A copy
 * that predates `disabled` would show the raw word on a switched-off
 * connection. This builds the registry the way CnAppRoot does and asks it.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
describe('the connection formatters the Integrations page reads', () => {
	it('come from the library, so a switched-off connection reads Switched off', async () => {
		const { BUILT_IN_FORMATTERS } =
			await import('@conduction/nextcloud-vue/src/utils/builtInFormatters.js')
		const registry = { ...BUILT_IN_FORMATTERS, ...formatters }

		expect(registry.connectionStatus('disabled')).toBe('Switched off')
		expect(registry.connectionSettingsLabel('')).toBe('')
	})
})
