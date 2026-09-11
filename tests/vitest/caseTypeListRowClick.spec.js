// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * A row in the admin Case types list opens its case type.
 *
 * The list is `selectable`, and with `selectable` set and `rowClickToView`
 * absent, nextcloud-vue's CnIndexPage and CnDataTable both treat a row-body
 * click as a selection toggle and RETURN before emitting `row-click`
 * (CnDataTable.onRowClick). So `@rowClick="selectCaseType"` could never fire,
 * and an admin clicking a row only ticked its checkbox, with no way to open a
 * case type from the list at all.
 *
 * What is asserted is the prop the library gates on. Rendering the list proves
 * nothing, because a list that cannot be opened renders exactly like one that
 * can, which is why nothing caught this.
 *
 * @spec openspec/specs/case-types/spec.md
 */
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

// The shape CaseTypeList reads: `loading.caseType`, `collections.caseType`,
// and `fetchSchema` in its created hook. Empty, because only the props handed
// to CnIndexPage are under test, not the rows.
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		loading: {},
		collections: {},
		fetchSchema: vi.fn(async () => ({})),
		fetchCollection: vi.fn(async () => []),
	}),
}))

// vitest.config aliases `@nextcloud/router` to a stub exporting only
// generateUrl; this component's imports also need imagePath.
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p) => p,
	imagePath: (app, file) => `/${app}/img/${file}`,
}))

const { default: CaseTypeList } =
	await import('../../src/views/settings/CaseTypeList.vue')

/**
 * A stand-in for nextcloud-vue's CnIndexPage that DECLARES the props under
 * test, so the mount records exactly what CaseTypeList hands it. The real
 * component's row-click gate is library code (CnDataTable.onRowClick); what can
 * regress in this app is the attribute that feeds it, so that is what is
 * captured.
 */
const CnIndexPageStub = {
	name: 'CnIndexPage',
	props: [
		'selectable',
		'rowClickToView',
		'title',
		'description',
		'schema',
		'objects',
		'loading',
	],
	template: '<div class="cn-index-page-stub" />',
}

describe('CaseTypeList', () => {
	it('lets a row open its case type, not merely select it', () => {
		const wrapper = shallowMount(CaseTypeList, {
			global: {
				mocks: { t: (_app, s) => s },
				stubs: { CnIndexPage: CnIndexPageStub },
			},
		})

		const index = wrapper.findComponent(CnIndexPageStub)
		expect(index.exists(), 'the list must render through CnIndexPage').toBe(true)

		// Both halves, because the bug is their combination. Selectable alone
		// is fine; selectable WITHOUT rowClickToView is what swallows the click.
		expect(index.props('selectable')).toBe(true)
		expect(
			index.props('rowClickToView'),
			'a selectable list needs rowClickToView, or a row click only toggles its checkbox',
		).toBe(true)
	})
})
