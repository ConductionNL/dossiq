<template>
	<NcDialog
		v-if="show"
		:name="t('procest', 'AI Data Extraction')"
		size="large"
		@close="$emit('close')">
		<div class="ai-extract-dialog">
			<NcLoadingIcon v-if="loading" :size="32" />

			<div v-else-if="error" class="ai-extract-dialog__error">
				<NcNoteCard type="error">{{ error }}</NcNoteCard>
			</div>

			<div v-else-if="fields.length > 0" class="ai-extract-dialog__result">
				<table class="ai-extract-dialog__table">
					<thead>
						<tr>
							<th>
								<NcCheckboxRadioSwitch
									:checked="allSelected"
									@update:checked="toggleAll" />
							</th>
							<th>{{ t('procest', 'Field') }}</th>
							<th>{{ t('procest', 'Extracted value') }}</th>
							<th>{{ t('procest', 'Confidence') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="field in fields"
							:key="field.name"
							:class="{ 'low-confidence': field.confidence < 0.60 }">
							<td>
								<NcCheckboxRadioSwitch
									:checked="selectedFields.includes(field.name)"
									@update:checked="toggleField(field.name)" />
							</td>
							<td>{{ field.name }}</td>
							<td>
								<NcTextField
									:value="modifiedValues[field.name] || field.value"
									@update:value="v => modifiedValues[field.name] = v" />
							</td>
							<td>
								<AiConfidenceBadge :confidence="field.confidence" />
							</td>
						</tr>
					</tbody>
				</table>

				<div class="ai-extract-dialog__actions">
					<NcButton type="primary" :disabled="selectedFields.length === 0" @click="applySelected">
						{{ t('procest', 'Apply selected ({count})', { count: selectedFields.length }) }}
					</NcButton>
					<NcButton @click="$emit('close')">
						{{ t('procest', 'Cancel') }}
					</NcButton>
				</div>
			</div>

			<div v-else>
				<NcNoteCard type="info">{{ t('procest', 'No data could be extracted from this document.') }}</NcNoteCard>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcTextField, NcLoadingIcon, NcNoteCard, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import { extractData } from '../../../services/aiApi.js'
import AiConfidenceBadge from './AiConfidenceBadge.vue'

export default {
	name: 'AiExtractDialog',
	components: { NcDialog, NcButton, NcTextField, NcLoadingIcon, NcNoteCard, NcCheckboxRadioSwitch, AiConfidenceBadge },
	props: {
		caseId: { type: String, required: true },
		documentId: { type: String, default: null },
		show: { type: Boolean, default: false },
	},
	emits: ['close', 'applied'],
	data() {
		return {
			loading: false,
			error: null,
			fields: [],
			selectedFields: [],
			modifiedValues: {},
		}
	},
	computed: {
		allSelected() {
			return this.fields.length > 0 && this.selectedFields.length === this.fields.length
		},
	},
	watch: {
		show(val) {
			if (val) this.extract()
		},
	},
	methods: {
		t,
		async extract() {
			this.loading = true
			this.error = null
			try {
				const response = await extractData(this.caseId, this.documentId)
				this.fields = response.fields || []
				this.selectedFields = this.fields
					.filter((f) => f.confidence >= 0.60)
					.map((f) => f.name)
				this.modifiedValues = {}
			} catch (e) {
				this.error = e.response?.data?.error || t('procest', 'Extraction failed')
			} finally {
				this.loading = false
			}
		},
		toggleAll(checked) {
			this.selectedFields = checked ? this.fields.map((f) => f.name) : []
		},
		toggleField(name) {
			const idx = this.selectedFields.indexOf(name)
			if (idx >= 0) {
				this.selectedFields.splice(idx, 1)
			} else {
				this.selectedFields.push(name)
			}
		},
		applySelected() {
			const applied = {}
			for (const name of this.selectedFields) {
				applied[name] = this.modifiedValues[name]
					|| this.fields.find((f) => f.name === name)?.value
			}
			this.$emit('applied', applied)
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.ai-extract-dialog__table {
	width: 100%;
	border-collapse: collapse;
}

.ai-extract-dialog__table th,
.ai-extract-dialog__table td {
	padding: 8px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.low-confidence {
	border-left: 3px solid var(--color-warning);
}

.ai-extract-dialog__actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}
</style>
