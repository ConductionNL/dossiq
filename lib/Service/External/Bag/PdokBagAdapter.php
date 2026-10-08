<?php

/**
 * BAG lookups for an instance that has no Kadaster key.
 *
 * `BagAdapterInterface` has had exactly two implementations: `BagApiAdapter`,
 * the paid Kadaster Bevragingen v2, and `LogBagAdapter`, which logs the
 * intention and answers LOOKUP_DEFERRED. So on every instance that has not
 * bought a Kadaster key — which is every instance today, because `log` is the
 * default tier — `LocationBagValidationListener` asks whether an address exists
 * and is told "deferred", and the address is accepted unchecked.
 *
 * `PdokBagService` has spoken the free PDOK BAG WFS mirror since 2026-05-19,
 * with the same three object lookups and no caller of any kind. This adapter is
 * that caller. It is a THIRD implementation and not a replacement, and the
 * distinction is the one `BagAdapterInterface` and `BagRegistrar` already
 * state: Kadaster's Bevragingen v2 is authoritative and paid, PDOK's WFS is the
 * free open mirror. Neither supersedes the other.
 *
 * 🔴 DORMANT IS STILL THE DEFAULT. Binding this in the `log` tier
 * unconditionally would turn every fresh install into something that makes
 * outbound calls to a government service nobody asked it to call. It is
 * selected by `integration.bag.source = pdok`, one key, and the tier model is
 * untouched.
 *
 * 🔴 WHAT IS GROUNDED HERE AND WHAT IS NOT. The verdict — FOUND, NOT_FOUND or
 * LOOKUP_ERROR — is exactly what `LocationBagValidationListener` acts on, and
 * it is grounded: `PdokBagService` now throws when it cannot reach PDOK and
 * answers `[]` only when PDOK says there is no such object. The FIELD NAMES in
 * the mapped address are NOT verified against a live PDOK response, because
 * this repository holds no recorded one. `BagResponseMapper` reads several
 * spellings and yields null for anything it does not recognise, so an
 * unrecognised field degrades to an absent value and never to a wrong one.
 * Recording one WFS response as a fixture is what would finish this.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Bag
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/pdok-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Bag;

use OCA\Dossiq\Service\Pdok\PdokBagService;
use OCA\Dossiq\Service\Pdok\PdokLocatieserverService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The free PDOK BAG mirror, behind the BAG port.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/pdok-integration/spec.md
 */
class PdokBagAdapter implements BagAdapterInterface {

	/**
	 * The config value of `integration.bag.source` that selects this adapter.
	 *
	 * @var string
	 */
	public const SOURCE = 'pdok';

	/**
	 * Which PdokBagService method answers each BAG object type.
	 *
	 * @var array<string, string>
	 */
	private const OBJECT_METHODS = [
		'nummeraanduiding' => 'getNummeraanduiding',
		'verblijfsobject' => 'getVerblijfsobject',
		'pand' => 'getPand',
	];

	/**
	 * Constructor.
	 *
	 * @param PdokBagService           $bag           The free BAG WFS mirror.
	 * @param PdokLocatieserverService $locatieserver Free postcode and house-number geocoding.
	 * @param BagResponseMapper        $mapper        The shape every BAG answer is normalised into.
	 * @param LoggerInterface          $logger        Logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pdok-integration/spec.md
	 */
	public function __construct(
		private readonly PdokBagService $bag,
		private readonly PdokLocatieserverService $locatieserver,
		private readonly BagResponseMapper $mapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Find an address by postcode and house number.
	 *
	 * Answered by the PDOK Locatieserver rather than the BAG WFS: the WFS
	 * filters on a BAG identificatie and the Locatieserver is the free service
	 * that takes a postcode, which is what a person types.
	 *
	 * @param string               $postcode    Dutch postcode.
	 * @param string               $houseNumber House number.
	 * @param string|null          $huisletter  House letter, when there is one.
	 * @param string|null          $toevoeging  House-number addition, when there is one.
	 * @param array<string, mixed> $context     Lookup context, for the log.
	 *
	 * @return BagLookupResult The outcome.
	 *
	 * @spec openspec/specs/pdok-integration/spec.md
	 */
	public function lookupAddress(
		string $postcode,
		string $houseNumber,
		?string $huisletter = null,
		?string $toevoeging = null,
		array $context = [],
	): BagLookupResult {
		$postcode = strtoupper(str_replace(' ', '', trim($postcode)));
		if ($postcode === '' || trim($houseNumber) === '') {
			return new BagLookupResult(
				lookupStatus: 'INVALID_INPUT',
				address: [],
				dormant: false,
				extras: ['reason' => 'postcode-and-house-number-are-both-required'],
			);
		}

		$query = $postcode . ' ' . trim($houseNumber) . (string)$huisletter . (string)$toevoeging;

		try {
			$response = $this->locatieserver->free(query: $query, filterQueries: ['type:adres']);
		} catch (Throwable $e) {
			return $this->unreachable(what: 'address', detail: $e->getMessage(), context: $context);
		}

		// 🔴 AN ABSENT `response` ENVELOPE IS NOT AN EMPTY RESULT. The
		// Locatieserver client answers [] when it could not call at all, and a
		// real answer always carries the envelope, empty or not. Reading the
		// two the same way would report every outage as "no such address".
		if (isset($response['response']) === false) {
			return $this->unreachable(what: 'address', detail: 'no-response-envelope', context: $context);
		}

		$docs = ($response['response']['docs'] ?? []);
		if (is_array($docs) === false || $docs === []) {
			return new BagLookupResult(lookupStatus: 'NOT_FOUND', address: [], dormant: false);
		}

		$matches = $this->mapper->mapMany(rawList: $docs);

		return new BagLookupResult(
			lookupStatus: 'FOUND',
			address: $matches[0],
			dormant: false,
			extras: ['source' => self::SOURCE, 'count' => count($matches), 'matches' => $matches],
		);
	}//end lookupAddress()

	/**
	 * Find one BAG object by its identificatie.
	 *
	 * @param string               $objectType `nummeraanduiding`, `verblijfsobject` or `pand`.
	 * @param string               $id         BAG identificatie.
	 * @param array<string, mixed> $context    Lookup context, for the log.
	 *
	 * @return BagLookupResult The outcome.
	 *
	 * @spec openspec/specs/pdok-integration/spec.md
	 */
	public function lookupObject(string $objectType, string $id, array $context = []): BagLookupResult {
		$method = (self::OBJECT_METHODS[$objectType] ?? null);
		if ($method === null || trim($id) === '') {
			return new BagLookupResult(
				lookupStatus: 'INVALID_INPUT',
				address: [],
				dormant: false,
				extras: ['reason' => 'invalid-object-type-or-id'],
			);
		}

		try {
			$raw = $this->bag->{$method}(trim($id));
		} catch (Throwable $e) {
			return $this->unreachable(what: $objectType, detail: $e->getMessage(), context: $context);
		}

		if ($raw === []) {
			return new BagLookupResult(lookupStatus: 'NOT_FOUND', address: [], dormant: false);
		}

		return new BagLookupResult(
			lookupStatus: 'FOUND',
			address: $this->mapper->map(raw: $raw),
			dormant: false,
			extras: ['source' => self::SOURCE],
		);
	}//end lookupObject()

	/**
	 * This adapter calls PDOK, so it is not dormant.
	 *
	 * @return bool Always FALSE.
	 *
	 * @spec openspec/specs/pdok-integration/spec.md
	 */
	public function isDormant(): bool {
		return false;
	}//end isDormant()

	/**
	 * The answer when PDOK could not be reached.
	 *
	 * LOOKUP_ERROR and never NOT_FOUND. The listener that consumes this rejects
	 * a location it cannot find, and telling it "not found" while PDOK is down
	 * would reject every address a handler typed, with a sentence saying the
	 * building does not exist.
	 *
	 * @param string               $what    What was being looked up.
	 * @param string               $detail  What went wrong.
	 * @param array<string, mixed> $context Lookup context.
	 *
	 * @return BagLookupResult The error outcome.
	 */
	private function unreachable(string $what, string $detail, array $context): BagLookupResult {
		$this->logger->warning(
			'Dossiq PDOK BAG lookup could not reach PDOK',
			['what' => $what, 'detail' => $detail, 'context' => $context],
		);

		return new BagLookupResult(
			lookupStatus: 'LOOKUP_ERROR',
			address: [],
			dormant: false,
			extras: ['reason' => 'transport-error', 'source' => self::SOURCE],
		);
	}//end unreachable()
}//end class
