// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for `approvalMarkerLabel`
 * (src/services/approvalMarker.js): the marker text for every shape of
 * answer.
 *
 * @spec openspec/specs/besluitvorming-leaf/spec.md
 */

import { describe, expect, it } from 'vitest'
import { approvalMarkerLabel } from '../../src/services/approvalMarker.js'

describe('approval marker label golden', () => {
	it.each([
		[undefined, ''],
		[null, ''],
		[{}, ''],
		[{ routed: 'yes', cleared: true }, ''],
		[{ routed: true, cleared: true, waitingOn: [{ stage: 2 }], stepCount: 3 }, 'Approved'],
		[{ routed: true, waitingOn: [{ stage: 2 }], stepCount: 3 }, 'In approval, step 2 of 3'],
		[{ routed: true, waitingOn: [{ stage: '2' }], stepCount: '3' }, 'In approval, step 2 of 3'],
		[{ routed: true, waitingOn: [{ stage: 2 }] }, 'In approval, step 2'],
		[{ routed: true, waitingOn: [{ stage: 2 }], stepCount: 0 }, 'In approval, step 2'],
		[{ routed: true, waitingOn: [{ stage: 0 }], stepCount: 3 }, 'In approval'],
		[{ routed: true, waitingOn: [null], stepCount: 3 }, 'In approval'],
		[{ routed: true, waitingOn: 'x', stepCount: 3 }, 'In approval'],
		[{ routed: true, waitingOn: [], stepCount: 3 }, 'In approval'],
		[{ routed: true, waitingOn: [{ stage: 'x' }], stepCount: 3 }, 'In approval'],
		[{ routed: true, cleared: 'true' }, 'In approval'],
	])('labels %j as %j', (marker, label) => {
		expect(approvalMarkerLabel(marker)).toBe(label)
	})
})
