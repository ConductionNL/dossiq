<?php

/**
 * Dossiq case timeline writer.
 *
 * The one seam in this app that writes a timeline entry. Every writer that
 * records a communication on a case calls this class, and nothing else in
 * dossiq knows how an entry is stored, because it is not stored here:
 * OpenRegister owns the record (`timeline-entries-are-records`, #3762) and
 * dossiq consumes it (ADR-022).
 *
 * IN PROCESS, NEVER OVER HTTP. ADR-080 D2/D3 forbids an app addressing its
 * own instance over HTTP, so the write goes through OpenRegister's
 * `TimelineWriteService` resolved from the container, the same lazy resolve
 * `SettingsService::getObjectService()` already uses. OpenRegister is an
 * optional runtime dependency and an instance carrying an older one has no
 * such class; asking the container at call time is what keeps that instance
 * sending mail instead of fatalling.
 *
 * IT NEVER THROWS AT ITS CALLERS. An entry records something that has
 * already happened: the mail went out, the call was taken, the statutory
 * acknowledgement was sent. Refusing the act because the log could not be
 * written would trade a missing line for a broken duty. So every path
 * answers the entry id or the empty string and logs its own failure at
 * warning, naming the case and the kind, so a timeline that silently stops
 * being written is visible in the log rather than only on the page.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Timeline
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Timeline;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes one timeline entry per recorded communication.
 *
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */
class CaseTimeline {

	/**
	 * OpenRegister's timeline writer, as a class name so this app never
	 * imports a class an older OpenRegister does not ship.
	 *
	 * @var string
	 */
	public const WRITE_SERVICE = 'OCA\\OpenRegister\\Service\\Timeline\\TimelineWriteService';

	/**
	 * An entry only the handling organisation reads.
	 *
	 * @var string
	 */
	public const INTERNAL = 'internal';

	/**
	 * An entry the applicant may read too.
	 *
	 * @var string
	 */
	public const PUBLIC_ENTRY = 'public';

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settings  Resolves the register, the case schema and the object service.
	 * @param ContainerInterface $container The DI container, for the lazy resolve.
	 * @param LoggerInterface    $logger    Logger for the failures that stay soft.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this instance can carry a timeline at all.
	 *
	 * Asked before a caller bothers building a payload, and asked again
	 * inside {@see self::record()} because the answer can change between the
	 * two: an admin can disable OpenRegister while a batch is running.
	 *
	 * @return boolean True when an entry can be written.
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function isAvailable(): bool {
		return $this->writer() !== null;
	}//end isAvailable()

	/**
	 * Record one entry on a case, and optionally on its related cases.
	 *
	 * @param string               $caseId         The case the entry hangs on.
	 * @param string               $kind           The declared kind, e.g. `contactmoment`.
	 * @param string               $message        The text a handler reads.
	 * @param array<string, mixed> $fields         The values the kind declares. Anything else is dropped by OpenRegister.
	 * @param string               $visibility     `internal` or `public`.
	 * @param array<int, string>   $relatedCaseIds Further cases the same entry belongs on.
	 *
	 * @return string The entry uuid, or '' when nothing was written.
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function record(
		string $caseId,
		string $kind,
		string $message,
		array $fields = [],
		string $visibility = self::INTERNAL,
		array $relatedCaseIds = [],
	): string {
		if (trim($caseId) === '' || trim($kind) === '') {
			return '';
		}

		$writer = $this->writer();
		if ($writer === null) {
			return '';
		}

		$coordinates = $this->coordinates();
		if ($coordinates === null) {
			$this->soften(caseId: $caseId, kind: $kind, reason: 'the register or the case schema is not configured');
			return '';
		}

		[$objectService, $register, $schema] = $coordinates;

		try {
			$object = $objectService->find($caseId, register: $register, schema: $schema);
			if ($object === null) {
				$this->soften(caseId: $caseId, kind: $kind, reason: 'the case could not be read');
				return '';
			}

			$data = [
				'message' => $message,
				'kind' => $kind,
				'fields' => $fields,
				'visibility' => $visibility,
				'register' => (string)$register,
				'schema' => (string)$schema,
			];

			$related = $this->relatedObjects(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				caseId: $caseId,
				relatedCaseIds: $relatedCaseIds,
			);

			if ($related === []) {
				return (string)$writer->write(object: $object, data: $data)->getUuid();
			}

			$written = $writer->writeToMany(objects: array_merge([$object], $related), data: $data);
			if ($written === []) {
				return '';
			}

			return (string)$written[0]->getUuid();
		} catch (Throwable $e) {
			$this->soften(caseId: $caseId, kind: $kind, reason: $e->getMessage());
			return '';
		}//end try
	}//end record()

	/**
	 * Resolve the further cases an entry also belongs on.
	 *
	 * A case that cannot be read is LEFT OUT rather than written as null:
	 * `writeToMany` takes resolved objects, and handing it a hole would write
	 * the note on some of the cases and fail on the rest, which is the exact
	 * half-write the endpoint's own contract refuses.
	 *
	 * @param object             $objectService  OpenRegister's object service.
	 * @param mixed              $register       The register as configured.
	 * @param mixed              $schema         The case schema as configured.
	 * @param string             $caseId         The case already resolved, never repeated.
	 * @param array<int, string> $relatedCaseIds The further cases.
	 *
	 * @return array<int, object> The resolved objects.
	 */
	private function relatedObjects(
		object $objectService,
		$register,
		$schema,
		string $caseId,
		array $relatedCaseIds,
	): array {
		$resolved = [];
		$seen = [$caseId => true];

		foreach ($relatedCaseIds as $relatedId) {
			$relatedId = trim((string)$relatedId);
			if ($relatedId === '' || isset($seen[$relatedId]) === true) {
				continue;
			}

			$seen[$relatedId] = true;

			try {
				$object = $objectService->find($relatedId, register: $register, schema: $schema);
			} catch (Throwable $e) {
				$object = null;
			}

			if ($object === null) {
				$this->logger->warning(
					'Dossiq timeline: related case {case} could not be read, so the entry was not written on it',
					['app' => Application::APP_ID, 'case' => $relatedId],
				);
				continue;
			}

			$resolved[] = $object;
		}//end foreach

		return $resolved;
	}//end relatedObjects()

	/**
	 * OpenRegister's timeline writer, or null when this instance has none.
	 *
	 * @return object|null The writer.
	 */
	private function writer(): ?object {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			$writer = $this->container->get(self::WRITE_SERVICE);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($writer) === false) {
			return null;
		}

		return $writer;
	}//end writer()

	/**
	 * The object service, register and case schema, or null when unconfigured.
	 *
	 * @return array{0: object, 1: mixed, 2: mixed}|null The three, or null.
	 */
	private function coordinates(): ?array {
		$objectService = $this->settings->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settings->getConfigValue('register');
		$schema = $this->settings->getConfigValue('case_schema');
		if ((string)$register === '' || (string)$schema === '') {
			return null;
		}

		return [$objectService, $register, $schema];
	}//end coordinates()

	/**
	 * Log a write that did not happen, without failing the act it recorded.
	 *
	 * @param string $caseId The case.
	 * @param string $kind   The kind that was not written.
	 * @param string $reason Why.
	 *
	 * @return void
	 */
	private function soften(string $caseId, string $kind, string $reason): void {
		$this->logger->warning(
			'Dossiq timeline: no {kind} entry was written on case {case}, {reason}',
			[
				'app' => Application::APP_ID,
				'kind' => $kind,
				'case' => $caseId,
				'reason' => $reason,
			],
		);
	}//end soften()
}//end class
