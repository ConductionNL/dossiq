<template>
	<div class="hearing-panel">
		<h4>{{ t('procest', 'Hearing (Hoorzitting)') }}</h4>

		<!-- Hearing waived -->
		<div v-if="isHearingWaived" class="hearing-panel__waived">
			<NcNoteCard type="info">
				{{ t('procest', 'The objector has waived the right to be heard.') }}
				<span v-if="waiverReason"
					>{{ t('procest', 'Reason:') }} {{ waiverReason }}</span
				>
			</NcNoteCard>
		</div>

		<!-- No hearing yet -->
		<template v-else-if="!activeHearing && !isReadOnly">
			<div class="hearing-panel__actions">
				<NcButton type="primary" @click="showScheduleDialog = true">
					{{ t('procest', 'Schedule Hearing') }}
				</NcButton>
				<NcButton @click="showWaiverDialog = true">
					{{ t('procest', 'Record Hearing Waiver') }}
				</NcButton>
			</div>
		</template>

		<!-- Active hearing -->
		<template v-else-if="activeHearing">
			<div class="hearing-panel__details">
				<div class="hearing-detail">
					<span class="hearing-detail__label">{{
						t('procest', 'Status')
					}}</span>
					<span
						class="hearing-detail__value status-badge"
						:class="'status-badge--' + activeHearing.status">
						{{ getHearingStatusLabel(activeHearing.status) }}
					</span>
				</div>

				<div class="hearing-detail">
					<span class="hearing-detail__label">{{
						t('procest', 'Date')
					}}</span>
					<span class="hearing-detail__value">{{
						formatDateTime(activeHearing.scheduledDate)
					}}</span>
				</div>

				<div v-if="activeHearing.location" class="hearing-detail">
					<span class="hearing-detail__label">{{
						t('procest', 'Location')
					}}</span>
					<span class="hearing-detail__value">{{
						activeHearing.location
					}}</span>
				</div>

				<div v-if="activeHearing.videoCallUrl" class="hearing-detail">
					<span class="hearing-detail__label">{{
						t('procest', 'Video link')
					}}</span>
					<a :href="activeHearing.videoCallUrl" target="_blank">{{
						t('procest', 'Join online')
					}}</a>
				</div>
			</div>

			<!-- Hearing actions based on status -->
			<div v-if="!isReadOnly" class="hearing-panel__actions">
				<NcButton
					v-if="activeHearing.status === 'gepland'"
					type="primary"
					@click="sendInvitations">
					{{ t('procest', 'Send Invitations') }}
				</NcButton>
				<NcButton
					v-if="
						activeHearing.status === 'gepland'
						|| activeHearing.status === 'uitgenodigd'
					"
					type="primary"
					@click="showMinutesDialog = true">
					{{ t('procest', 'Record Minutes') }}
				</NcButton>
				<NcButton
					v-if="activeHearing.status !== 'uitgevoerd'"
					type="error"
					@click="cancelHearing">
					{{ t('procest', 'Cancel Hearing') }}
				</NcButton>
			</div>

			<!-- Minutes (if hearing completed) -->
			<div v-if="activeHearing.minutesSummary" class="hearing-panel__minutes">
				<h5>{{ t('procest', 'Hearing Minutes') }}</h5>
				<p>{{ activeHearing.minutesSummary }}</p>
			</div>
		</template>

		<!-- Schedule Dialog -->
		<div
			v-if="showScheduleDialog"
			class="dialog-overlay"
			role="button"
			tabindex="0"
			@click.self="showScheduleDialog = false"
			@keydown.enter.self="showScheduleDialog = false"
			@keydown.space.self.prevent="showScheduleDialog = false">
			<div class="dialog-card">
				<h3>{{ t('procest', 'Schedule Hearing') }}</h3>
				<div class="form-group">
					<label for="hearing-panel-scheduled-date"
						>{{ t('procest', 'Date and Time') }} *</label
					>
					<NcTextField
						id="hearing-panel-scheduled-date"
						:modelValue="scheduleForm.scheduledDate"
						type="datetime-local"
						@update:modelValue="
							(v) => (scheduleForm.scheduledDate = v)
						" />
				</div>
				<div class="form-group">
					<label for="hearing-panel-location">{{
						t('procest', 'Location')
					}}</label>
					<NcTextField
						id="hearing-panel-location"
						:modelValue="scheduleForm.location"
						:placeholder="t('procest', 'Location or Online')"
						@update:modelValue="(v) => (scheduleForm.location = v)" />
				</div>
				<div class="form-group">
					<label for="hearing-panel-video-call-url">{{
						t('procest', 'Video Call URL')
					}}</label>
					<NcTextField
						id="hearing-panel-video-call-url"
						:modelValue="scheduleForm.videoCallUrl"
						:placeholder="t('procest', 'https://...')"
						@update:modelValue="
							(v) => (scheduleForm.videoCallUrl = v)
						" />
				</div>
				<div class="dialog-card__actions">
					<NcButton @click="showScheduleDialog = false">
						{{ t('procest', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" @click="scheduleHearing">
						{{ t('procest', 'Schedule') }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Waiver Dialog -->
		<div
			v-if="showWaiverDialog"
			class="dialog-overlay"
			role="button"
			tabindex="0"
			@click.self="showWaiverDialog = false"
			@keydown.enter.self="showWaiverDialog = false"
			@keydown.space.self.prevent="showWaiverDialog = false">
			<div class="dialog-card">
				<h3>{{ t('procest', 'Record Hearing Waiver') }}</h3>
				<p>
					{{
						t(
							'procest',
							'The objector waives the right to be heard (Awb art. 7:3).',
						)
					}}
				</p>
				<div class="form-group">
					<label for="hearing-panel-waiver-reason">{{
						t('procest', 'Reason')
					}}</label>
					<textarea
						id="hearing-panel-waiver-reason"
						v-model="waiverForm.reason"
						:placeholder="
							t('procest', 'Reason for waiving the hearing right...')
						"
						rows="3" />
				</div>
				<div class="dialog-card__actions">
					<NcButton @click="showWaiverDialog = false">
						{{ t('procest', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" @click="recordWaiver">
						{{ t('procest', 'Record Waiver') }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Minutes Dialog -->
		<div
			v-if="showMinutesDialog"
			class="dialog-overlay"
			role="button"
			tabindex="0"
			@click.self="showMinutesDialog = false"
			@keydown.enter.self="showMinutesDialog = false"
			@keydown.space.self.prevent="showMinutesDialog = false">
			<div class="dialog-card">
				<h3>{{ t('procest', 'Record Hearing Minutes') }}</h3>
				<div class="form-group">
					<label for="hearing-panel-minutes-summary"
						>{{ t('procest', 'Minutes Summary (Verslag)') }} *</label
					>
					<textarea
						id="hearing-panel-minutes-summary"
						v-model="minutesForm.summary"
						:placeholder="t('procest', 'Summary of the hearing...')"
						rows="6" />
				</div>
				<div class="dialog-card__actions">
					<NcButton @click="showMinutesDialog = false">
						{{ t('procest', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" @click="recordMinutes">
						{{ t('procest', 'Save Minutes') }}
					</NcButton>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { useBezwaarStore } from '../../../../store/modules/bezwaar.js'

export default {
	name: 'HearingPanel',
	components: {
		NcButton,
		NcTextField,
		NcNoteCard,
	},

	props: {
		caseId: {
			type: String,
			required: true,
		},

		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['hearing-scheduled', 'hearing-completed', 'hearing-waived'],
	data() {
		return {
			showScheduleDialog: false,
			showWaiverDialog: false,
			showMinutesDialog: false,
			scheduleForm: {
				scheduledDate: '',
				location: '',
				videoCallUrl: '',
			},

			waiverForm: {
				reason: '',
			},

			minutesForm: {
				summary: '',
			},
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		activeHearing() {
			const bezwaarStore = useBezwaarStore()
			return bezwaarStore.activeHearing
		},

		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		isHearingWaived() {
			const bezwaarStore = useBezwaarStore()
			return bezwaarStore.isHearingWaived
		},

		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		waiverReason() {
			const bezwaarStore = useBezwaarStore()
			const waived = bezwaarStore.hearingSessions.find(
				(h) => h.hearingWaived === true,
			)
			return waived?.waiverReason || ''
		},
	},

	methods: {
		/**
		 * @param status
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		getHearingStatusLabel(status) {
			const labels = {
				gepland: t('procest', 'Scheduled'),
				uitgenodigd: t('procest', 'Invitations sent'),
				uitgevoerd: t('procest', 'Completed'),
				geannuleerd: t('procest', 'Cancelled'),
				afgezien: t('procest', 'Waived'),
			}
			return labels[status] || status
		},

		/**
		 * @param dateStr
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		formatDateTime(dateStr) {
			if (!dateStr) return '—'
			try {
				return new Date(dateStr).toLocaleString('nl-NL', {
					year: 'numeric',
					month: 'long',
					day: 'numeric',
					hour: '2-digit',
					minute: '2-digit',
				})
			} catch {
				return dateStr
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		async scheduleHearing() {
			const bezwaarStore = useBezwaarStore()
			await bezwaarStore.createHearingSession({
				case: this.caseId,
				scheduledDate: this.scheduleForm.scheduledDate,
				location: this.scheduleForm.location,
				videoCallUrl: this.scheduleForm.videoCallUrl,
				chairperson: '',
				invitees: '[]',
				status: 'gepland',
				hearingWaived: false,
			})

			this.showScheduleDialog = false
			this.$emit('hearing-scheduled')
		},

		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		async sendInvitations() {
			if (!this.activeHearing) return
			const bezwaarStore = useBezwaarStore()
			await bezwaarStore.updateHearingSession({
				...this.activeHearing,
				status: 'uitgenodigd',
			})
		},

		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		async recordMinutes() {
			if (!this.activeHearing || !this.minutesForm.summary) return
			const bezwaarStore = useBezwaarStore()
			await bezwaarStore.updateHearingSession({
				...this.activeHearing,
				status: 'uitgevoerd',
				minutesSummary: this.minutesForm.summary,
			})

			this.showMinutesDialog = false
			this.$emit('hearing-completed')
		},

		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		async recordWaiver() {
			const bezwaarStore = useBezwaarStore()
			await bezwaarStore.createHearingSession({
				case: this.caseId,
				scheduledDate: new Date().toISOString(),
				chairperson: '',
				invitees: '[]',
				status: 'afgezien',
				hearingWaived: true,
				waiverReason: this.waiverForm.reason,
			})

			this.showWaiverDialog = false
			this.$emit('hearing-waived')
		},

		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		async cancelHearing() {
			if (!this.activeHearing) return
			const bezwaarStore = useBezwaarStore()
			await bezwaarStore.updateHearingSession({
				...this.activeHearing,
				status: 'geannuleerd',
			})
		},
	},
}
</script>

<style scoped>
.hearing-panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.hearing-panel__details {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
}

.hearing-detail {
	display: flex;
	flex-direction: column;
}

.hearing-detail__label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.hearing-panel__actions {
	display: flex;
	gap: 8px;
}

.hearing-panel__minutes {
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.dialog-overlay {
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

.dialog-card {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	padding: 24px;
	width: 480px;
	max-width: 90vw;
}

.dialog-card__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 16px;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}

textarea {
	width: 100%;
	padding: 8px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	resize: vertical;
}
</style>
