<template>
	<div class="lhs-matrix-admin">
		<h3>{{ t('procest', 'Enforcement Strategy (LHS Matrix)') }}</h3>
		<p class="lhs-matrix-admin__description">
			{{
				t(
					'procest',
					'Configure the Landelijke Handhavingsstrategie matrix. Each cell defines the intervention for a combination of severity (ernst) and behavior (gedrag).',
				)
			}}
		</p>

		<NcLoadingIcon v-if="loading" :size="32" />

		<div v-if="!loading" class="lhs-matrix-admin__grid">
			<table class="lhs-matrix-admin__table">
				<thead>
					<tr>
						<th />
						<th
							v-for="gedrag in gedragLabels"
							:key="gedrag.key"
							scope="col">
							{{ gedrag.label }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="ernst in ernstLabels" :key="ernst.key">
						<th scope="row">{{ ernst.label }}</th>
						<td v-for="gedrag in gedragLabels" :key="gedrag.key">
							<select
								:value="matrix[ernst.key]?.[gedrag.key] || ''"
								class="lhs-matrix-admin__select"
								@change="
									updateCell(
										ernst.key,
										gedrag.key,
										$event.target.value,
									)
								">
								<option
									v-for="option in interventionOptions"
									:key="option"
									:value="option">
									{{ option }}
								</option>
							</select>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div v-if="!loading" class="lhs-matrix-admin__actions">
			<NcButton
				type="primary"
				:disabled="saving || !dirty"
				@click="saveMatrix">
				{{
					saving ? t('procest', 'Saving...') : t('procest', 'Save matrix')
				}}
			</NcButton>
			<NcButton v-if="dirty" @click="resetMatrix">
				{{ t('procest', 'Reset to default') }}
			</NcButton>
		</div>

		<p v-if="saved" class="lhs-matrix-admin__saved">
			{{ t('procest', 'Matrix saved successfully.') }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { useEnforcementStore } from '../../../store/modules/enforcement.js'

export default {
	name: 'LhsMatrixAdmin',

	components: {
		NcButton,
		NcLoadingIcon,
	},

	data() {
		return {
			matrix: {},
			dirty: false,
			saving: false,
			saved: false,
			loading: true,
		}
	},

	computed: {
		/** @spec openspec/specs/vth-module/spec.md */
		enforcementStore() {
			return useEnforcementStore()
		},

		/** @spec openspec/specs/vth-module/spec.md */
		ernstLabels() {
			return [
				{ key: 'gering', label: t('procest', 'Minor (gering)') },
				{
					key: 'aanzienlijk',
					label: t('procest', 'Significant (substantial)'),
				},
				{ key: 'ernstig', label: t('procest', 'Serious (ernstig)') },
			]
		},

		/** @spec openspec/specs/vth-module/spec.md */
		gedragLabels() {
			return [
				{ key: 'goedwillend', label: t('procest', 'Cooperative') },
				{ key: 'onverschillig', label: t('procest', 'Indifferent') },
				{ key: 'calculerend', label: t('procest', 'Calculating') },
				{ key: 'crimineel', label: t('procest', 'Criminal') },
			]
		},

		/** @spec openspec/specs/vth-module/spec.md */
		interventionOptions() {
			return [
				'Waarschuwing',
				'Waarschuwing + herstel',
				'Herstelactie',
				'Last onder dwangsom',
				'Last + PV',
				'PV + Last',
				'PV + Bestuursdwang',
				'Bestuursdwang',
			]
		},
	},

	/** @spec openspec/specs/vth-module/spec.md */
	async mounted() {
		await this.enforcementStore.loadLhsMatrix()
		this.matrix = JSON.parse(JSON.stringify(this.enforcementStore.lhsMatrix))
		this.loading = false
	},

	methods: {
		t,

		/**
		 * @param ernst
		 * @param gedrag
		 * @param value
		 * @spec openspec/specs/vth-module/spec.md
		 */
		updateCell(ernst, gedrag, value) {
			if (!this.matrix[ernst]) {
				this.matrix[ernst] = {}
			}
			this.matrix[ernst][gedrag] = value
			this.dirty = true
			this.saved = false
		},

		/** @spec openspec/specs/vth-module/spec.md */
		async saveMatrix() {
			this.saving = true
			try {
				await this.enforcementStore.saveLhsMatrix(this.matrix)
				this.dirty = false
				this.saved = true
			} finally {
				this.saving = false
			}
		},

		/** @spec openspec/specs/vth-module/spec.md */
		resetMatrix() {
			this.matrix = {
				gering: {
					goedwillend: 'Waarschuwing',
					onverschillig: 'Waarschuwing + herstel',
					calculerend: 'Last onder dwangsom',
					crimineel: 'PV + Last',
				},
				aanzienlijk: {
					goedwillend: 'Herstelactie',
					onverschillig: 'Last onder dwangsom',
					calculerend: 'Last + PV',
					crimineel: 'PV + Bestuursdwang',
				},
				ernstig: {
					goedwillend: 'Last onder dwangsom',
					onverschillig: 'Last + PV',
					calculerend: 'PV + Bestuursdwang',
					crimineel: 'PV + Bestuursdwang',
				},
			}
			this.dirty = true
			this.saved = false
		},
	},
}
</script>

<style scoped>
.lhs-matrix-admin {
	padding: 16px 0;
}

.lhs-matrix-admin__description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.lhs-matrix-admin__table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 16px;
}

.lhs-matrix-admin__table th,
.lhs-matrix-admin__table td {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	text-align: center;
}

.lhs-matrix-admin__table th {
	background: var(--color-background-dark);
	font-weight: bold;
	font-size: 13px;
}

.lhs-matrix-admin__select {
	width: 100%;
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 12px;
}

.lhs-matrix-admin__actions {
	display: flex;
	gap: 8px;
}

.lhs-matrix-admin__saved {
	color: var(--color-success);
	margin-top: 8px;
}
</style>
