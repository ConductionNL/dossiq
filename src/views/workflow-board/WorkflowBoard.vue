<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Workflow Board — a Kanban board with one column per non-final status type,
	open cases grouped into their current status, and status transitions
	operable by both drag-and-drop AND keyboard alone (each CaseCard's "Move
	to…" menu). Both paths call the same onDrop() -> saveObject('case', …),
	which is RBAC-enforced server-side; on failure the card reverts and an
	error toast shows.

	Spec: openspec/changes/kanban-board-keyboard-status-transition/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
-->
<template>
	<div class="workflow-board">
		<div class="workflow-board__header">
			<div>
				<h2>{{ t('procest', 'Workflow Board') }}</h2>
				<p class="workflow-board__subtitle">
					{{ t('procest', 'Drag cases between statuses, or use a case card\'s "Move to…" menu, to advance their workflow') }}
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
				:all-columns="columns"
				:loading="false"
				@drop="onDrop"
				@move="onDrop"
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
import { initializeStores } from '../../store/store.js'

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
			/**
			 * Merged columns, sorted by order — one per distinct non-final
			 * status NAME. Each column's `id` is the status name (status types
			 * are per-case-type, so names recur across workflows and are merged).
			 */
			columns: [],
			/** Map of column name → array of open cases whose status has that name. */
			casesByStatus: {},
			/** Map of caseType id → display name. */
			caseTypeMap: {},
			/** Map of statusType id → the statusType object. */
			statusById: {},
			/** Map of `${caseType}::${statusName}` → statusType id (drop resolution). */
			statusIdByTypeAndName: {},
			/** Id of the case currently being dragged. */
			draggedCaseId: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
	},
	// @spec exclude Boot-order guard (register OR object types before fetch); no spec scenario.
	async mounted() {
		// Register the OR object types before fetching — this page may mount
		// (via direct navigation) before the app-boot initializeStores() has
		// resolved, otherwise fetchCollection() throws "type not registered".
		await initializeStores()
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

				// Index every status type by id, and build a (caseType, name) →
				// id lookup so a drop can resolve the status id belonging to the
				// dropped case's own workflow.
				const byId = {}
				const byTypeAndName = {}
				for (const st of (statusTypes || [])) {
					byId[st.id] = st
					byTypeAndName[`${st.caseType}::${st.name || ''}`] = st.id
				}
				this.statusById = byId
				this.statusIdByTypeAndName = byTypeAndName

				const isFinal = st => st.isFinal === true || st.isFinal === 'true'

				// Merge all non-final status types that share a name into one
				// column. Status types are defined per case type, so the same
				// name (e.g. "Ontvangen") recurs across every workflow; without
				// this merge the board renders one near-empty column per type.
				const colByName = new Map()
				for (const st of (statusTypes || [])) {
					if (isFinal(st)) continue
					const name = st.name || ''
					const order = st.order ?? 999
					const existing = colByName.get(name)
					if (existing) {
						existing.order = Math.min(existing.order, order)
					} else {
						colByName.set(name, { id: name, name, order })
					}
				}
				this.columns = [...colByName.values()]
					.sort((a, b) => a.order - b.order || a.name.localeCompare(b.name))

				// Group open cases into their merged column by resolving each
				// case's status id to its status-type name. Cases in a final
				// status (or with an unknown/foreign status id) are omitted.
				const grouped = {}
				for (const col of this.columns) {
					grouped[col.id] = []
				}
				for (const c of (cases || [])) {
					const st = byId[c.status]
					if (!st || isFinal(st)) continue
					if (grouped[st.name]) {
						grouped[st.name].push(c)
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
		 * @param {string} newColumn The target column's name (merged status name)
		 * @return {Promise<void>}
		 */
		async onDrop(caseId, newColumn) {
			this.draggedCaseId = null

			// Locate the card and its current column.
			let fromColumn = null
			let caseObj = null
			for (const [colName, list] of Object.entries(this.casesByStatus)) {
				const found = list.find(c => String(c.id) === String(caseId))
				if (found) {
					fromColumn = colName
					caseObj = found
					break
				}
			}

			if (!caseObj || fromColumn === null) return
			if (String(fromColumn) === String(newColumn)) return

			// Columns are merged by status name, but the case's status must be a
			// concrete status-type id from its OWN workflow. Resolve the id that
			// carries this column's name within the case's case type; refuse the
			// move when that workflow has no status by that name.
			const targetStatusId = this.statusIdByTypeAndName[`${caseObj.caseType}::${newColumn}`]
			if (!targetStatusId) {
				showError(this.t('procest', 'That status is not part of this case\'s workflow.'))
				return
			}

			// Optimistic move.
			const fromList = this.casesByStatus[fromColumn].filter(c => String(c.id) !== String(caseId))
			Vue.set(this.casesByStatus, fromColumn, fromList)
			const movedCase = { ...caseObj, status: targetStatusId }
			Vue.set(this.casesByStatus, newColumn, [...(this.casesByStatus[newColumn] || []), movedCase])

			try {
				const result = await this.objectStore.saveObject('case', movedCase)
				if (!result) {
					throw new Error('save returned no result')
				}
			} catch (err) {
				console.error('[procest] failed to advance case status', err)
				// Revert: pull from the new column, restore in the old one.
				const revertedNew = (this.casesByStatus[newColumn] || [])
					.filter(c => String(c.id) !== String(caseId))
				Vue.set(this.casesByStatus, newColumn, revertedNew)
				Vue.set(this.casesByStatus, fromColumn, [...this.casesByStatus[fromColumn], caseObj])
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
