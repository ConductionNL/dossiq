<template>
	<div class="dq-mail-new-case-host">
		<CnActionButtons
			ref="buttons"
			:actions="[action]"
			@created="onCreated" />
	</div>
</template>

<script>
/**
 * Opens dossiq's New case dialog, the open-form action of its own pages,
 * without the button: the library's action host renders the dialog, so the
 * form, the case type questions, the requester picker and the duplicate check
 * are the ones dossiq shows. Emits `done` with the created case, or with null
 * when the dialog closes without one.
 *
 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
 */
import { CnActionButtons } from '@conduction/nextcloud-vue'

export default {
	name: 'MailNewCaseHost',
	components: { CnActionButtons },
	props: {
		/** The New case open-form action, seeded from the mail. */
		action: { type: Object, required: true },
	},

	emits: ['done'],

	data() {
		return {
			finished: false,
		}
	},

	/**
	 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
	 */
	async mounted() {
		const buttons = this.$refs.buttons
		buttons.$watch('formEntry', (entry) => {
			if (entry === null) {
				this.finish(null)
			}
		})
		await buttons.openForm(this.action)
	},

	methods: {
		/**
		 * The dialog saved a case.
		 *
		 * @param {object} created The created case.
		 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
		 */
		onCreated(created) {
			this.finish(created)
		},

		/**
		 * Report once, whatever closes the dialog first.
		 *
		 * @param {object|null} created The created case, or null.
		 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
		 */
		finish(created) {
			if (this.finished) return
			this.finished = true
			this.$emit('done', created)
		},
	},
}
</script>

<style scoped>
/* The action host draws a button bar; only its dialog shows here. */
.dq-mail-new-case-host :deep(.cn-action-buttons) {
	display: none;
}
</style>
