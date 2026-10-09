/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type editor's Portal section: what it reads and what it writes.
 *
 * The write is validated against the REAL register fragment
 * (`lib/Settings/register.d/76-portal-citizen-writes.json`), key by key and
 * type by type, because a payload the schema refuses passes every test that
 * only compares it with itself. And it is sent through the real save function
 * with axios replaced, so the URL and the body are the ones a browser sends.
 *
 * @spec openspec/changes/portal-citizen-writes-on-the-case/specs/portal-contribution/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), patch: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url) => url,
}))

const axios = (await import('@nextcloud/axios')).default
const {
	PORTAL_AUDIENCES,
	WRITABLE_CEILING,
	portalPayload,
	portalStateFrom,
	refusalSentence,
	savePortalSettings,
} = await import('../../src/services/caseTypePortalSettings.js')

const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(
	fs.readFileSync(
		path.join(
			ROOT,
			'lib',
			'Settings',
			'register.d',
			'76-portal-citizen-writes.json',
		),
		'utf8',
	),
)
const CASE_TYPE = fragment.components.schemas.caseType.properties

/**
 * Every place a value does not fit the schema it is written under.
 *
 * @param {*} value The value.
 * @param {object} schema The JSON schema node.
 * @param {string} at The path, for the message.
 * @return {string[]} The misfits, empty when it fits.
 */
function misfits(value, schema, at) {
	const out = []
	const type = Array.isArray(value)
		? 'array'
		: value === null
			? 'null'
			: typeof value
	if (schema.type && schema.type !== type) {
		return [`${at} is ${type}, the schema says ${schema.type}`]
	}
	if (type === 'object') {
		for (const [key, child] of Object.entries(value)) {
			if (!schema.properties?.[key]) {
				out.push(`${at}.${key} is not declared`)
				continue
			}
			out.push(...misfits(child, schema.properties[key], `${at}.${key}`))
		}
	}
	if (type === 'array') {
		value.forEach((item, i) =>
			out.push(...misfits(item, schema.items ?? {}, `${at}[${i}]`)),
		)
	}
	return out
}

const ONTVANGEN = 'st-ontvangen'
const IN_BEHANDELING = 'st-in-behandeling'
const INGETROKKEN = 'st-ingetrokken'

/** The section as an administrator fills it in. @return {object} The state. */
function filledIn() {
	const state = portalStateFrom({})
	state.writable.description.enabled = true
	state.writable.description.openStatuses = [ONTVANGEN]
	state.writable.description.closedReason = 'Uw verzoek is al in behandeling.'
	state.amendment.openStatuses = [ONTVANGEN, IN_BEHANDELING]
	state.documents.openStatuses = [ONTVANGEN]
	state.documents.closedReason = 'Wij beoordelen de documenten al.'
	state.withdrawal.enabled = true
	state.withdrawal.openStatuses = [ONTVANGEN]
	state.withdrawal.targetStatus = INGETROKKEN
	state.withdrawal.confirmText = 'Weet u zeker dat u wilt intrekken?'
	return state
}

describe('the Portal section of the case type editor', () => {
	beforeEach(() => {
		axios.patch.mockReset()
	})

	it('offers exactly the fields the amendCase action lets through', () => {
		expect(WRITABLE_CEILING).toEqual(['description'])
	})

	it('saves a writable field, both windows and a withdrawal', async () => {
		axios.patch.mockResolvedValue({ data: { id: 'melding' } })

		await savePortalSettings('melding', filledIn())

		expect(axios.patch).toHaveBeenCalledTimes(1)
		const [url, body] = axios.patch.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/objects/dossiq/caseType/melding')
		expect(body).toEqual({
			portalWritable: [
				{
					field: 'description',
					audiences: PORTAL_AUDIENCES,
					openStatuses: [ONTVANGEN],
					closedReason: 'Uw verzoek is al in behandeling.',
				},
			],
			portalAmendmentWindow: { openStatuses: [ONTVANGEN, IN_BEHANDELING] },
			portalDocumentWindow: {
				openStatuses: [ONTVANGEN],
				closedReason: 'Wij beoordelen de documenten al.',
			},
			portalWithdrawal: {
				openStatuses: [ONTVANGEN],
				targetStatus: INGETROKKEN,
				confirmText: 'Weet u zeker dat u wilt intrekken?',
			},
		})
	})

	it('writes only what the register fragment declares, in the types it declares', () => {
		const body = portalPayload(filledIn())
		const problems = Object.entries(body).flatMap(([key, value]) =>
			CASE_TYPE[key]
				? misfits(value, CASE_TYPE[key], key)
				: [`${key} is not on caseType`],
		)
		expect(problems).toEqual([])
	})

	it('closes everything without writing a null the schema would refuse', () => {
		const body = portalPayload(portalStateFrom({}))
		expect(body).toEqual({
			portalWritable: [],
			portalAmendmentWindow: { openStatuses: [] },
			portalDocumentWindow: { openStatuses: [] },
			portalWithdrawal: {},
		})
		expect(JSON.stringify(body)).not.toContain('null')
	})

	it('drops a withdrawal that names no status, even when switched on', () => {
		const state = filledIn()
		state.withdrawal.targetStatus = '  '
		expect(portalPayload(state).portalWithdrawal).toEqual({})
	})

	it('reads back what it wrote', () => {
		const state = filledIn()
		const again = portalStateFrom(portalPayload(state))
		expect(portalPayload(again)).toEqual(portalPayload(state))
		expect(again.withdrawal.enabled).toBe(true)
		expect(again.writable.description.enabled).toBe(true)
	})

	it('reads the seeded Woo request type as the editor would show it', () => {
		const seed = JSON.parse(
			fs.readFileSync(
				path.join(
					ROOT,
					'lib',
					'Settings',
					'register.d',
					'81-woo-verzoek.json',
				),
				'utf8',
			),
		)
		const found = JSON.stringify(seed).match(/"portalWithdrawal":(\{[^}]*\})/)
		expect(found, 'the Woo seed no longer declares a withdrawal').toBeTruthy()
		const state = portalStateFrom({ portalWithdrawal: JSON.parse(found[1]) })
		expect(state.withdrawal.enabled).toBe(true)
		expect(state.withdrawal.openStatuses.length).toBe(2)
	})

	it("shows the guard's sentence when the save is refused", () => {
		const error = {
			response: {
				data: {
					errors: {
						message: 'A case in Ontvangen cannot move to Ingetrokken.',
					},
				},
			},
		}
		expect(refusalSentence(error)).toBe(
			'A case in Ontvangen cannot move to Ingetrokken.',
		)
		expect(refusalSentence(new Error('network'))).toBe('')
	})
})
