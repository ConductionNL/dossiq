// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Case confidentiality speaks the register's vocabulary.
 *
 * The case and caseType schemas store confidentiality as the ZGW
 * `vertrouwelijkheidaanduiding` enum, in Dutch, with hard validation on. Two
 * places in the front end spoke English to it, and OpenRegister refused both:
 *
 *  - DeelzaakCreateModal fell back to `'public'` when the chosen sub-case
 *    type had no confidentiality, so a sub-case could not be created for any
 *    such type. Nine of the 23 case types on the dev instance have none.
 *  - The case type General tab offered `public`, `internal`, `secret` and so
 *    on as option ids, so no confidentiality could be saved on a case type
 *    from the settings page. That is how case types without one came about.
 *
 * Both are asserted against the enum the app ships, so the test moves with
 * the schema rather than with a copy of it.
 *
 * @spec openspec/specs/case-types/spec.md
 */
import { describe, expect, it, vi } from 'vitest'
import register from '../../lib/Settings/dossiq_register.json'

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'behandelaar' }),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcDialog: {},
	NcEmptyContent: {},
	NcLoadingIcon: {},
	NcSelect: {},
	NcTextField: {},
}))
vi.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({ default: {} }))
vi.mock('../../src/store/modules/deelzaak.js', () => ({
	useDeelzaakStore: () => ({}),
}))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

const { default: DeelzaakCreateModal } =
	await import('../../src/modals/DeelzaakCreateModal.vue')
const { getConfidentialityOptions } =
	await import('../../src/utils/caseTypeValidation.js')

const caseEnum = register.components.schemas.case.properties.confidentiality.enum
const caseTypeEnum =
	register.components.schemas.caseType.properties.confidentiality.enum

/**
 * Run the modal's submit() for a sub-case type and capture the saved case.
 *
 * @param {object} caseType The selected sub-case type.
 * @return {Promise<object>} The payload handed to the object store.
 */
async function submitSubCase(caseType) {
	let saved = null
	const context = {
		serverError: '',
		saving: false,
		parentCase: 'parent-1',
		selectedCaseType: {
			id: 'ct-1',
			title: 'Deelzaak',
			processingDeadline: 'P10D',
			...caseType,
		},
		statusTypes: [{ id: 'st-1', order: 1 }],
		form: { title: 'Advies brandweer', description: '', caseType: 'ct-1' },
		validate: () => true,
		deelzaakStore: { validateSubCase: async () => ({ ok: true }) },
		objectStore: {
			saveObject: async (schema, data) => {
				saved = data
				return { id: 'sub-1' }
			},
		},
		$emit: () => {},
	}

	await DeelzaakCreateModal.methods.submit.call(context)
	expect(context.serverError).toBe('')

	return saved
}

describe('Sub-case confidentiality', () => {
	it('falls back to a level the case schema accepts when the type sets none', async () => {
		const saved = await submitSubCase({})

		expect(caseEnum).toContain(saved.confidentiality)
	})

	it('falls back to zaakvertrouwelijk, not to anything more open', async () => {
		const saved = await submitSubCase({ confidentiality: '' })

		expect(saved.confidentiality).toBe('zaakvertrouwelijk')
	})

	it('keeps the level the sub-case type sets', async () => {
		const saved = await submitSubCase({ confidentiality: 'geheim' })

		expect(saved.confidentiality).toBe('geheim')
	})
})

describe('Case type confidentiality options', () => {
	it('offers exactly the levels the caseType schema accepts, in order', () => {
		expect(getConfidentialityOptions().map((option) => option.id)).toEqual(
			caseTypeEnum,
		)
	})

	it('gives every level a label', () => {
		for (const option of getConfidentialityOptions()) {
			expect(option.label).toBeTruthy()
		}
	})
})
