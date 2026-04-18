<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<template>
	<NcDialog
		class="advies-dialog"
		:name="t('procest', 'Request Advice')"
		@update:open="$emit('close')">
		<div class="advies-dialog__body">
			<div class="advies-dialog__field">
				<label class="advies-dialog__label">
					{{ t('procest', 'Type') }}
				</label>
				<div class="advies-dialog__toggle">
					<button
						:class="{ 'is-active': form.type === 'intern' }"
						@click="form.type = 'intern'">
						{{ t('procest', 'Internal') }}
					</button>
					<button
						:class="{ 'is-active': form.type === 'extern' }"
						@click="form.type = 'extern'">
						{{ t('procest', 'External') }}
					</button>
				</div>
			</div>

			<div class="advies-dialog__field">
				<label class="advies-dialog__label">
					{{ t('procest', 'Advisor') }} *
				</label>
				<input
					v-model="form.adviseur"
					type="text"
					class="advies-dialog__input"
					:placeholder="form.type === 'intern' ? t('procest', 'Select internal user') : t('procest', 'Enter organization name')"
					required>
			</div>

			<div class="advies-dialog__field">
				<label class="advies-dialog__label">
					{{ t('procest', 'Subject') }} *
				</label>
				<input
					v-model="form.onderwerp"
					type="text"
					class="advies-dialog__input"
					:placeholder="t('procest', 'Topic of the advice request')"
					required>
			</div>

			<div class="advies-dialog__field">
				<label class="advies-dialog__label">
					{{ t('procest', 'Deadline') }} *
				</label>
				<input
					v-model="form.deadline"
					type="date"
					class="advies-dialog__input"
					required>
			</div>

			<div class="advies-dialog__field">
				<label class="advies-dialog__label">
					{{ t('procest', 'Questions') }}
				</label>
				<textarea
					v-model="form.questions"
					class="advies-dialog__textarea"
					:placeholder="t('procest', 'Specific questions for the advisor')"
					rows="4"></textarea>
			</div>

			<div class="advies-dialog__actions">
				<button
					:disabled="!isFormValid || loading"
					@click="submit">
					<span v-if="loading" class="icon-loading"></span>
					{{ t('procest', 'Create Request') }}
				</button>
				<button @click="$emit('close')">
					{{ t('procest', 'Cancel') }}
				</button>
			</div>

			<p v-if="error" class="advies-dialog__error">
				{{ error }}
			</p>
		</div>
	</NcDialog>
</template>

<script>
import { NcDialog } from '@conduction/nextcloud-vue'
import { createAdvice } from '../../../services/adviceApi'
import { useI18n } from 'vue-i18n'

export default {
	name: 'AdviesAanvraagDialog',
	components: {
		NcDialog,
	},
	props: {
		caseId: {
			type: String,
			required: true,
		},
	},
	emits: ['close', 'created'],
	setup() {
		const { t } = useI18n()
		return { t }
	},
	data() {
		return {
			form: {
				type: 'intern',
				adviseur: '',
				onderwerp: '',
				deadline: '',
				questions: '',
			},
			loading: false,
			error: '',
		}
	},
	computed: {
		isFormValid() {
			return this.form.adviseur && this.form.onderwerp && this.form.deadline
		},
	},
	mounted() {
		// Set default deadline to 14 days from today
		const deadline = new Date()
		deadline.setDate(deadline.getDate() + 14)
		this.form.deadline = deadline.toISOString().split('T')[0]
	},
	methods: {
		async submit() {
			if (!this.isFormValid) {
				this.error = this.t('procest', 'Please fill in all required fields')
				return
			}

			this.loading = true
			this.error = ''

			try {
				const response = await createAdvice({
					case: this.caseId,
					...this.form,
				})

				if (response.error) {
					this.error = response.error
					return
				}

				this.$emit('created', response.data)
				this.$emit('close')
			} catch (error) {
				this.error = error.message || this.t('procest', 'Failed to create advice request')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.advies-dialog {
	&__body {
		padding: 20px;
	}

	&__field {
		margin-bottom: 20px;
	}

	&__label {
		display: block;
		font-weight: 600;
		margin-bottom: 8px;
		color: var(--color-text-primary);
	}

	&__input,
	&__textarea {
		width: 100%;
		padding: 8px 12px;
		border: 1px solid var(--color-border);
		border-radius: 4px;
		font-size: 14px;

		&:focus {
			outline: none;
			border-color: var(--color-primary);
			box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.1);
		}
	}

	&__textarea {
		font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
		resize: vertical;
	}

	&__toggle {
		display: flex;
		gap: 10px;

		button {
			flex: 1;
			padding: 10px 16px;
			border: 1px solid var(--color-border);
			border-radius: 4px;
			background: var(--color-background-secondary);
			cursor: pointer;
			transition: all 0.2s;

			&:hover {
				background: var(--color-background-hover);
			}

			&.is-active {
				background: var(--color-primary);
				color: white;
				border-color: var(--color-primary);
			}
		}
	}

	&__actions {
		display: flex;
		gap: 10px;
		margin-top: 20px;

		button {
			flex: 1;
			padding: 10px 16px;
			border: 1px solid var(--color-border);
			border-radius: 4px;
			background: var(--color-background-secondary);
			cursor: pointer;
			font-size: 14px;
			font-weight: 500;
			transition: all 0.2s;

			&:hover:not(:disabled) {
				background: var(--color-primary);
				color: white;
				border-color: var(--color-primary);
			}

			&:disabled {
				opacity: 0.5;
				cursor: not-allowed;
			}

			&:first-child:hover:not(:disabled) {
				background: var(--color-primary);
				color: white;
			}
		}
	}

	&__error {
		color: var(--color-error);
		margin-top: 10px;
		padding: 10px;
		background: rgba(255, 0, 0, 0.1);
		border-radius: 4px;
		font-size: 14px;
	}
}

.icon-loading {
	display: inline-block;
	width: 14px;
	height: 14px;
	border: 2px solid rgba(255, 255, 255, 0.3);
	border-radius: 50%;
	border-top-color: white;
	animation: spin 0.6s linear infinite;
	margin-right: 8px;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}
</style>
