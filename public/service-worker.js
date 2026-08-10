/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Service Worker for the mobiel-inspectie-offline PWA.
 *
 * IMPORTANT SCOPE NOTE
 * --------------------
 * This worker is registered at the app-root scope (`/apps/procest/`), so it
 * controls EVERY procest page and sees EVERY fetch those pages make — including
 * the OpenRegister data calls (`/apps/openregister/api/objects/...`) and the
 * app-shell/navigation loads that the dashboard, case lists and task lists all
 * depend on. It must therefore only ever intercept the handful of requests the
 * offline field-inspection feature genuinely owns, and pass EVERYTHING else
 * straight through to the browser's own network stack. Intercepting general
 * app/data traffic here previously broke the whole app: re-issuing
 * `fetch(request)` inside the worker throws for the credentialed cross-app
 * OpenRegister requests, and the network-first fallback turned that into a
 * `Response.error()` (surfacing in the page as `TypeError: Failed to fetch`).
 *
 * The reason a re-issued `fetch()` throws is now measured rather than assumed:
 * a Service Worker inherits the CSP of its OWN script response, and Nextcloud
 * served `/apps/procest/service-worker.js` with `default-src 'none'` and no
 * `connect-src`, so EVERY fetch the worker made was blocked. Every request the
 * worker claimed with `respondWith()` therefore became a network error; the
 * worker could only ever break a request, never serve one. That is fixed in
 * `DashboardController::serviceWorker()`, which now sends a `connect-src` the
 * two strategies below can actually use — but the rule above stands: claim
 * nothing this feature does not own.
 *
 * Caching strategy (Workbox-style, hand-rolled to avoid a build-time Workbox
 * dependency in this app's webpack pipeline):
 *
 *  - SYNC API calls (GET /apps/procest/api/sync/...): network-first with a
 *    cache fallback, so the freshest planning is used when online and the
 *    last-known planning is served offline.
 *  - PDOK map tiles: cache-first (they are immutable for the cache window).
 *  - EVERYTHING ELSE (app shell, static assets, OpenRegister data, navigation):
 *    pass-through — the worker does NOT call respondWith(), so the browser
 *    fetches normally. App-shell caching is deliberately NOT done here: the SPA
 *    routes on the URL hash (`#/inspecties`), which the worker cannot see, so a
 *    scoped offline shell can't be distinguished from any other route anyway,
 *    and blanket asset caching would also serve stale JS across deploys.
 *  - MUTATIONS (POST/PUT/PATCH/DELETE): never cached. The Vue layer queues
 *    them into IndexedDB (`src/store/offlineDb.js`) and replays them through
 *    `src/services/syncReplayService.js`; the Service Worker only signals the
 *    page that connectivity returned via a `background-sync` message.
 *
 * The replay logic itself lives in the page context (Dexie + the pure
 * `syncQueueEngine`), not here, so it stays unit-testable. This worker is the
 * thin offline sync + tile cache layer and is exercised by Playwright, not vitest.
 */

const CACHE_VERSION = 'procest-mio-v2'
const DATA_CACHE = `${CACHE_VERSION}-data`
const TILE_CACHE = `${CACHE_VERSION}-tiles`

/**
 * The third-party hosts this app loads map tiles from.
 *
 * `service.pdok.nl` serves the BRT achtergrondkaart WMTS layer used by
 * `src/components/map/LocationPicker.vue`. It is deliberately an exact-host
 * allow-list rather than a substring test — see `isMapTileRequest()`.
 */
const TILE_HOSTS = new Set(['service.pdok.nl'])

/**
 * Is this request one of the map tiles the offline layer owns?
 *
 * Tiles are ALWAYS third-party, so a request back to this Nextcloud is never a
 * tile. That guard is the whole point of this function.
 *
 * ⚠️ REGRESSION THIS REPLACES. The predicate used to be
 * `/(brtachtergrondkaart|wmts|pdok|service\.pdok\.nl)/i.test(url.host + url.pathname)`
 * — a substring test over the SAME-ORIGIN path as well as the host. It
 * therefore matched this app's own address lookups, which since the
 * migrate-pdok-to-openconnector change live at
 * `/index.php/apps/openconnector/api/pdok/{suggest,lookup,free,reverse}`, on
 * the literal `pdok`. Every one of them was answered cache-first out of the
 * TILE cache, and since a worker cannot reach the network under its script's
 * `default-src 'none'` CSP (see DashboardController::serviceWorker()) the
 * caller got `TypeError: Failed to fetch` instead of the 503/404 degradation
 * `src/services/pdokService.js` implements — which rethrows on an error with
 * no HTTP status, so the address field broke outright.
 *
 * @param {URL} url The parsed request URL.
 * @return {boolean} True when the request is a third-party map tile.
 */
function isMapTileRequest(url) {
	return url.origin !== self.location.origin && TILE_HOSTS.has(url.hostname)
}

self.addEventListener('install', (event) => {
	event.waitUntil(self.skipWaiting())
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
 * Network-first with cache fallback (used for sync GETs).
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
		return cached || Response.error()
	}
}

/**
 * Cache-first (used for immutable map tiles).
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

	// Only ever touch safe GETs. Mutations pass straight through; the page
	// queues them into IndexedDB when the network is down.
	if (request.method !== 'GET') {
		return
	}

	// PDOK map tiles → cache-first. Third-party tile hosts ONLY: a request back
	// to this Nextcloud is never a tile, whatever its path happens to spell.
	if (isMapTileRequest(url)) {
		event.respondWith(cacheFirst(request, TILE_CACHE))
		return
	}

	// procest offline-inspection sync API → network-first with cache fallback.
	// NB: deliberately scoped to the inspection sync endpoints only. OpenRegister
	// data calls and app-shell/navigation loads are intentionally NOT handled
	// here — they fall through to the browser's own network stack.
	if (url.pathname.includes('/apps/procest/api/sync')) {
		event.respondWith(networkFirst(request, DATA_CACHE))
	}

	// Everything else: pass-through (no respondWith) — the browser fetches normally.
})

// Tell the page connectivity returned so it can drain the IndexedDB queue.
self.addEventListener('message', (event) => {
	if (event.data && event.data.type === 'PING_ONLINE') {
		self.clients.matchAll().then((clients) => {
			clients.forEach((client) => client.postMessage({ type: 'DRAIN_QUEUE' }))
		})
	}
})
