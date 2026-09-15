/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lens that keeps a handed-on case findable.
 *
 * 🔑 WHY THIS LENS EXISTS AT ALL, which is also what makes it easy to delete
 * by accident. The case moves to the receiving team the moment it is handed,
 * so the sending team's own lens stops answering for it: `assignedGroup` is
 * already Toezicht. Without a lens on `handoverPending`, a handover nobody
 * accepted is invisible to the team that made it, which is the exact failure
 * D-2 names.
 *
 * The filter key is asserted against the schema, because a chip bound to a
 * property the case does not declare returns every row on the instance rather
 * than an error.
 *
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
const panels = require('./helpers/casePanels.js')

const REGISTER_PATH = path.resolve(
	__dirname,
	'../../lib/Settings/dossiq_register.json',
)

function schemas () {
  return JSON.parse(fs.readFileSync(REGISTER_PATH, 'utf8')).components.schemas
}

/** @return {object|undefined} The Handed on chip. */
function handedOn () {
  return panels
		.pageConfig('Cases')
		.quickFilters.find((entry) => entry.label === 'Handed on')
}

describe('the Handed on lens', () => {
	it('filters on the pending marker and nothing else', () => {
		expect(handedOn().filter).toEqual({ handoverPending: true })
	})

	it('binds to a property the case schema declares', () => {
		const property = schemas().case.properties.handoverPending

		expect(property).toBeDefined()
		expect(property.type).toBe('boolean')
		// Default false. A chip whose key defaults to nothing on existing rows
		// would answer an empty list forever and read as "nobody hands cases
		// on", which is indistinguishable from the lens working.
		expect(property.default).toBe(false)
	})

	it('is not the default lens', () => {
		expect(handedOn().default).toBeUndefined()
	})
})

describe('the transfer record', () => {
	it('carries the team on the same shape the federated transfer uses', () => {
		const transfer = schemas().casetransfer

		expect(transfer.properties.handoverScope.enum).toEqual([
			'organisation',
			'team',
		])
		expect(transfer.properties.sourceTeam).toBeDefined()
		expect(transfer.properties.targetTeam).toBeDefined()
	})

	it('declares the doorzending, so Awb 2:3 is a fact and not an inference', () => {
		const doorzending = schemas().casetransfer.properties.doorzending

		expect(doorzending.type).toBe('boolean')
		expect(doorzending.default).toBe(false)
	})

	it('records the seats a handover emptied, beside the reason', () => {
		const emptied = schemas().casetransfer.properties.emptiedSeats

		expect(emptied.type).toBe('array')
		expect(Object.keys(emptied.items.properties)).toEqual([
			'seat',
			'holder',
			'reason',
		])
		expect(emptied.items.properties.seat.enum).toEqual([
			'handler',
			'coordinator',
		])
	})

	it('keeps the one custody trail rather than growing a second', () => {
		// The federated transfer's trail IS the internal one. A separate
		// `internalAuditTrail` would be two answers to "where has this case
		// been", and the first time they disagreed nobody would know which.
		const properties = Object.keys(schemas().casetransfer.properties)

		expect(properties).toContain('custodyAuditTrail')
		expect(properties.filter((key) => key.toLowerCase().includes('audittrail'))).toHaveLength(1)
	})
})
