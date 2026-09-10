<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The appointments leaf on the task page, anchored on the TASK.

  Same reason as TaskNotesLeaf: the manifest declared
  `type: "integration", integrationId: "calendar"`, which resolves through
  the library's calendar integration to an OBJECT-anchored read, and an
  engine task is not an object. The route id is an engine uuid, so the
  object read answered nothing and the widget rendered its empty state for
  every task that had appointments on it.

  The endpoint is OpenRegister's `GET /api/flow-tasks/{uuid}/events`
  (ADR-022). It answers `{ results, total }`, and each row is the union of
  the calendar link row and a live CalDAV scan: `summary` (not `title`),
  `dtstart` / `dtend` (not `start` / `end`), `location`, `calendarUri`, and
  a `source` of `link-table`, `xor-only` or `both`. `total` is the length of
  the page, not a table count, so nothing here reads it.

  🚧 SEAM: CREATING AN APPOINTMENT.
  This leaf LISTS and does not create, and that is deliberate rather than
  unfinished. `POST /api/flow-tasks/{uuid}/events` exists on the same
  controller, but two things about its contract are not settled enough to
  build a form against:

    1. The controller anchors the event with `(int)$task->getRegisterId()`
       and `(int)$task->getSchemaId()`, and both are nullable on the engine's
       Task. For a task that is not anchored on an OpenRegister object those
       become 0, and what a link row with register 0 / schema 0 does to the
       calendar leaf's own lookups is not something dossiq can assert from
       here.
    2. The endpoint is not yet on any branch of openregister — it is
       uncommitted work in the `feat/task-anchored-leaves` tree — so the
       request shape can still move before it lands.

  Guessing a form against that is how a silent write ends up somewhere
  nothing reads it, which is the exact failure this whole change is undoing
  (`workflow.js`'s `dispatchCreateTaskAction`, remove-casetask section 1).
  So the read moves now, the write moves when the endpoint lands, and this
  comment is the marker for it.

  @spec openspec/specs/task-management/spec.md
-->
<template>
	<section class="task-events-leaf" data-testid="task-events-leaf">
		<p
			v-if="error"
			class="task-events-leaf__error"
			data-testid="task-events-leaf-error">
			{{ error }}
		</p>

		<ul v-if="events.length > 0" class="task-events-leaf__list">
			<li
				v-for="event in events"
				:key="keyOf(event)"
				class="task-events-leaf__event"
				data-testid="task-events-leaf-event">
				<span class="task-events-leaf__summary">{{ summaryOf(event) }}</span>
				<span class="task-events-leaf__when">{{ whenOf(event) }}</span>
				<span v-if="event.location" class="task-events-leaf__where">
					{{ event.location }}
				</span>
			</li>
		</ul>
		<p
			v-else-if="!error"
			class="task-events-leaf__empty"
			data-testid="task-events-leaf-empty">
			{{ t('dossiq', 'Nobody has booked an appointment on this task') }}
		</p>
	</section>
</template>

<script>
import { useEngineTaskStore } from '../../store/modules/engineTask.js'

export default {
	name: 'TaskEventsLeaf',

	props: {
		/** The engine task the appointments hang on. */
		taskId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			/** The linked appointments, as the endpoint returns them. */
			events: [],
			/**
			 * The last failure, rendered in place rather than toasted, for
			 * the same reason as the notes leaf: an unreadable list and an
			 * empty one are indistinguishable on screen.
			 */
			error: '',
		}
	},

	watch: {
		taskId: {
			immediate: false,
			/**
			 * The page re-bound to another task.
			 *
			 * @return {void}
			 * @spec openspec/specs/task-management/spec.md
			 */
			handler() {
				this.load()
			},
		},
	},

	/**
	 * Read the appointments.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/task-management/spec.md
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Read the task's linked appointments.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/task-management/spec.md
		 */
		async load() {
			const id = String(this.taskId ?? '').trim()
			if (id === '') {
				this.events = []
				return
			}

			const outcome = await useEngineTaskStore().readLeaf(id, 'events')
			this.events = outcome.results
			this.error = outcome.error ?? ''
		},

		/**
		 * A stable list key.
		 *
		 * `id` is the event URI and `uid` the iCalendar uid; a row from the
		 * live CalDAV scan alone can be missing either, so both are tried
		 * before the summary.
		 *
		 * @param {object} event The event row.
		 * @return {string} The key.
		 * @spec openspec/specs/task-management/spec.md
		 */
		keyOf(event) {
			return String(event?.id ?? event?.uid ?? event?.summary ?? '')
		},

		/**
		 * What the appointment is called.
		 *
		 * @param {object} event The event row.
		 * @return {string} The summary.
		 * @spec openspec/specs/task-management/spec.md
		 */
		summaryOf(event) {
			const summary = String(event?.summary ?? '').trim()
			return summary === '' ? t('dossiq', 'Appointment') : summary
		},

		/**
		 * When the appointment is, in the reader's locale.
		 *
		 * A start with no end renders the start alone rather than an open
		 * range, and an unparseable value renders verbatim: a raw ATOM string
		 * still tells a handler the date, where "Invalid Date" tells them
		 * nothing.
		 *
		 * @param {object} event The event row.
		 * @return {string} The formatted window, or ''.
		 * @spec openspec/specs/task-management/spec.md
		 */
		whenOf(event) {
			const start = this.formatMoment(event?.dtstart)
			const end = this.formatMoment(event?.dtend)
			if (start === '') {
				return end
			}
			if (end === '') {
				return start
			}
			return `${start} - ${end}`
		},

		/**
		 * One ATOM timestamp, formatted.
		 *
		 * @param {string|null|undefined} value The timestamp.
		 * @return {string} The formatted value, or ''.
		 * @spec openspec/specs/task-management/spec.md
		 */
		formatMoment(value) {
			const raw = String(value ?? '').trim()
			if (raw === '') {
				return ''
			}
			const parsed = new Date(raw)
			if (Number.isNaN(parsed.getTime())) {
				return raw
			}
			return parsed.toLocaleString()
		},
	},
}
</script>

<style scoped lang="scss">
.task-events-leaf {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);

	&__list {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__event {
		display: flex;
		flex-direction: column;
		padding: calc(var(--default-grid-baseline) * 2);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
	}

	&__summary {
		font-weight: bold;
	}

	&__when,
	&__where,
	&__empty {
		color: var(--color-text-maxcontrast);
	}

	&__error {
		margin: 0;
		color: var(--color-error);
	}
}
</style>
