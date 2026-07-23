<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  CMMN adaptive case-plan panel (cmmn-adaptive-case) for the manifest
  CaseDetail overview. Sibling to the BPMN status-transition UI (which lives
  in the lifecycle-actions header) — this panel is the CMMN counterpart,
  shown only for cases whose caseType is CMMN-managed
  (caseType.handlingModel = 'cmmn'). Follows CaseAssistantPanel's
  availability-gated, route-param-driven pattern: renders NOTHING when the
  case is BPMN-managed (the engine call fails with case_not_cmmn_managed) so
  BPMN-handled cases see no CMMN chrome at all.

  Read-only-plus-actions: items are grouped by stage with state badges;
  enable/complete/terminate actions are offered only where the engine says
  they are currently legal (`enableableDiscretionary`/canComplete/canTerminate).

  @spec openspec/specs/cmmn-adaptive-case/spec.md
-->
<template>
	<div v-if="available" class="cmmn-plan-panel" data-testid="cmmn-plan-panel">
		<NcLoadingIcon v-if="loading" :size="20" />

		<template v-else>
			<div v-if="tree.length === 0" class="cmmn-plan-panel__empty">
				{{ t('procest', 'No case plan items') }}
			</div>

			<ul v-else class="cmmn-plan-panel__tree">
				<CmmnPlanItemNode
					v-for="node in tree"
					:key="node.id"
					:node="node"
					:milestones="milestones"
					:enableable-discretionary="enableableDiscretionary"
					:busy="busyItemId"
					@enable="onEnable"
					@complete="onComplete"
					@terminate="onTerminate" />
			</ul>

			<p v-if="errorMessage" class="cmmn-plan-panel__error" role="alert">
				{{ errorMessage }}
			</p>
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import CmmnPlanItemNode from './CmmnPlanItemNode.vue'
import { fetchCasePlan, enableDiscretionaryItem, completeTask, terminateTask } from '../../../services/cmmnApi.js'
import { buildPlanTree } from '../../../utils/cmmnHelpers.js'

export default {
	name: 'CmmnCasePlanPanel',
	components: { NcLoadingIcon, CmmnPlanItemNode },
	data() {
		return {
			available: false,
			loading: true,
			items: [],
			enableableDiscretionary: [],
			milestones: {},
			errorMessage: '',
			busyItemId: null,
		}
	},
	computed: {
		/** @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-001 */
		caseId() {
			return this.$route?.params?.id || null
		},
		/** @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-001 */
		tree() {
			return buildPlanTree(this.items)
		},
	},
	async created() {
		await this.loadPlan()
	},
	methods: {
		t,
		/**
		 * Load the case plan. A `case_not_cmmn_managed` (409) response means
		 * this case is BPMN-handled — the panel stays hidden, not erroring.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-001
		 */
		async loadPlan() {
			if (!this.caseId) {
				this.loading = false
				return
			}
			this.loading = true
			try {
				const plan = await fetchCasePlan(this.caseId)
				this.applyPlan(plan)
				this.available = true
			} catch (error) {
				if (error?.response?.status === 409) {
					// Not a CMMN-managed case — render nothing, no error chrome.
					this.available = false
				} else {
					console.error('Failed to load CMMN case plan:', error)
					this.available = false
				}
			} finally {
				this.loading = false
			}
		},
		/**
		 * @param {object} plan `{items, enableableDiscretionary, milestones, caseFile}`
		 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-001
		 */
		applyPlan(plan) {
			this.items = Array.isArray(plan?.items) ? plan.items : []
			this.enableableDiscretionary = Array.isArray(plan?.enableableDiscretionary) ? plan.enableableDiscretionary : []
			this.milestones = plan?.milestones || {}
		},
		/**
		 * @param {string} itemId Plan-item id
		 * @return {Promise<void>}
		 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-004
		 */
		async onEnable(itemId) {
			await this.runAction(itemId, () => enableDiscretionaryItem(this.caseId, itemId))
		},
		/**
		 * @param {string} itemId Plan-item id
		 * @return {Promise<void>}
		 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
		 */
		async onComplete(itemId) {
			await this.runAction(itemId, () => completeTask(this.caseId, itemId))
		},
		/**
		 * @param {string} itemId Plan-item id
		 * @return {Promise<void>}
		 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
		 */
		async onTerminate(itemId) {
			await this.runAction(itemId, () => terminateTask(this.caseId, itemId))
		},
		/**
		 * Shared action runner: busy-guard, apply the returned plan, surface errors.
		 *
		 * @param {string} itemId Plan-item id being acted on
		 * @param {Function} call The API call to invoke
		 * @return {Promise<void>}
		 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
		 */
		async runAction(itemId, call) {
			this.busyItemId = itemId
			this.errorMessage = ''
			try {
				const plan = await call()
				this.applyPlan(plan)
			} catch (error) {
				this.errorMessage = t('procest', 'This action could not be completed. The case plan may have changed — try reloading.')
				console.error('CMMN case-plan action failed:', error)
			} finally {
				this.busyItemId = null
			}
		},
	},
}
</script>

<style scoped>
.cmmn-plan-panel {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.cmmn-plan-panel__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.cmmn-plan-panel__tree {
	list-style: none;
	margin: 0;
	padding: 0;
}

.cmmn-plan-panel__error {
	color: var(--color-error-text, var(--color-error));
	font-size: 0.9em;
}
</style>
