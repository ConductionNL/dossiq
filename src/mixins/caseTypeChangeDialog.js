/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import { caseDialogFromRoute } from './caseDialog.js'

const PAGE_REFRESH = 'cn:page:refresh'

/**
 * A dialog that moves a case onto another case type or version through one
 * case endpoint: GET lists the targets (and previews one), POST performs the
 * change. The dialog supplies `canConfirm`, `confirmPayload()` and
 * `refusalOf(e)`.
 *
 * @param {string} action The endpoint segment after the case id.
 * @return {object} The mixin.
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
export function caseTypeChangeDialog(action) {
	return {
		mixins: [caseDialogFromRoute],

		data() {
			return {
				current: {},
				targets: [],
				target: '',
				preview: null,
				reason: '',
				error: '',
				loading: true,
				busy: false,
			}
		},

		async mounted() {
			await this.loadOptions()
		},

		methods: {
			/**
			 * The targets this case could change to.
			 *
			 * @return {Promise<void>}
			 *
			 * @spec openspec/specs/zaaktype-versioning/spec.md
			 */
			async loadOptions() {
				this.loading = true
				try {
					const { data } = await axios.get(this.endpoint())
					this.current = data?.current ?? {}
					this.targets = Array.isArray(data?.targets) ? data.targets : []
				} catch (e) {
					this.error = this.refusalOf(e)
				} finally {
					this.loading = false
				}
			},

			/**
			 * Change the case, and keep the dialog open on a refusal.
			 *
			 * @return {Promise<void>}
			 *
			 * @spec openspec/specs/zaaktype-versioning/spec.md
			 */
			async confirm() {
				if (!this.canConfirm) {
					return
				}
				this.busy = true
				this.error = ''
				try {
					await axios.post(this.endpoint(), this.confirmPayload())
					// Tell the whole page: the header, the stages widget and the
					// attributes panel all read the case type.
					emit(PAGE_REFRESH, {})
					this.$emit('close')
				} catch (e) {
					this.error = this.refusalOf(e)
				} finally {
					this.busy = false
				}
			},

			/**
			 * The endpoint for this case.
			 *
			 * @return {string} The url.
			 *
			 * @spec openspec/specs/zaaktype-versioning/spec.md
			 */
			endpoint() {
				return generateUrl(
					`/apps/dossiq/api/case/${encodeURIComponent(this.targetCaseId)}/${action}`,
				)
			},
		},
	}
}
