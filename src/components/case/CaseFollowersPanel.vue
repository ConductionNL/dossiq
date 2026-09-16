<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Who is following this case, on the People tab.

	The sections beside this one answer who the case is ABOUT: the parties, the
	roles, the two seats. This one answers who is WATCHING it, which is a
	different question and a smaller list. A teamleider following a sensitive
	case appears here and nowhere else on the tab, because they hold no role on
	it.

	🔴 THE LIST IS READ, NEVER MANAGED. Taking somebody else's subscription off
	needs `manage` in OpenRegister, and a handler pressing a cross next to a
	colleague's name would be deciding what that colleague hears. So no remove
	button is offered here at all. You stop following from the button on the
	case page, which acts on your own subscription only.

	🔴 A REFUSAL IS NOT AN EMPTY LIST, AND THE TWO ARE DRAWN APART. Reading the
	followers needs `update` on the case, so OpenRegister answers 403 to a
	reader who may only look at it. Drawing that as "nobody follows this case"
	would be a claim about the audience that this reader was never told. The
	refusal says so instead.

	🔴 THE NAME IS THE ACCOUNT NAME UNTIL OPENREGISTER SENDS A BETTER ONE.
	`GET .../watchers` answers a subscription row, whose `userId` is the
	Nextcloud account name. `displayName` is read first so the day OpenRegister
	enriches the row this panel reads the person's name with no change here.

	@spec openspec/changes/case-followers/specs/case-management/spec.md
-->
<template>
	<div class="case-followers" data-testid="case-followers">
		<NcLoadingIcon v-if="loading" :size="24" />

		<NcEmptyContent
			v-else-if="refused"
			:name="t('dossiq', 'Only a handler of this case sees who follows it')"
			:description="
				t(
					'dossiq',
					'You can still follow it yourself from the button on the case.',
				)
			" />

		<NcEmptyContent
			v-else-if="failed"
			:name="t('dossiq', 'The followers of this case could not be read')"
			:description="
				t('dossiq', 'OpenRegister did not answer. Nobody was unsubscribed.')
			" />

		<NcEmptyContent
			v-else-if="followers.length === 0"
			:name="t('dossiq', 'Nobody follows this case yet')"
			:description="
				t(
					'dossiq',
					'Follow it to hear about it without taking it over.',
				)
			" />

		<ul v-else class="case-followers__list">
			<li
				v-for="follower in followers"
				:key="follower.userId"
				class="case-followers__person"
				:data-user="follower.userId"
				data-testid="case-followers-person">
				<span class="case-followers__name">{{ nameOf(follower) }}</span>
				<span v-if="sinceOf(follower)" class="case-followers__since">
					{{ sinceOf(follower) }}
				</span>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { listFollowers } from '../../services/watcherApi.js'

export default {
	name: 'CaseFollowersPanel',

	components: { NcEmptyContent, NcLoadingIcon },

	props: {
		/** The case this panel belongs to, bound by CnTabsWidget. */
		objectId: {
			type: [String, Number],
			default: '',
		},
	},

	data() {
		return {
			loading: true,
			/** The read was refused because this reader may not update the case. */
			refused: false,
			/** The read failed for any other reason. */
			failed: false,
			followers: [],
		}
	},

	computed: {
		/**
		 * The case this panel is about.
		 *
		 * @return {string} The case uuid, or the empty string.
		 *
		 * @spec openspec/changes/case-followers/specs/case-management/spec.md
		 */
		caseId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},
	},

	watch: {
		caseId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/**
		 * Read the followers of the case.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/case-followers/specs/case-management/spec.md
		 */
		async load() {
			if (this.caseId === '') {
				this.loading = false
				return
			}

			this.loading = true
			this.refused = false
			this.failed = false

			try {
				this.followers = await listFollowers(this.caseId)
			} catch (error) {
				// 403 is OpenRegister saying this reader may not see the
				// audience, which is a different answer from an empty one.
				if (error?.response?.status === 403) {
					this.refused = true
				} else {
					this.failed = true
				}

				this.followers = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * What to call a follower.
		 *
		 * @param {object} follower The subscription row.
		 * @return {string} The person's name, or their account name.
		 *
		 * @spec openspec/changes/case-followers/specs/case-management/spec.md
		 */
		nameOf(follower) {
			return String(follower?.displayName || follower?.userId || '')
		},

		/**
		 * Since when this person has been following.
		 *
		 * @param {object} follower The subscription row.
		 * @return {string} The sentence, or the empty string.
		 *
		 * @spec openspec/changes/case-followers/specs/case-management/spec.md
		 */
		sinceOf(follower) {
			const raw = follower?.created
			if (!raw) {
				return ''
			}

			const moment = new Date(raw)
			if (Number.isNaN(moment.getTime())) {
				return ''
			}

			return t('dossiq', 'Following since {date}', {
				date: moment.toLocaleDateString(undefined, {
					year: 'numeric',
					month: 'short',
					day: 'numeric',
				}),
			})
		},
	},
}
</script>

<style scoped>
.case-followers__list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	list-style: none;
	margin: 0;
	padding: 0;
}

.case-followers__person {
	display: flex;
	align-items: baseline;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.case-followers__since {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
