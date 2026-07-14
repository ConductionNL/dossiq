/**
 * Process mining bottleneck-report API service for Procest.
 *
 * Wraps the single backend endpoint:
 *   GET /apps/procest/api/reports/process-mining
 *
 * The dashboard consumes the full payload; this service exposes one
 * function so the Vue layer never duplicates query-param wiring.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T05
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const REPORT_URL = generateUrl('/apps/procest/api/reports/process-mining')

/**
 * Fetch the process-mining bottleneck report.
 *
 * @param {object} options Optional filters.
 * @param {string|null} [options.from] Period start (`Y-m-d`).
 * @param {string|null} [options.to] Period end (`Y-m-d`).
 * @param {string|null} [options.caseType] CaseType UUID or slug to scope on.
 * @return {Promise<object>} Report body { period, caseTypeFilter, caseTypes, throughputTrend }.
 */
export async function fetchProcessMiningReport({ from = null, to = null, caseType = null } = {}) {
	const params = {}
	if (from) {
		params.from = from
	}
	if (to) {
		params.to = to
	}
	if (caseType) {
		params.caseType = caseType
	}

	const response = await axios.get(REPORT_URL, { params })
	return response.data
}
