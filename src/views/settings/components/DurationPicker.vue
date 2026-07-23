<template>
	<div class="duration-picker">
		<div class="duration-picker__input-row">
			<NcTextField
				:model-value="daysInput"
				:label="t('procest', 'Days')"
				type="number"
				class="duration-picker__field"
				@update:model-value="onDaysChange" />
			<span class="duration-picker__iso">
				{{ displayValue || t('procest', 'Enter days') }}
			</span>
		</div>

		<div class="duration-picker__presets">
			<button
				v-for="preset in presets"
				:key="preset.value"
				class="duration-picker__preset"
				:class="{ 'duration-picker__preset--active': value === preset.value }"
				type="button"
				@click="selectPreset(preset)">
				{{ preset.label }}
			</button>
		</div>
	</div>
</template>

<script>
import { NcTextField } from '@nextcloud/vue'
import { parseDuration, isValidDuration } from '../../../utils/durationHelpers.js'

export default {
	name: 'DurationPicker',
	components: {
		NcTextField,
	},
	props: {
		value: {
			type: String,
			default: '',
		},
		presetType: {
			type: String,
			default: 'deadline',
			validator: v => ['deadline', 'extension'].includes(v),
		},
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-milestone-tracking/tasks.md */
		daysInput() {
			if (!this.value) return ''
			const parsed = parseDuration(this.value)
			if (!parsed) return ''
			let totalDays = 0
			if (parsed.years) totalDays += parsed.years * 365
			if (parsed.months) totalDays += parsed.months * 30
			if (parsed.weeks) totalDays += parsed.weeks * 7
			if (parsed.days) totalDays += parsed.days
			return String(totalDays)
		},
		/** @spec openspec/changes/retrofit-2026-05-24-milestone-tracking/tasks.md */
		displayValue() {
			if (!this.value || !isValidDuration(this.value)) return ''
			const days = parseInt(this.daysInput, 10)
			if (!days) return this.value
			const weeks = Math.floor(days / 7)
			const remainder = days % 7
			if (remainder === 0 && weeks > 0) {
				return `${this.value} (${weeks} ${t('procest', 'weeks')})`
			}
			return `${this.value} (${days} ${t('procest', 'days')})`
		},
		/** @spec openspec/changes/retrofit-2026-05-24-milestone-tracking/tasks.md */
		presets() {
			if (this.presetType === 'extension') {
				return [
					{ label: t('procest', '2 weeks'), value: 'P14D' },
					{ label: t('procest', '4 weeks'), value: 'P28D' },
					{ label: t('procest', '6 weeks'), value: 'P42D' },
				]
			}
			return [
				{ label: t('procest', '6 weeks'), value: 'P42D' },
				{ label: t('procest', '8 weeks'), value: 'P56D' },
				{ label: t('procest', '13 weeks'), value: 'P91D' },
				{ label: t('procest', '26 weeks'), value: 'P182D' },
			]
		},
	},
	methods: {
		/**
		 * @param val
		 * @spec openspec/changes/retrofit-2026-05-24-milestone-tracking/tasks.md
		 */
		onDaysChange(val) {
			const days = parseInt(val, 10)
			if (!days || days < 0) {
				this.$emit('input', '')
				return
			}
			this.$emit('input', `P${days}D`)
		},
		/**
		 * @param preset
		 * @spec openspec/changes/retrofit-2026-05-24-milestone-tracking/tasks.md
		 */
		selectPreset(preset) {
			this.$emit('input', preset.value)
		},
	},
}
</script>

<style scoped>
.duration-picker__input-row {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 8px;
}

.duration-picker__field {
	max-width: 120px;
}

.duration-picker__iso {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	font-family: monospace;
}

.duration-picker__presets {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}

.duration-picker__preset {
	padding: 4px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill);
	background: var(--color-main-background);
	cursor: pointer;
	font-size: 12px;
	transition: all 0.15s ease;
}

.duration-picker__preset:hover {
	background: var(--color-background-hover);
}

.duration-picker__preset--active {
	background: var(--color-primary-light);
	border-color: var(--color-primary);
	color: var(--color-primary);
	font-weight: 500;
}
</style>
