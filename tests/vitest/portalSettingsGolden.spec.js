// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for `portalStateFrom` and `portalPayload`
 * (src/services/caseTypePortalSettings.js): the form state read from a
 * stored case type and the PATCH body written from a state. Snapshots taken
 * on the code before it was split.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

import { describe, expect, it } from 'vitest'
import { portalPayload, portalStateFrom, WRITABLE_CEILING } from '../../src/services/caseTypePortalSettings.js'

const [FIRST, SECOND] = WRITABLE_CEILING

describe('portal settings golden', () => {
	it('reads an empty case type', () => {
		expect(portalStateFrom()).toMatchInlineSnapshot(`
			{
			  "amendment": {
			    "closedReason": "",
			    "openStatuses": [],
			  },
			  "documents": {
			    "closedReason": "",
			    "openStatuses": [],
			  },
			  "withdrawal": {
			    "closedReason": "",
			    "confirmText": "",
			    "enabled": false,
			    "openStatuses": [],
			    "targetStatus": "",
			  },
			  "writable": {
			    "description": {
			      "audiences": [
			        "client",
			        "citizen",
			        "supplier",
			      ],
			      "closedReason": "",
			      "enabled": false,
			      "openStatuses": [],
			    },
			  },
			}
		`)
		expect(portalStateFrom(null)).toEqual(portalStateFrom())
	})

	it('reads a filled case type', () => {
		expect(portalStateFrom({
			portalWritable: [
				{ field: FIRST, openStatuses: ['s1', 3], closedReason: ' Closed ', audiences: ['citizen'] },
				{ field: SECOND, audiences: [] },
				{ field: 'not-in-ceiling' },
				null,
			],
			portalAmendmentWindow: { openStatuses: ['s2'], closedReason: 'No' },
			portalDocumentWindow: null,
			portalWithdrawal: { targetStatus: 'st-w', openStatuses: ['s3'], closedReason: 'x', confirmText: 'Sure?' },
		})).toMatchInlineSnapshot(`
			{
			  "amendment": {
			    "closedReason": "No",
			    "openStatuses": [
			      "s2",
			    ],
			  },
			  "documents": {
			    "closedReason": "",
			    "openStatuses": [],
			  },
			  "withdrawal": {
			    "closedReason": "x",
			    "confirmText": "Sure?",
			    "enabled": true,
			    "openStatuses": [
			      "s3",
			    ],
			    "targetStatus": "st-w",
			  },
			  "writable": {
			    "description": {
			      "audiences": [
			        "citizen",
			      ],
			      "closedReason": "Closed",
			      "enabled": true,
			      "openStatuses": [
			        "s1",
			      ],
			    },
			  },
			}
		`)
	})

	it('writes the body for a state', () => {
		const state = portalStateFrom({
			portalWritable: [
				{ field: FIRST, openStatuses: ['s1'], closedReason: 'Closed', audiences: ['citizen'] },
				{ field: SECOND },
			],
			portalAmendmentWindow: { openStatuses: ['s2'], closedReason: '' },
			portalDocumentWindow: { openStatuses: [], closedReason: 'Docs closed' },
			portalWithdrawal: { targetStatus: 'st-w', openStatuses: ['s3'], closedReason: '', confirmText: 'Sure?' },
		})
		expect(portalPayload(state)).toMatchInlineSnapshot(`
			{
			  "portalAmendmentWindow": {
			    "openStatuses": [
			      "s2",
			    ],
			  },
			  "portalDocumentWindow": {
			    "closedReason": "Docs closed",
			    "openStatuses": [],
			  },
			  "portalWithdrawal": {
			    "confirmText": "Sure?",
			    "openStatuses": [
			      "s3",
			    ],
			    "targetStatus": "st-w",
			  },
			  "portalWritable": [
			    {
			      "audiences": [
			        "citizen",
			      ],
			      "closedReason": "Closed",
			      "field": "description",
			      "openStatuses": [
			        "s1",
			      ],
			    },
			  ],
			}
		`)
	})

	it('writes a withdrawal only when it is enabled and names a target', () => {
		expect(portalPayload({ withdrawal: { enabled: true, targetStatus: '' } }).portalWithdrawal).toEqual({})
		expect(portalPayload({ withdrawal: { enabled: false, targetStatus: 'st' } }).portalWithdrawal).toEqual({})
		expect(portalPayload({ withdrawal: { enabled: true, targetStatus: 'st', closedReason: 'c' } }).portalWithdrawal).toEqual({ openStatuses: [], targetStatus: 'st', closedReason: 'c' })
		expect(portalPayload(null)).toMatchInlineSnapshot(`
			{
			  "portalAmendmentWindow": {
			    "openStatuses": [],
			  },
			  "portalDocumentWindow": {
			    "openStatuses": [],
			  },
			  "portalWithdrawal": {},
			  "portalWritable": [],
			}
		`)
	})
})
