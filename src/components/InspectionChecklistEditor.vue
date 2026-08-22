<!--
  Dossiq VTH Inspection Checklist Editor
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
  @spec openspec/changes/vth-module/tasks.md#task-5
-->
<template>
	<div class="checklist-editor">
		<div class="checklist-editor__header">
			<NcButton @click="$emit('cancel')">
				{{ t('dossiq', 'Back to list') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="saving"
				@click="$emit('save', localChecklist)">
				{{
					saving ? t('dossiq', 'Saving...') : t('dossiq', 'Save checklist')
				}}
			</NcButton>
		</div>

		<div class="checklist-editor__field">
			<label class="checklist-editor__label" for="checklist-editor-name">{{
				t('dossiq', 'Name')
			}}</label>
			<input
				id="checklist-editor-name"
				v-model="localChecklist.name"
				type="text"
				class="checklist-editor__input"
				:placeholder="t('dossiq', 'e.g. Bouwtoezicht fase 1 - Fundering')" />
		</div>

		<div class="checklist-editor__field">
			<label class="checklist-editor__label" for="checklist-editor-version">{{
				t('dossiq', 'Version')
			}}</label>
			<input
				id="checklist-editor-version"
				v-model.number="localChecklist.version"
				type="number"
				class="checklist-editor__input"
				min="1" />
		</div>

		<div class="checklist-editor__field">
			<label class="checklist-editor__label">
				<input v-model="localChecklist.active" type="checkbox" />
				{{ t('dossiq', 'Active') }}
			</label>
		</div>

		<div class="checklist-editor__field">
			<label
				class="checklist-editor__label"
				for="checklist-editor-valid-from"
				>{{ t('dossiq', 'Valid from') }}</label
			>
			<input
				id="checklist-editor-valid-from"
				v-model="localChecklist.validFrom"
				type="date"
				class="checklist-editor__input" />
		</div>

		<div class="checklist-editor__items">
			<div class="checklist-editor__items-header">
				<h4>{{ t('dossiq', 'Checklist items') }}</h4>
				<NcButton type="secondary" @click="addItem">
					{{ t('dossiq', 'Add item') }}
				</NcButton>
			</div>

			<div
				v-for="(item, index) in localChecklist.items"
				:key="index"
				class="checklist-editor__item">
				<div class="checklist-editor__item-fields">
					<input
						v-model="item.question"
						type="text"
						class="checklist-editor__input checklist-editor__input--question"
						:aria-label="t('dossiq', 'Question or instruction')"
						:placeholder="t('dossiq', 'Question or instruction')" />
					<select v-model="item.type" class="checklist-editor__select">
						<option value="boolean">
							{{ t('dossiq', 'Yes/No') }}
						</option>
						<option value="text">
							{{ t('dossiq', 'Text') }}
						</option>
						<option value="enum">
							{{ t('dossiq', 'Multiple choice') }}
						</option>
						<option value="photo">
							{{ t('dossiq', 'Photo') }}
						</option>
					</select>
					<label class="checklist-editor__checkbox-label">
						<input v-model="item.required" type="checkbox" />
						{{ t('dossiq', 'Required') }}
					</label>
					<input
						v-model.number="item.weight"
						type="number"
						class="checklist-editor__input checklist-editor__input--weight"
						:aria-label="t('dossiq', 'Weight')"
						:placeholder="t('dossiq', 'Weight')"
						min="0"
						max="100" />
				</div>
				<NcButton type="error" @click="removeItem(index)">
					{{ t('dossiq', 'Remove') }}
				</NcButton>
			</div>

			<p
				v-if="localChecklist.items.length === 0"
				class="checklist-editor__empty">
				{{ t('dossiq', 'No items yet. Add items to build the checklist.') }}
			</p>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'InspectionChecklistEditor',

	components: {
		NcButton,
	},

	props: {
		checklist: {
			type: Object,
			required: true,
		},

		saving: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['save', 'cancel'],

	data() {
		return {
			localChecklist: {
				...this.checklist,
				items: (this.checklist.items || []).map((item) => ({ ...item })),
			},
		}
	},

	methods: {
		addItem() {
			this.localChecklist.items.push({
				question: '',
				type: 'boolean',
				required: false,
				weight: 1,
				parent: null,
			})
		},

		removeItem(index) {
			this.localChecklist.items.splice(index, 1)
		},
	},
}
</script>

<style scoped>
.checklist-editor__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}

.checklist-editor__field {
	margin-bottom: 16px;
}

.checklist-editor__label {
	display: block;
	font-weight: bold;
	margin-bottom: 4px;
}

.checklist-editor__input {
	width: 100%;
	max-width: 400px;
	padding: 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
}

.checklist-editor__input--question {
	flex: 1;
	max-width: none;
}

.checklist-editor__input--weight {
	width: 80px;
}

.checklist-editor__select {
	padding: 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
}

.checklist-editor__items-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.checklist-editor__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 8px;
}

.checklist-editor__item-fields {
	display: flex;
	flex: 1;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
}

.checklist-editor__checkbox-label {
	display: flex;
	align-items: center;
	gap: 4px;
	white-space: nowrap;
}

.checklist-editor__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
