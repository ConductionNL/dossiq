// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The deadline-monitoring store must call dossiq's own routes.
 *
 * The defect this pins down: after the procest to dossiq rename the store
 * still requested `/apps/procest/api/termijn/...`. Nothing answers under
 * that id any more, so the termijnbewaking Overview 404'd on every load
 * while `appinfo/routes.php` served the same paths under dossiq. A URL is a
 * string, so no type or lint check could catch it; this does.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
 */
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { useTermijnDashboardStore } from '../../src/store/modules/termijnDashboard.js'

/**
 * The paths axios.get was called with, in call order.
 *
 * @return {string[]} The request paths.
 */
function requestedPaths() {
	return axios.get.mock.calls.map(([url]) => url)
}

describe('termijnDashboard store routes', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		axios.get.mockReset()
		axios.get.mockResolvedValue({ data: {} })
	})

	it('loads the KPI block from the dossiq termijn route', async () => {
		await useTermijnDashboardStore().loadKpi({ force: true })
		expect(requestedPaths()).toEqual([
			'/index.php/apps/dossiq/api/termijn/dashboard/kpi',
		])
	})

	it('loads the quarterly report from the dossiq termijn route', async () => {
		await useTermijnDashboardStore().loadQuarterly('2026-Q3')
		expect(requestedPaths()).toEqual([
			'/index.php/apps/dossiq/api/termijn/reports/kwartaal',
		])
	})

	it('loads the annual audit from the dossiq termijn route', async () => {
		await useTermijnDashboardStore().loadAnnual(2026)
		expect(requestedPaths()).toEqual([
			'/index.php/apps/dossiq/api/termijn/reports/jaarrekening',
		])
	})

	it('never addresses the retired procest app id', async () => {
		const store = useTermijnDashboardStore()
		await store.loadKpi({ force: true })
		await store.loadQuarterly('2026-Q3')
		await store.loadAnnual(2026)
		for (const url of requestedPaths()) {
			expect(url).not.toContain('/apps/procest/')
		}
	})
})
