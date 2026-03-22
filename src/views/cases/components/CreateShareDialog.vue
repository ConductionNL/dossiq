<template>
	<NcDialog
		:open="open"
		:name="t('procest', 'Share case')"
		size="normal"
		@update:open="$emit('update:open', $event)">
		<div class="create-share-dialog">
			<!-- Share type tabs -->
			<div class="create-share-dialog__tabs">
				<button
					:class="{ active: shareType === 'token' }"
					@click="shareType = 'token'">
					{{ t('procest', 'Share link') }}
				</button>
				<button
					:class="{ active: shareType === 'partner' }"
					@click="shareType = 'partner'">
					{{ t('procest', 'Partner organization') }}
				</button>
			</div>

			<!-- Token share form -->
			<div v-if="shareType === 'token'" class="create-share-dialog__form">
				<div class="form-group">
					<label>{{ t('procest', 'Label') }}</label>
					<NcTextField
						:value="form.label"
						:placeholder="t('procest', 'e.g., For external review')"
						@update:value="v => form.label = v" />
				</div>

				<div class="form-group">
					<label>{{ t('procest', 'Permission level') }}</label>
					<NcSelect
						v-model="form.permissionLevel"
						:options="permissionOptions"
						label="label"
						track-by="value" />
				</div>

				<div class="form-group">
					<label>{{ t('procest', 'Expiration date') }}</label>
					<NcDateTimePicker
						v-model="form.expiresAt"
						:placeholder="t('procest', 'No expiration')"
						type="datetime" />
				</div>

				<div class="form-group">
					<label>{{ t('procest', 'Password protection') }}</label>
					<NcTextField
						:value="form.password"
						type="password"
						:placeholder="t('procest', 'Optional password')"
						@update:value="v => form.password = v" />
				</div>
			</div>

			<!-- Partner share form -->
			<div v-if="shareType === 'partner'" class="create-share-dialog__form">
				<div class="form-group">
					<label>{{ t('procest', 'Partner organization') }}</label>
					<NcSelect
						v-model="form.partnerId"
						:options="partners"
						label="name"
						track-by="id"
						:placeholder="t('procest', 'Select partner...')" />
				</div>

				<div class="form-group">
					<label>{{ t('procest', 'Permission level') }}</label>
					<NcSelect
						v-model="form.permissionLevel"
						:options="permissionOptions"
						label="label"
						track-by="value" />
				</div>
			</div>

			<!-- Generated link display -->
			<div v-if="generatedLink" class="create-share-dialog__link">
				<label>{{ t('procest', 'Share link') }}</label>
				<div class="create-share-dialog__link-row">
					<NcTextField :value="generatedLink" readonly />
					<NcButton @click="copyLink">
						{{ t('procest', 'Copy') }}
					</NcButton>
				</div>
			</div>

			<template #actions>
				<NcButton @click="$emit('update:open', false)">
					{{ t('procest', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving"
					@click="createShare">
					{{ saving ? t('procest', 'Creating...') : t('procest', 'Create share') }}
				</NcButton>
			</template>
		</div>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcTextField, NcSelect, NcDateTimePicker } from '@nextcloud/vue'

export default {
	name: 'CreateShareDialog',
	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcSelect,
		NcDateTimePicker,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
		caseId: {
			type: String,
			required: true,
		},
		partners: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['update:open', 'created'],
	data() {
		return {
			shareType: 'token',
			saving: false,
			generatedLink: '',
			form: {
				label: '',
				permissionLevel: { value: 'bekijken', label: t('procest', 'View only') },
				expiresAt: null,
				password: '',
				partnerId: null,
			},
			permissionOptions: [
				{ value: 'bekijken', label: t('procest', 'View only') },
				{ value: 'bekijken_reageren', label: t('procest', 'View + Comment') },
				{ value: 'bekijken_bijdragen', label: t('procest', 'View + Contribute') },
			],
		}
	},
	methods: {
		async createShare() {
			this.saving = true
			try {
				const payload = {
					caseId: this.caseId,
					shareType: this.shareType,
					permissionLevel: this.form.permissionLevel?.value || 'bekijken',
					label: this.form.label,
				}

				if (this.shareType === 'token') {
					if (this.form.expiresAt) {
						payload.expiresAt = new Date(this.form.expiresAt).toISOString()
					}
					if (this.form.password) {
						payload.password = this.form.password
					}
				} else {
					payload.partnerId = this.form.partnerId?.id
				}

				this.$emit('created', payload)
			} finally {
				this.saving = false
			}
		},
		async copyLink() {
			if (this.generatedLink) {
				await navigator.clipboard.writeText(this.generatedLink)
			}
		},
	},
}
</script>

<style scoped>
.create-share-dialog__tabs {
	display: flex;
	gap: 4px;
	margin-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}

.create-share-dialog__tabs button {
	padding: 8px 16px;
	border: none;
	background: none;
	cursor: pointer;
	border-radius: var(--border-radius) var(--border-radius) 0 0;
	color: var(--color-text-maxcontrast);
}

.create-share-dialog__tabs button.active {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	font-weight: bold;
}

.create-share-dialog__form .form-group {
	margin-bottom: 12px;
}

.create-share-dialog__form .form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
	font-size: 13px;
}

.create-share-dialog__link {
	margin-top: 16px;
	padding: 12px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
}

.create-share-dialog__link-row {
	display: flex;
	gap: 8px;
	align-items: center;
}
</style>
