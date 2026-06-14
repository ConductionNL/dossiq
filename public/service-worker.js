/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Service Worker for the mobiel-inspectie-offline PWA.
 *
 * Caching strategy (Workbox-style, hand-rolled to avoid a build-time Workbox
 * dependency in this app's webpack pipeline):
 *
 *  - APP SHELL + static assets (JS / CSS / images / map glyphs): cache-first,
 *    so the inspector's app boots offline.
 *  - SYNC + DATA API calls (GET /apps/procest/api/sync/...): network-first
 *    with a cache fallback, so the freshest planning is used when online and
 *    the last-known planning is served offline.
 *  - PDOK map tiles: cache-first (they are immutable for the cache window).
 *  - MUTATIONS (POST/PUT/PATCH/DELETE): never cached. The Vue layer queues
 *    them into IndexedDB (`src/store/offlineDb.js`) and replays them through
 *    `src/services/syncReplayService.js`; the Service Worker only signals the
 *    page that connectivity returned via a `background-sync` message.
 *
 * The replay logic itself lives in the page context (Dexie + the pure
 * `syncQueueEngine`), not here, so it stays unit-testable. This worker is the
 * thin offline-shell + cache layer and is exercised by Playwright, not vitest.
 */

const CACHE_VERSION = 'procest-mio-v1'
const SHELL_CACHE = `${CACHE_VERSION}-shell`
const DATA_CACHE = `${CACHE_VERSION}-data`
const TILE_CACHE = `${CACHE_VERSION}-tiles`

const APP_SCOPE = '/index.php/apps/procest/'
const OFFLINE_FALLBACK = `${APP_SCOPE}#/inspecties`

// Pre-cache the bare offline shell on install.
const PRECACHE_URLS = [
	APP_SCOPE,
	`${APP_SCOPE}js/procest-main.js`,
]

self.addEventListener('install', (event) => {
	event.waitUntil(
		caches.open(SHELL_CACHE)
			.then((cache) => cache.addAll(PRECACHE_URLS).catch(() => undefined))
			.then(() => self.skipWaiting()),
	)
})

self.addEventListener('activate', (event) => {
	event.waitUntil(
		caches.keys()
			.then((keys) => Promise.all(
				keys.filter((k) => k.startsWith('procest-mio-') && k.startsWith(CACHE_VERSION) === false)
					.map((k) => caches.delete(k)),
			))
			.then(() => self.clients.claim()),
	)
})

/**
 * Network-first with cache fallback (used for sync/data GETs).
 *
 * @param {Request} request The request to satisfy.
 * @param {string}  cacheName The cache to populate/serve.
 * @return {Promise<Response>} The freshest available response.
 */
async function networkFirst(request, cacheName) {
	const cache = await caches.open(cacheName)
	try {
		const response = await fetch(request)
		if (response && response.ok) {
			cache.put(request, response.clone())
		}
		return response
	} catch (e) {
		const cached = await cache.match(request)
		if (cached) {
			return cached
		}
		// Last resort: the offline app shell.
		const shell = await caches.match(OFFLINE_FALLBACK)
		return shell || Response.error()
	}
}

/**
 * Cache-first (used for static assets and immutable map tiles).
 *
 * @param {Request} request The request to satisfy.
 * @param {string}  cacheName The cache to populate/serve.
 * @return {Promise<Response>} The cached or freshly-fetched response.
 */
async function cacheFirst(request, cacheName) {
	const cache = await caches.open(cacheName)
	const cached = await cache.match(request)
	if (cached) {
		return cached
	}
	try {
		const response = await fetch(request)
		if (response && response.ok) {
			cache.put(request, response.clone())
		}
		return response
	} catch (e) {
		return Response.error()
	}
}

self.addEventListener('fetch', (event) => {
	const { request } = event
	const url = new URL(request.url)

	// Only ever cache safe GETs. Mutations pass straight through; the page
	// queues them into IndexedDB when the network is down.
	if (request.method !== 'GET') {
		return
	}

	// PDOK map tiles → cache-first.
	if (/(brtachtergrondkaart|wmts|pdok|service\.pdok\.nl)/i.test(url.host + url.pathname)) {
		event.respondWith(cacheFirst(request, TILE_CACHE))
		return
	}

	// Sync + data API → network-first with offline fallback.
	if (url.pathname.includes('/apps/procest/api/sync')
		|| url.pathname.includes('/apps/openregister/api/objects')) {
		event.respondWith(networkFirst(request, DATA_CACHE))
		return
	}

	// App-scoped static assets → cache-first.
	if (url.pathname.startsWith('/apps/procest/') || url.pathname.startsWith(APP_SCOPE)) {
		event.respondWith(cacheFirst(request, SHELL_CACHE))
	}
})

// Tell the page connectivity returned so it can drain the IndexedDB queue.
self.addEventListener('message', (event) => {
	if (event.data && event.data.type === 'PING_ONLINE') {
		self.clients.matchAll().then((clients) => {
			clients.forEach((client) => client.postMessage({ type: 'DRAIN_QUEUE' }))
		})
	}
})
