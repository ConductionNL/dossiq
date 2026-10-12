<?php

/**
 * Dossiq withheld list recorder
 *
 * Hands a publication's withheld list to the catalogue that holds the
 * publication, through opencatalogi's one record door
 * (`OCA\OpenCatalogi\Service\Woo\WithheldDocuments::record()`, opencatalogi
 * woo-decision-shows-what-was-withheld REQ-WDW-002). Generic (decision 182):
 * any case type whose publication withholds documents records them here.
 *
 * The publication is never undone from here. A refused entry, an opencatalogi
 * without the door, or a door that throws all come back as an answer the
 * handler reads, not as an exception that rolls the publish back.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-decision-records-what-was-withheld/specs/publication-withheld-list/spec.md#requirement-a-publication-records-what-it-withheld-req-pwl-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Publication;

use OCA\Dossiq\AppInfo\Application;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records a publication's withheld list in opencatalogi.
 *
 * @spec openspec/changes/woo-decision-records-what-was-withheld/specs/publication-withheld-list/spec.md#requirement-a-publication-records-what-it-withheld-req-pwl-001
 */
class WithheldListRecorder {

	/**
	 * Opencatalogi's record door. Named as a string: the class is absent on an
	 * instance without opencatalogi, and a `use` of it would be fatal.
	 */
	public const RECORD_CLASS = 'OCA\OpenCatalogi\Service\Woo\WithheldDocuments';

	/**
	 * The source dossiq records under.
	 */
	public const SOURCE = 'dossiq';

	/**
	 * Opencatalogi is installed but has no record door.
	 */
	public const REASON_TOO_OLD = 'opencatalogi-too-old';

	/**
	 * The record door threw.
	 */
	public const REASON_FAILED = 'record-failed';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container   The DI container the record door resolves from.
	 * @param LoggerInterface    $logger      Logger.
	 * @param IL10N              $l10n        Translations for the sentence the case shows.
	 * @param string             $recordClass The record door's class; a test points it elsewhere.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
		private readonly string $recordClass = self::RECORD_CLASS,
	) {
	}//end __construct()

	/**
	 * Record the whole withheld list of a publication. An empty list clears it.
	 *
	 * @param string                                            $publicationId The publication.
	 * @param list<array{position: int, grounds: list<string>}> $entries       The entries.
	 *
	 * @return array<string, mixed> `{status: 'recorded', recorded, refused}` or `{status: 'not-recorded', reason}`.
	 *
	 * @spec openspec/changes/woo-decision-records-what-was-withheld/specs/publication-withheld-list/spec.md#requirement-a-publication-records-what-it-withheld-req-pwl-001
	 */
	public function record(string $publicationId, array $entries): array {
		if (class_exists($this->recordClass) === false) {
			return ['status' => 'not-recorded', 'reason' => self::REASON_TOO_OLD];
		}

		try {
			// A door that exists and will not resolve is a failure, not an old
			// opencatalogi: it is reported as one rather than swallowed.
			$door = $this->container->get($this->recordClass);
			if (is_object($door) === false || method_exists($door, 'record') === false) {
				return ['status' => 'not-recorded', 'reason' => self::REASON_TOO_OLD];
			}

			$answer = $door->record($publicationId, $entries, self::SOURCE);
		} catch (Throwable $e) {
			$this->logger->error(
				'The withheld list could not be recorded in opencatalogi; the publication stands',
				['app' => Application::APP_ID, 'publicationId' => $publicationId, 'error' => $e->getMessage()],
			);
			return ['status' => 'not-recorded', 'reason' => self::REASON_FAILED];
		}

		$refused = [];
		foreach ((array)($answer['refused'] ?? []) as $row) {
			if (is_array($row) === true) {
				$refused[] = [
					'position' => (int)($row['position'] ?? 0),
					'code' => (string)($row['code'] ?? ''),
					'reason' => (string)($row['reason'] ?? ''),
				];
			}
		}

		return [
			'status' => 'recorded',
			'recorded' => (int)($answer['recorded'] ?? 0),
			'refused' => $refused,
		];
	}//end record()

	/**
	 * The one sentence the case shows about its withheld list, '' when all went in.
	 *
	 * @param array<string, mixed> $answer What record() answered.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-decision-records-what-was-withheld/specs/woo-publication-via-opencatalogi/spec.md#requirement-without-opencatalogis-receiving-side-nothing-is-recorded-req-wrw-002
	 */
	public function describe(array $answer): string {
		if (($answer['status'] ?? '') !== 'recorded') {
			if (($answer['reason'] ?? '') === self::REASON_FAILED) {
				return $this->l10n->t(text: 'The withheld documents were not recorded: opencatalogi gave an error. The publication stands.');
			}

			return $this->l10n->t(text: 'The withheld documents were not recorded: this opencatalogi cannot record them yet. The publication stands.');
		}

		$refused = (array)($answer['refused'] ?? []);
		if ($refused === []) {
			return '';
		}

		$parts = [];
		foreach ($refused as $row) {
			$parts[] = $this->l10n->t(
				text: 'number %1$s (%2$s, %3$s)',
				parameters: [(string)($row['position'] ?? ''), (string)($row['code'] ?? ''), (string)($row['reason'] ?? '')]
			);
		}

		return $this->l10n->t(text: 'Opencatalogi did not record these withheld documents: %s.', parameters: [implode('; ', $parts)]);
	}//end describe()
}//end class
