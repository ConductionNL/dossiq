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
 * 🔴 THE CHANNEL AXIS IS THE PLATFORM'S, NOT THIS FILE'S. Each entry says
 * which channels its rule can deliver on (`availableChannels`, the default
 * ones then the opt-in ones such as e-mail); those are the columns, and a
 * cell is on when the entry's effective `channels` hold it. A platform that
 * answers without them gets ONE column, called what it is. Fabricating a mail
 * column would tell a handler they can choose when the platform stores no
 * such choice, and their click would be silently collapsed onto the single
 * value that does exist.
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
 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
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
 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
 */
export function channelsFrom({
	channels = [],
	singleChannelLabel = 'Notifications',
} = {}) {
	if (Array.isArray(channels) === true && channels.length > 0) {
		return channels.map((channel) => ({
			id: String(channel?.id ?? ''),
			label: String(channel?.label ?? channel?.id ?? ''),
			configured: channel?.configured !== false,
			unconfiguredReason: String(channel?.unconfiguredReason ?? ''),
		}))
	}

	return [
		{
			id: SINGLE_CHANNEL_ID,
			label: singleChannelLabel,
			configured: true,
			unconfiguredReason: '',
		},
	]
}

/**
 * The channels the entries can deliver on, in the order the platform names
 * them: every entry's default channels, then its opt-in ones.
 *
 * @param {Array<object>} entries - The platform's entries.
 * @return {Array<string>} The channel ids, or none when the platform answered without them.
 * @spec openspec/specs/case-management/spec.md#requirement-a-person-can-switch-e-mail-on-per-notification
 */
export function channelAxisFrom(entries = []) {
	const ids = []
	for (const entry of entries) {
		for (const id of Array.isArray(entry?.availableChannels) ? entry.availableChannels : []) {
			if (typeof id === 'string' && id !== '' && ids.includes(id) === false) {
				ids.push(id)
			}
		}
	}
	return ids
}

/**
 * The channels one entry delivers on now: none while it is off, else its
 * effective channels, else its default (non-opt-in) ones.
 *
 * @param {object} entry - One entry.
 * @return {Array<string>} The channel ids that are on.
 * @spec openspec/specs/case-management/spec.md#requirement-a-person-can-switch-e-mail-on-per-notification
 */
export function channelsOnFor(entry) {
	if (entry?.enabled !== true) {
		return []
	}
	if (Array.isArray(entry?.channels) === true) {
		return entry.channels.map(String)
	}
	const optIn = Array.isArray(entry?.optInChannels) ? entry.optInChannels : []
	const available = Array.isArray(entry?.availableChannels) ? entry.availableChannels : []
	return available.filter((id) => optIn.includes(id) === false).map(String)
}

/**
 * One boolean per channel.
 *
 * @param {Array<string>} channelIds - The columns.
 * @param {(id: string) => boolean} on - Whether a column is on.
 * @return {object} `{ [channelId]: boolean }`.
 */
function perChannel(channelIds, on) {
	return Object.fromEntries(channelIds.map((id) => [id, on(id)]))
}

/**
 * The rows to render.
 *
 * @param {object} options - The call.
 * @param {Array<object>} [options.entries] - The platform's entries.
 * @param {(entry: object) => string} [options.label] - What to call one entry on screen.
 * @param {(title: string) => string} [options.groupLabel] - What to call a group on screen,
 *   given the schema's title; the title as it is when absent.
 * @param {Array<string>} [options.channelIds] - The columns, when the platform names its channels.
 *
 * @return {Array<object>} `{ id, label, group, groupLabel, appDefault, immediate }`.
 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
 */
export function eventsFrom({ entries = [], label = (entry) => keyFor(entry), groupLabel = (title) => title, channelIds = [] } = {}) {
	return entries.map((entry) => ({
		id: keyFor(entry),
		label: String(label(entry)),
		group: String(entry?.schema ?? ''),
		groupLabel: String(groupLabel(String(entry?.schemaTitle ?? entry?.schema ?? ''))),
		// Only when the platform says so. See the header: an effective value
		// is not the shipped default, and a guess would render as one.
		appDefault: channelIds.length > 0 ? appDefaultPerChannel(entry, channelIds) : appDefaultOf(entry),
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
 * The shipped default per channel. An opt-in channel is off by default; a
 * default channel follows the shipped on/off, when that is knowable.
 *
 * @param {object} entry - One entry.
 * @param {Array<string>} channelIds - The columns.
 * @return {object} `{ [channelId]: boolean }`.
 */
function appDefaultPerChannel(entry, channelIds) {
	const optIn = Array.isArray(entry?.optInChannels) ? entry.optInChannels : []
	const available = Array.isArray(entry?.availableChannels) ? entry.availableChannels : []
	const on = appDefaultOf(entry)
	return perChannel(channelIds, (id) => on && available.includes(id) && optIn.includes(id) === false)
}

/**
 * The four levels, and the refusals, keyed the way the screen wants them.
 *
 * @param {object} options - The call.
 * @param {Array<object>} [options.entries] - The platform's entries.
 * @param {string} [options.channelId] - The column these values sit in, with one column.
 * @param {Array<string>} [options.channelIds] - The columns, one per channel, when the platform names them.
 *
 * @return {object} `{ groupValues, personalValues, forcedValues, refusals }`.
 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
 */
export function valuesFrom({ entries = [], channelId = SINGLE_CHANNEL_ID, channelIds = [] } = {}) {
	const groupValues = {}
	const personalValues = {}
	const forcedValues = {}
	const refusals = {}

	for (const entry of entries) {
		const id = keyFor(entry)
		const source = String(entry?.source ?? '')

		const on = channelIds.length > 0
			? perChannel(channelIds, (channel) => channelsOnFor(entry).includes(channel))
			: { [channelId]: entry?.enabled === true }
		if (source === SOURCE_PERSONAL) {
			personalValues[id] = on
		} else if (source === SOURCE_GROUP) {
			groupValues[id] = on
		}

		const forced = entry?.forced
		if (forced !== undefined && forced !== null) {
			// FORCED CAN FORCE A CHANNEL OFF. Reading a forced row as "on"
			// would switch on a channel an administrator forbade, so the
			// value is taken from the row and never assumed.
			const cell = {
				value: forced?.value === true,
				by: String(forced?.by ?? ''),
				reason: String(forced?.reason ?? ''),
			}
			forcedValues[id] = perChannel(channelIds.length > 0 ? channelIds : [channelId], () => ({ ...cell }))
		}

		const refused = entry?.refused
		if (refused !== undefined && refused !== null) {
			refusals[id] = perChannel(channelIds.length > 0 ? channelIds : [channelId], () => ({ reason: String(refused?.reason ?? '') }))
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
 * @param {(title: string) => string} [options.groupLabel] - What to call a group.
 * @param {string} [options.singleChannelLabel] - The one column's name.
 * @param {(id: string) => string} [options.channelLabel] - What to call a channel column.
 *
 * @return {object} The component's props.
 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
 */
export function propsFor({
	entries = [],
	channels = [],
	label,
	groupLabel,
	singleChannelLabel,
	channelLabel = (id) => id,
} = {}) {
	const axis = channelAxisFrom(entries)
	const declared = axis.length > 0 && (Array.isArray(channels) === false || channels.length === 0)
		? axis.map((id) => ({ id, label: channelLabel(id) }))
		: channels
	const columns = channelsFrom({ channels: declared, singleChannelLabel })
	const channelIds = axis.length > 0 ? columns.map((column) => column.id) : []

	return {
		events: eventsFrom({ entries, label, groupLabel, channelIds }),
		channels: columns,
		...valuesFrom({ entries, channelId: columns[0].id, channelIds }),
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
 * @param {string} [options.channelId] - The column the cell is in.
 * @param {?Array<string>} [options.current] - The row's channels that are on now, with several columns.
 *
 * @return {?object} `{ schema, notification, enabled, scope, channels? }`, or null when
 *   the id is not one this screen made.
 *
 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
 */
export function writeFor({ eventId, scope = '', value, channelId = SINGLE_CHANNEL_ID, current = null } = {}) {
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

	// One cell of several channels: the write is the row's whole channel set
	// with this one switched, and the row is on while any channel is.
	if (channelId !== SINGLE_CHANNEL_ID && Array.isArray(current) === true) {
		const others = current.filter((id) => id !== channelId)
		write.channels = value === true ? [...others, channelId] : others
		write.enabled = write.channels.length > 0
	}

	if (scope !== '') {
		write.scope = `domain:${scope}`
	}

	return write
}
