<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<div class="node-properties">
		<h3 class="node-properties__title">
			{{ heading }}
		</h3>

		<template v-if="node">
			<label class="node-properties__field">
				<span>{{ t('procest', 'Label') }}</span>
				<input
					:value="node.data.label"
					type="text"
					:disabled="readOnly"
					@input="updateField('label', $event.target.value)">
			</label>

			<label v-if="node.type === 'status' || node.type === 'end'" class="node-properties__field">
				<span>{{ t('procest', 'Status code') }}</span>
				<input
					:value="node.data.status || ''"
					type="text"
					:disabled="readOnly"
					placeholder="status-uuid"
					@input="updateField('status', $event.target.value)">
			</label>

			<label v-if="node.type === 'status'" class="node-properties__field node-properties__field--check">
				<input
					:checked="!!node.data.isFinal"
					type="checkbox"
					:disabled="readOnly"
					@change="updateField('isFinal', $event.target.checked)">
				<span>{{ t('procest', 'Final status') }}</span>
			</label>

			<label v-if="node.type === 'decision'" class="node-properties__field">
				<span>{{ t('procest', 'Guard expression') }}</span>
				<textarea
					:value="node.data.guard || ''"
					:disabled="readOnly"
					rows="3"
					@input="updateField('guard', $event.target.value)" />
			</label>

			<div class="node-properties__issues" v-if="nodeIssues.length">
				<h4>{{ t('procest', 'Issues') }}</h4>
				<ul>
					<li
						v-for="(issue, i) in nodeIssues"
						:key="i"
						:class="`node-properties__issue node-properties__issue--${issue.level}`">
						{{ issue.message }}
					</li>
				</ul>
			</div>
		</template>

		<p v-else class="node-properties__empty">
			{{ t('procest', 'Select a node to edit its properties.') }}
		</p>
	</div>
</template>

<script>
/**
 * NodeProperties — right-rail editor for the currently selected node.
 *
 * Emits `update` with `{nodeId, patch}` whenever a field changes.
 */
export default {
	name: 'NodeProperties',
	props: {
		node: {
			type: Object,
			default: null,
		},
		issues: {
			type: Array,
			default: () => [],
		},
		readOnly: {
			type: Boolean,
			default: false,
		},
	},
	computed: {
		heading() {
			if (!this.node) {
				return t('procest', 'Node properties')
			}
			const labelByType = {
				status: t('procest', 'Status node'),
				decision: t('procest', 'Decision node'),
				parallel: t('procest', 'Parallel node'),
				end: t('procest', 'End node'),
			}
			return labelByType[this.node.type] || t('procest', 'Node')
		},
		nodeIssues() {
			if (!this.node) {
				return []
			}
			return this.issues.filter((issue) => issue.nodeId === this.node.id)
		},
	},
	methods: {
		updateField(key, value) {
			this.$emit('update', { nodeId: this.node.id, patch: { [key]: value } })
		},
	},
}
</script>

<style scoped>
.node-properties {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
	width: 280px;
	border-left: 1px solid var(--color-border, #ddd);
	background: var(--color-main-background, #fff);
	overflow-y: auto;
}

.node-properties__title {
	font-size: 14px;
	font-weight: 600;
	margin: 0;
}

.node-properties__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 12px;
}

.node-properties__field--check {
	flex-direction: row;
	align-items: center;
	gap: 8px;
}

.node-properties__field input[type="text"],
.node-properties__field textarea {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
	font: inherit;
}

.node-properties__issues {
	margin-top: 8px;
	padding-top: 8px;
	border-top: 1px dashed var(--color-border, #ddd);
}

.node-properties__issues h4 {
	margin: 0 0 4px;
	font-size: 12px;
	font-weight: 600;
}

.node-properties__issues ul {
	list-style: none;
	margin: 0;
	padding: 0;
}

.node-properties__issue {
	font-size: 12px;
	padding: 4px 0;
}

.node-properties__issue--error {
	color: var(--color-error, #c33);
}

.node-properties__issue--warning {
	color: var(--color-warning, #c80);
}

.node-properties__empty {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #888);
}
</style>
