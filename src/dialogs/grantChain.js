/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the grant dialogs of a case share: the read of the case's grant chain
 * (`GET /api/cases/{caseId}/grant-chain`), the post of a step to its grant
 * route, the label of an execution and an amount in euro as the reader
 * writes it.
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
 */

import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * Read the executions of the case's grants.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<Array<object>>} The executions, each with its reports and settlements.
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
 */
export async function loadGrantChain(caseId) {
	const { data } = await axios.get(generateUrl(`/apps/dossiq/api/cases/${encodeURIComponent(caseId)}/grant-chain`))
	return Array.isArray(data?.executions) ? data.executions : []
}

/**
 * The words for one execution in a picker: its application number, else its id.
 *
 * @param {object} execution An execution of the grant chain.
 * @return {string}
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
 */
export function executionLabel(execution) {
	const number = String(execution?.applicationNumber ?? '')
	return number === '' ? t('dossiq', 'Grant {id}', { id: String(execution?.id ?? '') }) : t('dossiq', 'Grant {number}', { number })
}

/**
 * An amount in euro, with at most two decimals.
 *
 * @param {number|null|undefined} amount The amount.
 * @return {string} The amount, or an empty string when there is none.
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
 */
export function formatAmount(amount) {
	if (amount === null || amount === undefined || Number.isFinite(Number(amount)) === false) {
		return ''
	}
	return Number(amount).toLocaleString(undefined, { style: 'currency', currency: 'EUR', maximumFractionDigits: 2 })
}

/**
 * The message a refused write carries, else the fallback.
 *
 * @param {Error} e The axios error.
 * @param {string} fallback What to say when the server said nothing.
 * @return {string}
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
 */
export function refusalOf(e, fallback) {
	return e?.response?.data?.error || e?.response?.data?.message || fallback
}

/**
 * The state and the two calls every grant step dialog makes: read the case's
 * grant chain on mount (the dialog's `afterLoad()` picks a row), and post the
 * step, closing on success and keeping a refusal in the dialog.
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
 */
export const grantStep = {
	props: {
		/** The case uuid whose grant the step is on. */
		caseId: { type: String, required: true },
	},

	emits: ['close', 'done'],

	data() {
		return {
			executions: [],
			loading: false,
			saving: false,
			error: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the case's grant chain, then let the dialog pick its row.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.executions = await loadGrantChain(this.caseId)
				this.afterLoad()
			} catch (e) {
				this.executions = []
				this.error = refusalOf(e, t('dossiq', 'The grants of this case could not be read.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Pick the row the step acts on once the chain is read; a dialog overrides it.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		afterLoad() {},

		/**
		 * Post the step to its grant route.
		 *
		 * @param {string} path The route under /apps/dossiq/api/subsidies.
		 * @param {object} body What the route reads.
		 * @param {function(object): string} success The sentence for the answer.
		 * @param {string} failure What to say when the server says nothing.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
		 */
		async send(path, body, success, failure) {
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.post(generateUrl(`/apps/dossiq/api/subsidies/${path}`), body)
				showSuccess(success(data))
				this.$emit('done', data)
				this.$emit('close')
			} catch (e) {
				this.error = refusalOf(e, failure)
			} finally {
				this.saving = false
			}
		},
	},
}
