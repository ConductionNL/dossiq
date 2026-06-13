<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Workflow Board — a Kanban board with one column per non-final status type,
	open cases grouped into their current status, and drag-to-advance status
	transitions. Dropping a card calls saveObject('case', …) which is RBAC-
	enforced server-side; on failure the card reverts and an error toast shows.

	Spec: openspec/changes/dashboard/specs/dashboard/spec.md#REQ-DASH-V1-006
-->
<template>
	<div class="workflow-board">
		<div class="workflow-board__header">
			<div>
				<h2>{{ t('procest', 'Workflow Board') }}</h2>
				<p class="workflow-board__subtitle">
					{{ t('procest', 'Drag cases between statuses to advance their workflow') }}
				</p>
			</div>
			<NcButton type="tertiary" @click="$router.push({ name: 'Dashboard' })">
				{{ t('procest', 'Dashboard') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" class="workflow-board__loading" />

		<div v-else-if="error" class="workflow-board__error">
			<p>{{ error }}</p>
			<NcButton type="tertiary" @click="fetchData">
				{{ t('procest', 'Retry') }}
			</NcButton>
		</div>

		<div v-else-if="columns.length === 0" class="workflow-board__empty">
			<p>{{ t('procest', 'No workflow statuses configured. Define status types in Settings to use the board.') }}</p>
		</div>

		<div
			v-else
			class="workflow-board__columns"
			tabindex="0"
			role="region"
			:aria-label="t('procest', 'Workflow board columns')">
			<BoardColumn
				v-for="col in columns"
				:key="col.id"
				:status-type="col"
				:cases="casesByStatus[col.id] || []"
				:case-type-map="caseTypeMap"
				:loading="false"
				@drop="onDrop"
				@click-case="goToCase"
				@dragstart="onDragStart" />
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import Vue from 'vue'
import BoardColumn from './BoardColumn.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'WorkflowBoard',
	components: {
		NcButton,
		NcLoadingIcon,
		BoardColumn,
	},
	data() {
		return {
			loading: true,
			error: null,
			/** Non-final status types, sorted by order — one column each. */
			columns: [],
			/** Map of statusId → array of open cases in that status. */
			casesByStatus: {},
			/** Map of caseType id → display name. */
			caseTypeMap: {},
			/** Id of the case currently being dragged. */
			draggedCaseId: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
	},
	async mounted() {
		await this.fetchData()
	},
	methods: {
		/**
		 * Load status types, case types and open cases in parallel, then build
		 * the column model and the status → cases grouping.
		 *
		 * @return {Promise<void>}
		 */
		async fetchData() {
			this.loading = true
			this.error = null
			try {
				const [statusTypes, caseTypes, cases] = await Promise.all([
					this.objectStore.fetchCollection('statusType', { _limit: 200 }),
					this.objectStore.fetchCollection('caseType', { _limit: 100 }),
					this.objectStore.fetchCollection('case', { _limit: 500 }),
				])

				const typeMap = {}
				for (const ct of (caseTypes || [])) {
					typeMap[ct.id] = ct.title || ct.name || ''
				}
				this.caseTypeMap = typeMap

				// Non-final columns, ordered.
				const finalIds = new Set(
					(statusTypes || [])
						.filter(st => st.isFinal === true || st.isFinal === 'true')
						.map(st => st.id),
				)
				this.columns = (statusTypes || [])
					.filter(st => !finalIds.has(st.id))
					.sort((a, b) => (a.order ?? 999) - (b.order ?? 999))

				// Group only open (non-final-status) cases into their column.
				const grouped = {}
				for (const col of this.columns) {
					grouped[col.id] = []
				}
				for (const c of (cases || [])) {
					if (finalIds.has(c.status)) continue
					if (grouped[c.status]) {
						grouped[c.status].push(c)
					}
				}
				this.casesByStatus = grouped
			} catch (err) {
				console.error('[procest] failed to load workflow board', err)
				this.error = this.t('procest', 'Failed to load the workflow board.')
			} finally {
				this.loading = false
			}
		},
		/**
		 * Track the in-flight card id.
		 *
		 * @param {string} caseId The dragged case id
		 * @return {void}
		 */
		onDragStart(caseId) {
			this.draggedCaseId = caseId
		},
		/**
		 * Move a case card to a new status column. Optimistically moves the card
		 * in local state, persists via saveObject('case', …) (RBAC-enforced),
		 * and reverts + toasts on failure.
		 *
		 * @param {string} caseId The dropped case id
		 * @param {string} newStatusId The target column's status id
		 * @return {Promise<void>}
		 */
		async onDrop(caseId, newStatusId) {
			this.draggedCaseId = null

			// Locate the card and its current column.
			let fromStatusId = null
			let caseObj = null
			for (const [statusId, list] of Object.entries(this.casesByStatus)) {
				const found = list.find(c => String(c.id) === String(caseId))
				if (found) {
					fromStatusId = statusId
					caseObj = found
					break
				}
			}

			if (!caseObj || fromStatusId === null) return
			if (String(fromStatusId) === String(newStatusId)) return

			// Optimistic move.
			const fromList = this.casesByStatus[fromStatusId].filter(c => String(c.id) !== String(caseId))
			Vue.set(this.casesByStatus, fromStatusId, fromList)
			const movedCase = { ...caseObj, status: newStatusId }
			Vue.set(this.casesByStatus, newStatusId, [...(this.casesByStatus[newStatusId] || []), movedCase])

			try {
				const result = await this.objectStore.saveObject('case', movedCase)
				if (!result) {
					throw new Error('save returned no result')
				}
			} catch (err) {
				console.error('[procest] failed to advance case status', err)
				// Revert: pull from the new column, restore in the old one.
				const revertedNew = (this.casesByStatus[newStatusId] || [])
					.filter(c => String(c.id) !== String(caseId))
				Vue.set(this.casesByStatus, newStatusId, revertedNew)
				Vue.set(this.casesByStatus, fromStatusId, [...this.casesByStatus[fromStatusId], caseObj])
				showError(this.t('procest', 'Could not move the case. You may not have permission, or the change failed.'))
			}
		},
		/**
		 * Navigate to a case detail view.
		 *
		 * @param {string} caseId The case id
		 * @return {void}
		 */
		goToCase(caseId) {
			this.$router.push({ name: 'CaseDetail', params: { id: caseId } }).catch(() => {})
		},
	},
}
</script>

<style scoped>
.workflow-board {
	padding: 16px;
}

.workflow-board__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 16px;
	gap: 16px;
}

.workflow-board__header h2 {
	margin: 0;
}

.workflow-board__subtitle {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.workflow-board__columns {
	display: flex;
	gap: 12px;
	overflow-x: auto;
	align-items: flex-start;
	padding-bottom: 8px;
}

.workflow-board__loading {
	margin: 48px auto;
}

.workflow-board__error,
.workflow-board__empty {
	text-align: center;
	padding: 48px 16px;
	color: var(--color-text-maxcontrast);
}

.workflow-board__error {
	color: var(--color-error);
}
</style>
