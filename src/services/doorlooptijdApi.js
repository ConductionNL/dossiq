/**
 * Doorlooptijd (throughput-time) API service for Procest.
 *
 * Wraps the single backend endpoint:
 *   GET /apps/procest/api/doorlooptijd/metrics
 *
 * The dashboard consumes the full payload; this service exposes one
 * function so the Vue layer never duplicates query-param wiring.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T04
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const METRICS_URL = generateUrl('/apps/procest/api/doorlooptijd/metrics')

/**
 * Fetch throughput-time metrics.
 *
 * @param {object} options Optional filters.
 * @param {string|null} [options.caseType=null] CaseType UUID or slug to scope on.
 * @param {string} [options.period='12m'] Period spec (e.g. `12m`, `6m`).
 * @param {number} [options.atRiskDays=5] At-risk threshold (days).
 * @return {Promise<object>} Metrics body { kpi, compliance, caseTypeBreakdown, cases }.
 */
export async function fetchMetrics({ caseType = null, period = '12m', atRiskDays = 5 } = {}) {
	const params = { period, atRiskDays }
	if (caseType) {
		params.caseType = caseType
	}

	const response = await axios.get(METRICS_URL, { params })
	return response.data
}
