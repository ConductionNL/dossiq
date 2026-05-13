<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<div class="edge-properties">
		<h3 class="edge-properties__title">
			{{ t('procest', 'Transition') }}
		</h3>

		<template v-if="edge">
			<label class="edge-properties__field">
				<span>{{ t('procest', 'Label') }}</span>
				<input
					:value="edge.data && edge.data.label || edge.label || ''"
					type="text"
					:disabled="readOnly"
					@input="updateField('label', $event.target.value)">
			</label>

			<label class="edge-properties__field">
				<span>{{ t('procest', 'Guards (JSON)') }}</span>
				<textarea
					:value="guardsJson"
					:disabled="readOnly"
					rows="4"
					placeholder="[]"
					@input="updateGuards($event.target.value)" />
			</label>

			<label class="edge-properties__field">
				<span>{{ t('procest', 'Allowed roles (comma-separated)') }}</span>
				<input
					:value="(edge.data && edge.data.allowedRoles || []).join(', ')"
					type="text"
					:disabled="readOnly"
					@input="updateRoles($event.target.value)">
			</label>

			<div class="edge-properties__route">
				<span>{{ t('procest', 'From') }}: {{ edge.source }}</span>
				<span>{{ t('procest', 'To') }}: {{ edge.target }}</span>
			</div>

			<div v-if="edgeIssues.length" class="edge-properties__issues">
				<h4>{{ t('procest', 'Issues') }}</h4>
				<ul>
					<li
						v-for="(issue, i) in edgeIssues"
						:key="i"
						:class="`edge-properties__issue edge-properties__issue--${issue.level}`">
						{{ issue.message }}
					</li>
				</ul>
			</div>
		</template>

		<p v-else class="edge-properties__empty">
			{{ t('procest', 'Select a transition to edit its properties.') }}
		</p>
	</div>
</template>

<script>
/**
 * EdgeProperties — right-rail editor for the selected transition (edge).
 *
 * Emits `update` with `{edgeId, patch}` on field changes.
 */
export default {
	name: 'EdgeProperties',
	props: {
		edge: {
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
		guardsJson() {
			if (!this.edge || !this.edge.data) {
				return '[]'
			}
			try {
				return JSON.stringify(this.edge.data.guards || [], null, 2)
			} catch (e) {
				return '[]'
			}
		},
		edgeIssues() {
			if (!this.edge) {
				return []
			}
			return this.issues.filter((issue) => issue.edgeId === this.edge.id)
		},
	},
	methods: {
		updateField(key, value) {
			this.$emit('update', { edgeId: this.edge.id, patch: { [key]: value } })
		},
		updateGuards(raw) {
			let parsed = []
			try {
				parsed = JSON.parse(raw)
			} catch (e) {
				// Ignore invalid JSON while typing; user must produce valid JSON to commit.
				return
			}
			this.$emit('update', { edgeId: this.edge.id, patch: { guards: parsed } })
		},
		updateRoles(raw) {
			const roles = raw.split(',').map((r) => r.trim()).filter(Boolean)
			this.$emit('update', { edgeId: this.edge.id, patch: { allowedRoles: roles } })
		},
	},
}
</script>

<style scoped>
.edge-properties {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
	width: 280px;
	border-left: 1px solid var(--color-border, #ddd);
	background: var(--color-main-background, #fff);
	overflow-y: auto;
}

.edge-properties__title {
	font-size: 14px;
	font-weight: 600;
	margin: 0;
}

.edge-properties__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 12px;
}

.edge-properties__field input[type="text"],
.edge-properties__field textarea {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
	font: inherit;
}

.edge-properties__route {
	display: flex;
	flex-direction: column;
	font-size: 12px;
	color: var(--color-text-maxcontrast, #888);
	gap: 2px;
}

.edge-properties__issues h4 {
	margin: 0 0 4px;
	font-size: 12px;
	font-weight: 600;
}

.edge-properties__issues ul {
	list-style: none;
	margin: 0;
	padding: 0;
}

.edge-properties__issue {
	font-size: 12px;
	padding: 4px 0;
}

.edge-properties__issue--error {
	color: var(--color-error, #c33);
}

.edge-properties__issue--warning {
	color: var(--color-warning, #c80);
}

.edge-properties__empty {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #888);
}
</style>
