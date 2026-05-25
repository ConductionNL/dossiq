<template>
	<CnDetailCard :title="t('procest', 'Appointments')">
		<template #actions>
			<NcButton v-if="!isReadOnly" type="primary" @click="showBooking = true">
				{{ t('procest', 'Plan appointment') }}
			</NcButton>
		</template>

		<div v-if="appointments.length === 0" class="appointment-section__empty">
			{{ t('procest', 'No appointments scheduled.') }}
		</div>

		<div v-for="apt in appointments" :key="apt.uuid || apt.id" class="appointment-section__item">
			<div class="appointment-section__info">
				<strong>{{ formatDateTime(apt.dateTime) }}</strong>
				<span class="appointment-section__status" :class="`status--${apt.status}`">
					{{ statusLabel(apt.status) }}
				</span>
			</div>
			<div class="appointment-section__actions">
				<NcButton v-if="apt.status === 'scheduled'" type="error" @click="cancel(apt)">
					{{ t('procest', 'Cancel') }}
				</NcButton>
				<NcButton v-if="apt.status === 'scheduled'" @click="noShow(apt)">
					{{ t('procest', 'No-show') }}
				</NcButton>
			</div>
		</div>

		<AppointmentBookingDialog
			v-if="showBooking"
			:case-id="caseId"
			:show="showBooking"
			@close="showBooking = false"
			@booked="onBooked" />
	</CnDetailCard>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnDetailCard } from '@conduction/nextcloud-vue'
import { t } from '@nextcloud/l10n'
import { listAppointments, cancelAppointment, markNoShow } from '../../../services/appointmentApi.js'
import AppointmentBookingDialog from './AppointmentBookingDialog.vue'

export default {
	name: 'AppointmentSection',
	components: { NcButton, CnDetailCard, AppointmentBookingDialog },
	props: {
		caseId: { type: String, required: true },
		isReadOnly: { type: Boolean, default: false },
	},
	data() {
		return { appointments: [], showBooking: false }
	},
	async mounted() {
		await this.loadAppointments()
	},
	methods: {
		t,
		/** @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md */
		async loadAppointments() {
			try {
				const response = await listAppointments(this.caseId)
				this.appointments = response.appointments || []
			} catch (e) {
				this.appointments = []
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md */
		formatDateTime(dt) {
			if (!dt) return '-'
			return new Date(dt).toLocaleString('nl-NL', { dateStyle: 'long', timeStyle: 'short' })
		},
		/** @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md */
		statusLabel(status) {
			const labels = {
				scheduled: t('procest', 'Scheduled'),
				confirmed: t('procest', 'Confirmed'),
				cancelled: t('procest', 'Cancelled'),
				completed: t('procest', 'Completed'),
				no_show: t('procest', 'No-show'),
			}
			return labels[status] || status
		},
		/** @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md */
		async cancel(apt) {
			await cancelAppointment(apt.uuid || apt.id)
			await this.loadAppointments()
		},
		/** @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md */
		async noShow(apt) {
			await markNoShow(apt.uuid || apt.id)
			await this.loadAppointments()
		},
		/** @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md */
		async onBooked() {
			this.showBooking = false
			await this.loadAppointments()
		},
	},
}
</script>

<style scoped>
.appointment-section__item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.appointment-section__status {
	margin-left: 8px;
	font-size: 12px;
	font-weight: 600;
}

.status--scheduled { color: var(--color-primary); }
.status--confirmed { color: var(--color-success); }
.status--cancelled { color: var(--color-text-maxcontrast); }
.status--completed { color: var(--color-success); }
.status--no_show { color: var(--color-error); }

.appointment-section__actions { display: flex; gap: 4px; }
.appointment-section__empty { color: var(--color-text-maxcontrast); font-style: italic; }
</style>
