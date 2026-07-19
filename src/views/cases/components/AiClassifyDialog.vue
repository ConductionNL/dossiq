<template>
	<NcDialog
		v-if="show"
		:name="t('procest', 'AI Document Classification')"
		size="normal"
		@close="$emit('close')">
		<div class="ai-classify-dialog">
			<NcLoadingIcon v-if="loading" :size="32" />

			<div v-else-if="error" class="ai-classify-dialog__error">
				<NcNoteCard type="error">
					{{ error }}
				</NcNoteCard>
			</div>

			<div v-else-if="result" class="ai-classify-dialog__result">
				<div class="ai-classify-dialog__header">
					<AiConfidenceBadge :confidence="result.confidence || 0" size="medium" />
				</div>

				<div class="form-group">
					<label>{{ t('procest', 'Suggested document type') }}</label>
					<NcTextField
						:model-value="modifiedType"
						@update:model-value="v => modifiedType = v" />
				</div>

				<div v-if="result.metadata" class="ai-classify-dialog__metadata">
					<h4>{{ t('procest', 'Extracted metadata') }}</h4>
					<div v-for="(value, key) in result.metadata" :key="key" class="form-group">
						<label>{{ key }}</label>
						<NcTextField
							:model-value="modifiedMetadata[key] || value"
							@update:model-value="v => modifiedMetadata[key] = v" />
					</div>
				</div>

				<div class="ai-classify-dialog__actions">
					<NcButton type="primary" @click="apply">
						{{ t('procest', 'Apply classification') }}
					</NcButton>
					<NcButton type="error" @click="reject">
						{{ t('procest', 'Reject') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcTextField, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { classifyDocument } from '../../../services/aiApi.js'
import AiConfidenceBadge from './AiConfidenceBadge.vue'

export default {
	name: 'AiClassifyDialog',
	components: { NcDialog, NcButton, NcTextField, NcLoadingIcon, NcNoteCard, AiConfidenceBadge },
	props: {
		caseId: { type: String, required: true },
		documentId: { type: String, required: true },
		show: { type: Boolean, default: false },
	},
	emits: ['close', 'applied'],
	data() {
		return {
			loading: false,
			error: null,
			result: null,
			modifiedType: '',
			modifiedMetadata: {},
		}
	},
	watch: {
		/**
		 * @param val
		 * @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md
		 */
		show(val) {
			if (val) this.classify()
		},
	},
	methods: {
		t,
		/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
		async classify() {
			this.loading = true
			this.error = null
			try {
				const response = await classifyDocument(this.caseId, this.documentId)
				this.result = response.result || response
				this.modifiedType = this.result.documentType || ''
				this.modifiedMetadata = { ...(this.result.metadata || {}) }
			} catch (e) {
				this.error = e.response?.data?.error || t('procest', 'Classification failed')
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
		apply() {
			this.$emit('applied', {
				documentType: this.modifiedType,
				metadata: this.modifiedMetadata,
				confidence: this.result.confidence,
			})
			this.$emit('close')
		},
		/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
		reject() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.ai-classify-dialog__actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}

.ai-classify-dialog__metadata {
	margin-top: 16px;
}

.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}
</style>
