<?php

/**
 * Dossiq Freeze Registry.
 *
 * Reads the `freeze[]` entries packages have configured and answers which
 * entry, if any, covers a record.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\FrozenRecord
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
 * @spec openspec/specs/frozen-record/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\FrozenRecord;

use InvalidArgumentException;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * The configured freeze entries, keyed by the schema they guard.
 *
 * WHERE THEY LIVE. In app config under `frozen_records`, a JSON list of
 * entries, which a package's `settings.json` writes (hydra design section 2:
 * settings go to IAppConfig under the package key). An entry's `schema` is a
 * schema id or slug, or `config:<key>` to name the app-config key that holds
 * the schema id, the way the rest of dossiq resolves its schemas.
 *
 * A BROKEN ENTRY IS SKIPPED AND LOGGED, NOT FATAL. Refusing every write on
 * the instance because one entry is malformed would turn a configuration
 * slip into an outage; the package installer validates each entry with
 * {@see FreezeDefinition::__construct()} before it writes, so a broken entry
 * here means someone edited app config by hand.
 *
 * @spec openspec/specs/frozen-record/spec.md
 */
class FreezeRegistry {

	/**
	 * The app-config key holding the entries.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'frozen_records';

	/**
	 * The prefix that makes an entry's schema an app-config key.
	 *
	 * @var string
	 */
	private const CONFIG_REFERENCE = 'config:';

	/**
	 * Entries read in this request, or null before the first read.
	 *
	 * @var array<int, FreezeDefinition>|null
	 */
	private ?array $definitions = null;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Reads app config.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The entry covering a record, by the schema in its `@self`.
	 *
	 * @param array<string, mixed> $record The record payload (incl. `@self`).
	 *
	 * @return FreezeDefinition|null The entry, or null when none covers it.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function forRecord(array $record): ?FreezeDefinition {
		$self = ($record['@self'] ?? []);
		$candidate = '';
		if (is_array($self) === true && is_scalar(($self['schema'] ?? null)) === true) {
			$candidate = (string)$self['schema'];
		}

		if ($candidate === '') {
			return null;
		}

		foreach ($this->definitions() as $definition) {
			$expected = $this->resolve(schema: $definition->schema);
			if ($expected !== '' && ($candidate === $expected || str_ends_with($candidate, '/' . $expected) === true)) {
				return $definition;
			}
		}

		return null;
	}//end forRecord()

	/**
	 * Every valid configured entry.
	 *
	 * @return array<int, FreezeDefinition> The entries.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function definitions(): array {
		if ($this->definitions !== null) {
			return $this->definitions;
		}

		$this->definitions = [];
		$raw = trim($this->settingsService->getConfigValue(self::CONFIG_KEY));
		if ($raw === '') {
			return $this->definitions;
		}

		$entries = json_decode($raw, true);
		if (is_array($entries) === false) {
			$this->logger->error('Dossiq frozen records: the configured entries are not a JSON list, so nothing is frozen by them');
			return $this->definitions;
		}

		foreach ($entries as $index => $entry) {
			try {
				$this->definitions[] = new FreezeDefinition(entry: (array)$entry);
			} catch (InvalidArgumentException $invalid) {
				$this->logger->error(
					'Dossiq frozen records: a configured entry is invalid and was skipped',
					['index' => $index, 'reason' => $invalid->getMessage()]
				);
			}
		}

		return $this->definitions;
	}//end definitions()

	/**
	 * The schema id or slug an entry names, resolving a `config:` reference.
	 *
	 * @param string $schema The entry's schema.
	 *
	 * @return string The id or slug, or '' when a reference resolves to nothing.
	 */
	private function resolve(string $schema): string {
		if (str_starts_with($schema, self::CONFIG_REFERENCE) === false) {
			return $schema;
		}

		return trim($this->settingsService->getConfigValue(substr($schema, strlen(self::CONFIG_REFERENCE))));
	}//end resolve()
}//end class
