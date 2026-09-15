// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * An overrunning phase is visible on the case before the case term expires.
 *
 * The pair this spec exists for: a phase that has run over and a case term that
 * has not must BOTH be on screen, saying opposite things. A panel that showed
 * one overdue badge for "the case" would be the collapsed field this whole
 * change replaces, and it would pass any test that only looked for the word
 * overdue somewhere on the page.
 *
 * Three other ways this surface could be confidently wrong, each with its own
 * case below:
 *
 *  - A failed read must not render as "no deadline". A case with no clocks and
 *    a case that could not be read are opposite facts, and a handler told the
 *    second as the first stops looking for a deadline that exists.
 *
 *  - A clock whose kind the browser does not recognise must still get a row.
 *    Dropping it hides a deadline because the front end is older than the back
 *    end, which is exactly when nobody is looking.
 *
 *  - The tab must be REACHABLE: a sidebar tab naming a `component` that is not
 *    a registry key renders an empty panel and logs nothing, and an icon that
 *    is not in src/icons.js renders no glyph at all.
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { termRows, termTone } from '../../src/utils/caseTerms.js'

// The whole `@nextcloud/vue` index pulls NcRichContenteditable, which reaches
// for a Nextcloud runtime that does not exist under Vitest. The tab uses one
// component from it, so one stub is the whole surface.
vi.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: defineComponent({
		name: 'NcLoadingIcon',
		props: ['size'],
		render() {
			return h('span', { class: 'nc-loading-icon-stub' })
		},
	}),
}))

// Imported AFTER the mock so the component sees the stub.
const { default: CaseTermsTab } =
	await import('../../src/views/cases/components/CaseTermsTab.vue')

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')

/** The translate stub the util is handed, so no global is needed. */
function translate (app, text, vars = {}) {
  return text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match))
}

/** Every sidebar tab declared on any page of the manifest. */
function sidebarTabs() {
	const tabs = []
	for (const page of manifest.pages || []) {
		for (const tab of page?.config?.sidebar?.tabs || page?.sidebar?.tabs || []) {
			tabs.push({ page: page.id, ...tab })
		}
	}
	return tabs
}

/** A case whose phase has run over and whose statutory term has not. */
const overrunningPhase = {
	case: 'c1',
	terms: [
		{
			id: 's1',
			kind: 'statutory',
			endDate: '2026-10-25',
			daysLeft: 40,
			overdue: false,
			citizenVisible: true,
			status: 'lopend',
		},
		{
			id: 'p1',
			kind: 'phase',
			endDate: '2026-09-09',
			daysLeft: -6,
			overdue: true,
			citizenVisible: false,
			status: 'lopend',
		},
	],
	progress: {
		progress: 45,
		daysLeft: 40,
		phasesDone: 1,
		phasesTotal: 4,
		phaseOverdue: true,
		plannedOverdue: false,
		statutoryOverdue: false,
	},
}

describe('The phase clock is visible before the case clock', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('shows the phase overdue and the case term on time, at the same moment', async () => {
		axios.get.mockResolvedValue({ data: overrunningPhase })

		const wrapper = mount(CaseTermsTab, { props: { objectId: 'c1' } })
		await flushPromises()

		const rows = wrapper.findAll('.case-terms-tab__row')
		expect(rows).toHaveLength(2)

		// The statutory term is first in reading order, and it is NOT overdue.
		expect(rows[0].classes()).toContain('case-terms-tab__row--ontime')
		expect(rows[0].text()).toContain('40 days left')

		// The phase is, on the same screen, at the same time.
		expect(rows[1].classes()).toContain('case-terms-tab__row--overdue')
		expect(rows[1].text()).toContain('6 days over')
	})

	it('says at least one clock has run out', async () => {
		axios.get.mockResolvedValue({ data: overrunningPhase })

		const wrapper = mount(CaseTermsTab, { props: { objectId: 'c1' } })
		await flushPromises()

		expect(wrapper.find('.case-terms-tab__attention').exists()).toBe(true)
	})

	it('does not claim a case has no deadline when the read failed', async () => {
		axios.get.mockRejectedValue(new Error('the endpoint is unreachable'))

		const wrapper = mount(CaseTermsTab, { props: { objectId: 'c1' } })
		await flushPromises()

		expect(wrapper.find('.case-terms-tab__unreadable').exists()).toBe(true)
		expect(wrapper.find('.case-terms-tab__empty').exists()).toBe(false)
		expect(wrapper.findAll('.case-terms-tab__row')).toHaveLength(0)
	})

	it('renders a clock whose kind it does not recognise, at the end', () => {
		const rows = termRows(
			[
				{ kind: 'phase', endDate: '2026-09-09', daysLeft: -6, overdue: true },
				{ kind: 'something-new', endDate: '2026-12-01', daysLeft: 70, overdue: false },
				{ kind: 'statutory', endDate: '2026-10-25', daysLeft: 40, overdue: false },
			],
			translate,
		)

		expect(rows.map((row) => row.kind)).toEqual(['statutory', 'phase', 'something-new'])
		expect(rows[2].label).toBe('something-new')
	})

	it('keeps an overdue clock in its place rather than floating it to the top', () => {
		const rows = termRows(
			[
				{ kind: 'statutory', endDate: '2026-10-25', daysLeft: 40, overdue: false },
				{ kind: 'phase', endDate: '2026-09-09', daysLeft: -6, overdue: true },
			],
			translate,
		)

		expect(rows[0].kind).toBe('statutory')
	})

	it('reads a clock with no end date as unknown, not as on time', () => {
		expect(termTone({ kind: 'planned', endDate: '', daysLeft: 0 })).toBe('unknown')
		expect(termTone({ kind: 'planned', endDate: '2026-12-01', daysLeft: 70 })).toBe('ontime')
		expect(termTone({ kind: 'planned', endDate: '2026-09-18', daysLeft: 3 })).toBe('soon')
	})
})

describe('The Terms tab is reachable', () => {
	it('names a component that is a registry key', () => {
		const tab = sidebarTabs().find((entry) => entry.id === 'terms')
		expect(tab, 'CaseDetail declares a Terms sidebar tab').toBeTruthy()
		expect(tab.component).toBe('CaseTermsTab')
		expect(registrySource).toContain('CaseTermsTab: {')
		expect(registrySource).toContain("import CaseTermsTab from './views/cases/components/CaseTermsTab.vue'")
	})

	it('names an icon the app actually imports', () => {
		const tab = sidebarTabs().find((entry) => entry.id === 'terms')
		expect(iconsSource).toContain(`import ${tab.icon} from 'vue-material-design-icons/${tab.icon}.vue'`)
	})

	it('is registered as a page and not as a custom widget', () => {
		// ADR-049: a custom `kind: "widget"` entry grows the ratchet. A sidebar
		// tab component is a `page` in this registry, which is what the four
		// tabs beside it are.
		const entry = registrySource.slice(registrySource.indexOf('CaseTermsTab: {'))
		expect(entry.slice(0, 200)).toContain("kind: 'page'")
	})
})
