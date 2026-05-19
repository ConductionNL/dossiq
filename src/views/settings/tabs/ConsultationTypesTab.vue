<template>
	<div class="consultation-types-tab">
		<div v-if="isCreate" class="consultation-types-tab__notice">
			<p>{{ t('procest', 'Save the case type first before configuring consultation types.') }}</p>
		</div>

		<template v-else>
			<NcLoadingIcon v-if="loading" />

			<template v-else>
				<div class="consultation-types-tab__header">
					<p class="consultation-types-tab__description">
						{{ t('procest', 'Configure which advisory body consultations are required or optional for this case type. Mandatory consultations block case progression until completed.') }}
					</p>
					<NcButton type="primary" @click="showAddForm = true">
						{{ t('procest', 'Add consultation type') }}
					</NcButton>
				</div>

				<!-- Add form -->
				<div v-if="showAddForm" class="consultation-type-form">
					<h4>{{ t('procest', 'New consultation type') }}</h4>

					<div class="form-row">
						<label class="form-label">{{ t('procest', 'Advisory body') }} *</label>
						<select v-model="addForm.advisoryBodyId" class="form-select">
							<option value="">
								{{ t('procest', '-- Select advisory body --') }}
							</option>
							<option v-for="body in advisoryBodies" :key="body.id" :value="body.id">
								{{ body.name }}
							</option>
						</select>
					</div>

					<div class="form-row">
						<label class="form-label">{{ t('procest', 'Label') }} *</label>
						<input
							v-model="addForm.label"
							type="text"
							class="form-input"
							:placeholder="t('procest', 'e.g. Fire safety advice')" />
					</div>

					<div class="form-row">
						<label class="form-label">{{ t('procest', 'Obligation') }}</label>
						<select v-model="addForm.obligation" class="form-select">
							<option value="mandatory">
								{{ t('procest', 'Mandatory (blocks progression)') }}
							</option>
							<option value="optional">
								{{ t('procest', 'Optional') }}
							</option>
						</select>
					</div>

					<div class="form-row">
						<label class="form-label">{{ t('procest', 'Default deadline (days)') }}</label>
						<input
							v-model.number="addForm.defaultDeadlineDays"
							type="number"
							min="1"
							max="365"
							class="form-input form-input--short" />
					</div>

					<div class="form-row">
						<label class="form-label">{{ t('procest', 'Depends on') }}</label>
						<select v-model="addForm.dependsOn" class="form-select">
							<option value="">
								{{ t('procest', '-- None --') }}
							</option>
							<option v-for="ct in consultationTypes" :key="ct.id" :value="ct.id">
								{{ ct.label }}
							</option>
						</select>
						<span class="form-hint">{{ t('procest', 'This consultation can only start after the selected one is completed.') }}</span>
					</div>

					<span v-if="addError" class="form-error">{{ addError }}</span>

					<div class="form-actions">
						<NcButton type="primary" :disabled="addSaving || !addFormValid" @click="saveAdd">
							<template v-if="addSaving" #icon>
								<NcLoadingIcon :size="20" />
							</template>
							{{ t('procest', 'Add') }}
						</NcButton>
						<NcButton type="tertiary" @click="cancelAdd">
							{{ t('procest', 'Cancel') }}
						</NcButton>
					</div>
				</div>

				<!-- Existing consultation types -->
				<div v-if="consultationTypes.length > 0" class="consultation-types-list">
					<div
						v-for="ct in consultationTypes"
						:key="ct.id"
						class="consultation-type-row">
						<template v-if="editingId !== ct.id">
							<div class="consultation-type-row__info">
								<span class="consultation-type-row__label">{{ ct.label }}</span>
								<span class="consultation-type-row__body">{{ ct.advisoryBodyName }}</span>
								<span
									class="consultation-type-row__badge"
									:class="ct.obligation === 'mandatory' ? 'badge--mandatory' : 'badge--optional'">
									{{ ct.obligation === 'mandatory' ? t('procest', 'Mandatory') : t('procest', 'Optional') }}
								</span>
								<span v-if="ct.defaultDeadlineDays" class="consultation-type-row__deadline">
									{{ t('procest', '{n} days', { n: ct.defaultDeadlineDays }) }}
								</span>
							</div>
							<div class="consultation-type-row__actions">
								<NcButton type="tertiary" @click="startEdit(ct)">
									<template #icon>
										<PencilIcon :size="20" />
									</template>
								</NcButton>
								<NcButton type="tertiary" @click="deleteConsultationType(ct)">
									<template #icon>
										<DeleteIcon :size="20" />
									</template>
								</NcButton>
							</div>
						</template>

						<template v-else>
							<div class="consultation-type-form">
								<div class="form-row">
									<label class="form-label">{{ t('procest', 'Advisory body') }} *</label>
									<select v-model="editForm.advisoryBodyId" class="form-select">
										<option value="">
											{{ t('procest', '-- Select advisory body --') }}
										</option>
										<option v-for="body in advisoryBodies" :key="body.id" :value="body.id">
											{{ body.name }}
										</option>
									</select>
								</div>
								<div class="form-row">
									<label class="form-label">{{ t('procest', 'Label') }} *</label>
									<input v-model="editForm.label" type="text" class="form-input" />
								</div>
								<div class="form-row">
									<label class="form-label">{{ t('procest', 'Obligation') }}</label>
									<select v-model="editForm.obligation" class="form-select">
										<option value="mandatory">
											{{ t('procest', 'Mandatory (blocks progression)') }}
										</option>
										<option value="optional">
											{{ t('procest', 'Optional') }}
										</option>
									</select>
								</div>
								<div class="form-row">
									<label class="form-label">{{ t('procest', 'Default deadline (days)') }}</label>
									<input
										v-model.number="editForm.defaultDeadlineDays"
										type="number"
										min="1"
										max="365"
										class="form-input form-input--short" />
								</div>
								<div class="form-row">
									<label class="form-label">{{ t('procest', 'Depends on') }}</label>
									<select v-model="editForm.dependsOn" class="form-select">
										<option value="">
											{{ t('procest', '-- None --') }}
										</option>
										<option
											v-for="other in consultationTypes.filter(x => x.id !== ct.id)"
											:key="other.id"
											:value="other.id">
											{{ other.label }}
										</option>
									</select>
								</div>
								<span v-if="editError" class="form-error">{{ editError }}</span>
								<div class="form-actions">
									<NcButton type="primary" :disabled="editSaving" @click="saveEdit">
										{{ t('procest', 'Save') }}
									</NcButton>
									<NcButton type="tertiary" @click="cancelEdit">
										{{ t('procest', 'Cancel') }}
									</NcButton>
								</div>
							</div>
						</template>
					</div>
				</div>

				<p v-else-if="!showAddForm" class="consultation-types-tab__empty">
					{{ t('procest', 'No consultation types configured yet.') }}
				</p>
			</template>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const EMPTY_ADD_FORM = {
	advisoryBodyId: '',
	label: '',
	obligation: 'mandatory',
	defaultDeadlineDays: 28,
	dependsOn: '',
}

export default {
	name: 'ConsultationTypesTab',
	components: {
		NcButton,
		NcLoadingIcon,
		PencilIcon,
		DeleteIcon,
	},
	props: {
		caseTypeId: {
			type: String,
			default: null,
		},
		isCreate: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			loading: false,
			consultationTypes: [],
			advisoryBodies: [],
			showAddForm: false,
			addForm: { ...EMPTY_ADD_FORM },
			addSaving: false,
			addError: '',
			editingId: null,
			editForm: {},
			editSaving: false,
			editError: '',
		}
	},
	computed: {
		addFormValid() {
			return this.addForm.advisoryBodyId !== '' && this.addForm.label.trim() !== ''
		},
	},
	async mounted() {
		if (!this.isCreate) {
			await Promise.all([this.loadConsultationTypes(), this.loadAdvisoryBodies()])
		}
	},
	methods: {
		async loadConsultationTypes() {
			this.loading = true
			try {
				const url = generateUrl('/apps/procest/api/consultation-types')
				const { data } = await axios.get(url, { params: { caseTypeId: this.caseTypeId } })
				this.consultationTypes = data.results || []
			} catch (e) {
				this.consultationTypes = []
			} finally {
				this.loading = false
			}
		},

		async loadAdvisoryBodies() {
			try {
				const url = generateUrl('/apps/procest/api/advisory-bodies')
				const { data } = await axios.get(url)
				this.advisoryBodies = data.results || []
			} catch (e) {
				this.advisoryBodies = []
			}
		},

		cancelAdd() {
			this.showAddForm = false
			this.addForm = { ...EMPTY_ADD_FORM }
			this.addError = ''
		},

		async saveAdd() {
			this.addSaving = true
			this.addError = ''
			try {
				const url = generateUrl('/apps/procest/api/consultation-types')
				const body = {
					...this.addForm,
					caseTypeId: this.caseTypeId,
					advisoryBodyName: (this.advisoryBodies.find(b => b.id === this.addForm.advisoryBodyId) || {}).name || '',
				}
				const { data } = await axios.post(url, body)
				this.consultationTypes.push(data)
				this.cancelAdd()
			} catch (e) {
				this.addError = e?.response?.data?.error || t('procest', 'Could not save consultation type')
			} finally {
				this.addSaving = false
			}
		},

		startEdit(ct) {
			this.editingId = ct.id
			this.editForm = {
				advisoryBodyId: ct.advisoryBodyId || '',
				label: ct.label || '',
				obligation: ct.obligation || 'mandatory',
				defaultDeadlineDays: ct.defaultDeadlineDays || 28,
				dependsOn: ct.dependsOn || '',
			}
			this.editError = ''
		},

		cancelEdit() {
			this.editingId = null
			this.editForm = {}
			this.editError = ''
		},

		async saveEdit() {
			this.editSaving = true
			this.editError = ''
			try {
				const url = generateUrl('/apps/procest/api/consultation-types/' + encodeURIComponent(this.editingId))
				const body = {
					...this.editForm,
					advisoryBodyName: (this.advisoryBodies.find(b => b.id === this.editForm.advisoryBodyId) || {}).name || '',
				}
				const { data } = await axios.put(url, body)
				const idx = this.consultationTypes.findIndex(x => x.id === this.editingId)
				if (idx !== -1) {
					this.consultationTypes.splice(idx, 1, data)
				}
				this.cancelEdit()
			} catch (e) {
				this.editError = e?.response?.data?.error || t('procest', 'Could not save changes')
			} finally {
				this.editSaving = false
			}
		},

		async deleteConsultationType(ct) {
			if (!confirm(t('procest', 'Remove this consultation type from the case type?'))) {
				return
			}
			try {
				const url = generateUrl('/apps/procest/api/consultation-types/' + encodeURIComponent(ct.id))
				await axios.delete(url)
				this.consultationTypes = this.consultationTypes.filter(x => x.id !== ct.id)
			} catch (e) {
				// Silent failure — record already gone or network issue.
			}
		},
	},
}
</script>

<style scoped>
.consultation-types-tab__notice,
.consultation-types-tab__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	padding: 16px 0;
}

.consultation-types-tab__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 16px;
	gap: 12px;
}

.consultation-types-tab__description {
	color: var(--color-text-maxcontrast);
	max-width: 600px;
}

.consultation-type-form {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 16px;
	margin-bottom: 16px;
}

.consultation-type-form h4 {
	margin: 0 0 12px;
}

.form-row {
	margin-bottom: 12px;
}

.form-label {
	display: block;
	font-weight: 500;
	margin-bottom: 4px;
}

.form-input,
.form-select {
	width: 100%;
	max-width: 400px;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.form-input--short {
	max-width: 120px;
}

.form-hint {
	display: block;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}

.form-error {
	display: block;
	color: var(--color-error);
	margin-bottom: 8px;
}

.form-actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}

.consultation-types-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.consultation-type-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.consultation-type-row__info {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.consultation-type-row__label {
	font-weight: 500;
}

.consultation-type-row__body {
	color: var(--color-text-maxcontrast);
}

.consultation-type-row__badge {
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.8em;
	font-weight: 500;
}

.badge--mandatory {
	background: var(--color-error-soft);
	color: var(--color-error);
}

.badge--optional {
	background: var(--color-info-soft);
	color: var(--color-info);
}

.consultation-type-row__deadline {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.consultation-type-row__actions {
	display: flex;
	gap: 4px;
}
</style>
