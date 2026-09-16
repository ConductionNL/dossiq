// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Archiving tab renders what openregister decided, and nothing it worked
 * out itself.
 *
 * Three states are pinned because they are the three that look alike from an
 * absent appraisal and are not alike at all: a nominated case, a case
 * openregister could not nominate, and a case nobody has closed yet. Only the
 * middle one is somebody's problem today, and drawing them the same way is the
 * failure mode that keeps personal data past its lawful term.
 *
 * The recompute guard is here too. openregister answers 400 without a reason,
 * so the panel has to collect it before it sends rather than relay the refusal.
 *
 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

/**
 * A click-forwarding stand-in for a Nextcloud component.
 *
 * @param {string} name The component name.
 * @param {string} tag The element to render.
 * @return {object} The stub component.
 */
function stub(name, tag = 'div') {
	return defineComponent({
		name,
		props: ['disabled', 'modelValue', 'label', 'name', 'description', 'type'],
		emits: ['click', 'update:modelValue'],
		render() {
			return h(
				tag,
				{ onClick: () => this.$emit('click'), disabled: this.disabled },
				this.$slots.default?.(),
			)
		},
	})
}

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: stub('NcButton', 'button') }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: stub('NcLoadingIcon') }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: stub('NcNoteCard') }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: stub('NcTextField', 'input') }))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({
	default: defineComponent({
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		render() {
			return h('div', {}, [h('h3', {}, this.name), this.$slots.action?.()])
		},
	}),
}))
vi.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({ default: stub('AlertCircleOutline') }))
vi.mock('vue-material-design-icons/ArchiveOutline.vue', () => ({ default: stub('ArchiveOutline') }))

/** Who is reading. Replaced per test. */
let currentUser = { uid: 'els', isAdmin: false, groups: ['archivaris'] }
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => currentUser }))

/** What the case read answers. Replaced per test. */
let readAnswer = vi.fn()
/** Every recomputation the panel asked for. */
let recomputes = []

vi.mock('../../src/services/archivalApi.js', () => ({
	caseRetention: (...args) => readAnswer(...args),
	recomputeNomination: (...args) => {
		recomputes.push(args)
		return Promise.resolve({})
	},
}))

const { default: CaseArchivalPanel } = await import(
	'../../src/components/case/CaseArchivalPanel.vue'
)

/**
 * Mount the panel and let its read settle.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountPanel() {
	const wrapper = mount(CaseArchivalPanel, {
		props: { objectId: 'case-1' },
		global: { mocks: { t: (_app, s, vars) => (vars ? `${s} ${JSON.stringify(vars)}` : s) } },
	})
	await flushPromises()
	return wrapper
}

describe('CaseArchivalPanel', () => {
	beforeEach(() => {
		recomputes = []
		currentUser = { uid: 'els', isAdmin: false, groups: ['archivaris'] }
		readAnswer = vi.fn().mockResolvedValue({
			retention: {
				appraisal: 'vernietigen',
				disposalDate: '2031-04-01',
				retentionPeriod: 'P5Y',
				selectionListRow: '4.3.1',
				nomination: { status: 'nominated', rule: 'selection_list', at: '2026-04-01T10:00:00+02:00' },
			},
			archived: null,
			archiveStatus: '',
		})
	})

	it('shows the appraisal, the disposal date and the row that decided', async () => {
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="case-archival-appraisal"]').text()).toBe('vernietigen')
		expect(wrapper.find('[data-testid="case-archival-disposal-date"]').text()).toBe('2031-04-01')
		expect(wrapper.find('[data-testid="case-archival-selection-list-row"]').text()).toBe('4.3.1')
		expect(wrapper.find('[data-testid="case-archival-rule"]').text()).toBe('selection_list')
	})

	it('names the missing source when openregister could not nominate the case', async () => {
		readAnswer = vi.fn().mockResolvedValue({
			retention: {
				nomination: {
					status: 'unnominatable',
					unnominatableReason: 'no selectielijst row matches category 4.3.1',
				},
			},
			archived: null,
			archiveStatus: '',
		})

		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="case-archival-unnominatable"]').text())
			.toContain('no selectielijst row matches category 4.3.1')
		expect(wrapper.find('[data-testid="case-archival-none"]').exists()).toBe(false)
	})

	it('draws a case nobody has closed yet apart from one nobody could nominate', async () => {
		readAnswer = vi.fn().mockResolvedValue({ retention: {}, archived: null, archiveStatus: '' })

		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="case-archival-none"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-archival-unnominatable"]').exists()).toBe(false)
	})

	it('shows an error with a retry when openregister cannot be read', async () => {
		readAnswer = vi.fn().mockRejectedValue(new Error('openregister is unreachable'))

		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="case-archival-error"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-archival-retry"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-archival-facts"]').exists()).toBe(false)
	})

	it('refuses to recompute until a reason is typed, and sends nothing', async () => {
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="case-archival-recompute"]').attributes('disabled'))
			.toBeDefined()

		await wrapper.vm.recompute()

		expect(recomputes).toEqual([])
	})

	it('sends the reason once one is typed', async () => {
		const wrapper = await mountPanel()

		wrapper.vm.reason = 'the selectielijst was corrected'
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="case-archival-recompute"]').attributes('disabled'))
			.toBeUndefined()

		await wrapper.vm.recompute()

		expect(recomputes).toEqual([['case-1', 'the selectielijst was corrected']])
	})

	it('tells a reader without the role which role recomputing needs', async () => {
		currentUser = { uid: 'joris', isAdmin: false, groups: ['behandelaars'] }

		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="case-archival-recompute-denied"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-archival-recompute"]').exists()).toBe(false)
	})

	it('shows the transfer once a reviewer has handed the case over', async () => {
		readAnswer = vi.fn().mockResolvedValue({
			retention: {
				appraisal: 'blijvend_bewaren',
				nomination: { status: 'nominated', rule: 'selection_list' },
				outcome: {
					kind: 'transfer',
					at: '2026-09-01T09:00:00+02:00',
					by: 'els',
					reason: 'overbrenging naar het e-depot',
					transferListUuid: 'transfer-7',
				},
			},
			archived: null,
			archiveStatus: '',
		})

		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="case-archival-transfer-list"]').text()).toContain('transfer-7')
	})

	it('says so when the platform marker and the case disagree about being archived', async () => {
		readAnswer = vi.fn().mockResolvedValue({
			retention: { nomination: { status: 'nominated', rule: 'schema_default' } },
			archived: null,
			archiveStatus: 'archived',
		})

		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="case-archival-disagreement"]').exists()).toBe(true)
	})

	it('reads the archive marker openregister holds, not the case field', async () => {
		readAnswer = vi.fn().mockResolvedValue({
			retention: { nomination: { status: 'nominated', rule: 'schema_default' } },
			archived: { by: 'els', at: '2026-09-02T09:00:00+02:00', reason: 'afgehandeld' },
			archiveStatus: 'archived',
		})

		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="case-archival-archived"]').text()).toContain('els')
		expect(wrapper.find('[data-testid="case-archival-disagreement"]').exists()).toBe(false)
	})
})
