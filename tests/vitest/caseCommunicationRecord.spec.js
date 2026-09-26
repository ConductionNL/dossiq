// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The outbound communication record on the case, and the gesture that clears
 * an unmet Awb 4:3a duty.
 *
 * Every assertion here guards a way this could ship DARK, and each was reached
 * by reading how the case page resolves things rather than by guessing:
 *
 *  - a `data` widget renders the fields it INCLUDES, and only fields the schema
 *    declares. A field named in the manifest and absent from the register
 *    renders nothing at all, silently, so both fields are asserted to exist in
 *    the register fragment as well as in the manifest;
 *  - an `api-call` action needs a route behind its url or the click is a 404
 *    toast, so the url is asserted against `appinfo/routes.php`;
 *  - an icon that `src/icons.js` does not register renders the default glyph
 *    with no visible breakage, so the icon is asserted to be registered;
 *  - clearing a statutory duty is not a click to make by accident, so the
 *    action is asserted to be confirm-gated.
 *
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const fragment = JSON.parse(
	fs.readFileSync(
		path.join(
			ROOT,
			'lib',
			'Settings',
			'register.d',
			'36-ontvangstbevestiging.json',
		),
		'utf8',
	),
)
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')

/**
 * One page out of the manifest, by id.
 *
 * @param {string} id The page id.
 * @return {object} The page.
 */
function page(id) {
	const found = manifest.pages.find((p) => p.id === id)
	expect(found, `page ${id} is missing from the manifest`).toBeTruthy()
	return found
}

/**
 * Every widget declared anywhere under one page, flattened.
 *
 * @param {object} node The manifest node to walk.
 * @param {Array} out The accumulator.
 * @return {Array} Every object carrying an `id` and a `type`.
 */
function widgets(node, out = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => widgets(child, out))
		return out
	}
	if (node && typeof node === 'object') {
		if (typeof node.id === 'string' && typeof node.type === 'string') {
			out.push(node)
		}
		Object.values(node).forEach((child) => widgets(child, out))
	}
	return out
}

const caseDetail = page('CaseDetail')
const allWidgets = widgets(caseDetail)

describe('the outbound communication record on the case', () => {
	it('declares both fields on the case schema', () => {
		const props = fragment.components.schemas.case.properties

		expect(props.outboundCommunications.type).toBe('array')
		const record = props.outboundCommunications.items.properties
		// REQ-TERM-023 names these four by name. A record missing one of them
		// cannot answer "did we confirm receipt, when, to whom and by which
		// channel", which is the question the requirement exists for.
		expect(Object.keys(record)).toEqual(
			expect.arrayContaining([
				'moment',
				'channel',
				'recipient',
				'templateVersion',
			]),
		)

		expect(props.acknowledgementDuty.type).toBe('object')
		const duty = props.acknowledgementDuty.properties
		expect(duty.status.enum).toEqual(['not-required', 'pending', 'met', 'unmet'])
		// REQ-TERM-024: a duty met another way names who said so and when.
		expect(Object.keys(duty)).toEqual(
			expect.arrayContaining([
				'metBy',
				'metAt',
				'metHow',
				'attempts',
				'lastError',
			]),
		)
	})

	it('declares the acknowledgement on the case type, defaulting to on', () => {
		const declaration =
			fragment.components.schemas.caseType.properties.acknowledgement

		expect(declaration.properties.enabled.default).toBe(true)
		expect(declaration.properties.intakeChannels.default).toEqual([
			'email',
			'website',
			'zgw-api',
		])
		expect(declaration.properties.contentOnPlatform.default).toBe(false)

		// REQ-TERM-025: asking for something is its own moment, distinct from
		// a status change. One value for both would make informing and chasing
		// the same message.
		const moments =
			fragment.components.schemas.caseType.properties.notificationMoments.items
				.properties
		expect(moments.moment.enum).toEqual(
			expect.arrayContaining([
				'case-received',
				'case-incomplete',
				'status-changed',
			]),
		)
		expect(moments.statutory).toBeTruthy()
	})

	it('renders the record on the Communication tab', () => {
		const widget = allWidgets.find((w) => w.id === 'case-acknowledgement')
		expect(
			widget,
			'the case page declares no receipt-confirmation widget',
		).toBeTruthy()
		expect(widget.type).toBe('data')
		expect(widget.content.include).toEqual([
			// the-case-says-when-it-arrived (#2946): the moment the case
			// arrived, the moment its clock starts, and the flag between them.
			// They lead the card because the confirmation is only readable
			// against them: a receipt sent on Monday for a form filed on
			// Sunday is correct, and says so only if both moments are on it.
			'receivedAt',
			'termStartsAt',
			'receivedOutsideWorkingHours',
			'acknowledgementDuty',
			'outboundCommunications',
		])

		// Read-only on purpose: a duty a handler can type over is exactly the
		// state this feature exists to make visible.
		expect(widget.content.overrides.acknowledgementDuty.editable).toBe(false)
		expect(widget.content.overrides.outboundCommunications.editable).toBe(false)

		const communication = allWidgets.find(
			(w) => w.id === 'case-communication-panel',
		)
		const labels = communication.content.sections.map((s) => s.label)
		expect(labels).toContain('Receipt confirmation')
	})

	it('offers a confirm-gated gesture backed by a real route', () => {
		const action = (caseDetail.config.headerActions || []).find(
			(a) => a.id === 'case-acknowledgement-met',
		)
		expect(
			action,
			'the case page offers no way to record the duty met',
		).toBeTruthy()

		// A `handler` action resolves its name against the manifest's JSON
		// actions map, so a function can never answer it: the button would warn
		// once to the console and do nothing.
		expect(action.type).toBe('api-call')
		expect(action.method).toBe('POST')
		expect(action.confirm).toBe(true)

		// The endpoint refuses an empty reason, so the action has to send one.
		expect(action.payload.how).toBeTruthy()

		expect(action.url).toBe(
			'/apps/dossiq/api/case/@objectId/acknowledgement/met',
		)
		expect(
			routes,
			'the api-call url has no route behind it, so the click is a 404',
		).toContain("'url' => '/api/case/{caseId}/acknowledgement/met'")
		expect(routes).toContain("'name' => 'acknowledgement#recordMet'")
		expect(routes).toContain("'name' => 'acknowledgement#duty'")

		expect(
			iconsSource,
			`icon ${action.icon} is not registered, so it renders the default glyph`,
		).toContain(`import ${action.icon} from`)
	})
})
