<template>
	<div>
		<CnDetailPage
			:title="complaint.onderwerp || t('procest', 'Complaint')"
			:subtitle="complaint.klachtnummer ? `${t('procest', 'Complaint')} \u2014 ${complaint.klachtnummer}` : t('procest', 'Complaint')"
			:back-route="{ name: 'Complaints' }"
			:back-label="t('procest', 'Back to complaints')"
			:loading="loading"
			:sidebar="!isNew && !loading"
			object-type="procest_complaint"
			:object-id="complaintId">
			<template #header-actions>
				<NcButton
					v-if="!isReadOnly"
					type="primary"
					:disabled="saving"
					@click="save">
					<template v-if="saving">
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('procest', 'Save') }}
				</NcButton>
			</template>

			<!-- Status card -->
			<CnDetailCard :title="t('procest', 'Status')">
				<template #header-actions>
					<NcSelect
						v-if="!isReadOnly"
						v-model="selectedStatus"
						:options="availableTransitions"
						:placeholder="t('procest', 'Change status...')"
						class="status-select"
						@input="onStatusChange" />
				</template>

				<div class="status-section">
					<span class="status-badge" :class="'status-badge--' + complaint.status">
						{{ getStatusLabel(complaint.status) }}
					</span>
				</div>
			</CnDetailCard>

			<!-- Awb Deadlines card -->
			<CnDetailCard :title="t('procest', 'Awb Deadlines')">
				<DeadlinePanel
					:start-date="complaint.ontvangstdatum"
					:deadline="complaint.afhandelDeadline"
					processing-deadline="P42D"
					:extension-allowed="complaint.verdagingMogelijk === true"
					extension-period="P28D"
					:extension-count="complaint.verdagingMogelijk === false ? 1 : 0"
					:is-final="complaint.status === 'afgehandeld' || complaint.status === 'ingetrokken'"
					@extend="showExtensionDialog = true" />

				<div class="deadline-panel__extra">
					<div class="deadline-panel__item">
						<span class="deadline-panel__label">{{ t('procest', 'Acknowledgment deadline') }}</span>
						<span class="deadline-panel__value" :class="acknowledgmentDeadlineClass">
							{{ formatDate(complaint.ontvangstbevestigingDeadline) }}
						</span>
					</div>
				</div>
			</CnDetailCard>

			<!-- Complaint Information card -->
			<CnDetailCard :title="t('procest', 'Complaint Information')">
				<div class="form-group">
					<label>{{ t('procest', 'Subject') }} *</label>
					<NcTextField
						:value="form.onderwerp"
						:disabled="isReadOnly"
						:error="!!validationErrors.onderwerp"
						@update:value="v => { form.onderwerp = v; validationErrors.onderwerp = '' }" />
					<p v-if="validationErrors.onderwerp" class="form-error">
						{{ validationErrors.onderwerp }}
					</p>
				</div>

				<div class="form-group">
					<label>{{ t('procest', 'Description') }} *</label>
					<textarea
						v-model="form.omschrijving"
						:disabled="isReadOnly"
						rows="4" />
				</div>

				<div class="form-row">
					<div class="form-group">
						<label>{{ t('procest', 'Category') }}</label>
						<NcSelect
							v-model="form.categorie"
							:options="categories"
							label="name"
							track-by="id"
							:disabled="isReadOnly"
							:placeholder="t('procest', 'Select category...')" />
					</div>
					<div class="form-group">
						<label>{{ t('procest', 'Priority') }}</label>
						<NcSelect
							v-model="form.prioriteit"
							:options="priorityOptions"
							:disabled="isReadOnly" />
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label>{{ t('procest', 'Intake channel') }}</label>
						<NcSelect
							v-model="form.ontvangstkanaal"
							:options="channelOptions"
							:disabled="isReadOnly" />
					</div>
					<div class="form-group">
						<label>{{ t('procest', 'Received date') }}</label>
						<span class="form-value">{{ formatDate(complaint.ontvangstdatum) }}</span>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label>{{ t('procest', 'Handler') }}</label>
						<NcTextField
							:value="form.behandelaar"
							:disabled="isReadOnly"
							:placeholder="t('procest', 'Assign handler...')"
							@update:value="v => form.behandelaar = v" />
					</div>
					<div class="form-group">
						<label>{{ t('procest', 'Department') }}</label>
						<NcTextField
							:value="form.betrokkenAfdeling"
							:disabled="isReadOnly"
							@update:value="v => form.betrokkenAfdeling = v" />
					</div>
				</div>
			</CnDetailCard>

			<!-- Complainant Information card -->
			<CnDetailCard :title="t('procest', 'Complainant')">
				<div class="form-row">
					<div class="form-group">
						<label>{{ t('procest', 'Name') }}</label>
						<NcTextField
							:value="form.klagerNaam"
							:disabled="isReadOnly"
							@update:value="v => form.klagerNaam = v" />
					</div>
					<div class="form-group">
						<label>{{ t('procest', 'Email') }}</label>
						<NcTextField
							:value="form.klagerEmail"
							:disabled="isReadOnly"
							@update:value="v => form.klagerEmail = v" />
					</div>
				</div>
				<div class="form-group">
					<label>{{ t('procest', 'Phone') }}</label>
					<NcTextField
						:value="form.klagerTelefoon"
						:disabled="isReadOnly"
						@update:value="v => form.klagerTelefoon = v" />
				</div>
			</CnDetailCard>

			<!-- Escalation card (if escalated) -->
			<CnDetailCard v-if="complaint.geescaleerdeZaak" :title="t('procest', 'Escalated Case')">
				<div class="escalation-link">
					<router-link :to="{ name: 'CaseDetail', params: { id: complaint.geescaleerdeZaak } }">
						{{ t('procest', 'View linked case') }}
					</router-link>
				</div>
			</CnDetailCard>

			<!-- Actions card -->
			<CnDetailCard v-if="!isReadOnly && !isNew" :title="t('procest', 'Actions')">
				<template #header-actions>
					<NcButton
						v-if="complaint.status === 'in_behandeling'"
						@click="showHearingDialog = true">
						{{ t('procest', 'Schedule Hearing') }}
					</NcButton>
					<NcButton
						v-if="complaint.status === 'hoorgesprek_afgerond'"
						@click="showDispositionDialog = true">
						{{ t('procest', 'Record Disposition') }}
					</NcButton>
					<NcButton
						v-if="!complaint.geescaleerdeZaak && complaint.status !== 'afgehandeld'"
						@click="showEscalateDialog = true">
						{{ t('procest', 'Escalate to Case') }}
					</NcButton>
				</template>
			</CnDetailCard>

			<!-- Activity card -->
			<CnDetailCard :title="t('procest', 'Activity')">
				<ActivityTimeline
					:activity="complaint.activity || []"
					:is-read-only="isReadOnly"
					@add-note="onAddNote" />
			</CnDetailCard>
		</CnDetailPage>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField, NcSelect } from '@nextcloud/vue'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import { formatDate } from '../../utils/caseHelpers.js'
import DeadlinePanel from '../cases/components/DeadlinePanel.vue'
import ActivityTimeline from '../cases/components/ActivityTimeline.vue'

export default {
	name: 'ComplaintDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		NcSelect,
		CnDetailPage,
		CnDetailCard,
		DeadlinePanel,
		ActivityTimeline,
	},
	props: {
		complaintId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: false,
			saving: false,
			complaint: {},
			categories: [],
			form: {
				onderwerp: '',
				omschrijving: '',
				categorie: null,
				prioriteit: 'normaal',
				ontvangstkanaal: null,
				behandelaar: '',
				betrokkenAfdeling: '',
				klagerNaam: '',
				klagerEmail: '',
				klagerTelefoon: '',
			},
			validationErrors: {},
			selectedStatus: null,
			showHearingDialog: false,
			showDispositionDialog: false,
			showEscalateDialog: false,
			showExtensionDialog: false,
		}
	},
	computed: {
		isNew() {
			return this.complaintId === 'new'
		},
		isReadOnly() {
			return this.complaint.status === 'afgehandeld' || this.complaint.status === 'ingetrokken'
		},
		priorityOptions() {
			return [
				{ id: 'laag', label: this.t('procest', 'Low') },
				{ id: 'normaal', label: this.t('procest', 'Normal') },
				{ id: 'hoog', label: this.t('procest', 'High') },
				{ id: 'urgent', label: this.t('procest', 'Urgent') },
			]
		},
		channelOptions() {
			return [
				{ id: 'balie', label: this.t('procest', 'Counter') },
				{ id: 'telefoon', label: this.t('procest', 'Phone') },
				{ id: 'email', label: this.t('procest', 'Email') },
				{ id: 'brief', label: this.t('procest', 'Letter') },
				{ id: 'website', label: this.t('procest', 'Website') },
				{ id: 'socialmedia', label: this.t('procest', 'Social media') },
			]
		},
		availableTransitions() {
			const transitions = {
				ontvangen: ['ontvangst_bevestigd', 'ingetrokken'],
				ontvangst_bevestigd: ['in_behandeling', 'ingetrokken'],
				in_behandeling: ['hoorgesprek_gepland', 'afgehandeld', 'ingetrokken'],
				hoorgesprek_gepland: ['hoorgesprek_afgerond', 'ingetrokken'],
				hoorgesprek_afgerond: ['afgehandeld', 'ingetrokken'],
			}
			const allowed = transitions[this.complaint.status] || []
			return allowed.map(s => ({ id: s, label: this.getStatusLabel(s) }))
		},
		acknowledgmentDeadlineClass() {
			if (!this.complaint.ontvangstbevestigingDeadline) return ''
			if (this.complaint.status !== 'ontvangen') return ''
			const now = new Date()
			const deadline = new Date(this.complaint.ontvangstbevestigingDeadline)
			if (now > deadline) return 'deadline--overdue'
			const daysLeft = Math.ceil((deadline - now) / (1000 * 60 * 60 * 24))
			if (daysLeft <= 2) return 'deadline--warning'
			return ''
		},
	},
	mounted() {
		if (!this.isNew) {
			this.loadComplaint()
		}
	},
	methods: {
		formatDate,
		async loadComplaint() {
			this.loading = true
			try {
				const store = useObjectStore()
				this.complaint = await store.fetchObject('complaint', this.complaintId)
				this.populateForm()
			} catch (error) {
				console.error('Failed to load complaint:', error)
			} finally {
				this.loading = false
			}
		},
		populateForm() {
			this.form = {
				onderwerp: this.complaint.onderwerp || '',
				omschrijving: this.complaint.omschrijving || '',
				categorie: this.complaint.categorie || null,
				prioriteit: this.complaint.prioriteit || 'normaal',
				ontvangstkanaal: this.complaint.ontvangstkanaal || null,
				behandelaar: this.complaint.behandelaar || '',
				betrokkenAfdeling: this.complaint.betrokkenAfdeling || '',
				klagerNaam: this.complaint.klagerNaam || '',
				klagerEmail: this.complaint.klagerEmail || '',
				klagerTelefoon: this.complaint.klagerTelefoon || '',
			}
		},
		getStatusLabel(status) {
			const labels = {
				ontvangen: this.t('procest', 'Received'),
				ontvangst_bevestigd: this.t('procest', 'Acknowledged'),
				in_behandeling: this.t('procest', 'In progress'),
				hoorgesprek_gepland: this.t('procest', 'Hearing planned'),
				hoorgesprek_afgerond: this.t('procest', 'Hearing completed'),
				afgehandeld: this.t('procest', 'Resolved'),
				ingetrokken: this.t('procest', 'Withdrawn'),
			}
			return labels[status] || status
		},
		async save() {
			this.validationErrors = {}
			if (!this.form.onderwerp) {
				this.validationErrors.onderwerp = this.t('procest', 'Subject is required')
				return
			}
			this.saving = true
			try {
				const store = useObjectStore()
				const data = { ...this.form }
				if (this.isNew) {
					data.ontvangstdatum = new Date().toISOString().split('T')[0]
					data.status = 'ontvangen'
					await store.createObject('complaint', data)
				} else {
					await store.updateObject('complaint', this.complaintId, data)
				}
				this.loadComplaint()
			} catch (error) {
				console.error('Failed to save complaint:', error)
			} finally {
				this.saving = false
			}
		},
		async onStatusChange(status) {
			if (!status) return
			try {
				const store = useObjectStore()
				await store.updateObject('complaint', this.complaintId, { status: status.id })
				this.selectedStatus = null
				this.loadComplaint()
			} catch (error) {
				console.error('Failed to update status:', error)
			}
		},
		async onAddNote(text) {
			try {
				const store = useObjectStore()
				const activity = [...(this.complaint.activity || [])]
				activity.push({
					type: 'note',
					description: text,
					user: OC.getCurrentUser().uid,
					date: new Date().toISOString(),
				})
				await store.updateObject('complaint', this.complaintId, { activity })
				this.loadComplaint()
			} catch (error) {
				console.error('Failed to add note:', error)
			}
		},
	},
}
</script>

<style scoped>
.status-section {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.status-section__change {
	flex: 1;
	min-width: 200px;
}

.deadline-panel__extra {
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.deadline-panel__item {
	display: flex;
	flex-direction: column;
}

.deadline-panel__label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.deadline-panel__value {
	font-size: 14px;
	font-weight: 500;
}

.deadline--overdue {
	color: var(--color-error);
}

.deadline--warning {
	color: var(--color-warning);
}

.action-buttons {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.escalation-link a {
	color: var(--color-primary);
	text-decoration: underline;
}

.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	font-size: 13px;
	font-weight: 500;
	margin-bottom: 4px;
}

.form-row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
}

.form-error {
	color: var(--color-error);
	font-size: 12px;
	margin-top: 4px;
}

.form-value {
	font-size: 14px;
}
</style>
