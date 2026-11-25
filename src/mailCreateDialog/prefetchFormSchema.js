// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Read an open-form action's schema into the object store before the dialog
 * opens, so the dialog's own open finds it there instead of reading it after
 * everything else the dialog waits for.
 *
 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
 */

/**
 * The store's type slug for a register and schema: the one already
 * registered for them, else `<register>/<schema>`, registered here. This is
 * the slug the library's action host resolves for the same pair, so both
 * read the same cached schema.
 *
 * @param {object} store The object store.
 * @param {string} register The register slug.
 * @param {string} schema The schema slug.
 * @return {string} The type slug.
 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
 */
export function formTypeSlug(store, register, schema) {
	const known = Object.entries(store.objectTypeRegistry || {})
		.find(([, config]) => config && String(config.register) === register && String(config.schema) === schema)
	if (known) {
		return known[0]
	}
	const slug = `${register}/${schema}`
	store.registerObjectType(slug, schema, register)
	return slug
}

/**
 * Read the action's schema into the store. Never fails: when the read does
 * not succeed, the dialog reads the schema itself as it always did.
 *
 * @param {object} store The object store.
 * @param {object} action The open-form action (`register`, `schema`).
 * @return {Promise<object|null>} The schema, or null.
 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
 */
export async function prefetchFormSchema(store, action) {
	const register = typeof action?.register === 'string' ? action.register : ''
	const schema = typeof action?.schema === 'string' ? action.schema : ''
	if (register === '' || schema === '' || schema.includes('/')) {
		return null
	}
	try {
		return await store.fetchSchema(formTypeSlug(store, register, schema))
	} catch {
		return null
	}
}

/**
 * Read the object a reference field opens on into the store, under the slug
 * the form dialog resolves its label by (`createObjectTypeSlug(register,
 * schema)`), so the dialog takes the name from the store's copy instead of
 * reading the object again after it mounts. Never fails: without the copy the
 * dialog reads the object itself as it always did.
 *
 * @param {object} store The object store.
 * @param {string} register The register slug.
 * @param {string} schema The referenced schema slug.
 * @param {string|undefined} id The referenced object's id.
 * @return {Promise<object|null>} The object, or null.
 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
 */
export async function prefetchReferencedObject(store, register, schema, id) {
	if (typeof id !== 'string' || id === '' || !register || !schema || typeof store?.createObjectTypeSlug !== 'function') {
		return null
	}
	try {
		const slug = store.createObjectTypeSlug(register, schema)
		if (!store.objectTypeRegistry?.[slug]) {
			store.registerObjectType(slug, schema, register)
		}
		return await store.fetchObject(slug, id)
	} catch {
		return null
	}
}
