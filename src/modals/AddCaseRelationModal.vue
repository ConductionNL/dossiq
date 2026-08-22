<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Add-case-relation modal — isolated NcDialog-wrapped form per ADR-004
  (modal isolation). Lets a handler search for a case, pick a typed
  relation (aardRelatie) and an optional toelichting, then creates a
  symmetric typed peer relation via the case-relation API. Inline
  validation surfaces the guard responses (self / duplicate / hierarchy
  overlap / access).

  Usage:
    <AddCaseRelationModal
      :case-id="caseId"
      @created="onCreated"
      @close="showModal = false" />

  @spec openspec/specs/related-case-linking/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Link related case')"
		:open="true"
		size="normal"
		@update:open="onDialogClose"
		@closing="$emit('close')">
		<div class="add-case-relation">
			<!-- Case search picker -->
			<div class="form-group">
				<label for="acr-target">{{ t('dossiq', 'Related case') }} *</label>
				<NcSelect
					id="acr-target"
					v-model="selectedCase"
					:options="caseOptions"
					:loading="searching"
					:aria-label-combobox="t('dossiq', 'Related case')"
					:inputLabel="t('dossiq', 'Related case')"
					label="label"
					trackBy="id"
					:placeholder="t('dossiq', 'Search for a case…')"
					@search="onSearch" />
				<p v-if="errors.target" class="form-error" role="alert">
					{{ errors.target }}
				</p>
			</div>

			<!-- Relation type -->
			<div class="form-group">
				<label for="acr-type">{{ t('dossiq', 'Relation type') }} *</label>
				<NcSelect
					id="acr-type"
					v-model="selectedType"
					:options="typeOptions"
					:aria-label-combobox="t('dossiq', 'Relation type')"
					:inputLabel="t('dossiq', 'Relation type')"
					label="label"
					trackBy="value"
					:placeholder="t('dossiq', 'Select a relation type…')" />
				<p v-if="errors.type" class="form-error" role="alert">
					{{ errors.type }}
				</p>
			</div>

			<!-- Toelichting (optional) -->
			<div class="form-group">
				<label for="acr-toelichting">{{ t('dossiq', 'Explanation') }}</label>
				<NcTextField
					id="acr-toelichting"
					:modelValue="notes"
					:placeholder="t('dossiq', 'Optional clarification…')"
					@update:modelValue="
						(v) => {
							toelichting = v
						}
					" />
			</div>

			<p v-if="submitError" class="form-error" role="alert">
				{{ submitError }}
			</p>
		</div>

		<template #actions>
			<NcButton type="tertiary" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving" @click="submit">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('dossiq', 'Link case') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import {
	AARD_RELATIE_TYPES,
	addRelation,
	relationErrorMessage,
	relationTypeLabel,
} from '../services/caseRelationApi.js'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'AddCaseRelationModal',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The origin case UUID. */
		caseId: {
			type: String,
			required: true,
		},
	},

	emits: ['created', 'close'],
	data() {
		return {
			selectedCase: null,
			selectedType: null,
			notes: '',
			caseOptions: [],
			searching: false,
			saving: false,
			submitError: '',
			errors: { target: '', type: '' },
			searchDebounce: null,
		}
	},

	computed: {
		/** @spec openspec/specs/related-case-linking/spec.md */
		objectStore() {
			return useObjectStore()
		},

		/** @spec openspec/specs/related-case-linking/spec.md */
		typeOptions() {
			return AARD_RELATIE_TYPES.map((value) => ({
				value,
				label: relationTypeLabel(value),
			}))
		},
	},

	methods: {
		/**
		 * Debounced case search via the object store, excluding the origin case.
		 *
		 * @param {string} term The search term.
		 * @return {void}
		 *
		 * @spec openspec/specs/related-case-linking/spec.md
		 */
		onSearch(term) {
			if (this.searchDebounce) {
				clearTimeout(this.searchDebounce)
			}
			this.searchDebounce = setTimeout(() => this.runSearch(term), 300)
		},

		/**
		 * Execute the case search and map results to picker options.
		 *
		 * @param {string} term The search term.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/related-case-linking/spec.md
		 */
		async runSearch(term) {
			if (!term || term.length < 2) {
				this.caseOptions = []
				return
			}
			this.searching = true
			try {
				const results = await this.objectStore.fetchCollection('case', {
					_search: term,
					_limit: 25,
				})
				this.caseOptions = (results || [])
					.filter((c) => (c.id || c['@self']?.id) !== this.caseId)
					.map((c) => ({
						id: c.id || c['@self']?.id,
						label: c.title || c.identifier || c.id || c['@self']?.id,
					}))
			} catch (e) {
				this.caseOptions = []
			} finally {
				this.searching = false
			}
		},

		/**
		 * Validate and create the relation; surface guard responses inline.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/related-case-linking/spec.md
		 */
		async submit() {
			this.errors = { target: '', type: '' }
			this.submitError = ''

			if (!this.selectedCase || !this.selectedCase.id) {
				this.errors.target = t('dossiq', 'Select a case to relate.')
				return
			}
			if (!this.selectedType || !this.selectedType.value) {
				this.errors.type = t('dossiq', 'Select a relation type.')
				return
			}

			this.saving = true
			try {
				const result = await addRelation(this.caseId, {
					targetId: this.selectedCase.id,
					aardRelatie: this.selectedType.value,
					notes: this.notes || undefined,
				})
				if (result.ok) {
					this.$emit('created')
					this.$emit('close')
					return
				}
				this.submitError = relationErrorMessage(result.reason)
			} catch (e) {
				this.submitError = t('dossiq', 'Could not save the relation.')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Handle NcDialog open-state changes (close on false).
		 *
		 * @param {boolean} open The new open state.
		 * @return {void}
		 *
		 * @spec openspec/specs/related-case-linking/spec.md
		 */
		onDialogClose(open) {
			if (!open) {
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped>
.add-case-relation {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 0;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.form-error {
	color: var(--color-error);
	font-size: 0.85em;
	margin: 0;
}
</style>
