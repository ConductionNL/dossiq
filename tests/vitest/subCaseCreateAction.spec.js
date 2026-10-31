// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A sub-case is created from the parent's Sub-cases section.
 *
 * The section is a library object list. Its `createAction` opens
 * DeelzaakCreateModal instead of the list's own create form, because the
 * modal creates through /api/deelzaken and the sub-case then inherits what
 * case.parentCase declares; a direct save inherits nothing. The action shows
 * only on an open case that is not itself a sub-case; elsewhere its
 * `hiddenNote` says why in its place, and so does the modal if reached. `summary` is the completion roll-up.
 *
 * Both structure profiles carry the same declaration, so a person on the
 * simple profile does not lose the action.
 *
 * @spec openspec/specs/deelzaak-support/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'behandelaar' }) }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcDialog: {},
	NcEmptyContent: {},
	NcLoadingIcon: {},
	NcSelect: {},
	NcTextField: {},
}))
vi.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({ default: {} }))
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => ({}) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
const refreshes = []
vi.mock('@nextcloud/event-bus', () => ({ emit: (name) => refreshes.push(name) }))
vi.mock('../../src/services/deelzaakApi.js', () => ({
	createSubCase: async () => ({ ok: true, object: { id: 'sub-1' } }),
}))

const { default: DeelzaakCreateModal } = await import('../../src/modals/DeelzaakCreateModal.vue')

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

/**
 * Find a widget by id anywhere in a JSON tree.
 *
 * @param {unknown} node The tree.
 * @param {string} id The widget id.
 * @return {object|null} The widget.
 */
function findWidget(node, id) {
	if (Array.isArray(node)) {
		for (const child of node) {
			const hit = findWidget(child, id)
			if (hit) {
				return hit
			}
		}
		return null
	}
	if (node && typeof node === 'object') {
		if (node.id === id && node.content) {
			return node
		}
		for (const value of Object.values(node)) {
			const hit = findWidget(value, id)
			if (hit) {
				return hit
			}
		}
	}
	return null
}

const PROFILES = [
	['the full profile', findWidget(readJson('src', 'manifest.json'), 'case-sub-cases')],
	['the simple profile', findWidget(readJson('src', 'menu-layout.simple.json'), 'case-sub-cases-card')],
]

describe.each(PROFILES)('the Sub-cases section on %s', (_, widget) => {
	it('opens DeelzaakCreateModal for the parent on screen', () => {
		const action = widget.content.createAction
		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('DeelzaakCreateModal')
		expect(action.label).toBe('Create sub-case')
		expect(action.props).toEqual({ parentCase: '@objectId' })
	})

	it('offers it only on an open case that is not itself a sub-case', () => {
		expect(widget.content.createAction.visibleWhen).toEqual({
			all: [
				{ field: 'endDate', op: 'empty' },
				{ field: 'parentCase', op: 'empty' },
			],
		})
	})

	it('says why where it is withheld, as the old list did', () => {
		expect(widget.content.createAction.hiddenNote).toEqual([
			{ when: { field: 'parentCase', op: 'notEmpty' }, text: 'Sub-cases cannot themselves have sub-cases.' },
			{ when: { field: 'endDate', op: 'notEmpty' }, text: 'This case is closed; sub-cases can no longer be added.' },
		])
	})

	it('rolls up how many sub-cases are completed', () => {
		expect(widget.content.summary).toEqual({
			text: '({completed}/{total} completed)',
			counts: { completed: { field: 'endDate', op: 'notEmpty' } },
		})
	})
})

describe('DeelzaakCreateModal opened from the section', () => {
	it('is a registered modal', () => {
		const source = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
		expect(source).toMatch(/DeelzaakCreateModal: \{\n\t\tkind: 'modal',\n\t\tcomponent: DeelzaakCreateModal,/)
	})

	it('reads the parent and its case type when none is passed', async () => {
		const fetched = []
		const context = {
			parentCase: 'parent-1',
			parentCaseType: null,
			parent: null,
			loadedParentCaseType: null,
			loadingParent: true,
			objectStore: {
				fetchObject: async (schema, id) => {
					fetched.push([schema, id])
					return schema === 'case' ? { id, caseType: 'ct-parent' } : { id, subCaseTypes: ['ct-child'] }
				},
			},
		}
		await DeelzaakCreateModal.methods.loadParent.call(context)
		expect(fetched).toEqual([['case', 'parent-1'], ['caseType', 'ct-parent']])
		expect(context.loadedParentCaseType.subCaseTypes).toEqual(['ct-child'])
		expect(context.loadingParent).toBe(false)
	})

	it('says why a sub-case or a closed case cannot take one', () => {
		const refusal = DeelzaakCreateModal.computed.parentRefusal
		expect(refusal.call({ parent: { parentCase: 'p' } })).toBe('Sub-cases cannot themselves have sub-cases.')
		expect(refusal.call({ parent: { endDate: '2026-10-01' } })).toBe('This case is closed; sub-cases can no longer be added.')
		expect(refusal.call({ parent: { id: 'open' } })).toBe('')
	})

	it('filters the sub-case types by the loaded parent type', () => {
		const types = DeelzaakCreateModal.computed.availableCaseTypes.call({
			effectiveParentCaseType: { subCaseTypes: ['ct-child'] },
			caseTypes: [{ id: 'ct-child' }, { id: 'ct-other' }],
		})
		expect(types).toEqual([{ id: 'ct-child' }])
	})

	it('refreshes the page and closes after a create', async () => {
		refreshes.length = 0
		const emitted = []
		const context = {
			serverError: '',
			saving: false,
			parentCase: 'parent-1',
			selectedCaseType: { id: 'ct-child', title: 'Advies', processingDeadline: 'P10D' },
			statusTypes: [],
			form: { title: 'Advies brandweer', description: '', caseType: 'ct-child' },
			validate: () => true,
			deelzaakStore: { validateSubCase: async () => ({ ok: true }) },
			$emit: (name, value) => emitted.push([name, value]),
		}
		await DeelzaakCreateModal.methods.submit.call(context)
		expect(context.serverError).toBe('')
		expect(emitted).toEqual([['created', 'sub-1'], ['close', undefined]])
		expect(refreshes).toEqual(['cn:page:refresh'])
	})
})
