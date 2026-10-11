// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Reports the pages a reviewer displayed to the document review, each page once.
 *
 * Generic (decision 182): any viewer that knows which page it shows calls
 * `displayed(page, pageCount)` as a page comes into view. The reporter collects
 * the pages and posts them in one call per short pause, so paging quickly
 * through a long document does not send one request per page, and a page seen
 * twice in one viewing is reported once.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The route the pages are reported to.
 *
 * @param {string} caseId      The case.
 * @param {string} documentRef The document.
 * @return {string} The url.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
 */
export function pagesSeenUrl(caseId, documentRef) {
	return generateUrl(
		`/apps/dossiq/api/cases/${encodeURIComponent(caseId)}/review/documents/${encodeURIComponent(documentRef)}/pages-seen`,
	)
}

/**
 * A reporter for one document in one viewing.
 *
 * @param {object}   options             The options.
 * @param {string}   options.caseId      The case.
 * @param {string}   options.documentRef The document.
 * @param {number}   [options.delay]     How long to wait for more pages before posting, in ms.
 * @param {(review: object) => void} [options.onSaved]   Called with the saved review (with `unseen`) after each post.
 * @param {(error: Error) => void} [options.onError]   Called with the error when a post fails.
 * @return {{displayed: (page: number, count?: number) => void, flush: () => Promise<object|null>, reported: () => Array<number>}} The reporter.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
 */
export function createPagesSeenReporter({ caseId, documentRef, delay = 400, onSaved = () => {}, onError = () => {} }) {
	const reported = new Set()
	let pending = new Set()
	let pageCount = null
	let timer = null

	/**
	 * Post what is pending, if anything.
	 *
	 * @return {Promise<object|null>} The saved review, or null when nothing was pending.
	 */
	async function flush() {
		if (timer !== null) {
			clearTimeout(timer)
			timer = null
		}
		if (pending.size === 0) {
			return null
		}
		const pages = [...pending].sort((a, b) => a - b)
		pending = new Set()
		pages.forEach((page) => reported.add(page))
		const body = { pages }
		if (pageCount !== null) {
			body.pageCount = pageCount
		}
		try {
			const { data } = await axios.post(pagesSeenUrl(caseId, documentRef), body)
			onSaved(data)
			return data
		} catch (error) {
			// Not seen as far as the server knows: let the next display report them again.
			pages.forEach((page) => reported.delete(page))
			onError(error)
			return null
		}
	}

	/**
	 * A page came into view.
	 *
	 * @param {number}      page    The page number, from 1.
	 * @param {number|null} [count] The document's page count, when the viewer knows it.
	 * @return {void}
	 */
	function displayed(page, count = null) {
		const number = Number(page)
		if (!Number.isInteger(number) || number < 1) {
			return
		}
		if (Number.isInteger(Number(count)) && Number(count) > 0) {
			pageCount = Number(count)
		}
		if (reported.has(number) || pending.has(number)) {
			return
		}
		pending.add(number)
		if (timer !== null) {
			clearTimeout(timer)
		}
		timer = setTimeout(() => {
			flush()
		}, delay)
	}

	return {
		displayed,
		flush,
		reported: () => [...reported].sort((a, b) => a - b),
	}
}
