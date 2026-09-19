<?php

/**
 * The one reader and writer of the sociaal domein schemas.
 *
 * 🔴 NOTHING IN lib/ READ THESE SCHEMAS AT ALL BEFORE THIS FILE. `gezinsplan`,
 * `sociaalDomeinAuditLog` and the three domain case schemas have been declared
 * in `register.d/50-sociaal-domein.json` since the register was written, and a
 * grep of `lib/` for either slug returned nothing on 2026-09-18. That is the
 * shape of the two rows this change closes: the register is ambitious and the
 * code behind it is not there, so a plan is a list of strings nobody reads and
 * an authorisation ground is a declared field nobody writes.
 *
 * 🔑 THE SLUGS ARE NOT IN `SchemaSlugMap`, AND THAT IS ON PURPOSE. That map
 * exists for the schemas whose uuid is stored in appconfig by the importer, and
 * the sociaal domein schemas have never been. They are addressed by SLUG here,
 * which `SearchesObjects` supports through OpenRegister's slug-aware bridge, so
 * this change needs no new appconfig key and no import step to run. A reader
 * looking for a `sociaal_domein_*_schema` key will not find one, and should not
 * add it: a second way of naming one schema is how two callers end up reading
 * two different objects.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\SociaalDomein
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\SociaalDomein;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\RefusesWhenIndeterminate;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read and write the sociaal domein objects, by slug.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */
class SociaalDomeinStore {

	use RefusesWhenIndeterminate;
	use SearchesObjects;

	/**
	 * The register these schemas live in.
	 *
	 * @var string
	 */
	public const REGISTER = 'dossiq';

	/**
	 * The three domains a person can have a case in, and how each names them.
	 *
	 * 🔴 THE BSN KEY IS NOT THE SAME WORD IN ALL THREE. `wmoZaak` and
	 * `participatiewetZaak` carry `bsn`; `jeugdwetZaak` carries `jeugdigeBsn`,
	 * because the person a Jeugdwet case is about is the juvenile and the
	 * schema says so. Filtering all three on `bsn` reads as a clean "no case
	 * exists in Jeugdwet" rather than as an error, which is the single worst
	 * answer this lookup can give: it tells a consulent nobody else is working
	 * with a household that somebody is. The key is therefore declared per
	 * domain here, beside the schema, so the two can never drift apart.
	 *
	 * Ordered, and the order is the answer's order, so two lookups of one
	 * person never read as two different answers because a store happened to
	 * return its rows the other way round.
	 *
	 * @var array<string, array{schema: string, bsnKey: string}>
	 */
	public const DOMAINS = [
		'Wmo' => ['schema' => 'wmoZaak', 'bsnKey' => 'bsn'],
		'Jeugdwet' => ['schema' => 'jeugdwetZaak', 'bsnKey' => 'jeugdigeBsn'],
		'Participatiewet' => ['schema' => 'participatiewetZaak', 'bsnKey' => 'bsn'],
	];

	/**
	 * The status every one of the three schemas spells its closed state with.
	 *
	 * All three enums end in the same literal, checked 2026-09-18. A domain
	 * that grows a second terminal status has to be added here, and the test
	 * that enumerates the enums is what will say so.
	 *
	 * @var string
	 */
	public const CLOSED_STATUS = 'closed';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register/schema configuration and the object service.
	 * @param LoggerInterface $logger          The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Rows of one schema matching these filters.
	 *
	 * 🔴 AN UNREADABLE STORE IS NOT AN EMPTY LIST, AND HERE IT REFUSES. A plan
	 * that could not be read and a household with no plan look identical as an
	 * empty array, and only one of them is safe to act on: a consulent shown an
	 * empty plan writes a new one over the plan that is already there.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The bare filter keys.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @throws RefusedException When the store cannot be asked.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
	 */
	public function rows(string $schema, array $filters = []): array {
		$objectService = $this->objectService();

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: self::REGISTER,
				schema: $schema,
				filters: $filters
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SociaalDomeinStore: ' . $schema . ' could not be read, so the read is refused rather than answered empty',
				['error' => $e->getMessage()]
			);

			$this->refuseIndeterminate(
				rule: 'sociaal-domein-unreadable',
				sentence: 'The social domain register could not be read, so nothing is being shown rather than an empty plan.',
				previous: $e,
			);
		}
	}//end rows()

	/**
	 * One row by its uuid, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The object uuid.
	 *
	 * @return array<string, mixed>|null The row.
	 *
	 * @throws RefusedException When the store cannot be asked.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
	 */
	public function read(string $schema, string $id): ?array {
		if (trim($id) === '') {
			return null;
		}

		$objectService = $this->objectService();

		try {
			return $this->findObjectAsArray(
				objectService: $objectService,
				register: self::REGISTER,
				schema: $schema,
				id: $id
			);
		} catch (Throwable $e) {
			$this->refuseIndeterminate(
				rule: 'sociaal-domein-unreadable',
				sentence: 'That record of the social domain register could not be read.',
				previous: $e,
			);
		}
	}//end read()

	/**
	 * Write one row, and refuse rather than answer null when it fails.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<string, mixed> The stored object.
	 *
	 * @throws RefusedException When the write fails.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
	 */
	public function write(string $schema, array $object): array {
		$objectService = $this->objectService();
		$declaredId = trim((string)($object['id'] ?? ''));
		$uuid = null;
		if ($declaredId !== '') {
			$uuid = $declaredId;
		}

		try {
			$saved = $this->saveObjectAsArray(
				objectService: $objectService,
				register: self::REGISTER,
				schema: $schema,
				object: $object,
				uuid: $uuid
			);
		} catch (Throwable $e) {
			$this->refuseIndeterminate(
				rule: 'sociaal-domein-not-written',
				sentence: 'That change to the social domain register could not be saved.',
				previous: $e,
			);
		}

		if ($saved === null) {
			// 🔴 NOT A SILENT NULL. `saveObjectAsArray` answers null when the
			// store hands back a shape it cannot read, and a caller that took
			// that as "nothing to do" would report a goal it never wrote.
			throw new RefusedException(
				rule: 'sociaal-domein-not-written',
				sentence: 'That change to the social domain register could not be saved.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		return $saved;
	}//end write()

	/**
	 * The object service, or a refusal naming what is missing.
	 *
	 * @return object The service.
	 *
	 * @throws RefusedException When OpenRegister is absent.
	 */
	private function objectService(): object {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RefusedException(
				rule: 'sociaal-domein-store-unavailable',
				sentence: 'The social domain register could not be reached.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		return $objectService;
	}//end objectService()
}//end class
