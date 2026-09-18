<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	The line that says this case is in the archive.

	An archived case has most of its buttons taken away. Without a sentence
	saying why, that reads as a case somebody has locked, or as a page that
	failed to load its actions, and the handler's next move is to ask a
	colleague. So the strip names the archive, the person who filed it and the
	day they did, and it says that the case can be read and not changed.

	🔴 IT RENDERS NOTHING ON A CASE THAT IS NOT ARCHIVED, which is almost every
	case. A strip that drew an empty box on every open case would cost every
	reader a row of the page for a state they will meet twice a year.

	🔴 THE STATE IS `@self.archived` AND NOT `archiveStatus`. The marker is what
	the Cases page, the queue, the tiles and the search exclude on, so reading
	the ZGW field here would let this strip disagree with every list in the
	app: a case imported over ZGW carrying `archiefstatus: gearchiveerd` and no
	marker is in every working lens, and telling its reader it is archived
	would be a sentence contradicted by the page they came from.

	🔑 RESTORE IS NOT A BUTTON HERE. It is one entry in the Lifecycle menu
	beside every other act on the case, because an act that lives in two places
	is gated in two places, and the one on the strip would be the one nobody
	remembered to gate. The strip points at the menu instead.

	@spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
-->
<template>
	<div v-if="archived" class="case-archived" data-testid="case-archived">
		<ArchiveOutline :size="20" class="case-archived__icon" />
		<div class="case-archived__body">
			<p class="case-archived__headline" data-testid="case-archived-headline">
				{{ headline }}
			</p>
			<p v-if="reason" class="case-archived__reason" data-testid="case-archived-reason">
				{{ reason }}
			</p>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import ArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'

export default {
	name: 'CaseArchivedStrip',

	components: { ArchiveOutline },

	props: {
		/**
		 * The case itself, so the strip renders without a call of its own.
		 *
		 * 🔴 THE NAME IS `objectData` AND NOT `object`.
		 * `CnDetailWidgetHost.rendererProps()` binds `objectData`, and a prop
		 * called `object` arrives as null on every case, so the strip would be
		 * silent on the archived ones too and nothing would report it.
		 */
		objectData: {
			type: Object,
			default: null,
		},
	},

	computed: {
		/**
		 * The marker the platform wrote, or null.
		 *
		 * @return {object|null} `{ by, at, reason }`, or null when open.
		 *
		 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
		 */
		marker() {
			const self = this.objectData?.['@self']
			const marker = self && typeof self === 'object' ? self.archived : null

			return marker && typeof marker === 'object' ? marker : null
		},

		/**
		 * Whether this case is in the archive.
		 *
		 * @return {boolean} True when the marker is present.
		 *
		 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
		 */
		archived() {
			return this.marker !== null
		},

		/**
		 * Who archived the case, when, and what that means for the reader.
		 *
		 * Falls back to the shorter sentence when the marker names nobody: a
		 * marker written by a background job or by an import carries no user,
		 * and "Archived by on" is worse than saying less.
		 *
		 * @return {string} The sentence.
		 *
		 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
		 */
		headline() {
			const by = String(this.marker?.by ?? '').trim()
			const at = this.day

			if (by !== '' && at !== '') {
				return t(
					'dossiq',
					'This case is archived. {user} filed it on {date}. You can read it, and changes are refused.',
					{ user: by, date: at },
				)
			}

			return t(
				'dossiq',
				'This case is archived. You can read it, and changes are refused.',
			)
		},

		/**
		 * The archiving day, as the reader's locale writes it.
		 *
		 * An unparseable stamp yields the empty string rather than "Invalid
		 * Date", which is the one thing on this strip nobody could act on.
		 *
		 * @return {string} The day, or the empty string.
		 *
		 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
		 */
		day() {
			const at = String(this.marker?.at ?? '').trim()
			if (at === '') {
				return ''
			}

			const parsed = new Date(at)
			if (Number.isNaN(parsed.getTime())) {
				return ''
			}

			return parsed.toLocaleDateString()
		},

		/**
		 * The reason the archiving act was given, when it was given one.
		 *
		 * @return {string} The reason, or the empty string.
		 *
		 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
		 */
		reason() {
			return String(this.marker?.reason ?? '').trim()
		},
	},

	methods: { t },
}
</script>

<style scoped>
.case-archived {
	display: flex;
	align-items: flex-start;
	gap: var(--default-grid-baseline, 4px);
	padding: calc(var(--default-grid-baseline, 4px) * 2);
	border-radius: var(--border-radius-large, 10px);
	background-color: var(--color-background-hover);
}

.case-archived__icon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.case-archived__body {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) / 2);
}

.case-archived__headline {
	margin: 0;
	font-weight: bold;
}

.case-archived__reason {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
