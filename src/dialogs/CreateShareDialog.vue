<template>
	<NcDialog
		:open="open"
		:name="t('procest', 'Share case with partner')"
		size="normal"
		@update:open="$emit('update:open', $event)">
		<div class="create-share-dialog">
			<!--
				Partner-organisation handover only. Public "track your case"
				token links are minted through OpenRegister's shares
				integration leaf (ADR-022), NOT this dialog — the bespoke
				token "Share link" path was removed by
				migrate-public-share-to-shares-leaf.
			-->
			<div class="create-share-dialog__form">
				<div class="form-group">
					<label>{{ t('procest', 'Partner organization') }}</label>
					<NcSelect
						v-model="form.partnerId"
						:options="partners"
						:aria-label-combobox="t('procest', 'Partner organization')"
						label="name"
						trackBy="id"
						:placeholder="t('procest', 'Select partner...')" />
				</div>

				<div class="form-group">
					<label>{{ t('procest', 'Permission level') }}</label>
					<NcSelect
						v-model="form.permissionLevel"
						:options="permissionOptions"
						:aria-label-combobox="t('procest', 'Permission level')"
						label="label"
						trackBy="value" />
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('update:open', false)">
				{{ t('procest', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving" @click="createShare">
				{{
					saving
						? t('procest', 'Creating...')
						: t('procest', 'Create share')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'

export default {
	name: 'CreateShareDialog',
	components: {
		NcDialog,
		NcButton,
		NcSelect,
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
			saving: false,
			form: {
				permissionLevel: {
					value: 'bekijken',
					label: t('procest', 'View only'),
				},

				partnerId: null,
			},

			permissionOptions: [
				{ value: 'bekijken', label: t('procest', 'View only') },
				{
					value: 'bekijken_reageren',
					label: t('procest', 'View + Comment'),
				},
				{
					value: 'bekijken_bijdragen',
					label: t('procest', 'View + Contribute'),
				},
			],
		}
	},

	methods: {
		/**
		 * Emit a partner-organisation share payload. Public token links are
		 * handled by the OR shares leaf, not here.
		 *
		 * @spec openspec/changes/migrate-public-share-to-shares-leaf/tasks.md#P2.2
		 */
		async createShare() {
			this.saving = true
			try {
				this.$emit('created', {
					caseId: this.caseId,
					shareType: 'partner',
					permissionLevel: this.form.permissionLevel?.value || 'bekijken',
					partnerId: this.form.partnerId?.id,
				})
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.create-share-dialog__form .form-group {
	margin-bottom: 12px;
}

.create-share-dialog__form .form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
	font-size: 13px;
}
</style>
