<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Follow a case you do not own.

	A case knows a handler and a team. A teamleider who wants to hear about a
	sensitive case without taking it over had no way to, so they asked to be
	copied in by hand, or they read nothing. Following subscribes you to the
	case's own notifications, puts it under Followed on Cases, and lists you
	under Followers on the People tab.

	🔴 FOLLOWING IS NOT STARRING, AND THE TWO STRIPS SIT SIDE BY SIDE ON
	PURPOSE. The star beside this one is private and silent. Following is a
	subscription: it produces notifications, and the people who may edit the
	case can see who took it. Merging them into one control would make one
	gesture do a visible thing and an invisible thing at once.

	🔴 WHY THIS IS A WIDGET AND NOT TWO HEADER ACTIONS. `api-call` is the
	manifest action type that would carry it, and it writes POST or PUT only:
	`executeApiCall` maps anything that is not `PUT` to `post`, so an Unfollow
	declared with `method: "DELETE"` would POST to a route that takes DELETE
	and fail with no clue in the manifest that it could never have worked.
	`CnActionButtons`' `toggle` type writes with one `method` for both
	directions, and a `handler` action is handed `action.args` verbatim with no
	token resolved, so it would run with no case to act on. The star hit the
	same three walls and this file records the same answer. All three go the
	day the library takes a DELETE verb and a two-verb toggle, which is where
	this belongs for every app in the fleet.

	🔴 THE STATE IS READ OFF THE OBJECT, NOT FETCHED. `@self.watching` rides
	every object read, so the strip renders from what the page already holds
	and makes no call until somebody presses it.

	🔴 AN ABSENT COUNT IS NOT ZERO. `@self.watcherCount` is attached only for a
	reader who may update the case, so the count is silent rather than showing
	"0 followers" to a reader OpenRegister declined to tell.

	The flip is optimistic and reverts on failure. Both verbs are idempotent,
	so a double press is not an error.

	@spec openspec/changes/case-followers/specs/case-management/spec.md
-->
<template>
	<div class="case-follow" data-testid="case-follow">
		<!--
			`:pressed` is NcButton's OWN prop and renders `aria-pressed` itself.
			Passing `aria-pressed` as a plain attribute instead would land in
			`$attrs` beside the component's own binding of the same name.
		-->
		<NcButton
			variant="tertiary"
			:disabled="busy || caseId === ''"
			:pressed="following"
			data-testid="case-follow-toggle"
			@click="toggle">
			<template #icon>
				<BellRing v-if="following" :size="20" />
				<BellOutline v-else :size="20" />
			</template>
			{{ label }}
		</NcButton>

		<span
			v-if="countLabel !== ''"
			class="case-follow__count"
			data-testid="case-follow-count">
			{{ countLabel }}
		</span>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import BellOutline from 'vue-material-design-icons/BellOutline.vue'
import BellRing from 'vue-material-design-icons/BellRing.vue'
import {
	followerCountOf,
	isFollowing,
	objectIdOf,
	setFollowing,
} from '../../services/watcherApi.js'

export default {
	name: 'CaseFollowStrip',

	components: { BellOutline, BellRing, NcButton },

	props: {
		/** The case this strip belongs to, bound by CnDetailWidgetHost. */
		objectId: {
			type: [String, Number],
			default: '',
		},

		/**
		 * The case itself, so the button renders without a call of its own.
		 *
		 * 🔴 THE NAME IS `objectData` AND NOT `object`.
		 * `CnDetailWidgetHost.rendererProps()` hands a registry widget
		 * `{ content, objectId, register, schema, objectData, objectType,
		 * store }`. A prop called `object` is never bound, arrives as null, and
		 * the button would then read "Follow" on every case including the ones
		 * this reader already follows. Nothing would fail: the press works, the
		 * write works, only the first paint lies.
		 */
		objectData: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			busy: false,
			/** null until the first press; the object's own marker until then. */
			local: null,
			/** How far the local flip has moved the count, in either direction. */
			delta: 0,
		}
	},

	computed: {
		/**
		 * The case this strip is about.
		 *
		 * @return {string} The case uuid, or the empty string.
		 *
		 * @spec openspec/changes/case-followers/specs/case-management/spec.md
		 */
		caseId() {
			return String(
				this.objectId
					|| objectIdOf(this.objectData)
					|| this.$route?.params?.id
					|| '',
			)
		},

		/**
		 * Whether this reader follows the case.
		 *
		 * The local flip wins once there has been one, so the button answers
		 * the press rather than the payload the page was rendered from.
		 *
		 * @return {boolean} TRUE when following.
		 *
		 * @spec openspec/changes/case-followers/specs/case-management/spec.md
		 */
		following() {
			if (this.local !== null) {
				return this.local
			}

			return isFollowing(this.objectData)
		},

		/**
		 * What the button says it will do.
		 *
		 * The label names the RESULT of the press, not the current state.
		 *
		 * @return {string} The label in the reader's language.
		 *
		 * @spec openspec/changes/case-followers/specs/case-management/spec.md
		 */
		label() {
			return this.following
				? t('dossiq', 'Stop following')
				: t('dossiq', 'Follow this case')
		},

		/**
		 * How many people follow this case, or nothing at all.
		 *
		 * Silent when OpenRegister told this reader no count: an absent
		 * `@self.watcherCount` means the reader may not update the case, which
		 * is a different fact from a case nobody follows.
		 *
		 * @return {string} The sentence, or the empty string.
		 *
		 * @spec openspec/changes/case-followers/specs/case-management/spec.md
		 */
		countLabel() {
			const known = followerCountOf(this.objectData)
			if (known === null) {
				return ''
			}

			const total = Math.max(0, known + this.delta)

			return n('dossiq', '{count} follower', '{count} followers', total, {
				count: total,
			})
		},
	},

	methods: {
		/**
		 * Follow or stop, and put the button back if the write is refused.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/case-followers/specs/case-management/spec.md
		 */
		async toggle() {
			if (this.busy || this.caseId === '') {
				return
			}

			const previous = this.following
			const previousDelta = this.delta
			const wanted = previous === false

			this.local = wanted
			this.delta = previousDelta + (wanted ? 1 : -1)
			this.busy = true

			try {
				await setFollowing(this.caseId, wanted)
				window.dispatchEvent(new CustomEvent('dossiq:cases-changed'))
			} catch (error) {
				this.local = previous
				this.delta = previousDelta
				const refusal = String(error?.response?.data?.message ?? '')
				showError(
					refusal !== ''
						? refusal
						: t('dossiq', 'This did not work. Try again.'),
				)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.case-follow {
	display: flex;
	align-items: center;
	justify-content: flex-start;
	gap: var(--default-grid-baseline, 4px);
}

.case-follow__count {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
