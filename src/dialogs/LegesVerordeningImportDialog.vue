<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcDialog v-if="open"
		:name="t('procest', 'Legesverordening importeren')"
		size="normal"
		:can-close="!submitting"
		@closing="onClose">
		<div class="leges-import">
			<NcTextField
				:value="naam"
				:label="t('procest', 'Naam verordening')"
				:placeholder="t('procest', 'Legesverordening 2026')"
				required
				@update:value="v => naam = v" />

			<label class="leges-import__date-label">
				{{ t('procest', 'Geldig vanaf') }}
			</label>
			<NcDateTimePicker
				v-model="geldigVanaf"
				type="date" />

			<NcTextField
				:value="besluitId"
				:label="t('procest', 'Raadsbesluit-referentie (decidesk)')"
				:placeholder="t('procest', 'Raadsbesluit 2025-RB-0481')"
				@update:value="v => besluitId = v" />

			<label class="leges-import__csv-label">
				{{ t('procest', 'Tarieventabel (CSV)') }}
			</label>
			<NcTextArea
				:value="csv"
				:placeholder="csvPlaceholder"
				rows="6"
				@update:value="v => csv = v" />
			<p class="leges-import__hint">
				{{ t('procest', 'Kolommen: tariefNummer, omschrijving, bedrag (eurocenten), grondslag, eenheid, btwTarief, grootboekrekening') }}
			</p>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="result" type="success">
				{{ t('procest', 'Verordening geïmporteerd als concept: {n} tarieven ({errors} fouten)', { n: result.tarieven, errors: result.errors ? result.errors.length : 0 }) }}
			</NcNoteCard>
		</div>
		<template #actions>
			<NcButton :disabled="submitting" @click="onClose">
				{{ t('procest', 'Sluiten') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="!canSubmit"
				@click="onSubmit">
				{{ submitting ? t('procest', 'Bezig...') : t('procest', 'Importeren (concept)') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDateTimePicker, NcDialog, NcNoteCard, NcTextArea, NcTextField } from '@nextcloud/vue'
import { importVerordening } from '../services/legesApi.js'

export default {
	name: 'LegesVerordeningImportDialog',
	components: {
		NcButton,
		NcDateTimePicker,
		NcDialog,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			naam: '',
			geldigVanaf: null,
			besluitId: '',
			csv: '',
			submitting: false,
			error: '',
			result: null,
		}
	},
	computed: {
		/** @spec openspec/changes/leges-heffingen/specs.md#req-leges-001 */
		csvPlaceholder() {
			return 'tariefNummer,omschrijving,bedrag,grondslag,eenheid,btwTarief,grootboekrekening\n1.1.1,Paspoort,10000,vast,per_stuk,0,8004010'
		},
		/** @spec openspec/changes/leges-heffingen/specs.md#req-leges-001 */
		canSubmit() {
			return !this.submitting && this.naam.trim() && this.geldigVanaf && this.csv.trim()
		},
	},
	watch: {
		/**
		 * @param value
		 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-001
		 */
		open(value) {
			if (value) {
				this.error = ''
				this.result = null
			}
		},
	},
	methods: {
		/** @spec openspec/changes/leges-heffingen/specs.md#req-leges-001 */
		async onSubmit() {
			if (!this.canSubmit) return
			this.submitting = true
			this.error = ''
			this.result = null
			try {
				const metaData = {
					naam: this.naam.trim(),
					geldigVanaf: this.toIsoDate(this.geldigVanaf),
					vastgesteldDoor: this.besluitId.trim(),
				}
				this.result = await importVerordening({ metaData, csv: this.csv })
				this.$emit('imported', this.result)
			} catch (err) {
				this.error = err?.response?.data?.error || this.t('procest', 'Import mislukt')
				console.error('Procest leges import failed', err)
			} finally {
				this.submitting = false
			}
		},
		/**
		 * @param date
		 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-001
		 */
		toIsoDate(date) {
			if (!date) return ''
			const d = new Date(date)
			return d.toISOString().slice(0, 10)
		},
		/** @spec openspec/changes/leges-heffingen/specs.md#req-leges-001 */
		onClose() {
			if (this.submitting) return
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.leges-import {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px 0;
}

.leges-import__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.leges-import__csv-label,
.leges-import__date-label {
	font-weight: bold;
}
</style>
