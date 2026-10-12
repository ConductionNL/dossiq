<?php

/**
 * Dossiq collection case links.
 *
 * A resident can start a case from a collection they keep of published
 * documents. This class reads that collection only when it is the resident's
 * own, writes one case object per item that names a publication, and records
 * the case on the collection's `sourceOf`, once. The collection's register and
 * schema, and the publication's, are configuration.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-only-the-owners-dossier-starts-a-request-req-wri-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Support\UnscopedObjectReader;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Links a resident's own collection of publications to the case it started.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-dossier-records-the-request-it-started-req-wri-004
 */
class CollectionCaseLinks {

	use SearchesObjects;

	/**
	 * The case object type of a publication from a collection.
	 *
	 * @var string
	 */
	public const OBJECT_TYPE = 'opencatalogi.publication';

	/**
	 * The prefix of the reference a collection keeps in `sourceOf`.
	 *
	 * @var string
	 */
	public const SOURCE_PREFIX = 'dossiq:case:';

	/**
	 * Reads the collection and the publications as the system, unscoped.
	 *
	 * @var UnscopedObjectReader
	 */
	private readonly UnscopedObjectReader $reader;

	/**
	 * Constructor.
	 *
	 * @param SettingsService           $settingsService Schemas, and the collection and publication configuration.
	 * @param IURLGenerator             $urlGenerator    Makes the publication links absolute.
	 * @param LoggerInterface           $logger          Logger.
	 * @param UnscopedObjectReader|null $reader          Reads unscoped; a fresh one when null.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
		?UnscopedObjectReader $reader = null,
	) {
		$this->reader = ($reader ?? new UnscopedObjectReader());
	}//end __construct()

	/**
	 * The resident's own collection, or null when it is absent or someone else's.
	 *
	 * Absent and not-yours answer the same, so the caller's refusal does not
	 * say whether the collection exists.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $collectionId  The collection uuid.
	 * @param string $subjectRef    The resident.
	 *
	 * @return array<string, mixed>|null The collection, with its `id`.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-only-the-owners-dossier-starts-a-request-req-wri-003
	 */
	public function owned(object $objectService, string $collectionId, string $subjectRef): ?array {
		$collection = null;
		try {
			$collection = $this->reader->find(
				objectService: $objectService,
				register: $this->settingsService->getWooPublicationConfigValue('woo_collection_register'),
				schema: $this->settingsService->getWooPublicationConfigValue('woo_collection_schema'),
				id: $collectionId,
			);
		} catch (Throwable $e) {
			$this->logger->warning('CollectionCaseLinks: the collection could not be read', ['collection' => $collectionId, 'error' => $e->getMessage()]);
		}

		if (is_array($collection) === false || (string)($collection['owner'] ?? '') !== $subjectRef) {
			return null;
		}

		$collection['id'] = $collectionId;

		return $collection;
	}//end owned()

	/**
	 * Link the collection to the case: one case object per item, then the
	 * case on the collection's `sourceOf`.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param string               $register      The dossiq register.
	 * @param string               $caseId        The case.
	 * @param array<string, mixed> $collection    The collection as owned() read it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-dossier-records-the-request-it-started-req-wri-004
	 */
	public function link(object $objectService, string $register, string $caseId, array $collection): void {
		$this->writeCaseObjects(objectService: $objectService, register: $register, caseId: $caseId, collection: $collection);
		$this->recordSource(objectService: $objectService, collection: $collection, caseId: $caseId);
	}//end link()

	/**
	 * The public path of a publication, the same one the publish side links to.
	 *
	 * @param string $publicationId The publication uuid.
	 *
	 * @return string The path, starting with `/index.php`.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
	 */
	public function publicationPath(string $publicationId): string {
		$catalogSlug = $this->settingsService->getConfigValue('woo_publication_catalog_slug', 'publication');

		return '/index.php/apps/opencatalogi/' . $catalogSlug . '/' . $publicationId;
	}//end publicationPath()

	/**
	 * One case object per collection item.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param string               $register      The dossiq register.
	 * @param string               $caseId        The case.
	 * @param array<string, mixed> $collection    The collection.
	 *
	 * @return void
	 */
	private function writeCaseObjects(object $objectService, string $register, string $caseId, array $collection): void {
		$schema = $this->settingsService->getConfigValue('case_object_schema');
		foreach ((array)($collection['items'] ?? []) as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$publication = trim((string)($item['publication'] ?? ''));
			if ($publication === '') {
				continue;
			}

			$attachment = ($item['attachment'] ?? null);
			if ($attachment !== null) {
				$attachment = (string)$attachment;
			}

			$object = [
				'case' => $caseId,
				'objectType' => self::OBJECT_TYPE,
				'objectUrl' => $this->urlGenerator->getAbsoluteURL($this->publicationPath(publicationId: $publication)),
				// The schema declares a string, so the pair travels as JSON.
				'objectIdentification' => (string)json_encode(['publication' => $publication, 'attachment' => $attachment]),
				'description' => $this->describe(objectService: $objectService, publication: $publication, note: (string)($item['note'] ?? '')),
			];

			try {
				$this->runAsSystemIfAvailable(
					objectService: $objectService,
					operation: fn (): mixed => $objectService->saveObject(object: $object, register: $register, schema: $schema, _rbac: false, _multitenancy: false)
				);
			} catch (Throwable $e) {
				$this->logger->error(
					'CollectionCaseLinks: a collection item could not be linked to the case',
					['case' => $caseId, 'publication' => $publication, 'error' => $e->getMessage()]
				);
			}
		}//end foreach
	}//end writeCaseObjects()

	/**
	 * What a case object says: the publication's title and the resident's note.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $publication   The publication uuid.
	 * @param string $note          The resident's note on the item.
	 *
	 * @return string
	 */
	private function describe(object $objectService, string $publication, string $note): string {
		$title = '';
		try {
			$row = $this->reader->find(
				objectService: $objectService,
				register: $this->settingsService->getWooPublicationConfigValue('woo_publication_register'),
				schema: $this->settingsService->getWooPublicationConfigValue('woo_publication_schema'),
				id: $publication,
			);
			$title = trim((string)($row['title'] ?? ''));
		} catch (Throwable $e) {
			$title = '';
		}

		$note = trim($note);
		if ($title !== '' && $note !== '') {
			return $title . ': ' . $note;
		}

		if ($title !== '') {
			return $title;
		}

		return $note;
	}//end describe()

	/**
	 * Append the case to the collection's `sourceOf`, once, leaving its items alone.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $collection    The collection as read.
	 * @param string               $caseId        The case.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-dossier-records-the-request-it-started-req-wri-004
	 */
	private function recordSource(object $objectService, array $collection, string $caseId): void {
		$reference = self::SOURCE_PREFIX . $caseId;
		$sourceOf = array_values(array_filter((array)($collection['sourceOf'] ?? []), 'is_string'));
		if (in_array($reference, $sourceOf, true) === true) {
			return;
		}

		$collectionId = (string)$collection['id'];
		$data = $collection;
		unset($data['@self'], $data['id']);
		$data['sourceOf'] = array_merge($sourceOf, [$reference]);

		try {
			$this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): mixed => $objectService->saveObject(
					object: $data,
					register: $this->settingsService->getWooPublicationConfigValue('woo_collection_register'),
					schema: $this->settingsService->getWooPublicationConfigValue('woo_collection_schema'),
					uuid: $collectionId,
					_rbac: false,
					_multitenancy: false,
				)
			);
		} catch (Throwable $e) {
			// The case exists and is the resident's; a collection that does not
			// learn about it loses a link, not the request.
			$this->logger->warning(
				'CollectionCaseLinks: the collection could not record the case it started',
				['collection' => $collectionId, 'case' => $caseId, 'error' => $e->getMessage()]
			);
		}
	}//end recordSource()
}//end class
