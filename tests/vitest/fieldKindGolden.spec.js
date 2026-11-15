// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for `fieldKind` (src/utils/fieldDescription.js):
 * the kind of answer for every shape of field. The test translator leaves
 * the plural count as `%n`.
 */

import { describe, expect, it } from 'vitest'
import { fieldKind } from '../../src/utils/fieldDescription.js'

describe('field kind golden', () => {
	it.each([
		[null, ''],
		['text', ''],
		[{}, ''],
		[{ propertyType: 7 }, ''],
		[{ propertyType: 'string' }, 'Text'],
		[{ propertyType: 'boolean' }, 'Yes or no'],
		[{ propertyType: 'array' }, 'List'],
		[{ propertyType: 'object' }, 'Group of fields'],
		[{ propertyType: 'mystery' }, 'mystery'],
		[{ propertyType: 'enum' }, 'Choice list, %n options'],
		[{ propertyType: 'enum', enumValues: ['a'] }, 'Choice list, %n option'],
		[{ propertyType: 'string', enumValues: ['a', 'b'] }, 'Choice list, %n options'],
		[{ propertyType: 'string', enumValues: 'ab' }, 'Text'],
		[{ propertyType: 'string', propertySource: 'bag' }, 'Text from BAG'],
		[{ propertyType: 'string', propertySource: '' }, 'Text'],
		[{ propertyType: 'string', propertySource: 3 }, 'Text'],
		[{ propertyType: 'boolean', calculation: 'x' }, 'Yes or no, calculated'],
		[{ propertyType: 'boolean', calculation: '' }, 'Yes or no'],
		[{ propertyType: 'enum', enumValues: ['a'], propertySource: 'brp', calculation: 'x' }, 'Choice list, %n option from BRP, calculated'],
		[{ propertySource: 'bag', calculation: 'x' }, ''],
	])('reads %j as %j', (field, kind) => {
		expect(fieldKind(field)).toBe(kind)
	})
})
