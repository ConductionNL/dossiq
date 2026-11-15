// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for `ruleFields` (src/services/caseTypeRules.js):
 * the fields a condition can name, from property definitions, statuses and
 * document types. Snapshots taken on the code before it was split.
 */

import { describe, expect, it } from 'vitest'
import { ruleFields } from '../../src/services/caseTypeRules.js'

describe('rule fields golden', () => {
	it('reads nothing from an empty catalogue', () => {
		expect(ruleFields()).toEqual([])
		expect(ruleFields({})).toEqual([])
	})

	it('reads each property definition', () => {
		expect(ruleFields({
			propertyDefinitions: [
				null,
				{},
				{ name: 'bouwkosten', propertyType: 'number', title: 'Bouwkosten' },
				{ name: 'bouwkosten2', propertyType: 'string' },
				{ name: 'feeAmount', propertyType: 'integer', label: 'Fee' },
				{ name: 'kind', propertyType: 'enum', enumValues: ['a', 2] },
				{ name: 'empty', propertyType: 'enum', enumValues: [] },
				{ name: 'mystery', propertyType: 'weird' },
				{ name: 'title', title: { nl: 'Titel', en: 'Title' } },
			],
		})).toMatchInlineSnapshot(`
			[
			  {
			    "format": "currency",
			    "id": "bouwkosten",
			    "label": "Bouwkosten",
			    "type": "number",
			  },
			  {
			    "id": "bouwkosten2",
			    "label": "Bouwkosten2",
			    "type": "string",
			  },
			  {
			    "id": "feeAmount",
			    "label": "Fee",
			    "type": "integer",
			  },
			  {
			    "id": "kind",
			    "label": "Kind",
			    "options": [
			      {
			        "id": "a",
			        "label": "a",
			      },
			      {
			        "id": 2,
			        "label": "2",
			      },
			    ],
			    "type": "string",
			  },
			  {
			    "id": "empty",
			    "label": "Empty",
			    "type": "string",
			  },
			  {
			    "id": "mystery",
			    "label": "Mystery",
			    "type": "string",
			  },
			  {
			    "id": "title",
			    "label": "Titel",
			    "type": "string",
			  },
			]
		`)
	})

	it('reads the statuses and document types', () => {
		expect(ruleFields({
			statusTypes: [{ id: 's1', name: 'Open' }, { id: 's2', title: 'Closed' }, { id: 's3' }],
			documentTypes: [null, {}, { id: 'd1', name: 'Plan' }, { id: 'd2', title: 'Tekening' }, { id: 'd3' }],
		})).toMatchInlineSnapshot(`
			[
			  {
			    "id": "status",
			    "label": "status",
			    "options": [
			      {
			        "id": "s1",
			        "label": "Open",
			      },
			      {
			        "id": "s2",
			        "label": "Closed",
			      },
			      {
			        "id": "s3",
			        "label": "s3",
			      },
			    ],
			    "type": "enum",
			  },
			  {
			    "id": "document:d1",
			    "label": "Document Plan",
			    "type": "presence",
			  },
			  {
			    "id": "document:d2",
			    "label": "Document Tekening",
			    "type": "presence",
			  },
			  {
			    "id": "document:d3",
			    "label": "Document d3",
			    "type": "presence",
			  },
			]
		`)
	})
})
