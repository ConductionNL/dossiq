<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="checklist-editor">
		<div class="checklist-editor__toolbar">
			<NcButton @click="$emit('cancel')">
				<template #icon>
					<ArrowLeft :size="18" />
				</template>
				{{ t('procest', 'Back') }}
			</NcButton>
			<h3 class="checklist-editor__title">
				{{ isNew ? t('procest', 'New inspection checklist') : t('procest', 'Edit inspection checklist') }}
			</h3>
			<NcButton type="primary" :disabled="saving" @click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="18" />
					<ContentSaveOutline v-else :size="18" />
				</template>
				{{ saving ? t('procest', 'Saving…') : t('procest', 'Save') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<!-- Metadata -->
		<div class="checklist-editor__section">
			<h4>{{ t('procest', 'General') }}</h4>
			<div class="checklist-editor__field">
				<label class="checklist-editor__label">{{ t('procest', 'Name') }} *</label>
				<NcTextField
					:value="form.name"
					:label="t('procest', 'Checklist name')"
					:placeholder="t('procest', 'e.g. Bouwtoezicht fase 1 – Fundering')"
					@update:value="form.name = $event" />
			</div>
			<div class="checklist-editor__field">
				<label class="checklist-editor__label">{{ t('procest', 'Case type reference') }}</label>
				<NcTextField
					:value="form.caseTypeRef"
					:label="t('procest', 'Case type UUID')"
					:placeholder="t('procest', 'UUID of the case type')"
					@update:value="form.caseTypeRef = $event" />
			</div>
			<div class="checklist-editor__field checklist-editor__field--row">
				<label>
					<NcCheckboxRadioSwitch
						:checked="form.active"
						type="checkbox"
						@update:checked="form.active = $event">
						{{ t('procest', 'Active') }}
					</NcCheckboxRadioSwitch>
				</label>
			</div>
		</div>

		<!-- Items -->
		<div class="checklist-editor__section">
			<div class="checklist-editor__section-header">
				<h4>{{ t('procest', 'Checklist items') }}</h4>
				<NcButton size="small" @click="addItem">
					<template #icon>
						<Plus :size="16" />
					</template>
					{{ t('procest', 'Add item') }}
				</NcButton>
			</div>

			<p v-if="form.items.length === 0" class="checklist-editor__empty">
				{{ t('procest', 'No items yet. Add at least one item.') }}
			</p>

			<div
				v-for="(item, index) in form.items"
				:key="index"
				class="checklist-editor__item">
				<div class="checklist-editor__item-drag">
					<DragHorizontalVariant :size="18" />
				</div>

				<div class="checklist-editor__item-fields">
					<div class="checklist-editor__field">
						<NcTextField
							:value="item.question"
							:label="t('procest', 'Question / label')"
							:placeholder="t('procest', 'e.g. Fundering conform tekening')"
							@update:value="item.question = $event" />
					</div>

					<div class="checklist-editor__field-row">
						<div class="checklist-editor__field checklist-editor__field--small">
							<label class="checklist-editor__label">{{ t('procest', 'Type') }}</label>
							<select v-model="item.type" class="checklist-editor__select">
								<option value="boolean">{{ t('procest', 'Yes / No / N.A.') }}</option>
								<option value="text">{{ t('procest', 'Text') }}</option>
								<option value="enum">{{ t('procest', 'Multiple choice') }}</option>
								<option value="photo">{{ t('procest', 'Photo') }}</option>
							</select>
						</div>

						<div class="checklist-editor__field checklist-editor__field--small">
							<label class="checklist-editor__label">{{ t('procest', 'Weight') }}</label>
							<input
								v-model.number="item.weight"
								type="number"
								min="0"
								max="10"
								step="1"
								class="checklist-editor__input">
						</div>

						<label class="checklist-editor__toggle">
							<input v-model="item.required" type="checkbox">
							{{ t('procest', 'Required') }}
						</label>

						<label class="checklist-editor__toggle">
							<input v-model="item.fotoRequired" type="checkbox">
							{{ t('procest', 'Photo required for non-conformity') }}
						</label>
					</div>

					<div v-if="item.type === 'enum'" class="checklist-editor__field">
						<NcTextField
							:value="(item.options || []).join(', ')"
							:label="t('procest', 'Options (comma-separated)')"
							:placeholder="t('procest', 'Option A, Option B, Option C')"
							@update:value="item.options = $event.split(',').map(o => o.trim()).filter(Boolean)" />
					</div>
				</div>

				<NcButton size="small" type="error" @click="removeItem(index)">
					<template #icon>
						<Delete :size="16" />
					</template>
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DragHorizontalVariant from 'vue-material-design-icons/DragHorizontalVariant.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'InspectionChecklistEditor',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		ArrowLeft,
		ContentSaveOutline,
		Delete,
		DragHorizontalVariant,
		Plus,
	},

	props: {
		/** Existing checklist to edit, or null for a new one. */
		checklist: {
			type: Object,
			default: null,
		},
	},

	emits: ['save', 'cancel'],

	data() {
		return {
			saving: false,
			error: null,
			form: this.buildForm(this.checklist),
		}
	},

	computed: {
		/** @spec openspec/changes/vth-module/tasks.md#task-5 */
		isNew() {
			return !this.checklist?.id
		},
	},

	methods: {
		/** @spec openspec/changes/vth-module/tasks.md#task-5 */
		buildForm(checklist) {
			if (!checklist) {
				return { name: '', caseTypeRef: '', active: true, items: [] }
			}

			return {
				id: checklist.id,
				name: checklist.name || '',
				caseTypeRef: checklist.caseTypeRef || '',
				active: checklist.active !== false,
				version: checklist.version || 1,
				items: (checklist.items || []).map(item =>
					typeof item === 'object' ? { ...item } : { question: item, type: 'boolean', required: false, weight: 1, fotoRequired: false }
				),
			}
		},

		/** @spec openspec/changes/vth-module/tasks.md#task-5 */
		addItem() {
			this.form.items.push({ question: '', type: 'boolean', required: false, weight: 1, fotoRequired: false, options: [] })
		},

		/** @spec openspec/changes/vth-module/tasks.md#task-5 */
		removeItem(index) {
			this.form.items.splice(index, 1)
		},

		/** @spec openspec/changes/vth-module/tasks.md#task-5 */
		async save() {
			if (!this.form.name.trim()) {
				this.error = t('procest', 'Checklist name is required')
				return
			}

			this.saving = true
			this.error = null

			try {
				let response
				if (this.form.id) {
					response = await axios.put(
						generateUrl('/apps/procest/api/vth/checklists/' + encodeURIComponent(this.form.id)),
						this.form
					)
				} else {
					response = await axios.post(generateUrl('/apps/procest/api/vth/checklists'), this.form)
				}
				this.$emit('save', response.data)
			} catch (err) {
				this.error = err?.response?.data?.message || t('procest', 'Failed to save checklist')
			} finally {
				this.saving = false
			}
		},

		t,
	},
}
</script>

<style scoped>
.checklist-editor__toolbar {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 20px;
}

.checklist-editor__title {
	flex: 1;
	margin: 0;
}

.checklist-editor__section {
	margin-bottom: 24px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.checklist-editor__section-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.checklist-editor__section-header h4 {
	margin: 0;
}

.checklist-editor__field {
	margin-bottom: 12px;
}

.checklist-editor__field--row {
	display: flex;
	align-items: center;
}

.checklist-editor__field-row {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.checklist-editor__field--small {
	min-width: 120px;
}

.checklist-editor__label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}

.checklist-editor__select,
.checklist-editor__input {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
}

.checklist-editor__toggle {
	display: flex;
	align-items: center;
	gap: 6px;
	cursor: pointer;
	white-space: nowrap;
}

.checklist-editor__item {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 8px;
}

.checklist-editor__item-drag {
	padding-top: 8px;
	color: var(--color-text-maxcontrast);
	cursor: grab;
}

.checklist-editor__item-fields {
	flex: 1;
}

.checklist-editor__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	text-align: center;
	padding: 20px 0;
}
</style>
