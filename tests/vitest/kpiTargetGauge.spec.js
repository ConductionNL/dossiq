// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * A dashboard can show a KPI as a gauge: the value against its target, and
 * both in words (REQ-CRS-35, row 10.1).
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/dashboard/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import KpiTargetGauge from '../../src/components/dashboard/KpiTargetGauge.vue'
import { formatGaugeNumber, gaugeReading, toNumber } from '../../src/utils/kpiGauge.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const registrySource = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
const dashboard = manifest.pages.find((page) => page.id === 'Dashboard').config
const preset = dashboard.userWidgets.find((entry) => entry.id === 'on-time-against-target')

describe('gaugeReading', () => {
	it('keeps the value and target as they are and scales both on one axis', () => {
		expect(gaugeReading(80, 90, { max: 100, suffix: '%' })).toEqual({
			value: 80,
			target: 90,
			valueText: '80%',
			targetText: '90%',
			fill: 80,
			mark: 90,
			met: false,
		})
	})

	it('stretches the axis so a value over the declared maximum stays on the bar', () => {
		const reading = gaugeReading(150, 100, { max: 120 })
		expect(reading.fill).toBe(100)
		expect(reading.mark).toBeCloseTo(66.667, 2)
		expect(reading.met).toBe(true)
	})

	it('without a maximum, runs the axis to the larger of the two', () => {
		const reading = gaugeReading(30, 60)
		expect(reading.fill).toBe(50)
		expect(reading.mark).toBe(100)
	})

	it('reads a lower-is-better KPI as met at or below the target', () => {
		expect(gaugeReading(5, 7, { invert: true }).met).toBe(true)
		expect(gaugeReading(9, 7, { invert: true }).met).toBe(false)
	})

	it('has no reading when value or target is not a number', () => {
		expect(gaugeReading(null, 90)).toBeNull()
		expect(gaugeReading(80, 'ninety')).toBeNull()
		expect(gaugeReading(true, 90)).toBeNull()
	})

	it('reads numbers and formats them with decimals and suffix', () => {
		expect(toNumber('12.5')).toBe(12.5)
		expect(toNumber('')).toBeNull()
		expect(formatGaugeNumber(80.456, { decimals: 1, suffix: '%' })).toBe('80.5%')
		expect(formatGaugeNumber(80.456, { decimals: -1 })).toBe('80')
	})
})

describe('KpiTargetGauge', () => {
	let axios

	beforeEach(async () => {
		axios = (await import('@nextcloud/axios')).default
		axios.get.mockReset()
	})

	it('shows 80 against 90 and reads "80% of target 90%"', async () => {
		axios.get.mockResolvedValue({ data: { slaCompliance: 80 } })
		const wrapper = mount(KpiTargetGauge, { props: { content: preset.widget.content } })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/dossiq/api/dashboard/kpis')
		expect(wrapper.get('[data-testid="kpi-target-gauge-words"]').text()).toBe('80% of target 90%')
		const meter = wrapper.get('[role="meter"]')
		expect(meter.attributes('aria-valuenow')).toBe('80')
		expect(meter.attributes('aria-valuemax')).toBe('100')
		expect(meter.attributes('aria-valuetext')).toBe('80% of target 90%')
		expect(wrapper.get('.kpi-target-gauge__fill').attributes('style')).toContain('width: 80%')
		expect(wrapper.get('[data-testid="kpi-target-gauge-mark"]').attributes('style')).toContain('left: 90%')
		expect(wrapper.find('.kpi-target-gauge__fill--short').exists()).toBe(true)
	})

	it('says the figure is not available when the answer leaves it out, never 0', async () => {
		axios.get.mockResolvedValue({ data: { slaCompliance: null } })
		const wrapper = mount(KpiTargetGauge, { props: { content: preset.widget.content } })
		await flushPromises()

		expect(wrapper.text()).toContain('This figure is not available to you')
		expect(wrapper.find('[role="meter"]').exists()).toBe(false)
	})

	it('says the figure could not be loaded when the read fails', async () => {
		axios.get.mockRejectedValue(new Error('500'))
		const wrapper = mount(KpiTargetGauge, { props: { content: preset.widget.content } })
		await flushPromises()

		expect(wrapper.get('[role="alert"]').text()).toBe('This figure could not be loaded')
	})

	it('reads nothing for an endpoint outside the apps', async () => {
		const wrapper = mount(KpiTargetGauge, { props: { content: { ...preset.widget.content, endpoint: 'https://example.org/x' } } })
		await flushPromises()

		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('This figure is not available to you')
	})
})

describe('the dashboard offers the gauge', () => {
	it('as a preset a reader adds, resolved by the app registry', () => {
		expect(preset.kind).toBe('kpi-target-gauge')
		expect(preset.widget.type).toBe('kpi-target-gauge')
		expect(preset.widget.content).toMatchObject({ valueField: 'slaCompliance', target: 90 })
		expect(registrySource).toMatch(/'kpi-target-gauge': \{[\s\S]*?component: KpiTargetGauge/)
	})
})
