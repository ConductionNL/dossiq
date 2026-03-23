<template>
	<NcDialog
		v-if="show"
		:name="t('procest', 'Book Appointment')"
		size="normal"
		@close="$emit('close')">
		<div class="booking-dialog">
			<div class="form-group">
				<label>{{ t('procest', 'Product') }}</label>
				<NcTextField :value="form.productId" :label="t('procest', 'Product ID')"
					@update:value="v => form.productId = v" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Location') }}</label>
				<NcTextField :value="form.locationId" :label="t('procest', 'Location ID')"
					@update:value="v => form.locationId = v" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Date') }}</label>
				<NcTextField :value="form.date" type="date"
					@update:value="v => { form.date = v; loadSlots() }" />
			</div>

			<div v-if="timeslots.length > 0" class="booking-dialog__slots">
				<label>{{ t('procest', 'Available timeslots') }}</label>
				<div class="booking-dialog__slot-grid">
					<button
						v-for="slot in timeslots"
						:key="slot.time"
						class="booking-dialog__slot"
						:class="{ 'booking-dialog__slot--selected': form.time === slot.time }"
						:disabled="!slot.available"
						@click="form.time = slot.time">
						{{ slot.time }}
					</button>
				</div>
			</div>

			<div class="form-group">
				<NcTextField :value="form.citizenName" :label="t('procest', 'Citizen name')"
					@update:value="v => form.citizenName = v" />
			</div>

			<div class="form-group">
				<NcTextField :value="form.citizenEmail" :label="t('procest', 'Citizen email')"
					@update:value="v => form.citizenEmail = v" />
			</div>

			<NcButton type="primary" :disabled="!canBook" @click="book">
				{{ t('procest', 'Book') }}
			</NcButton>
		</div>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcTextField } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import { bookAppointment, getTimeslots } from '../../../services/appointmentApi.js'

export default {
	name: 'AppointmentBookingDialog',
	components: { NcDialog, NcButton, NcTextField },
	props: {
		caseId: { type: String, required: true },
		show: { type: Boolean, default: false },
	},
	emits: ['close', 'booked'],
	data() {
		return {
			form: { productId: '', locationId: '', date: '', time: '', citizenName: '', citizenEmail: '' },
			timeslots: [],
		}
	},
	computed: {
		canBook() {
			return this.form.productId && this.form.locationId && this.form.date && this.form.time
		},
	},
	methods: {
		t,
		async loadSlots() {
			if (!this.form.productId || !this.form.locationId || !this.form.date) return
			try {
				const response = await getTimeslots(this.form.productId, this.form.locationId, this.form.date)
				this.timeslots = response.timeslots || []
			} catch (e) {
				this.timeslots = []
			}
		},
		async book() {
			try {
				await bookAppointment({
					caseId: this.caseId,
					productId: this.form.productId,
					locationId: this.form.locationId,
					dateTime: `${this.form.date}T${this.form.time}:00`,
					citizenName: this.form.citizenName,
					citizenEmail: this.form.citizenEmail,
				})
				this.$emit('booked')
			} catch (e) {
				// Handle error
			}
		},
	},
}
</script>

<style scoped>
.booking-dialog__slot-grid {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 4px;
	margin: 8px 0;
}

.booking-dialog__slot {
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	cursor: pointer;
	text-align: center;
}

.booking-dialog__slot--selected {
	background: var(--color-primary-element);
	color: white;
	border-color: var(--color-primary-element);
}

.booking-dialog__slot:disabled {
	opacity: 0.4;
	cursor: not-allowed;
}

.form-group { margin-bottom: 12px; }
.form-group label { display: block; font-weight: 600; margin-bottom: 4px; }
</style>
