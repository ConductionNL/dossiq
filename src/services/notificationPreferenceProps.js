/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The platform's answer, shaped for the shared preferences screen.
 *
 * dossiq had a second notification settings screen of its own. It said which
 * layer decided each value, which was the right idea, but it could only say
 * three layers and it had no way to show a channel an administrator has
 * forced. `CnNotificationMatrix` shows four layers and a refusal, so this
 * maps onto that rather than growing a second implementation beside it.
 *
 * 🔴 A LAYER NOBODY ANSWERED FOR IS NOT GUESSED. The platform returns the
 * EFFECTIVE value and the source that decided it. When somebody has overridden
 * a notification, the shipped default underneath is not in the answer, and
 * writing the effective value into `appDefault` would put a made-up value on a
 * layer the screen displays. It is left false and corrected by the next read,
 * which is what happens the moment anybody clears their own value.
 *
 * 🔴 THE CHANNEL AXIS IS THE PLATFORM'S, NOT THIS FILE'S. dossiq's preferences
 * are one switch per notification today, not per notification and channel.
 * When the platform answers with channels, they are the columns. When it does
 * not, there is ONE column and it is called what it is. Fabricating a mail and
 * a push column would tell a handler they can choose between them when the
 * platform stores no such choice, and their click would be silently collapsed
 * onto the single value that does exist.
 *
 * Pure: no axios, no Vue, no store.
 *
 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
 */

/** The column used when the platform answers without a channel axis. */
export const SINGLE_CHANNEL_ID = 'notification'

/** What the platform calls a value somebody set for themselves. */
export const SOURCE_PERSONAL = 'user-override'

/** What the platform calls a value a team lead set. */
export const SOURCE_GROUP = 'group-default'

/**
 * A stable id for one notification.
 *
 * Schema and key together, because the same key can exist on two schemas and
 * a collision would merge two rows into one switch.
 *
 * @param {object} entry - One entry from the platform.
 * @return {string} The id.
 */
export function keyFor(entry) {
	return `${String(entry?.schema ?? '')}-${String(entry?.notification ?? '')}`
}

/**
 * The columns to render.
 *
 * @param {object} options - The call.
 * @param {Array<object>} [options.channels] - What the platform answered with.
 * @param {string} [options.singleChannelLabel] - What to call the one column
 *   when the platform has no channel axis.
 *
 * @return {Array<object>} `{ id, label, configured, unconfiguredReason }`.
 */
export function channelsFrom({ channels = [], singleChannelLabel = 'Notifications' } = {}) {
	if (Array.isArray(channels) === true && channels.length > 0) {
		return channels.map((channel) => ({
			id: String(channel?.id ?? ''),
			label: String(channel?.label ?? channel?.id ?? ''),
			configured: channel?.configured !== false,
			unconfiguredReason: String(channel?.unconfiguredReason ?? ''),
		}))
	}

	return [{
		id: SINGLE_CHANNEL_ID,
		label: singleChannelLabel,
		configured: true,
		unconfiguredReason: '',
	}]
}

/**
 * The rows to render.
 *
 * @param {object} options - The call.
 * @param {Array<object>} [options.entries] - The platform's entries.
 * @param {(entry: object) => string} [options.label] - What to call one entry on screen.
 *
 * @return {Array<object>} `{ id, label, group, groupLabel, appDefault, immediate }`.
 */
export function eventsFrom({ entries = [], label = (entry) => keyFor(entry) } = {}) {
	return entries.map((entry) => ({
		id: keyFor(entry),
		label: String(label(entry)),
		group: String(entry?.schema ?? ''),
		groupLabel: String(entry?.schemaTitle ?? entry?.schema ?? ''),
		// Only when the platform says so. See the header: an effective value
		// is not the shipped default, and a guess would render as one.
		appDefault: appDefaultOf(entry),
		immediate: entry?.immediate === true,
	}))
}

/**
 * The shipped default, when it is knowable.
 *
 * @param {object} entry - One entry.
 * @return {boolean} The default, or false when nobody answered for it.
 */
function appDefaultOf(entry) {
	if (typeof entry?.appDefault === 'boolean') {
		return entry.appDefault
	}

	// The effective value IS the default when no layer above it decided.
	const source = String(entry?.source ?? '')
	if (source !== SOURCE_PERSONAL && source !== SOURCE_GROUP) {
		return entry?.enabled === true
	}

	return false
}

/**
 * The four levels, and the refusals, keyed the way the screen wants them.
 *
 * @param {object} options - The call.
 * @param {Array<object>} [options.entries] - The platform's entries.
 * @param {string} [options.channelId] - The column these values sit in.
 *
 * @return {object} `{ groupValues, personalValues, forcedValues, refusals }`.
 */
export function valuesFrom({ entries = [], channelId = SINGLE_CHANNEL_ID } = {}) {
	const groupValues = {}
	const personalValues = {}
	const forcedValues = {}
	const refusals = {}

	for (const entry of entries) {
		const id = keyFor(entry)
		const source = String(entry?.source ?? '')

		if (source === SOURCE_PERSONAL) {
			personalValues[id] = { [channelId]: entry?.enabled === true }
		} else if (source === SOURCE_GROUP) {
			groupValues[id] = { [channelId]: entry?.enabled === true }
		}

		const forced = entry?.forced
		if (forced !== undefined && forced !== null) {
			// FORCED CAN FORCE A CHANNEL OFF. Reading a forced row as "on"
			// would switch on a channel an administrator forbade, so the
			// value is taken from the row and never assumed.
			forcedValues[id] = {
				[channelId]: {
					value: forced?.value === true,
					by: String(forced?.by ?? ''),
					reason: String(forced?.reason ?? ''),
				},
			}
		}

		const refused = entry?.refused
		if (refused !== undefined && refused !== null) {
			refusals[id] = { [channelId]: { reason: String(refused?.reason ?? '') } }
		}
	}

	return { groupValues, personalValues, forcedValues, refusals }
}

/**
 * Everything the screen needs, from one read.
 *
 * @param {object} options - The call.
 * @param {Array<object>} [options.entries] - The platform's entries.
 * @param {Array<object>} [options.channels] - The platform's channels, if any.
 * @param {(entry: object) => string} [options.label] - What to call one entry.
 * @param {string} [options.singleChannelLabel] - The one column's name.
 *
 * @return {object} The component's props.
 */
export function propsFor({ entries = [], channels = [], label, singleChannelLabel } = {}) {
	const columns = channelsFrom({ channels, singleChannelLabel })

	return {
		events: eventsFrom({ entries, label }),
		channels: columns,
		...valuesFrom({ entries, channelId: columns[0].id }),
	}
}

/**
 * The write behind one change on the screen.
 *
 * 🔴 THE ID IS SPLIT AT THE FIRST DASH, NOT THE LAST. A notification key may
 * contain dashes; a schema name is one word. Splitting at the last would move
 * half the key into the schema and write to a notification nobody has.
 *
 * There is no clear here. `CnNotificationMatrix` emits true or false and
 * has no third state, so the per-row "use the setting from my team" that
 * dossiq's own list carried has no equivalent yet. That loss is named in the
 * change rather than papered over with an unreachable branch: the store behind
 * the shared screen already accepts a null to clear, so the control belongs in
 * the component, and `clearPreference()` in the API service is waiting for it.
 *
 * @param {object} options - The call.
 * @param {string} options.eventId - The row's id, as `keyFor` made it.
 * @param {string} [options.scope] - The domain, or the empty string.
 * @param {boolean} options.value - The new value.
 *
 * @return {?object} `{ schema, notification, enabled, scope }`, or null when
 *   the id is not one this screen made.
 */
export function writeFor({ eventId, scope = '', value } = {}) {
	const at = String(eventId ?? '').indexOf('-')
	if (at < 1) {
		// Not an id this file produced. Returning null rather than writing a
		// half-parsed row: a write to the wrong notification is silent and
		// permanent.
		return null
	}

	const write = {
		schema: String(eventId).slice(0, at),
		notification: String(eventId).slice(at + 1),
		enabled: value === true,
	}

	if (scope !== '') {
		write.scope = `domain:${scope}`
	}

	return write
}
