<template>
	<div class="deelzaaktypen-tab">
		<div v-if="isCreate" class="deelzaaktypen-tab__notice">
			<p>{{ t('procest', 'Save the case type first before configuring sub-case types.') }}</p>
		</div>

		<template v-else>
			<p class="deelzaaktypen-tab__intro">
				{{ t('procest', 'Configure which case types can be created as a sub-case (deelzaak) under cases of this type. Leave empty to allow any type.') }}
			</p>

			<NcLoadingIcon v-if="loading" />

			<template v-else>
				<!-- List of currently configured sub-case types -->
				<div v-if="configuredTypes.length > 0" class="deelzaaktypen-tab__list">
					<div
						v-for="ct in configuredTypes"
						:key="ct.id"
						class="deelzaaktype-row">
						<span class="deelzaaktype-row__name">{{ ct.title || ct.id }}</span>
						<NcButton
							type="tertiary"
							:aria-label="t('procest', 'Remove')"
							@click="removeType(ct.id)">
							<template #icon>
								<CloseIcon :size="20" />
							</template>
						</NcButton>
					</div>
				</div>

				<div v-else class="deelzaaktypen-tab__empty">
					{{ t('procest', 'No sub-case types configured — any case type is allowed.') }}
				</div>

				<!-- Add a new sub-case type -->
				<div class="deelzaaktypen-tab__add">
					<NcSelect
						v-model="selectedToAdd"
						:options="availableOptions"
						:placeholder="t('procest', 'Add a sub-case type…')"
						label="label"
						track-by="value" />
					<NcButton
						type="secondary"
						:disabled="!selectedToAdd"
						@click="addSelectedType">
						{{ t('procest', 'Add') }}
					</NcButton>
				</div>

				<!-- Closure guard option -->
				<div class="deelzaaktypen-tab__option">
					<NcCheckboxRadioSwitch
						:checked="requireAllClosed"
						@update:checked="toggleRequireAllClosed">
						{{ t('procest', 'Require all sub-cases to be closed before this case can be closed') }}
					</NcCheckboxRadioSwitch>
				</div>

				<!-- Save -->
				<div class="deelzaaktypen-tab__actions">
					<NcButton
						type="primary"
						:disabled="saving"
						@click="saveConfiguration">
						{{ saving ? t('procest', 'Saving…') : t('procest', 'Save') }}
					</NcButton>
				</div>
			</template>
		</template>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import { useObjectStore } from '../../../store/modules/object.js'

/**
 * Settings tab for configuring which caseTypes can be created as a
 * deelzaak (sub-case) under a given caseType. Also controls the
 * closure guard (`requireAllDeelzakenClosed`).
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T06
 */
export default {
	name: 'DeelzaakTypenTab',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSelect,
		CloseIcon,
	},

	props: {
		/** Whether this is a new (unsaved) caseType. */
		isCreate: {
			type: Boolean,
			default: false,
		},
		/** The current caseType data object. */
		caseType: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['save'],

	data() {
		return {
			loading: true,
			saving: false,
			allCaseTypes: [],
			configuredIds: [],
			requireAllClosed: false,
			selectedToAdd: null,
		}
	},

	computed: {
		objectStore() {
			return useObjectStore()
		},

		/**
		 * Full caseType objects for the currently configured IDs.
		 *
		 * @spec openspec/changes/deelzaak-support/tasks.md#T06
		 */
		configuredTypes() {
			return this.allCaseTypes.filter(ct => this.configuredIds.includes(ct.id))
		},

		/**
		 * Options for the add-selector: all types NOT already configured,
		 * and excluding the current caseType itself (no self-nesting).
		 *
		 * @spec openspec/changes/deelzaak-support/tasks.md#T06
		 */
		availableOptions() {
			const currentId = this.caseType?.id ?? null
			return this.allCaseTypes
				.filter(ct => ct.id !== currentId && !this.configuredIds.includes(ct.id))
				.map(ct => ({ value: ct.id, label: ct.title || ct.id }))
		},
	},

	watch: {
		caseType: {
			immediate: true,
			handler(val) {
				if (val && !this.isCreate) {
					this.initFromCaseType(val)
				}
			},
		},
	},

	async mounted() {
		if (!this.isCreate) {
			await this.loadAllCaseTypes()
		}
		this.loading = false
	},

	methods: {
		/**
		 * Load all available case types for the selector.
		 *
		 * @spec openspec/changes/deelzaak-support/tasks.md#T06
		 */
		async loadAllCaseTypes() {
			try {
				const types = await this.objectStore.fetchCollection('caseType', { _limit: 500 })
				this.allCaseTypes = types || []
			} catch (err) {
				console.error('DeelzaakTypenTab: failed to load case types', err)
			}
		},

		/**
		 * Initialise local state from a caseType object.
		 *
		 * @param {object} caseType The caseType to read from
		 *
		 * @spec openspec/changes/deelzaak-support/tasks.md#T06
		 */
		initFromCaseType(caseType) {
			const raw = caseType.subCaseTypes ?? []
			this.configuredIds = Array.isArray(raw) ? [...raw] : []
			this.requireAllClosed = caseType.requireAllDeelzakenClosed === true
				|| caseType.requireAllDeelzakenClosed === 'true'
		},

		/**
		 * Add the currently-selected case type to the configured list.
		 *
		 * @spec openspec/changes/deelzaak-support/tasks.md#T06
		 */
		addSelectedType() {
			if (!this.selectedToAdd) return
			const id = this.selectedToAdd.value
			if (!this.configuredIds.includes(id)) {
				this.configuredIds.push(id)
			}
			this.selectedToAdd = null
		},

		/**
		 * Remove a case type from the configured list.
		 *
		 * @param {string} id The case type UUID to remove
		 *
		 * @spec openspec/changes/deelzaak-support/tasks.md#T06
		 */
		removeType(id) {
			this.configuredIds = this.configuredIds.filter(i => i !== id)
		},

		/**
		 * Toggle the requireAllDeelzakenClosed flag.
		 *
		 * @param {boolean} value New value
		 *
		 * @spec openspec/changes/deelzaak-support/tasks.md#T06
		 */
		toggleRequireAllClosed(value) {
			this.requireAllClosed = value
		},

		/**
		 * Persist changes via parent emit (CaseTypeDetail handles the save call).
		 *
		 * @spec openspec/changes/deelzaak-support/tasks.md#T06
		 */
		async saveConfiguration() {
			this.saving = true
			try {
				this.$emit('save', {
					subCaseTypes: [...this.configuredIds],
					requireAllDeelzakenClosed: this.requireAllClosed,
				})
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.deelzaaktypen-tab {
	padding: 16px 0;
}

.deelzaaktypen-tab__notice,
.deelzaaktypen-tab__intro {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin-bottom: 16px;
}

.deelzaaktypen-tab__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 16px;
}

.deelzaaktype-row {
	display: flex;
	align-items: center;
	padding: 6px 10px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	gap: 8px;
}

.deelzaaktype-row__name {
	flex: 1;
}

.deelzaaktypen-tab__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	margin-bottom: 16px;
}

.deelzaaktypen-tab__add {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	margin-bottom: 16px;
}

.deelzaaktypen-tab__add .vs__dropdown-toggle {
	min-width: 260px;
}

.deelzaaktypen-tab__option {
	margin-bottom: 16px;
}

.deelzaaktypen-tab__actions {
	display: flex;
	justify-content: flex-end;
}
</style>
