<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div v-if="selectedCount > 0" class="dossier-bulk-bar">
		<span class="dossier-bulk-bar__count">
			{{ n('procest', '%n document selected', '%n documents selected', selectedCount) }}
		</span>

		<div class="dossier-bulk-bar__actions">
			<NcButton :disabled="busy" @click="$emit('mark-final')">
				{{ t('procest', 'Mark as final') }}
			</NcButton>

			<NcSelect
				v-model="bulkClassification"
				class="dossier-bulk-bar__select"
				:input-label="t('procest', 'Change confidentiality')"
				:options="classificationOptions"
				:reduce="option => option.id"
				label="label"
				:disabled="busy"
				@option:selected="onClassificationSelected" />

			<NcButton :disabled="busy" @click="$emit('download-zip')">
				{{ t('procest', 'Download selection as ZIP') }}
			</NcButton>

			<NcButton type="tertiary" :disabled="busy" @click="$emit('clear-selection')">
				{{ t('procest', 'Clear selection') }}
			</NcButton>
		</div>

		<ul v-if="results.length > 0" class="dossier-bulk-bar__results">
			<li
				v-for="result in results"
				:key="result.id"
				:class="result.success ? 'dossier-bulk-bar__result--ok' : 'dossier-bulk-bar__result--fail'">
				{{ result.id }}: {{ result.success ? t('procest', 'OK') : (result.error || t('procest', 'Failed')) }}
			</li>
		</ul>
	</div>
</template>

<script>
import { NcButton, NcSelect } from '@nextcloud/vue'

/**
 * Multi-select toolbar shown when one or more dossier documents are selected.
 * Offers bulk "mark as final", bulk confidentiality change, and "download
 * selection as ZIP", and renders the per-item success/failure summary returned
 * by the bulk endpoints.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T08
 */
export default {
	name: 'BulkActionsBar',
	components: {
		NcButton,
		NcSelect,
	},
	props: {
		selectedCount: {
			type: Number,
			default: 0,
		},
		busy: {
			type: Boolean,
			default: false,
		},
		results: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['mark-final', 'change-confidentiality', 'download-zip', 'clear-selection'],
	data() {
		return {
			bulkClassification: '',
		}
	},
	computed: {
		/**
		 * Confidentiality dropdown options.
		 *
		 * @return {Array} The classification options.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T08
		 */
		classificationOptions() {
			return [
				{ id: 'openbaar', label: this.t('procest', 'Public') },
				{ id: 'beperkt_openbaar', label: this.t('procest', 'Limited public') },
				{ id: 'intern', label: this.t('procest', 'Internal') },
				{ id: 'zaakvertrouwelijk', label: this.t('procest', 'Case-confidential') },
				{ id: 'vertrouwelijk', label: this.t('procest', 'Confidential') },
				{ id: 'confidentieel', label: this.t('procest', 'Restricted') },
				{ id: 'geheim', label: this.t('procest', 'Secret') },
				{ id: 'zeer_geheim', label: this.t('procest', 'Top secret') },
			]
		},
	},
	methods: {
		/**
		 * Emit the chosen confidentiality level for the selection.
		 *
		 * @param {object} option The selected option.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T08
		 */
		onClassificationSelected(option) {
			if (option && option.id) {
				this.$emit('change-confidentiality', option.id)
			}
		},
	},
}
</script>

<style scoped>
.dossier-bulk-bar {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	margin-bottom: 8px;
}

.dossier-bulk-bar__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.dossier-bulk-bar__select {
	min-width: 200px;
}

.dossier-bulk-bar__results {
	list-style: none;
	padding: 0;
	margin: 0;
	font-size: 0.85em;
}

.dossier-bulk-bar__result--ok {
	color: var(--color-success);
}

.dossier-bulk-bar__result--fail {
	color: var(--color-error);
}
</style>
