/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The blocks the settings boards draw on the admin tabs: the search index run
 * and rows (DqInstellingen, DqBeheerGeavanceerd), the apps dossiq needs, the
 * term table, queue urgency and Woo refusal grounds on Integrations, the time
 * zone, date format and beta switch on General (DqInstellingenAlgemeen), the
 * advice and public notice cards three abreast on VTH (DqVthInstellingen), and
 * the setup wizard's first step named Welcome (DqInstellen).
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { lastRunSeconds } from '../../src/services/searchIndexApi.js'
import { indexRowFacts, runFacts } from '../../src/utils/searchIndexFacts.js'
import { queueOrderFields, textsAndAccessFields } from '../../src/views/settings/adminFields.js'
import { adviceRows, publicationRows, wooRefusalGroundColumns } from '../../src/views/settings/adminSettingsBlocks.js'

const read = (...parts) => fs.readFileSync(path.resolve(__dirname, '../..', ...parts), 'utf8')
const admin = read('src', 'views', 'settings', 'AdminRoot.vue')
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match))

const NEVER_RAN = { lastRun: null, lastRunSeconds: null, rowsInTables: null, rowsInIndex: null, indexed: null }

describe('the search index facts', () => {
	it('reads the duration off the run report', () => {
		expect(lastRunSeconds({ startedAt: '2026-10-01T14:28:00Z', finishedAt: '2026-10-01T14:32:12Z' })).toBe(252)
		expect(lastRunSeconds([])).toBeNull()
		expect(lastRunSeconds({ startedAt: '2026-10-01T14:28:00Z' })).toBeNull()
	})

	it('never leaves a run value empty on a new installation', () => {
		const rows = runFacts(NEVER_RAN)
		expect(rows.map((row) => row.value)).toEqual(['Never', 'no run yet', 'nothing yet'])
	})

	it('writes the duration in minutes and seconds and the counts per kind', () => {
		const rows = runFacts({ ...NEVER_RAN, lastRun: '2026-10-01T14:32:12Z', lastRunSeconds: 252, indexed: { cases: 12, documents: 31 } })
		expect(rows[1].value).toBe('4 min 12 s')
		expect(rows[2].value).toBe('12 cases, 31 documents')
	})

	it('leaves out a row count OpenRegister does not report, and names the rebuild command', () => {
		const labels = indexRowFacts(NEVER_RAN).map((row) => row.label)
		expect(labels).toEqual(['Latest run', 'Rebuild with'])
		expect(indexRowFacts(NEVER_RAN)[1].value).toBe('occ openregister:tables:search-index rebuild')
		expect(indexRowFacts({ ...NEVER_RAN, rowsInTables: 5, rowsInIndex: 5 }).map((row) => row.label).slice(0, 2))
			.toEqual(['Rows in the tables', 'Rows in the index'])
	})
})

describe('the Integrations tab blocks', () => {
	it('draws the dependencies, terms, urgency, search index and refusal grounds after Kadaster', () => {
		const order = ['section-kadaster', 'section-dependency-apps', 'section-term-definitions', 'section-queue-order', 'section-search-index-run', 'section-woo-refusal-grounds']
		const at = order.map((id) => admin.indexOf(`id="${id}"`))
		expect(at.every((index) => index > 0)).toBe(true)
		expect([...at].sort((a, b) => a - b)).toEqual(at)
	})

	it('adds a connection on integriq, and only when integriq is there', () => {
		expect(admin).toContain('<div v-if="ownerApps.integriq" class="dq-settings-action-row cn-settings-row-actions">')
		expect(admin).toContain('generateUrl(INTEGRIQ_CONNECTIONS_PATH)')
	})

	it('shows the term table without case type and actions, Add definition in the header', () => {
		const tab = read('src', 'views', 'settings', 'tabs', 'DeadlineDefinitionsTab.vue')
		expect(tab).toContain('<th v-if="!summary" scope="col">')
		expect(admin).toContain('<DeadlineDefinitionsTab v-if="storesReady" ref="termSummary" :summary="true" />')
		expect(admin).toContain('$refs.termSummary.openNew()')
	})

	it('orders the queue by three coloured thresholds and two weights', () => {
		const fields = queueOrderFields(t)
		expect(fields.map((field) => [field.key, field.hint])).toEqual([
			['queue_critical_days', 'red'],
			['queue_warning_days', 'orange'],
			['queue_in_sight_days', 'grey'],
			['queue_idle_weight', 'per day without action'],
			['queue_resident_wait_weight', 'from 0 to 50'],
		])
	})

	it('lists a refusal ground by code, label, article, kind and whether it is active', () => {
		expect(wooRefusalGroundColumns(t).map((column) => column.key)).toEqual(['code', 'label', 'article', 'kind', 'status'])
		expect(wooRefusalGroundColumns(t).at(-1)).toMatchObject({ widget: 'badge', widgetProps: { colorMap: { active: 'success' } } })
	})
})

describe('the General tab', () => {
	it('carries the time zone, the date format and the new case page switch under beta features', () => {
		const keys = textsAndAccessFields(t).map((field) => field.key)
		expect(keys.slice(-4)).toEqual(['timezone', 'date_format', 'betaFeatures', 'feature_new_case_page'])
	})
})

describe('the VTH tab', () => {
	it('lays checklists, advice, public notices and enforcement out three abreast', () => {
		const trio = admin.slice(admin.indexOf('<div class="dq-settings-trio cn-settings-cards">'))
		const order = ["'Inspection checklists'", 'id="section-advice"', 'id="section-publications"', 'id="section-enforcement-strategy"']
		const at = order.map((needle) => trio.indexOf(needle))
		expect(at.every((index) => index > 0)).toBe(true)
		expect([...at].sort((a, b) => a - b)).toEqual(at)
		expect(admin).toMatch(/\.dq-settings-trio \{\s*grid-template-columns: repeat\(auto-fit, minmax\(340px, 1fr\)\);/)
	})

	it('names the advisers with how they are reached', () => {
		const rows = adviceRows(t, { defaultDeadlineDays: 28, warningOffsetDays: 3 }, [
			{ name: 'Welstandscommissie', type: 'internal' },
			{ name: 'Brandweer', type: 'external', email: 'advies@brandweer.example' },
		])
		expect(rows.map((row) => row.value)).toEqual([
			'4 weeks after the request, adjustable per adviser',
			'Welstandscommissie (internal), Brandweer (external, by email)',
			'3 days',
		])
	})

	it('says a publication setting is not set rather than inventing one', () => {
		const rows = publicationRows(t, {})
		expect(rows[0]).toMatchObject({ value: 'Not set', muted: true })
		expect(rows[1]).toMatchObject({ value: 'Not set', muted: true })
	})
})

describe('the card rows', () => {
	it('keep their own box on the admin grid, so the cards sit side by side', () => {
		expect(admin).toContain('<div class="dq-settings-pair cn-settings-cards">')
		expect(admin).toContain('<div class="dq-settings-trio cn-settings-cards">')
	})

	it('fit three cards in the 1119px content column, each as tall as the row', () => {
		const rule = admin.slice(admin.lastIndexOf('.dq-settings-trio {'))
		expect(rule).toContain('minmax(340px, 1fr)')
		expect(rule).toContain('align-items: stretch')
	})
})

describe('the facts list', () => {
	it('takes back the dt and dd padding and column that server.css sets', () => {
		const list = read('src', 'views', 'settings', 'components', 'SettingsFactsList.vue')
		const dt = list.slice(list.indexOf('.settings-facts__row dt {'))
		expect(dt).toMatch(/width: auto;\s+padding: 0;/)
		expect(dt).toContain('text-align: start')
		expect(list.slice(list.indexOf('.settings-facts__row dd {'))).toContain('padding: 0')
	})

	it('draws the rows of the Advanced search index card as read-only boxes', () => {
		const facts = read('src', 'views', 'settings', 'components', 'SearchIndexFacts.vue')
		expect(facts).toContain(":layout=\"variant === 'run' ? 'inline' : 'boxes'\"")
		expect(read('src', 'views', 'settings', 'components', 'SettingsFactsList.vue')).toContain('class="settings-facts__box"')
	})
})

describe('the setup wizard', () => {
	it('names its first step Welcome', () => {
		const manifest = JSON.parse(read('src', 'manifest.json'))
		expect(manifest.setup.steps[0]).toMatchObject({ id: 'welcome', title: 'Welcome' })
		expect(JSON.parse(read('l10n', 'nl.json')).translations.Welcome).toBe('Welkom')
	})
})
