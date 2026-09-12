/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the ZGW DRC dossier helpers in src/utils/dossierHelpers.js:
 * confidentiality ordering, share-eligibility threshold, forward-only status
 * transitions, more-restrictive-only classification rule, grouping by type,
 * and byte-size formatting.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
 */

import { describe, expect, it } from 'vitest'
import {
	canShare,
	collectKeywords,
	CONFIDENTIALITY_HIERARCHY,
	confidentialityOrdinal,
	DEFAULT_DIRECTION,
	DOCUMENT_DIRECTIONS,
	documentKeywords,
	filterGroupsByKeywords,
	formatSize,
	groupByType,
	isClassificationAllowed,
	isTransitionAllowed,
} from '../../src/utils/dossierHelpers.js'

describe('confidentialityOrdinal', () => {
	it('orders the hierarchy lowest to highest', () => {
		expect(confidentialityOrdinal('openbaar')).toBe(0)
		expect(confidentialityOrdinal('intern')).toBe(2)
		expect(confidentialityOrdinal('zeer_geheim')).toBe(
			CONFIDENTIALITY_HIERARCHY.length - 1,
		)
	})

	it('fails closed on unknown levels (most restrictive)', () => {
		expect(confidentialityOrdinal('bogus')).toBe(
			CONFIDENTIALITY_HIERARCHY.length - 1,
		)
		expect(confidentialityOrdinal('')).toBe(CONFIDENTIALITY_HIERARCHY.length - 1)
	})
})

describe('canShare', () => {
	it('allows sharing below the vertrouwelijk threshold', () => {
		expect(canShare('openbaar')).toBe(true)
		expect(canShare('intern')).toBe(true)
		expect(canShare('zaakvertrouwelijk')).toBe(true)
	})

	it('forbids sharing at or above the vertrouwelijk threshold', () => {
		expect(canShare('vertrouwelijk')).toBe(false)
		expect(canShare('geheim')).toBe(false)
		expect(canShare('zeer_geheim')).toBe(false)
	})
})

describe('isTransitionAllowed', () => {
	it('permits forward transitions only', () => {
		expect(isTransitionAllowed('draft', 'final')).toBe(true)
		expect(isTransitionAllowed('final', 'archived')).toBe(true)
	})

	it('rejects reverse, skipping and self transitions', () => {
		expect(isTransitionAllowed('final', 'draft')).toBe(false)
		expect(isTransitionAllowed('archived', 'final')).toBe(false)
		expect(isTransitionAllowed('draft', 'archived')).toBe(false)
		expect(isTransitionAllowed('draft', 'draft')).toBe(false)
	})
})

describe('isClassificationAllowed', () => {
	it('permits equal or more restrictive but not less', () => {
		expect(isClassificationAllowed('intern', 'intern')).toBe(true)
		expect(isClassificationAllowed('intern', 'geheim')).toBe(true)
		expect(isClassificationAllowed('intern', 'openbaar')).toBe(false)
	})

	it('treats an empty requested level as allowed (use default)', () => {
		expect(isClassificationAllowed('intern', '')).toBe(true)
	})
})

describe('groupByType', () => {
	it('groups documents by informatieobjecttype with counts', () => {
		const groups = groupByType([
			{ id: '1', informatieobjecttype: 'Advies' },
			{ id: '2', informatieobjecttype: 'Advies' },
			{ id: '3', informatieobjecttype: 'Aanvraag' },
		])
		expect(groups).toHaveLength(2)
		const byType = Object.fromEntries(
			groups.map((g) => [g.informatieobjecttype, g.count]),
		)
		expect(byType.Advies).toBe(2)
		expect(byType.Aanvraag).toBe(1)
	})

	it('falls back to onbekend for untyped documents', () => {
		const groups = groupByType([{ id: '1' }])
		expect(groups[0].informatieobjecttype).toBe('unknown')
	})
})

describe('formatSize', () => {
	it('formats bytes, KB and MB', () => {
		expect(formatSize(512)).toBe('512 B')
		expect(formatSize(2048)).toBe('2.0 KB')
		expect(formatSize(5 * 1024 * 1024)).toBe('5.0 MB')
		expect(formatSize(undefined)).toBe('0 B')
	})
})

describe('documentKeywords', () => {
	it('returns the keywords of a tagged document', () => {
		expect(documentKeywords({ keywords: ['bezwaar', 'bouwtekening'] })).toEqual([
			'bezwaar',
			'bouwtekening',
		])
	})

	it('is empty for a document written before the property existed', () => {
		expect(documentKeywords({ title: 'Advies' })).toEqual([])
		expect(documentKeywords({ keywords: null })).toEqual([])
		expect(documentKeywords(undefined)).toEqual([])
	})

	it('drops blanks and non-strings rather than rendering them', () => {
		expect(
			documentKeywords({ keywords: ['bezwaar', '', '  ', 7, null] }),
		).toEqual(['bezwaar'])
	})

	it('trims, so " bezwaar" and "bezwaar" are one facet entry', () => {
		expect(documentKeywords({ keywords: [' bezwaar '] })).toEqual(['bezwaar'])
	})
})

describe('collectKeywords', () => {
	const groups = [
		{
			informatieobjecttype: 'Advies',
			documents: [
				{ id: '1', keywords: ['bouwtekening', 'bezwaar'] },
				{ id: '2' },
			],
		},
		{
			informatieobjecttype: 'Aanvraag',
			documents: [{ id: '3', keywords: ['bezwaar'] }],
		},
	]

	it('offers every keyword in use, once, sorted', () => {
		expect(collectKeywords(groups)).toEqual(['bezwaar', 'bouwtekening'])
	})

	it('offers nothing when nothing is tagged', () => {
		expect(collectKeywords([{ documents: [{ id: '1' }] }])).toEqual([])
		expect(collectKeywords(undefined)).toEqual([])
	})
})

describe('filterGroupsByKeywords', () => {
	const groups = [
		{
			informatieobjecttype: 'Advies',
			count: 2,
			documents: [
				{ id: '1', keywords: ['bezwaar'] },
				{ id: '2', keywords: [] },
			],
		},
		{
			informatieobjecttype: 'Aanvraag',
			count: 1,
			documents: [{ id: '3', keywords: ['bouwtekening'] }],
		},
	]

	it('shows only the tagged document', () => {
		const filtered = filterGroupsByKeywords(groups, ['bezwaar'])
		expect(filtered).toHaveLength(1)
		expect(filtered[0].documents.map((d) => d.id)).toEqual(['1'])
		expect(filtered[0].count).toBe(1)
	})

	it('clearing the filter shows everything again', () => {
		expect(filterGroupsByKeywords(groups, [])).toBe(groups)
		expect(filterGroupsByKeywords(groups, undefined)).toBe(groups)
	})

	it('narrows to documents carrying EVERY chosen keyword', () => {
		expect(filterGroupsByKeywords(groups, ['bezwaar', 'bouwtekening'])).toEqual(
			[],
		)
	})

	it('drops a type heading whose documents were all filtered out', () => {
		const filtered = filterGroupsByKeywords(groups, ['bouwtekening'])
		expect(filtered.map((g) => g.informatieobjecttype)).toEqual(['Aanvraag'])
	})

	it('leaves the caller groups untouched', () => {
		filterGroupsByKeywords(groups, ['bezwaar'])
		expect(groups[0].documents).toHaveLength(2)
		expect(groups[0].count).toBe(2)
	})
})

describe('direction vocabulary', () => {
	it('matches the enum the register fragment declares', () => {
		expect(DOCUMENT_DIRECTIONS).toEqual(['incoming', 'outgoing', 'internal'])
	})

	it('defaults to internal, like the schema', () => {
		expect(DEFAULT_DIRECTION).toBe('internal')
		expect(DOCUMENT_DIRECTIONS).toContain(DEFAULT_DIRECTION)
	})
})
