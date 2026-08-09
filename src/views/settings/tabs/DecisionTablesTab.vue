<template>
	<div class="sub-entity-tab decision-tables-tab">
		<NcLoadingIcon v-if="loading" />

		<template v-else>
			<div v-if="items.length > 0" class="sub-entity-tab__list">
				<div
					v-for="item in items"
					:key="item.id"
					class="sub-entity-row">
					<template v-if="editingId !== item.id">
						<span class="sub-entity-row__name">{{ item.name }}</span>
						<span class="sub-entity-row__meta">{{ item.key }}</span>
						<span class="sub-entity-row__badge">{{ summarise(item) }}</span>
						<span v-if="item.enabled === false" class="sub-entity-row__meta">
							{{ t('procest', 'Disabled') }}
						</span>
						<div class="sub-entity-row__actions">
							<NcButton type="tertiary" :aria-label="t('procest', 'Edit {name}', { name: item.name })" @click="startEdit(item)">
								<template #icon>
									<PencilIcon :size="20" />
								</template>
							</NcButton>
							<NcButton type="tertiary" :aria-label="t('procest', 'Delete {name}', { name: item.name })" @click="deleteItem(item)">
								<template #icon>
									<DeleteIcon :size="20" />
								</template>
							</NcButton>
						</div>
					</template>

					<template v-else>
						<div class="sub-entity-row__edit-form">
							<div class="edit-row">
								<NcTextField
									:model-value="editForm.name"
									:label="t('procest', 'Name')"
									:error="!!editError"
									class="edit-field"
									@update:model-value="v => editForm.name = v" />
								<NcTextField
									:model-value="editForm.key"
									:label="t('procest', 'Key (used to invoke the decision)')"
									class="edit-field"
									@update:model-value="v => editForm.key = v" />
							</div>
							<div class="edit-row">
								<NcTextField
									:model-value="editForm.description"
									:label="t('procest', 'Description')"
									class="edit-field edit-field--full"
									@update:model-value="v => editForm.description = v" />
							</div>
							<div class="edit-row">
								<NcSelect
									v-model="editForm.hitPolicy"
									:options="hitPolicies"
									:input-label="t('procest', 'Hit policy')"
									:placeholder="t('procest', 'Hit policy')"
									class="edit-field" />
								<NcCheckboxRadioSwitch
									:model-value="editForm.enabled"
									@update:model-value="v => editForm.enabled = v">
									{{ t('procest', 'Enabled') }}
								</NcCheckboxRadioSwitch>
							</div>
							<div class="edit-row">
								<NcTextArea
									:model-value="editForm.definitionJson"
									:label="t('procest', 'Inputs, outputs and rules (JSON)')"
									:helper-text="t('procest', 'A JSON object with inputs[], outputs[] and rules[]. Each rule row aligns positionally to the inputs and outputs.')"
									rows="14"
									class="edit-field edit-field--full decision-tables-tab__json"
									@update:model-value="v => editForm.definitionJson = v" />
							</div>
							<NcNoteCard v-if="editError" type="error">
								{{ editError }}
							</NcNoteCard>
							<ul v-if="structuralErrors.length > 0" class="decision-tables-tab__errors">
								<li v-for="(err, i) in structuralErrors" :key="i">
									{{ err }}
								</li>
							</ul>
							<div class="edit-actions">
								<NcButton type="primary" @click="saveEdit">
									{{ t('procest', 'Save') }}
								</NcButton>
								<NcButton @click="cancelEdit">
									{{ t('procest', 'Cancel') }}
								</NcButton>
							</div>
						</div>
					</template>
				</div>
			</div>
			<p v-else class="sub-entity-tab__empty">
				{{ t('procest', 'No decision tables configured yet.') }}
			</p>

			<NcButton v-if="editingId === null" @click="startAdd">
				{{ t('procest', 'Add Decision Table') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField, NcTextArea, NcSelect, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import { listDecisionTables, createDecisionTable, updateDecisionTable, deleteDecisionTable } from '../../../services/decisionTableApi.js'
import { HIT_POLICIES, summariseTable, validateTableStructure, parseDefinitionJson } from '../../../utils/decisionTableHelpers.js'

const DEFAULT_DEFINITION = {
	inputs: [{ name: 'income', label: 'Income', type: 'number' }],
	outputs: [{ name: 'eligible', label: 'Eligible', type: 'boolean' }],
	rules: [
		{ id: 'r1', annotation: 'Low income', inputEntries: ['[0..25000]'], outputEntries: [true] },
		{ id: 'r2', annotation: 'Higher income', inputEntries: ['> 25000'], outputEntries: [false] },
	],
}

export default {
	name: 'DecisionTablesTab',
	components: { NcButton, NcLoadingIcon, NcTextField, NcTextArea, NcSelect, NcCheckboxRadioSwitch, NcNoteCard, PencilIcon, DeleteIcon },
	data() {
		return {
			loading: false,
			items: [],
			editingId: null,
			editForm: this.emptyForm(),
			editError: '',
			structuralErrors: [],
			hitPolicies: HIT_POLICIES,
		}
	},
	async mounted() {
		await this.loadItems()
	},
	methods: {
		/**
		 * A blank edit form seeded with a starter definition.
		 *
		 * @return {object} The blank form
		 * @spec openspec/specs/dmn-decision-tables/spec.md
		 */
		emptyForm() {
			return {
				name: '',
				key: '',
				description: '',
				hitPolicy: 'UNIQUE',
				enabled: true,
				definitionJson: JSON.stringify(DEFAULT_DEFINITION, null, 2),
			}
		},
		/**
		 * One-line summary for the list row.
		 *
		 * @param {object} item The decision table
		 * @return {string} The summary
		 * @spec openspec/specs/dmn-decision-tables/spec.md
		 */
		summarise(item) {
			return summariseTable(item)
		},
		/**
		 * Load all decision tables.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/dmn-decision-tables/spec.md
		 */
		async loadItems() {
			this.loading = true
			try {
				this.items = await listDecisionTables()
			} catch (e) {
				this.items = []
			}
			this.loading = false
		},
		/**
		 * Begin adding a new decision table.
		 *
		 * @return {void}
		 * @spec openspec/specs/dmn-decision-tables/spec.md
		 */
		startAdd() {
			this.editingId = 'new'
			this.editForm = this.emptyForm()
			this.editError = ''
			this.structuralErrors = []
			this.items.push({ id: 'new', name: '', key: '' })
		},
		/**
		 * Begin editing an existing decision table.
		 *
		 * @param {object} item The decision table
		 * @return {void}
		 * @spec openspec/specs/dmn-decision-tables/spec.md
		 */
		startEdit(item) {
			this.editingId = item.id
			this.editForm = {
				name: item.name || '',
				key: item.key || '',
				description: item.description || '',
				hitPolicy: item.hitPolicy || 'UNIQUE',
				enabled: item.enabled !== false,
				definitionJson: JSON.stringify({
					inputs: item.inputs || [],
					outputs: item.outputs || [],
					rules: item.rules || [],
				}, null, 2),
			}
			this.editError = ''
			this.structuralErrors = []
		},
		/**
		 * Cancel the current edit.
		 *
		 * @return {void}
		 * @spec openspec/specs/dmn-decision-tables/spec.md
		 */
		cancelEdit() {
			if (this.editingId === 'new') this.items = this.items.filter(i => i.id !== 'new')
			this.editingId = null
			this.editError = ''
			this.structuralErrors = []
		},
		/**
		 * Validate and persist the current edit.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/dmn-decision-tables/spec.md
		 */
		async saveEdit() {
			this.editError = ''
			this.structuralErrors = []

			if (!this.editForm.name.trim()) {
				this.editError = t('procest', 'Name is required')
				return
			}
			if (!this.editForm.key.trim()) {
				this.editError = t('procest', 'Key is required')
				return
			}

			const parsed = parseDefinitionJson(this.editForm.definitionJson)
			if (!parsed.ok) {
				this.editError = parsed.error
				return
			}

			const structure = validateTableStructure(parsed.value)
			if (!structure.valid) {
				this.structuralErrors = structure.errors
				this.editError = t('procest', 'The decision definition has structural errors.')
				return
			}

			const payload = {
				name: this.editForm.name.trim(),
				key: this.editForm.key.trim(),
				description: this.editForm.description.trim(),
				hitPolicy: this.editForm.hitPolicy,
				enabled: this.editForm.enabled,
				inputs: parsed.value.inputs,
				outputs: parsed.value.outputs,
				rules: parsed.value.rules,
			}

			try {
				if (this.editingId === 'new') {
					await createDecisionTable(payload)
				} else {
					await updateDecisionTable(this.editingId, payload)
				}
			} catch (e) {
				this.editError = e?.response?.data?.error || t('procest', 'Could not save the decision table.')
				return
			}

			this.editingId = null
			await this.loadItems()
		},
		/**
		 * Delete a decision table after confirmation.
		 *
		 * @param {object} item The decision table
		 * @return {Promise<void>}
		 * @spec openspec/specs/dmn-decision-tables/spec.md
		 */
		async deleteItem(item) {
			if (!confirm(t('procest', 'Delete decision table "{name}"?', { name: item.name }))) return
			try {
				await deleteDecisionTable(item.id)
			} catch (e) {
				// Surface nothing more than a reload; the row simply stays.
			}
			await this.loadItems()
		},
	},
}
</script>

<style scoped>
@import './sub-entity-tab.css';

.decision-tables-tab__json :deep(textarea) {
	font-family: var(--font-face-monospace, monospace);
	min-height: 240px;
}

.decision-tables-tab__errors {
	margin: 8px 0;
	padding-left: 18px;
	color: var(--color-error);
	font-size: 0.9em;
}
</style>
