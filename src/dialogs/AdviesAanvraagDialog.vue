<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<NcDialog
		:name="t(appName, 'Advies aanvragen')"
		size="normal"
		@closing="$emit('close')">
		<div class="advies-dialog">
			<div class="advies-dialog__row">
				<label>{{ t(appName, 'Type advies') }}</label>
				<div class="advies-dialog__type-toggle">
					<NcButton
						:type="form.type === 'intern' ? 'primary' : 'secondary'"
						@click="form.type = 'intern'">
						{{ t(appName, 'Intern') }}
					</NcButton>
					<NcButton
						:type="form.type === 'extern' ? 'primary' : 'secondary'"
						@click="form.type = 'extern'">
						{{ t(appName, 'Extern') }}
					</NcButton>
				</div>
			</div>

			<NcTextField
				v-model="form.advisor"
				:label="
					form.type === 'intern'
						? t(appName, 'Adviseur (gebruiker)')
						: t(appName, 'Adviseur (organisatie)')
				"
				:placeholder="
					form.type === 'intern' ? 'username' : 'Naam organisatie'
				" />

			<NcTextField v-model="form.subject" :label="t(appName, 'Onderwerp')" />

			<NcTextField
				v-model="form.deadline"
				type="date"
				:label="t(appName, 'Deadline')" />

			<label class="advies-dialog__textarea-label">
				{{ t(appName, 'Specifieke vragen') }}
				<textarea
					v-model="form.questions"
					class="advies-dialog__textarea"
					rows="4"
					:placeholder="
						t(
							appName,
							'Optioneel — beschrijf welke vragen je beantwoord wilt zien.',
						)
					" />
			</label>

			<p v-if="errorMessage" class="advies-dialog__error">
				{{ errorMessage }}
			</p>
		</div>

		<template #actions>
			<NcButton type="tertiary" @click="$emit('close')">
				{{ t(appName, 'Annuleren') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!canSubmit || submitting"
				@click="submit">
				{{ t(appName, 'Aanvragen') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'
import { createAdviceWithNotification } from '../services/adviceApi.js'

const APP_NAME = 'procest'

/**
 * Build a default deadline date 14 days from today (ISO YYYY-MM-DD).
 *
 * @return {string} ISO date string
 */
function defaultDeadline() {
	const date = new Date()
	date.setDate(date.getDate() + 14)
	return date.toISOString().substring(0, 10)
}

export default {
	name: 'AdviesAanvraagDialog',
	components: {
		NcButton,
		NcDialog,
		NcTextField,
	},

	props: {
		caseId: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'created'],
	data() {
		return {
			appName: APP_NAME,
			submitting: false,
			errorMessage: '',
			form: {
				type: 'intern',
				advisor: '',
				subject: '',
				deadline: defaultDeadline(),
				questions: '',
			},
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md */
		canSubmit() {
			return this.form.advisor.trim() !== '' && this.form.deadline !== ''
		},
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md */
		async submit() {
			if (!this.canSubmit) {
				return
			}
			this.submitting = true
			this.errorMessage = ''
			try {
				const payload = {
					case: this.caseId,
					type: this.form.type,
					advisor: this.form.advisor.trim(),
					subject: this.form.subject.trim(),
					deadline: this.form.deadline,
					questions: this.form.questions.trim(),
				}
				await createAdviceWithNotification(payload)
				this.$emit('created')
			} catch (error) {
				console.error('Procest: failed to create advice', error)
				this.errorMessage = this.t(
					this.appName,
					'Aanmaken van advies is mislukt. Probeer het opnieuw.',
				)
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.advies-dialog {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	padding: 0.5rem 0;
}

.advies-dialog__row {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.advies-dialog__type-toggle {
	display: flex;
	gap: 0.5rem;
}

.advies-dialog__textarea-label {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	font-weight: 500;
}

.advies-dialog__textarea {
	width: 100%;
	border: 1px solid var(--color-border, #d0d0d0);
	border-radius: var(--border-radius, 4px);
	padding: 0.5rem;
	font-family: inherit;
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #000);
}

.advies-dialog__error {
	color: var(--color-error, #d94343);
	margin: 0;
}
</style>
