<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  What may I do right now, in two halves.

  A case's acts come from two places and mean two different things. The phase's
  acts are the moves the case can make from where it is, answered by
  OpenRegister's `available-actions` through dossiq's own lifecycle provider.
  The always-available acts are declared on the case type and are allowed in
  every phase: withdraw the case, add a document, ask a colleague.

  🔴 ONE LIST, TWO MARKS. Two separate menus are two places to look, and one of
  them gets forgotten. But an always-available act is NOT a phase act with a
  flag on it: it never enters the phase strip, the progress figure or a term,
  and it is kept out of all three by coming from a different endpoint rather
  than by every reader remembering to filter. `phaseActsOnly()` in
  `src/utils/caseActs.js` is what the strip and the figure ask.

  🔑 A REFUSED ACT IS SHOWN, DISABLED, WITH ITS REASON. An act that vanishes
  for a reader without the role tells them the system cannot do it. One shown
  with "Onvoldoende rechten" tells them who to ask.

  WHY THIS IS A DOSSIQ COMPONENT. It merges two answers with different shapes
  and renders a disabled row carrying a guard's sentence. No configured widget
  reads two sources, and `CnObjectListWidget` has neither row actions nor a
  reason column. It goes when `lifecycle-acts-on-the-case` lands its one
  lifecycle menu on CaseDetail and this becomes the always-available half of
  that menu.

  @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
-->
<template>
	<div class="case-acts-pane" data-testid="case-acts-pane">
		<p v-if="acts.length === 0" class="case-acts-pane__empty" data-testid="case-acts-pane-empty">
			{{ t('dossiq', 'Nothing to do on this case right now') }}
		</p>
		<ul v-else class="case-acts-pane__list">
			<li
				v-for="act in acts"
				:key="act.id"
				class="case-acts-pane__item"
				:data-testid="`case-acts-pane-act-${act.id}`">
				<span class="case-acts-pane__label">{{ act.label }}</span>
				<span
					v-if="act.alwaysAvailable"
					class="case-acts-pane__mark"
					:data-testid="`case-acts-pane-always-${act.id}`">
					{{ t('dossiq', 'Always available') }}
				</span>
				<span
					v-if="!act.available"
					class="case-acts-pane__reason"
					:data-testid="`case-acts-pane-reason-${act.id}`">
					{{ act.reason || t('dossiq', 'Not possible on this case') }}
				</span>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { actsFor } from '../../utils/caseActs.js'

export default {
	name: 'CaseActsPane',

	// CnDetailWidgetHost spreads the widget's whole `content` blob onto the
	// renderer, so keys this component does not declare would otherwise be
	// stringified onto the root element.
	inheritAttrs: false,

	props: {
		/** The case this pane is on, bound by the detail surface. */
		objectId: {
			type: [String, Number],
			default: '',
		},
	},

	data() {
		return {
			/** The phase's own acts, as the lifecycle provider answers them. */
			phase: [],
			/** The acts the case type allows in every phase. */
			always: [],
		}
	},

	computed: {
		/**
		 * Both halves, in reading order, each carrying its mark.
		 *
		 * @return {object[]} The acts.
		 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
		 */
		acts() {
			return actsFor(this.phase, this.always)
		},
	},

	watch: {
		objectId: {
			immediate: false,
			/**
			 * The surface re-bound this widget to another case.
			 *
			 * @return {void}
			 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
			 */
			handler() {
				this.load()
			},
		},
	},

	/**
	 * Read both halves.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Ask both authorities, and let neither failure hide the other half.
		 *
		 * The two are read separately on purpose. A case type that declares no
		 * always-available acts still has phase acts, and an unreadable
		 * lifecycle provider must not empty a list the case type filled.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
		 */
		async load() {
			const caseId = String(this.objectId ?? '').trim()
			if (caseId === '') {
				this.phase = []
				this.always = []
				return
			}

			const [phase, always] = await Promise.all([
				this.read(
					generateUrl(
						`/apps/openregister/api/objects/${encodeURIComponent(caseId)}/available-actions`,
					),
					'actions',
				),
				this.read(
					generateUrl(`/apps/dossiq/api/case/${encodeURIComponent(caseId)}/acts`),
					'alwaysAvailable',
				),
			])
			this.phase = phase
			this.always = always
		},

		/**
		 * One read, answering a list whatever the endpoint wraps it in.
		 *
		 * @param {string} url The endpoint.
		 * @param {string} key The key the list arrives under.
		 * @return {Promise<object[]>} The rows, or an empty list.
		 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
		 */
		async read(url, key) {
			try {
				const response = await axios.get(url)
				const body = response?.data ?? {}
				const rows = Array.isArray(body) ? body : (body[key] ?? body.results ?? [])
				return Array.isArray(rows) ? rows : []
			} catch (error) {
				return []
			}
		},
	},
}
</script>

<style scoped lang="scss">
.case-acts-pane {
	display: flex;
	flex-direction: column;
	padding: calc(var(--default-grid-baseline) * 2);

	&__list {
		list-style: none;
		margin: 0;
		padding: 0;
	}

	&__item {
		display: flex;
		align-items: baseline;
		gap: calc(var(--default-grid-baseline) * 2);
		padding: calc(var(--default-grid-baseline) / 2) 0;
	}

	&__mark,
	&__reason,
	&__empty {
		color: var(--color-text-maxcontrast);
	}

	&__empty {
		margin: 0;
	}
}
</style>
