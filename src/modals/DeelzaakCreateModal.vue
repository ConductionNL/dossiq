<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Deelzaak (sub-case) create modal — isolated NcDialog-wrapped form per
  ADR-004 (modal isolation). Consumes the deelzaak store for parent-case
  validation and the OpenRegister object store to persist the new case
  with parentCase set.

  Opened by the `case-sub-cases` list's create action on the case page
  (manifest `createAction`, `parentCase: "@objectId"`). Without a
  `parentCaseType` it reads the parent case and its case type itself. A
  parent that cannot take a sub-case gets the reason instead of the form.
  After a create it refreshes the page and closes.

  @spec openspec/changes/deelzaak-support/tasks.md#T08
  @spec openspec/changes/deelzaak-support/tasks.md#T09
-->
<template>
	<NcDialog
		:name="dialogTitle"
		:open="true"
		size="normal"
		@update:open="onDialogClose"
		@closing="$emit('close')">
		<div class="deelzaak-create">
			<!-- Parent case context -->
			<div
				v-if="effectiveParentCaseType"
				class="deelzaak-create__parent-context"
				role="status">
				<span class="parent-context-label">{{
					t('dossiq', 'Parent case type')
				}}</span>
				<span class="parent-context-value">{{
					effectiveParentCaseType.title || effectiveParentCaseType.name
				}}</span>
			</div>

			<NcLoadingIcon v-if="loadingTypes || loadingParent" :size="32" />

			<template v-else>
				<!-- The parent itself cannot take a sub-case. -->
				<NcEmptyContent
					v-if="parentRefusal"
					:name="t('dossiq', 'No sub-case can be added here')"
					:description="parentRefusal"
					data-testid="deelzaak-create-refusal">
					<template #icon>
						<AlertCircleOutline :size="48" />
					</template>
				</NcEmptyContent>

				<!-- No allowed child types -->
				<NcEmptyContent
					v-else-if="availableCaseTypes.length === 0"
					:name="t('dossiq', 'No allowed sub-case types')"
					:description="
						t(
							'dossiq',
							'The parent case type does not allow any sub-cases. Configure sub-case types on the parent case type in Settings.',
						)
					">
					<template #icon>
						<AlertCircleOutline :size="48" />
					</template>
				</NcEmptyContent>

				<template v-else>
					<!-- Case Type Selection (restricted to parent.subCaseTypes) -->
					<div class="form-group">
						<label for="dc-case-type"
							>{{ t('dossiq', 'Sub-case type') }} *</label
						>
						<NcSelect
							id="dc-case-type"
							v-model="selectedCaseType"
							:options="availableCaseTypes"
							:aria-label-combobox="t('dossiq', 'Sub-case type')"
							:inputLabel="t('dossiq', 'Sub-case type')"
							label="title"
							trackBy="id"
							:placeholder="t('dossiq', 'Select a sub-case type…')"
							@update:modelValue="onCaseTypeSelected" />
						<p v-if="errors.caseType" class="form-error" role="alert">
							{{ errors.caseType }}
						</p>
					</div>

					<!-- Title -->
					<div class="form-group">
						<label for="dc-title">{{ t('dossiq', 'Title') }} *</label>
						<NcTextField
							id="dc-title"
							:modelValue="form.title"
							:placeholder="t('dossiq', 'Enter sub-case title…')"
							:error="!!errors.title"
							@update:modelValue="
								(v) => {
									form.title = v
									errors.title = ''
								}
							" />
						<p v-if="errors.title" class="form-error" role="alert">
							{{ errors.title }}
						</p>
					</div>

					<!-- Description -->
					<div class="form-group">
						<label for="dc-description">{{
							t('dossiq', 'Description')
						}}</label>
						<textarea
							id="dc-description"
							v-model="form.description"
							class="deelzaak-create__textarea"
							:placeholder="t('dossiq', 'Optional description…')"
							rows="3" />
					</div>

					<!-- Validation error from server -->
					<p
						v-if="serverError"
						class="form-error form-error--server"
						role="alert">
						{{ serverError }}
					</p>
				</template>
			</template>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!parentRefusal && availableCaseTypes.length > 0"
				variant="primary"
				:disabled="saving || !selectedCaseType"
				@click="submit">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('dossiq', 'Create sub-case') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { emit } from '@nextcloud/event-bus'
import {
	NcButton,
	NcDialog,
	NcEmptyContent,
	NcLoadingIcon,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import logger from '../logger.js'
import { createSubCase } from '../services/deelzaakApi.js'
import { useDeelzaakStore } from '../store/modules/deelzaak.js'
import { useObjectStore } from '../store/modules/object.js'
import {
	calculateDeadline,
	DEFAULT_CASE_CONFIDENTIALITY,
	generateIdentifier,
} from '../utils/caseHelpers.js'

/** The library's page refresh channel: the lists on the page refetch. */
const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'DeelzaakCreateModal',
	components: {
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		AlertCircleOutline,
	},

	props: {
		/** Parent case UUID — will be set as `parentCase` on the new sub-case. */
		parentCase: {
			type: String,
			required: true,
		},

		/** Parent caseType object, used to filter the allowed child types. Read from the parent when absent. */
		parentCaseType: {
			type: Object,
			default: null,
		},
	},

	emits: ['created', 'close'],
	data() {
		return {
			form: {
				title: '',
				description: '',
				caseType: null,
			},

			selectedCaseType: null,
			parent: null,
			loadedParentCaseType: null,
			caseTypes: [],
			statusTypes: [],
			errors: {},
			saving: false,
			loadingTypes: false,
			loadingParent: true,
			serverError: '',
		}
	},

	computed: {
		objectStore() {
			return useObjectStore()
		},

		deelzaakStore() {
			return useDeelzaakStore()
		},

		/**
		 * Dialog heading for the sub-case creation modal.
		 *
		 * @return {string} The translated title.
		 *
		 * @spec openspec/specs/deelzaak-support/spec.md#requirement-sub-case-creation-from-parent-case
		 */
		dialogTitle() {
			return t('dossiq', 'Create sub-case')
		},

		/**
		 * @spec openspec/changes/deelzaak-support/tasks.md#T08
		 * Filter caseTypes to ONLY those listed on parentCaseType.subCaseTypes.
		 */
		effectiveParentCaseType() {
			return this.parentCaseType || this.loadedParentCaseType
		},

		/**
		 * Why the parent cannot take a sub-case, or '' when it can.
		 *
		 * @return {string} The translated reason.
		 *
		 * @spec openspec/specs/deelzaak-support/spec.md#requirement-sub-case-creation-from-parent-case
		 */
		parentRefusal() {
			if (this.parent?.parentCase) {
				return t('dossiq', 'Sub-cases cannot themselves have sub-cases.')
			}
			if (this.parent?.endDate) {
				return t('dossiq', 'This case is closed; sub-cases can no longer be added.')
			}
			return ''
		},

		/**
		 * The case types the parent's case type allows as sub-cases.
		 *
		 * @return {Array<object>} The allowed case types.
		 *
		 * @spec openspec/specs/deelzaak-support/spec.md#requirement-sub-case-creation-from-parent-case
		 */
		availableCaseTypes() {
			const allowed = this.effectiveParentCaseType?.subCaseTypes || []
			if (!Array.isArray(allowed) || allowed.length === 0) {
				return []
			}
			const allowedSet = new Set(allowed.map(String))
			return this.caseTypes.filter(
				(ct) =>
					allowedSet.has(String(ct.id))
					|| allowedSet.has(String(ct.slug))
					|| allowedSet.has(String(ct.uuid)),
			)
		},
	},

	async mounted() {
		await Promise.all([this.loadParent(), this.loadCaseTypes()])
	},

	methods: {
		/**
		 * Read the parent case, and its case type when none was passed.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/deelzaak-support/spec.md#requirement-sub-case-creation-from-parent-case
		 */
		async loadParent() {
			try {
				this.parent = await this.objectStore.fetchObject('case', this.parentCase)
				const typeId = this.parent?.caseType
				if (!this.parentCaseType && typeId) {
					this.loadedParentCaseType = await this.objectStore.fetchObject('caseType', typeId)
				}
			} catch (err) {
				logger.error('DeelzaakCreateModal: failed to load the parent case', { error: err })
			} finally {
				this.loadingParent = false
			}
		},

		async loadCaseTypes() {
			this.loadingTypes = true
			try {
				const results = await this.objectStore.fetchCollection('caseType', {
					_limit: 200,
				})
				this.caseTypes = Array.isArray(results) ? results : []
			} catch (err) {
				console.error('[DeelzaakCreateModal] Failed to load caseTypes', err)
				this.caseTypes = []
			} finally {
				this.loadingTypes = false
			}
		},

		/**
		 * Load the status types of the SELECTED sub-case type.
		 *
		 * Filters on a bare `caseType` field name: the `_filters[caseType]`
		 * form this used is inert, so the picker offered every case type's
		 * statuses rather than the chosen one's.
		 *
		 * @param {object|null} caseType The selected sub-case type.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/deelzaak-support/spec.md#requirement-sub-case-creation-from-parent-case
		 */
		async onCaseTypeSelected(caseType) {
			this.form.caseType = caseType?.id || null
			this.errors.caseType = ''
			this.statusTypes = []
			if (!caseType) {
				return
			}
			try {
				const results = await this.objectStore.fetchCollection(
					'statusType',
					{
						caseType: caseType.id,
						_order: JSON.stringify({ order: 'asc' }),
						_limit: 100,
					},
				)
				this.statusTypes = Array.isArray(results) ? results : []
			} catch (err) {
				console.error(
					'[DeelzaakCreateModal] Failed to load statusTypes',
					err,
				)
				this.statusTypes = []
			}
		},

		/**
		 * Validate the sub-case form before submission.
		 *
		 * @return {boolean} True when the form may be submitted.
		 *
		 * @spec openspec/specs/deelzaak-support/spec.md#requirement-sub-case-creation-from-parent-case
		 */
		validate() {
			const errs = {}
			if (!this.form.title || !this.form.title.trim()) {
				errs.title = t('dossiq', 'Title is required')
			}
			if (!this.selectedCaseType) {
				errs.caseType = t('dossiq', 'Sub-case type is required')
			}
			this.errors = errs
			return Object.keys(errs).length === 0
		},

		/**
		 * @spec openspec/changes/deelzaak-support/tasks.md#T08
		 * Submit creates a `case` with `parentCase` set, optionally pre-checked
		 * via the backend `/api/deelzaken/validate` endpoint.
		 */
		async submit() {
			this.serverError = ''
			if (!this.validate()) {
				return
			}
			this.saving = true
			try {
				// Server-side validation (cheaper than rolling back a saved row)
				const v = await this.deelzaakStore.validateSubCase({
					parentCaseUuid: this.parentCase,
					childCaseTypeId: this.selectedCaseType.id,
				})
				if (v && v.ok === false) {
					this.serverError =
						v.reason || t('dossiq', 'Sub-case validation failed.')
					this.saving = false
					return
				}

				const now = new Date()
				const startDate = now.toISOString().split('T')[0] + 'T00:00:00Z'
				const deadline = calculateDeadline(
					now,
					this.selectedCaseType.processingDeadline,
				)
				const initialStatus = [...this.statusTypes].sort(
					(a, b) => (a.order || 0) - (b.order || 0),
				)[0]
				const currentUser =
					(getCurrentUser() && getCurrentUser().uid) || 'unknown'

				const caseData = {
					title: this.form.title.trim(),
					description: this.form.description.trim(),
					identifier: generateIdentifier(),
					caseType: this.selectedCaseType.id,
					status: initialStatus?.id || null,
					startDate,
					deadline: deadline
						? deadline.toISOString().split('T')[0] + 'T17:00:00Z'
						: null,

					confidentiality:
						this.selectedCaseType.confidentiality
						|| DEFAULT_CASE_CONFIDENTIALITY,

					assignee: this.selectedCaseType.defaultAssignee || null,
					intakeChannel: 'manual',
					priority: 'normal',
					endDate: null,
					result: null,
					extensionCount: 0,
					parentCase: this.parentCase,
					// statusHistory/activity are JSON-encoded strings per the
					// case schema (dossiq_register.json).
					statusHistory: JSON.stringify([
						{
							status: initialStatus?.id || null,
							date: now.toISOString(),
							changedBy: currentUser,
						},
					]),

					activity: JSON.stringify([
						{
							date: now.toISOString(),
							type: 'created',
							description: t(
								'dossiq',
								"Sub-case created with type '{type}'",
								{
									type: this.selectedCaseType.title,
								},
							),
							user: currentUser,
						},
					]),
				}

				// A derive, not a save. The sub-case takes the confidentiality
				// and the handler its parent carries because case.parentCase
				// declares that inheritance; saving the case directly would
				// create the hierarchy and inherit nothing, silently.
				const result = await createSubCase({
					parentCaseUuid: this.parentCase,
					childCaseTypeId: this.selectedCaseType.id,
					object: caseData,
				})

				if (result?.ok === false) {
					this.serverError =
						result.reason || t('dossiq', 'Failed to create sub-case.')
					return
				}

				const created = result?.object || {}
				this.$emit('created', created.id || created['@self']?.id || created)
				emit(PAGE_REFRESH)
				this.$emit('close')
			} catch (err) {
				console.error('[DeelzaakCreateModal] Failed to create sub-case', err)
				this.serverError =
					err?.response?.data?.message
					|| err?.message
					|| t('dossiq', 'Failed to create sub-case.')
			} finally {
				this.saving = false
			}
		},

		onDialogClose(open) {
			if (!open) {
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped>
.deelzaak-create {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
}

.deelzaak-create__parent-context {
	display: flex;
	gap: 8px;
	align-items: center;
	padding: 8px 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	font-size: 0.875rem;
}

.parent-context-label {
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.parent-context-value {
	color: var(--color-main-text);
	font-weight: 600;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.form-group label {
	font-weight: 500;
	color: var(--color-main-text);
}

.form-error {
	color: var(--color-error);
	font-size: 0.85rem;
	margin: 0;
}

.form-error--server {
	padding: 8px;
	background: var(--color-error-hover);
	border-radius: var(--border-radius);
}

.deelzaak-create__textarea {
	width: 100%;
	resize: vertical;
	padding: 8px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-family: inherit;
}

.deelzaak-create__textarea:focus {
	border-color: var(--color-primary-element);
	outline: none;
}
</style>
