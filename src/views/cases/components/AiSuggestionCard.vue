<template>
	<CnDetailCard class="ai-suggestion-card" :title="suggestion.type || t('procest', 'AI Suggestion')">
		<template #actions>
			<AiConfidenceBadge v-if="suggestion.confidence" :confidence="suggestion.confidence" />
			<NcButton v-if="!readonly" type="success" :disabled="loading" @click="$emit('accept', suggestion)">
				{{ t('procest', 'Accept') }}
			</NcButton>
			<NcButton v-if="!readonly" type="error" :disabled="loading" @click="showRejectInput = true">
				{{ t('procest', 'Reject') }}
			</NcButton>
			<NcButton v-if="!readonly" :disabled="loading" @click="$emit('modify', suggestion, suggestion.value)">
				{{ t('procest', 'Modify') }}
			</NcButton>
		</template>

		<div class="ai-suggestion-card__content">
			<slot>
				<p v-if="suggestion.explanation" class="ai-suggestion-card__explanation">
					{{ suggestion.explanation }}
				</p>
				<div v-if="suggestion.value" class="ai-suggestion-card__value">
					<strong>{{ t('procest', 'Suggestion') }}:</strong> {{ formatValue(suggestion.value) }}
				</div>
			</slot>
		</div>

		<div v-if="showRejectInput" class="ai-suggestion-card__reject">
			<NcTextField
				:value="rejectReason"
				:label="t('procest', 'Reason for rejection')"
				@update:value="v => rejectReason = v" />
			<NcButton type="error" @click="handleReject">
				{{ t('procest', 'Confirm rejection') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="20" />
	</CnDetailCard>
</template>

<script>
import { NcButton, NcTextField, NcLoadingIcon } from '@nextcloud/vue'
import { CnDetailCard } from '@conduction/nextcloud-vue'
import { t } from '@nextcloud/l10n'
import AiConfidenceBadge from './AiConfidenceBadge.vue'

export default {
	name: 'AiSuggestionCard',
	components: { NcButton, NcTextField, NcLoadingIcon, CnDetailCard, AiConfidenceBadge },
	props: {
		suggestion: { type: Object, required: true },
		loading: { type: Boolean, default: false },
		readonly: { type: Boolean, default: false },
	},
	emits: ['accept', 'reject', 'modify'],
	data() {
		return {
			showRejectInput: false,
			rejectReason: '',
		}
	},
	methods: {
		t,
		formatValue(value) {
			if (typeof value === 'object') return JSON.stringify(value, null, 2)
			return String(value)
		},
		handleReject() {
			this.$emit('reject', this.suggestion, this.rejectReason)
			this.showRejectInput = false
			this.rejectReason = ''
		},
	},
}
</script>

<style scoped>
.ai-suggestion-card__actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}

.ai-suggestion-card__reject {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	margin-top: 8px;
}

.ai-suggestion-card__explanation {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	margin-bottom: 8px;
}
</style>
