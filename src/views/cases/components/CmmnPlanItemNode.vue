<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Recursive plan-item node for CmmnCasePlanPanel — one stage/humanTask/
  milestone row with a state badge, optional milestone-achieved marker, and
  enable/complete/terminate actions gated by what the engine currently
  allows. Renders its own `children` recursively for nested stages.

  @spec openspec/specs/cmmn-adaptive-case/spec.md
-->
<template>
	<li
		class="cmmn-plan-item"
		:class="`cmmn-plan-item--${node.type}`"
		:data-testid="`cmmn-plan-item-${node.id}`">
		<div class="cmmn-plan-item__row">
			<span class="cmmn-plan-item__name">
				{{ node.name }}
				<span
					v-if="node.discretionary"
					class="cmmn-plan-item__discretionary-tag">
					{{ t('procest', 'optional') }}
				</span>
			</span>

			<span class="cmmn-plan-panel__badge" :class="badge.cssClass">{{
				badge.label
			}}</span>

			<span
				v-if="node.type === 'milestone' && achieved"
				class="cmmn-plan-item__achieved">
				{{ t('procest', 'Achieved') }}
			</span>

			<span class="cmmn-plan-item__actions">
				<NcButton
					v-if="enableable"
					variant="secondary"
					:disabled="busy === node.id"
					:aria-label="t('procest', 'Enable this optional task')"
					@click="$emit('enable', node.id)">
					{{ t('procest', 'Enable') }}
				</NcButton>
				<NcButton
					v-if="completable"
					variant="primary"
					:disabled="busy === node.id"
					:aria-label="t('procest', 'Complete this task')"
					@click="$emit('complete', node.id)">
					{{ t('procest', 'Complete') }}
				</NcButton>
				<NcButton
					v-if="terminable"
					variant="tertiary"
					:disabled="busy === node.id"
					:aria-label="t('procest', 'Terminate this task')"
					@click="$emit('terminate', node.id)">
					{{ t('procest', 'Terminate') }}
				</NcButton>
			</span>
		</div>

		<ul
			v-if="node.children && node.children.length > 0"
			class="cmmn-plan-item__children">
			<CmmnPlanItemNode
				v-for="child in node.children"
				:key="child.id"
				:node="child"
				:milestones="milestones"
				:enableableDiscretionary="enableableDiscretionary"
				:busy="busy"
				@enable="$emit('enable', $event)"
				@complete="$emit('complete', $event)"
				@terminate="$emit('terminate', $event)" />
		</ul>
	</li>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import {
	canComplete,
	canTerminate,
	isEnableable,
	isMilestoneAchieved,
	stateBadge,
} from '../../../utils/cmmnHelpers.js'

export default {
	name: 'CmmnPlanItemNode',
	components: { NcButton },
	props: {
		node: {
			type: Object,
			required: true,
		},

		milestones: {
			type: Object,
			default: () => ({}),
		},

		enableableDiscretionary: {
			type: Array,
			default: () => [],
		},

		busy: {
			type: String,
			default: null,
		},
	},

	emits: ['enable', 'complete', 'terminate'],
	computed: {
		/** @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-002 */
		badge() {
			return stateBadge(this.node.state)
		},

		/** @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-004 */
		enableable() {
			return isEnableable(this.node, this.enableableDiscretionary)
		},

		/** @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007 */
		completable() {
			return canComplete(this.node)
		},

		/** @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007 */
		terminable() {
			return canTerminate(this.node)
		},

		/** @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-005 */
		achieved() {
			return isMilestoneAchieved(this.milestones, this.node.id)
		},
	},

	methods: { t },
}
</script>

<style scoped>
.cmmn-plan-item {
	border-inline-start: 2px solid var(--color-border);
	padding: 4px 0 4px 8px;
	margin: 2px 0;
}

.cmmn-plan-item--stage {
	border-inline-start-color: var(--color-primary-element);
}

.cmmn-plan-item__row {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.cmmn-plan-item__name {
	font-weight: 600;
}

.cmmn-plan-item__discretionary-tag {
	font-weight: 400;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
	margin-inline-start: 4px;
}

.cmmn-plan-item__achieved {
	font-size: 0.85em;
	color: var(--color-success-text, var(--color-success));
}

.cmmn-plan-item__actions {
	display: flex;
	gap: 4px;
	margin-inline-start: auto;
}

.cmmn-plan-item__children {
	list-style: none;
	margin: 4px 0 0 12px;
	padding: 0;
}

.cmmn-plan-panel__badge {
	font-size: 0.8em;
	padding: 1px 6px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}

.cmmn-plan-panel__badge--enabled,
.cmmn-plan-panel__badge--active {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text, var(--color-primary-element));
}

.cmmn-plan-panel__badge--completed {
	background: var(--color-success-light, #e8f5e9);
	color: var(--color-success-text, var(--color-success));
}

.cmmn-plan-panel__badge--terminated,
.cmmn-plan-panel__badge--disabled {
	background: var(--color-background-darker, var(--color-background-dark));
	color: var(--color-text-maxcontrast);
}
</style>
