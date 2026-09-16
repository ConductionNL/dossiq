<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	The star on the case page.

	A handler who works the same eight cases for a fortnight should not be
	searching for them every morning. The star puts a case in their own
	Favourites lens on the Cases index and on the dashboard tile, and it is
	theirs alone: OpenRegister keeps it in its own table, so starring cuts no
	version, writes no audit entry and is invisible to everybody else.

	🔴 WHY THIS IS A WIDGET AND NOT A HEADER ACTION. `CnActionButtons` has a
	`toggle` type that looks exactly right, and it cannot express this gesture:
	it writes with ONE method, flipping a boolean and PUTting it, where
	starring is PUT and unstarring is DELETE on the same path. A `handler`
	header action is no better, because `dispatchAction` spreads `action.args`
	verbatim and resolves no tokens in them, so the handler would be called
	with no case to act on. Both would ship a button that does half the
	gesture or none of it, silently. The day the library takes a two-verb
	toggle, or a favourite affordance of its own, this file goes.

	🔴 THE STATE IS READ OFF THE OBJECT, NOT FETCHED. `@self.favourite` rides
	every object read, so the strip renders from what the page already has and
	makes no call until somebody presses it. There is no endpoint that answers
	the state on its own, by design.

	THE FLIP IS OPTIMISTIC AND REVERTS ON FAILURE. Both verbs are idempotent,
	so a double press is not an error; a failed write puts the star back where
	it was and says what the server said.

	@spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md
-->
<template>
	<div class="case-favourite" data-testid="case-favourite">
		<NcButton
			variant="tertiary"
			:disabled="busy || caseId === ''"
			:aria-pressed="String(starred)"
			data-testid="case-favourite-toggle"
			@click="toggle">
			<template #icon>
				<Star v-if="starred" :size="20" />
				<StarOutline v-else :size="20" />
			</template>
			{{ label }}
		</NcButton>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import Star from 'vue-material-design-icons/Star.vue'
import StarOutline from 'vue-material-design-icons/StarOutline.vue'
import { isFavourite, objectIdOf, setFavourite } from '../../services/favouriteApi.js'

export default {
	name: 'CaseFavouriteStrip',

	components: { NcButton, Star, StarOutline },

	props: {
		/** The case this strip belongs to, bound by CnDetailWidgetHost. */
		objectId: {
			type: [String, Number],
			default: '',
		},

		/**
		 * The case itself, so the star renders without a call of its own.
		 *
		 * 🔴 THE NAME IS `objectData` AND NOT `object`, WHICH IS NOT A STYLE
		 * CHOICE. `CnDetailWidgetHost.rendererProps()` hands a registry widget
		 * `{ content, objectId, register, schema, objectData, objectType,
		 * store }`. A prop called `object` is never bound, arrives as null, and
		 * `isFavourite(null)` is false, so the star would paint EMPTY on every
		 * case including the ones this reader has starred. Nothing would fail:
		 * the button works, the write works, only the first paint lies.
		 */
		objectData: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			busy: false,
			/** null until the first press; the object's own flag until then. */
			local: null,
		}
	},

	computed: {
		/**
		 * The case this strip is about.
		 *
		 * @return {string} The case uuid, or the empty string.
		 *
		 * @spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md
		 */
		caseId() {
			return String(this.objectId || objectIdOf(this.objectData) || this.$route?.params?.id || '')
		},

		/**
		 * Whether this reader has starred the case.
		 *
		 * The local flip wins once there has been one, so the button answers
		 * the press rather than the payload the page was rendered from.
		 *
		 * @return {boolean} TRUE when starred.
		 *
		 * @spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md
		 */
		starred() {
			if (this.local !== null) {
				return this.local
			}

			return isFavourite(this.objectData)
		},

		/**
		 * What the button says it will do.
		 *
		 * The label names the RESULT of the press, not the current state: a
		 * button reading "Favourite" beside a filled star tells a reader
		 * nothing about what pressing it does.
		 *
		 * @return {string} The label in the reader's language.
		 *
		 * @spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md
		 */
		label() {
			return this.starred
				? t('dossiq', 'Remove from favourites')
				: t('dossiq', 'Add to favourites')
		},
	},

	methods: {
		/**
		 * Flip the star, and put it back if the write is refused.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md
		 */
		async toggle() {
			if (this.busy || this.caseId === '') {
				return
			}

			const previous = this.starred
			const wanted = (previous === false)

			this.local = wanted
			this.busy = true

			try {
				await setFavourite(this.caseId, wanted)
				window.dispatchEvent(new CustomEvent('dossiq:cases-changed'))
			} catch (error) {
				this.local = previous
				const refusal = String(error?.response?.data?.message ?? '')
				showError(refusal !== '' ? refusal : t('dossiq', 'This did not work. Try again.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.case-favourite {
	display: flex;
	align-items: center;
	justify-content: flex-start;
}
</style>
