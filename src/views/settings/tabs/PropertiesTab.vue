<template>
	<div class="properties-tab">
		<div v-if="isCreate" class="properties-tab__notice">
			<p>
				{{
					t(
						'dossiq',
						'Save the case type first before adding property definitions.',
					)
				}}
			</p>
		</div>

		<template v-else>
			<NcLoadingIcon v-if="loading" />

			<template v-else>
				<div v-if="propertyDefs.length > 0" class="properties-tab__list">
					<div
						v-for="pd in propertyDefs"
						:key="pd.id"
						class="property-row"
						:class="{ 'property-row--editing': editingId === pd.id }">
						<template v-if="editingId !== pd.id">
							<span class="property-row__name">{{ pd.name }}</span>
							<span class="property-row__format">{{
								typeSummary(pd)
							}}</span>
							<span v-if="pd.maxLength" class="property-row__max">
								{{ t('dossiq', 'max {n}', { n: pd.maxLength }) }}
							</span>
							<span class="property-row__required">
								{{ requiredLabel(pd) }}
							</span>
							<div class="property-row__actions">
								<NcButton
									variant="tertiary"
									:aria-label="
										t('dossiq', 'Edit {name}', {
											name: pd.name,
										})
									"
									@click="startEdit(pd)">
									<template #icon>
										<PencilIcon :size="20" />
									</template>
								</NcButton>
								<NcButton
									variant="tertiary"
									:aria-label="
										t('dossiq', 'Delete {name}', {
											name: pd.name,
										})
									"
									@click="deleteProperty(pd)">
									<template #icon>
										<DeleteIcon :size="20" />
									</template>
								</NcButton>
							</div>
						</template>

						<template v-else>
							<div class="property-row__edit-form">
								<PropertyDefinitionFields
									:value="editForm"
									:vocabulary="vocabulary"
									:vocabularySource="vocabularySource"
									:statusTypes="statusTypes"
									:nameError="editError"
									idPrefix="pd-edit"
									@update="applyEdit" />
								<span v-if="editError" class="field-error">{{
									editError
								}}</span>
								<div class="edit-row edit-row--actions">
									<NcButton
										variant="primary"
										:disabled="editSaving"
										@click="saveEdit">
										{{ t('dossiq', 'Save') }}
									</NcButton>
									<NcButton variant="tertiary" @click="cancelEdit">
										{{ t('dossiq', 'Cancel') }}
									</NcButton>
								</div>
							</div>
						</template>
					</div>
				</div>

				<p v-else class="properties-tab__empty">
					{{ t('dossiq', 'No property definitions yet.') }}
				</p>

				<div class="properties-tab__add">
					<h4>{{ t('dossiq', 'Add property definition') }}</h4>
					<div class="add-form">
						<PropertyDefinitionFields
							:value="newForm"
							:vocabulary="vocabulary"
							:vocabularySource="vocabularySource"
							:statusTypes="statusTypes"
							:nameError="addError"
							idPrefix="pd-add"
							@update="applyNew" />
						<span v-if="addError" class="field-error">{{
							addError
						}}</span>
						<NcButton
							variant="primary"
							:disabled="addSaving"
							@click="addProperty">
							{{ t('dossiq', 'Add') }}
						</NcButton>
					</div>
				</div>
			</template>

			<p v-if="error" class="properties-tab__error">
				{{ error }}
			</p>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import PropertyDefinitionFields from '../../../components/PropertyDefinitionFields.vue'
import {
	fetchPropertyVocabulary,
	resolveStoredType,
} from '../../../services/propertyVocabulary.js'
import { VOCABULARY_SNAPSHOT } from '../../../services/propertyVocabularySnapshot.js'
import { useObjectStore } from '../../../store/modules/object.js'

/**
 * An empty definition, with every key the vocabulary lets a case type declare.
 *
 * @return {object} A blank form.
 */
function blankForm() {
	return {
		name: '',
		definition: '',
		description: '',
		propertyType: 'string',
		format: '',
		pattern: '',
		maxLength: null,
		minimum: null,
		maximum: null,
		items: null,
		ref: '',
		calculation: null,
		propertySource: '',
		enumValues: [],
		isRequired: false,
		requiredAtStatus: null,
	}
}

/**
 * The definition as it is saved, without the form's own state.
 *
 * `choiceList` says whether the administrator wants a choice list. It is a
 * switch, not a property of the definition, and OpenRegister drops an
 * undeclared key without a word, so it is stripped here where that is visible.
 *
 * @param {object} form The form being saved.
 * @return {object} The definition to save.
 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
 */
function payload(form) {
	const out = { ...form }
	delete out.choiceList
	return out
}

export default {
	name: 'PropertiesTab',
	components: {
		NcButton,
		NcLoadingIcon,
		PencilIcon,
		DeleteIcon,
		PropertyDefinitionFields,
	},

	props: {
		caseTypeId: { type: String, default: null },
		isCreate: { type: Boolean, default: false },
	},

	data() {
		return {
			propertyDefs: [],
			statusTypes: [],
			loading: false,
			error: '',
			vocabulary: VOCABULARY_SNAPSHOT,
			vocabularySource: 'snapshot',
			newForm: blankForm(),
			addError: '',
			addSaving: false,
			editingId: null,
			editForm: {},
			editError: '',
			editSaving: false,
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		objectStore() {
			return useObjectStore()
		},
	},

	/** @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md */
	async mounted() {
		await this.loadVocabulary()
		if (!this.isCreate && this.caseTypeId) {
			await Promise.all([this.fetchPropertyDefs(), this.fetchStatusTypes()])
		}
	},

	methods: {
		/**
		 * Read the vocabulary this instance authors against.
		 *
		 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
		 */
		async loadVocabulary() {
			const answer = await fetchPropertyVocabulary()
			this.vocabulary = answer.vocabulary
			this.vocabularySource = answer.source
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		async fetchPropertyDefs() {
			this.loading = true
			try {
				const result = await this.objectStore.fetchCollection(
					'propertyDefinition',
					{
						caseType: this.caseTypeId,
						_limit: 100,
					},
				)
				this.propertyDefs = result || []
			} catch (e) {
				this.error = e.message
			}
			this.loading = false
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		async fetchStatusTypes() {
			try {
				const result = await this.objectStore.fetchCollection('statusType', {
					caseType: this.caseTypeId,
					_limit: 100,
				})
				this.statusTypes = result || []
			} catch (e) {
				/* ignore — status types are optional for property definitions */
			}
		},

		/**
		 * How a definition's type reads in the list.
		 *
		 * The format is part of the answer: `string` alone does not tell a
		 * reader whether the field asks for a date or a paragraph.
		 *
		 * @param {object} pd The property definition.
		 * @return {string} The type, with its format when it has one.
		 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
		 */
		typeSummary(pd) {
			const type = pd.propertyType || 'string'
			return pd.format ? `${type} · ${pd.format}` : type
		},

		/**
		 * Why a save is refused, or an empty string when it is not.
		 *
		 * A choice list with no values is the one an administrator could ship
		 * before: the tab had no input for `enumValues`, so choosing `enum`
		 * produced a dropdown nobody could fill and nothing said so.
		 *
		 * @param {object} form The form being saved.
		 * @return {string} The refusal.
		 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
		 */
		refusal(form) {
			if (!form.name?.trim()) {
				return t('dossiq', 'Name is required')
			}
			const values = form.enumValues || []
			const wantsList =
				form.choiceList === true || form.propertyType === 'enum'
			if (wantsList && values.length === 0) {
				return t(
					'dossiq',
					'A choice list needs values. Add one per line, or pick another type.',
				)
			}
			return ''
		},

		/**
		 * @param {object} update The keys the field set changed.
		 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
		 */
		applyNew(update) {
			this.newForm = { ...this.newForm, ...update }
		},

		/**
		 * @param {object} update The keys the field set changed.
		 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
		 */
		applyEdit(update) {
			this.editForm = { ...this.editForm, ...update }
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		async addProperty() {
			this.addError = this.refusal(this.newForm)
			if (this.addError) {
				return
			}
			this.addSaving = true
			const result = await this.objectStore.saveObject('propertyDefinition', {
				...payload(this.newForm),
				caseType: this.caseTypeId,
			})
			this.addSaving = false
			if (result) {
				this.propertyDefs.push(result)
				this.newForm = blankForm()
			} else {
				this.addError =
					this.objectStore.getError('propertyDefinition')
					|| t('dossiq', 'Failed to add property')
			}
		},

		/**
		 * How a property definition's obligation reads in the list.
		 *
		 * `requiredAtStatus` holds a status REFERENCE, so rendering it raw
		 * puts a UUID in the column. It is also distinct from `isRequired`:
		 * one demands an answer at intake, the other from a later status on.
		 *
		 * @param {object} pd The property definition.
		 * @return {string} The label for the obligation column.
		 * @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md
		 */
		requiredLabel(pd) {
			if (pd.isRequired) return t('dossiq', 'Always required')
			if (!pd.requiredAtStatus) return t('dossiq', 'Optional')
			const status = this.statusTypes.find(
				(st) => st.id === pd.requiredAtStatus,
			)
			return status
				? t('dossiq', 'Required from {status}', { status: status.name })
				: t('dossiq', 'Required from a later status')
		},

		/**
		 * Open a definition for editing, unless this instance cannot type it.
		 *
		 * A case type authored where the vocabulary is wider carries a type
		 * this instance does not know. Opening it in a form whose type picker
		 * cannot hold that value is how the value gets rewritten to text on
		 * the next save, so the definition is shown and left alone instead.
		 *
		 * @param {object} pd The property definition to open for editing.
		 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
		 */
		startEdit(pd) {
			const stored = resolveStoredType(this.vocabulary, pd.propertyType)
			if (!stored.known) {
				this.error = t(
					'dossiq',
					'{name} has the type {type}, which this instance does not offer. dossiq keeps it as it is.',
					{ name: pd.name, type: stored.type },
				)
				return
			}
			this.error = ''
			this.editingId = pd.id
			this.editForm = { ...blankForm(), ...pd }
			this.editError = ''
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		cancelEdit() {
			this.editingId = null
			this.editForm = {}
			this.editError = ''
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		async saveEdit() {
			this.editError = this.refusal(this.editForm)
			if (this.editError) {
				return
			}
			this.editSaving = true
			const result = await this.objectStore.saveObject(
				'propertyDefinition',
				payload(this.editForm),
			)
			this.editSaving = false
			if (result) {
				const idx = this.propertyDefs.findIndex(
					(p) => p.id === this.editingId,
				)
				if (idx !== -1) this.propertyDefs[idx] = result
				this.editingId = null
				this.editForm = {}
			} else {
				this.editError =
					this.objectStore.getError('propertyDefinition')
					|| t('dossiq', 'Failed to save')
			}
		},

		/**
		 * @param {object} pd The property definition to delete.
		 * @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md
		 */
		async deleteProperty(pd) {
			if (
				!confirm(t('dossiq', 'Delete property "{name}"?', { name: pd.name }))
			)
				return
			const ok = await this.objectStore.deleteObject(
				'propertyDefinition',
				pd.id,
			)
			if (ok) {
				this.propertyDefs = this.propertyDefs.filter((p) => p.id !== pd.id)
			} else {
				this.error =
					this.objectStore.getError('propertyDefinition')
					|| t('dossiq', 'Failed to delete property')
			}
		},
	},
}
</script>

<style scoped>
.properties-tab__notice {
	padding: 16px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	color: var(--color-text-maxcontrast);
}

.properties-tab__list {
	margin-bottom: 24px;
}

.property-row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	transition: background 0.15s;
}

.property-row:hover {
	background: var(--color-background-hover);
}

.property-row--editing {
	background: var(--color-background-dark);
	padding: 12px;
	flex-direction: column;
	align-items: stretch;
}

.property-row__name {
	flex: 1;
	font-weight: 500;
}

.property-row__format {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 11px;
	font-weight: 500;
	background: var(--color-background-dark);
}

.property-row__max {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.property-row__required {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.property-row__actions {
	display: flex;
	gap: 2px;
	margin-left: auto;
}

.property-row__edit-form {
	width: 100%;
}

.edit-row {
	display: flex;
	gap: 12px;
	margin-bottom: 8px;
	align-items: center;
}

.edit-row--actions {
	margin-top: 8px;
}

.properties-tab__add {
	border-top: 2px solid var(--color-border);
	padding-top: 16px;
}

.properties-tab__add h4 {
	margin-bottom: 12px;
}

.properties-tab__empty {
	color: var(--color-text-maxcontrast);
	padding: 20px;
	text-align: center;
}

.properties-tab__error {
	color: var(--color-error);
	margin-top: 12px;
}

.field-error {
	display: block;
	color: var(--color-error);
	font-size: 12px;
	margin-bottom: 8px;
}

@media (prefers-reduced-motion: reduce) {
	.property-row {
		transition: none;
	}
}
</style>
