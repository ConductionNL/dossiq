<template>
	<div class="decisions-section">
		<template v-if="loading">
			<NcLoadingIcon :size="20" />
		</template>

		<template v-else-if="decisions.length === 0">
			<p class="decisions-section__empty">
				{{ t('procest', '(no decisions yet)') }}
			</p>
		</template>

		<template v-else>
			<div class="decisions-section__list">
				<div
					v-for="decision in sortedDecisions"
					:key="decision.id"
					class="decision-card"
					role="button"
					tabindex="0"
					@click="editDecision(decision)"
					@keydown.enter="editDecision(decision)"
					@keydown.space.prevent="editDecision(decision)">
					<div class="decision-card__header">
						<span class="decision-card__title">{{
							decision.title || '—'
						}}</span>
						<span
							v-if="getValidity(decision).label"
							class="decision-card__validity"
							:class="getValidity(decision).style">
							{{ getValidity(decision).label }}
						</span>
					</div>
					<div class="decision-card__meta">
						<span v-if="decision.decidedBy || decision.decisionDate">
							{{
								t('procest', 'Decided by {user} on {date}', {
									user:
										decision.decidedBy
										|| t('procest', 'unknown'),
									date: formatDate(decision.decisionDate),
								})
							}}
						</span>
						<span
							v-if="decision.decisionType"
							class="decision-card__type">
							{{ getDecisionTypeName(decision.decisionType) }}
						</span>
					</div>
					<div
						v-if="getValidity(decision).remaining"
						class="decision-card__remaining">
						{{ getValidity(decision).remaining }}
					</div>
				</div>
			</div>
		</template>

		<NcButton v-if="!isReadOnly" @click="showCreateForm = true">
			{{ t('procest', 'Add Decision') }}
		</NcButton>

		<!-- Create/Edit Dialog -->
		<div
			v-if="showCreateForm"
			class="decision-overlay"
			role="button"
			tabindex="0"
			@click.self="closeForm"
			@keydown.enter.self="closeForm"
			@keydown.space.self.prevent="closeForm">
			<div class="decision-dialog">
				<h3>
					{{
						editingDecision
							? t('procest', 'Edit Decision')
							: t('procest', 'New Decision')
					}}
				</h3>

				<div class="form-group">
					<label for="decisions-section-title"
						>{{ t('procest', 'Title') }} *</label
					>
					<NcTextField
						id="decisions-section-title"
						:model-value="form.title"
						:error="!!formErrors.title"
						@update:model-value="
							(v) => {
								form.title = v
								formErrors.title = ''
							}
						" />
					<p v-if="formErrors.title" class="form-error">
						{{ formErrors.title }}
					</p>
				</div>

				<div class="form-group">
					<label for="decisions-section-description">{{
						t('procest', 'Description')
					}}</label>
					<textarea
						id="decisions-section-description"
						v-model="form.description"
						rows="3" />
				</div>

				<div class="form-row">
					<div class="form-group">
						<label for="decisions-section-effective-date">{{
							t('procest', 'Effective date')
						}}</label>
						<NcTextField
							id="decisions-section-effective-date"
							:model-value="form.effectiveDate"
							type="date"
							@update:model-value="(v) => (form.effectiveDate = v)" />
					</div>
					<div class="form-group">
						<label for="decisions-section-expiry-date">{{
							t('procest', 'Expiry date')
						}}</label>
						<NcTextField
							id="decisions-section-expiry-date"
							:model-value="form.expiryDate"
							type="date"
							:error="!!formErrors.expiryDate"
							@update:model-value="
								(v) => {
									form.expiryDate = v
									formErrors.expiryDate = ''
								}
							" />
						<p v-if="formErrors.expiryDate" class="form-error">
							{{ formErrors.expiryDate }}
						</p>
					</div>
				</div>

				<div v-if="decisionTypes.length > 0" class="form-group">
					<label>{{ t('procest', 'Decision type') }}</label>
					<NcSelect
						v-model="form.decisionType"
						:options="decisionTypeOptions"
						:aria-label-combobox="t('procest', 'Decision type')"
						label="label"
						:reduce="(o) => o.value"
						:clearable="true"
						:placeholder="
							t('procest', 'Select decision type (optional)')
						" />
				</div>

				<div class="decision-dialog__actions">
					<NcButton @click="closeForm">
						{{ t('procest', 'Cancel') }}
					</NcButton>
					<NcButton
						v-if="editingDecision"
						type="error"
						@click="deleteDecision">
						{{ t('procest', 'Delete') }}
					</NcButton>
					<NcButton
						type="primary"
						:disabled="saving"
						@click="saveDecision">
						{{ t('procest', 'Save') }}
					</NcButton>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField, NcSelect } from '@nextcloud/vue'
import { useObjectStore } from '../../../store/modules/object.js'
import {
	getDecisionValidity,
	formatDecisionDate,
	validateDecision,
} from '../../../utils/decisionHelpers.js'

export default {
	name: 'DecisionsSection',
	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		NcSelect,
	},
	props: {
		caseId: {
			type: String,
			required: true,
		},
		caseTypeId: {
			type: String,
			default: null,
		},
		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			loading: false,
			saving: false,
			decisions: [],
			decisionTypes: [],
			showCreateForm: false,
			editingDecision: null,
			form: {
				title: '',
				description: '',
				effectiveDate: '',
				expiryDate: '',
				decisionType: null,
			},
			formErrors: {},
		}
	},
	computed: {
		/** @spec openspec/specs/roles-decisions/spec.md */
		sortedDecisions() {
			return [...this.decisions].sort((a, b) => {
				const dateA = a.decisionDate || a.created || ''
				const dateB = b.decisionDate || b.created || ''
				return dateB.localeCompare(dateA) // newest first
			})
		},
		/** @spec openspec/specs/roles-decisions/spec.md */
		decisionTypeOptions() {
			return this.decisionTypes.map((dt) => ({
				value: dt.id,
				label: dt.name,
			}))
		},
	},
	async mounted() {
		await this.loadData()
	},
	methods: {
		/** @spec openspec/specs/roles-decisions/spec.md */
		async loadData() {
			this.loading = true
			const objectStore = useObjectStore()

			const fetchPromises = [
				objectStore.fetchCollection('decision', {
					'_filters[case]': this.caseId,
					_limit: 50,
				}),
			]

			if (this.caseTypeId) {
				fetchPromises.push(
					objectStore.fetchCollection('decisionType', {
						'_filters[caseType]': this.caseTypeId,
						_limit: 50,
					}),
				)
			}

			const [decisions, decisionTypes] = await Promise.all(fetchPromises)
			this.decisions = decisions || []
			this.decisionTypes = decisionTypes || []
			this.loading = false
		},

		getValidity(decision) {
			return getDecisionValidity(decision)
		},

		/**
		 * @param dateStr
		 * @spec openspec/specs/roles-decisions/spec.md
		 */
		formatDate(dateStr) {
			return formatDecisionDate(dateStr)
		},

		/**
		 * @param typeId
		 * @spec openspec/specs/roles-decisions/spec.md
		 */
		getDecisionTypeName(typeId) {
			const dt = this.decisionTypes.find((t) => t.id === typeId)
			return dt?.name || ''
		},

		/**
		 * @param decision
		 * @spec openspec/specs/roles-decisions/spec.md
		 */
		editDecision(decision) {
			if (this.isReadOnly) return
			this.editingDecision = decision
			this.form = {
				title: decision.title || '',
				description: decision.description || '',
				effectiveDate: decision.effectiveDate || '',
				expiryDate: decision.expiryDate || '',
				decisionType: decision.decisionType || null,
			}
			this.formErrors = {}
			this.showCreateForm = true
		},

		/** @spec openspec/specs/roles-decisions/spec.md */
		closeForm() {
			this.showCreateForm = false
			this.editingDecision = null
			this.form = {
				title: '',
				description: '',
				effectiveDate: '',
				expiryDate: '',
				decisionType: null,
			}
			this.formErrors = {}
		},

		/** @spec openspec/specs/roles-decisions/spec.md */
		async saveDecision() {
			const validation = validateDecision(this.form)
			if (!validation.valid) {
				this.formErrors = validation.errors
				return
			}

			this.saving = true
			const objectStore = useObjectStore()

			const currentUser = OC?.currentUser || 'unknown'
			const data = {
				title: this.form.title.trim(),
				description: this.form.description.trim(),
				case: this.caseId,
				effectiveDate: this.form.effectiveDate || null,
				expiryDate: this.form.expiryDate || null,
				decisionType: this.form.decisionType || null,
			}

			if (this.editingDecision) {
				data.id = this.editingDecision.id
				data.decidedBy = this.editingDecision.decidedBy
				data.decisionDate = this.editingDecision.decisionDate
			} else {
				data.decidedBy = currentUser
				data.decisionDate = new Date().toISOString().split('T')[0]
			}

			await objectStore.saveObject('decision', data)
			this.saving = false
			this.closeForm()
			await this.loadData()
		},

		/** @spec openspec/specs/roles-decisions/spec.md */
		async deleteDecision() {
			if (!this.editingDecision) return
			if (
				!confirm(
					t('procest', 'Are you sure you want to delete this decision?'),
				)
			)
				return

			const objectStore = useObjectStore()
			await objectStore.deleteObject('decision', this.editingDecision.id)
			this.closeForm()
			await this.loadData()
		},
	},
}
</script>

<style scoped>
.decisions-section__empty {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin-bottom: 12px;
}

.decisions-section__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 12px;
}

.decision-card {
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	transition: background-color 0.15s ease;
}

.decision-card:hover {
	background: var(--color-background-hover);
}

.decision-card__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	margin-bottom: 4px;
}

.decision-card__title {
	font-weight: 600;
}

.decision-card__validity {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 11px;
	font-weight: 500;
}

.validity--active {
	background: var(--color-success);
	color: white;
}

.validity--warning {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.validity--expired {
	background: var(--color-error);
	color: white;
}

.validity--pending {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.decision-card__meta {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	display: flex;
	gap: 12px;
}

.decision-card__remaining {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

/* Dialog */
.decision-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.decision-dialog {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.2);
	padding: 24px;
	width: 520px;
	max-width: 90vw;
}

.decision-dialog h3 {
	margin: 0 0 16px;
}

.decision-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 16px;
}

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.form-group textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.form-row {
	display: flex;
	gap: 16px;
}

.form-row .form-group {
	flex: 1;
}

.form-error {
	color: var(--color-error);
	font-size: 13px;
	margin-top: 4px;
}

@media (prefers-reduced-motion: reduce) {
	.decision-card {
		transition: none;
	}
}
</style>
