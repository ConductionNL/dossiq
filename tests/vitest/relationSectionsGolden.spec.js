/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `relationErrorMessage` and `relationSections`
 * (src/utils/caseRelationHelpers.js): every refusal reason, and how the
 * relations of a case are grouped, labelled and skipped.
 */

import { describe, expect, it } from 'vitest'
import { relationErrorMessage, relationSections } from '../../src/utils/caseRelationHelpers.js'

describe('relationErrorMessage golden', () => {
	it.each([
		['self_relation', 'A case cannot be related to itself.'],
		['duplicate', 'This relation already exists.'],
		['hierarchy_overlap', 'These cases are already linked through the main/sub-case hierarchy.'],
		['access_denied', 'You do not have access to one of the cases.'],
		['invalid_aard_relatie', 'Select a valid relation type.'],
		['missing_case_id', 'A target case and relation type are required.'],
		['other', 'Could not save the relation.'],
		[undefined, 'Could not save the relation.'],
		['toString', 'Could not save the relation.'],
	])('says %j as %s', (reason, message) => {
		expect(relationErrorMessage(reason)).toBe(message)
	})
})

describe('relationSections golden', () => {
	it('groups by display label in first-seen order and skips unreadable rows', () => {
		const sections = relationSections([
			{ caseId: 'c1', title: 'Bouw', aardRelatie: 'vervolg' },
			{ caseId: '', title: 'No id', aardRelatie: 'vervolg' },
			{ caseId: 'c2', title: '  ', aardRelatie: 'vervolg' },
			{ caseId: 'c3', title: '8afb946b-1111-4222-8333-944445555666', aardRelatie: 'vervolg' },
			{ caseId: 'c4', title: ' Kap ', notes: ' spoed ', aardRelatie: 'samenhang' },
			{ caseId: 'c5', title: 'Sloop', notes: '  ', aardRelatie: 'vervolg' },
			null,
		])
		expect(sections.map((section) => section.items)).toEqual([
			[{ id: 'c1', label: 'Bouw' }, { id: 'c5', label: 'Sloop' }],
			[{ id: 'c4', label: 'Kap (spoed)' }],
		])
		expect(sections.map((section) => section.key)).toEqual(sections.map((section) => 'relation-' + section.label))
		expect(sections.every((section) => section.icon === 'LinkVariant')).toBe(true)
		expect(Object.keys(sections[0])).toEqual(['key', 'label', 'icon', 'items'])
	})

	it.each([null, undefined, []])('has no sections for %j', (relations) => {
		expect(relationSections(relations)).toEqual([])
	})
})
