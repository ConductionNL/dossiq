<?php

/**
 * The plumbing every register-backed queue source shares.
 *
 * Each source is then four short methods and one filter, which is the point:
 * a source that takes an afternoon to write is a source somebody will skip in
 * favour of adding a query to the page.
 *
 * A source THROWS when it cannot read. It never answers an empty list on
 * failure, because the queue reads an empty list as "nothing is waiting on
 * you" and prints exactly that. ADR-102.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue\Source
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
 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue\Source;

use OCA\Dossiq\Service\Queue\QueueSource;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * A queue source that reads OpenRegister objects.
 *
 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
 */
abstract class RegisterBackedSource implements QueueSource {
	use SearchesObjects;

	/**
	 * How many rows one source may contribute.
	 *
	 * A queue is read top to bottom, so a source that answers two thousand
	 * rows is not more complete, it is slower and identical on screen.
	 *
	 * @var int
	 */
	protected const LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings The register configuration and the ObjectService bridge.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function __construct(
		protected readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * The register every dossiq object lives in.
	 *
	 * @return string The register slug or id.
	 *
	 * @throws RuntimeException When the app is not configured.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	protected function register(): string {
		$register = trim((string)$this->settings->getConfigValue('register'));
		if ($register === '') {
			throw new RuntimeException('Dossiq has no register configured yet.');
		}

		return $register;
	}//end register()

	/**
	 * OpenRegister's object service, or a refusal naming why there is none.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException When OpenRegister is absent or unreachable.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	protected function objects(): object {
		$service = $this->settings->getObjectService();
		if ($service === null) {
			throw new RuntimeException('OpenRegister is not available, so this source cannot be read.');
		}

		return $service;
	}//end objects()

	/**
	 * Read one schema with a filter.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filter, in OpenRegister's object-field grammar.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @throws RuntimeException When the read fails.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	protected function rows(string $schema, array $filters): array {
		$filters['_limit'] = self::LIMIT;

		try {
			return $this->searchObjectsAsArrays(
				objectService: $this->objects(),
				register: $this->register(),
				schema: $schema,
				filters: $filters
			);
		} catch (RuntimeException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new RuntimeException($e->getMessage(), 0, $e);
		}
	}//end rows()

	/**
	 * The object's own id, whichever shape the row came back in.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or an empty string when the row carries none.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	protected function idOf(array $row): string {
		$self = ($row['@self'] ?? []);
		if (is_array($self) === true && trim((string)($self['id'] ?? '')) !== '') {
			return (string)$self['id'];
		}

		return (string)($row['id'] ?? ($row['uuid'] ?? ''));
	}//end idOf()

	/**
	 * One date off a row, or null when the row carries none.
	 *
	 * An empty string is null rather than a date, because the scorer reads an
	 * empty string as an unparseable date and would tier the item as normal
	 * either way. Saying null once here keeps that from being a coincidence.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param string               $key The property holding the date.
	 *
	 * @return string|null The date, or null.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	protected function dateOf(array $row, string $key): ?string {
		$value = $row[$key] ?? null;
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end dateOf()
}//end class
