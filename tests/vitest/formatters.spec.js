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

describe('the case type list formatters', () => {
	it('processingTime adds the extension a case type allows, and only then', () => {
		expect(
			formatters.processingTime('P56D', {
				extensionAllowed: true,
				extensionPeriod: 'P42D',
			}),
		).toBe('8 weeks, +6')
		expect(
			formatters.processingTime('P56D', {
				extensionAllowed: false,
				extensionPeriod: 'P42D',
			}),
		).toBe('8 weeks')
		expect(formatters.processingTime('P56D', {})).toBe('8 weeks')
		expect(formatters.processingTime('P56D')).toBe('8 weeks')
		expect(formatters.processingTime('', { extensionPeriod: 'P14D' })).toBe('')
	})

	it('processingTime writes whole weeks as weeks, and the extension in the same unit as a bare number (DqZaaktypen)', () => {
		expect(formatters.processingTime('P28D', { extensionPeriod: 'P14D' })).toBe('4 weeks, +2')
		expect(formatters.processingTime('P1M', { extensionPeriod: 'P2M' })).toBe('1 month, +2')
		expect(formatters.processingTime('P13W')).toBe('13 weeks')
		expect(formatters.processingTime('P10D')).toBe('10 days')
		expect(formatters.processingTime('P56D', { extensionPeriod: 'P1M' })).toBe('8 weeks, +1 month')
		expect(formatters.processingTime('P56D', { extensionPeriod: 'P10D' })).toBe('8 weeks, +10 days')
		expect(formatters.processingTime('Termijn van de rechtbank', { extensionPeriod: 'P14D' })).toBe('Termijn van de rechtbank, +2 weeks')
	})

	it('isoDuration puts a statutory term in words and leaves other text alone', () => {
		expect(formatters.isoDuration('P56D')).not.toBe('P56D')
		expect(formatters.isoDuration('Termijn van de rechtbank')).toBe(
			'Termijn van de rechtbank',
		)
		expect(formatters.isoDuration('')).toBe('')
	})

	it('versionLabel writes the version as a phrase and nothing for no version', () => {
		expect(formatters.versionLabel(3)).toBe('Version 3')
		expect(formatters.versionLabel(null)).toBe('')
	})

	it('caseTypeCount writes a number of case types as a phrase and nothing when unknown (DqCatalogus "3 zaaktypen")', () => {
		expect(formatters.caseTypeCount(1)).toBe('%n case type')
		expect(formatters.caseTypeCount(3)).toBe('%n case types')
		expect(formatters.caseTypeCount(0)).toBe('%n case types')
		expect(formatters.caseTypeCount(null)).toBe('')
		expect(formatters.caseTypeCount('x')).toBe('')
	})

	it('caseCount writes a number of cases as a phrase and nothing for a non-count', () => {
		// The l10n stub picks the form and leaves `%n` to the real library.
		expect(formatters.caseCount(1)).toBe('%n case')
		expect(formatters.caseCount(2)).toBe('%n cases')
		expect(formatters.caseCount(null)).toBe('%n cases')
		expect(formatters.caseCount('x')).toBe('')
	})

	it('draftState says Draft only for a draft, and Published for everything else', () => {
		expect(formatters.draftState(true)).toBe('Draft')
		expect(formatters.draftState(false)).toBe('Published')
		expect(formatters.draftState(undefined)).toBe('Published')
	})
})

describe('the requesterName formatter', () => {
	it('names who asked, from the name the case carries', () => {
		expect(
			formatters.requesterName('', {
				initiatorDisplayName: 'Repetitie Inwoner',
			}),
		).toBe('Repetitie Inwoner')
		expect(
			formatters.requesterName('person-1', {
				initiatorDisplayName: ' CloudGemeente B.V. ',
			}),
		).toBe('CloudGemeente B.V.')
	})

	it('is empty when the case names nobody, so the field reads as empty', () => {
		expect(formatters.requesterName('', {})).toBe('')
		expect(formatters.requesterName(undefined, undefined)).toBe('')
	})

	it('is what the case page shows under Requester', async () => {
		const { readFileSync } = await import('fs')
		const { resolve } = await import('path')
		const manifest = JSON.parse(
			readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'),
		)
		const found = []
		const walk = (node) => {
			if (Array.isArray(node)) {
				node.forEach(walk)
			} else if (node && typeof node === 'object') {
				if (node.id === 'case-core' && node.type === 'data') {
					found.push(node.content.overrides.requester)
				}
				Object.values(node).forEach(walk)
			}
		}
		walk(manifest)
		expect(found).toHaveLength(1)
		expect(found[0].formatter).toBe('requesterName')
	})
})

describe('the catalogue formatters', () => {
	// The test l10n answers the plural template unfilled, as for caseCount above.
	it('reads a property count as a phrase and leaves a type without a schema empty', () => {
		expect(formatters.propertyCount(9)).toBe('%n properties')
		expect(formatters.propertyCount(1)).toBe('%n property')
		expect(formatters.propertyCount(null)).toBe('')
	})

	it('names the views an object type supports', () => {
		expect(formatters.objectViews(['map', 'table'])).toBe('Map, Table')
		expect(formatters.objectViews(['table'])).toBe('Table')
		expect(formatters.objectViews([])).toBe('')
		expect(formatters.objectViews(null)).toBe('')
	})
})
