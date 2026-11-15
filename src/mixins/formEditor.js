/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * An editor dialog that checks its `form` and hands a copy to its parent as
 * `save`. The dialog supplies `formErrors()`, the refusals per field, and an
 * `errors` data field the template reads them from.
 *
 * @spec exclude shared editor plumbing, no behaviour of its own
 */
export const formEditor = {
	methods: {
		/**
		 * @return {boolean} True when the form has no refused field.
		 * @spec exclude shared editor plumbing
		 */
		validate() {
			const errs = this.formErrors()
			this.errors = errs
			return Object.keys(errs).length === 0
		},

		/**
		 * Hand the form to the parent when it validates.
		 *
		 * @spec exclude shared editor plumbing
		 */
		save() {
			if (!this.validate()) return
			this.$emit('save', { ...this.form })
		},
	},
}

/**
 * A form editor that shows `saving` while it hands the form over.
 *
 * @spec exclude shared editor plumbing, no behaviour of its own
 */
export const savingFormEditor = {
	mixins: [formEditor],

	methods: {
		/**
		 * Hand the form to the parent when it validates, flagging `saving`.
		 *
		 * @spec exclude shared editor plumbing
		 */
		async save() {
			if (!this.validate()) return
			this.saving = true
			try {
				this.$emit('save', { ...this.form })
			} finally {
				this.saving = false
			}
		},
	},
}
