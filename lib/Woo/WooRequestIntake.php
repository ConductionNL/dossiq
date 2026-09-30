<?php

/**
 * Dossiq Woo request intake
 *
 * THE ONE PATH A WOO REQUEST IS OPENED BY (hydra woo-citizen-journey, C5). A
 * resident starting a request from their dossier on the portal and a KCC
 * employee converting a question in pipelinq both land here, so the two can
 * never open different kinds of case. pipelinq reaches this class by name
 * through the container (duck-typed, no hard dependency); dossiq's portal
 * receiver calls it after verifying portaliq's assertion.
 *
 * The caller is never a Nextcloud user with rights on these records: it is a
 * portal subject or a service call. So every read and write runs as the
 * system, and the one authorization is the ownership check on the dossier:
 * its `owner` must be the `subjectRef` the request names. A dossier that does
 * not exist and one that belongs to someone else give the same refusal, so
 * nobody can probe which ids exist.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Opens a Woo request case for a resident, optionally from their dossier.
 *
 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
 */
class WooRequestIntake {

	use SearchesObjects;

	/**
	 * The seeded Woo request case type (register.d/81-woo-verzoek.json).
	 */
	public const CASE_TYPE_ID = '3c0f5a00-0000-4000-a000-00000000a001';

	/**
	 * The case object type of a publication from a dossier.
	 */
	public const OBJECT_TYPE = 'opencatalogi.publication';

	/**
	 * Where a request may come from.
	 */
	public const ORIGINS = ['portal', 'pipelinq'];

	/**
	 * The prefix of the reference a dossier keeps in `sourceOf`.
	 */
	public const SOURCE_PREFIX = 'dossiq:case:';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register, schemas and OpenRegister.
	 * @param IURLGenerator   $urlGenerator    Makes the publication and case links absolute.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Open a Woo request.
	 *
	 * @param array<string, mixed> $request `{subjectRef, collectionId?, onderwerp, omschrijving,
	 *                                      periodeVan, periodeTot, origin, originReference}`.
	 *
	 * @return array{caseId: string, caseUrl: string}
	 *
	 * @throws WooRequestRefused When the request is unusable, the dossier is not the resident's,
	 *                           or the register or case type is missing.
	 *
	 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
	 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/woo-request-intake/spec.md#requirement-only-the-owners-dossier-starts-a-request-req-wri-003
	 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/woo-request-intake/spec.md#requirement-the-dossier-records-the-request-it-started-req-wri-004
	 */
	public function start(array $request): array {
		$wooRequest = (new WooRequestForm())->normalise(request: $request);
		$subjectRef = trim((string)$request['subjectRef']);

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '') {
			throw new WooRequestRefused(WooRequestRefused::UNAVAILABLE, 'OpenRegister or the dossiq register is missing.');
		}

		$collection = null;
		if ($wooRequest['collectionId'] !== '') {
			$collection = $this->ownedCollection(objectService: $objectService, collectionId: $wooRequest['collectionId'], subjectRef: $subjectRef);
		}

		$caseType = $this->caseType(objectService: $objectService, register: $register);
		$caseId = $this->writeCase(
			objectService: $objectService,
			register: $register,
			caseType: $caseType,
			subjectRef: $subjectRef,
			wooRequest: $wooRequest,
		);

		if ($collection !== null) {
			$this->writeCaseObjects(objectService: $objectService, register: $register, caseId: $caseId, collection: $collection);
			$this->recordSource(objectService: $objectService, collection: $collection, caseId: $caseId);
		}

		return [
			'caseId' => $caseId,
			'caseUrl' => $this->urlGenerator->getAbsoluteURL('/index.php/apps/dossiq/cases/' . $caseId),
		];
	}//end start()

	/**
	 * The public path of a publication, the same one the publish side links to.
	 *
	 * @param string $publicationId The publication uuid.
	 *
	 * @return string The path, starting with `/index.php`.
	 *
	 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
	 */
	public function publicationPath(string $publicationId): string {
		$catalogSlug = $this->settingsService->getConfigValue('woo_publication_catalog_slug', 'publication');

		return '/index.php/apps/opencatalogi/' . $catalogSlug . '/' . $publicationId;
	}//end publicationPath()


	/**
	 * The resident's own dossier, or a refusal that does not say whether it exists.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $collectionId  The collection uuid.
	 * @param string $subjectRef    The resident.
	 *
	 * @return array<string, mixed> The collection.
	 *
	 * @throws WooRequestRefused NOT_FOUND when it is absent or someone else's.
	 *
	 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/woo-request-intake/spec.md#requirement-only-the-owners-dossier-starts-a-request-req-wri-003
	 */
	private function ownedCollection(object $objectService, string $collectionId, string $subjectRef): array {
		$collection = null;
		try {
			$collection = $this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): ?array => $this->findObjectAsArray(
					objectService: $objectService,
					register: $this->settingsService->getWooPublicationConfigValue('woo_collection_register'),
					schema: $this->settingsService->getWooPublicationConfigValue('woo_collection_schema'),
					id: $collectionId,
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning('WooRequestIntake: the dossier could not be read', ['collection' => $collectionId, 'error' => $e->getMessage()]);
		}

		if (is_array($collection) === false || (string)($collection['owner'] ?? '') !== $subjectRef) {
			throw new WooRequestRefused(WooRequestRefused::NOT_FOUND, 'No such dossier.');
		}

		$collection['id'] = $collectionId;

		return $collection;
	}//end ownedCollection()

	/**
	 * The seeded Woo request case type.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The dossiq register.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WooRequestRefused UNAVAILABLE when it is not there.
	 */
	private function caseType(object $objectService, string $register): array {
		$caseType = null;
		try {
			$caseType = $this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): ?array => $this->findObjectAsArray(
					objectService: $objectService,
					register: $register,
					schema: $this->settingsService->getConfigValue('case_type_schema'),
					id: self::CASE_TYPE_ID,
				)
			);
		} catch (Throwable $e) {
			$this->logger->error('WooRequestIntake: the Woo request case type could not be read', ['error' => $e->getMessage()]);
		}

		if (is_array($caseType) === false) {
			throw new WooRequestRefused(WooRequestRefused::UNAVAILABLE, 'The Woo request case type is not installed.');
		}

		return $caseType;
	}//end caseType()

	/**
	 * Write the case and answer its uuid.
	 *
	 * @param object                $objectService The OpenRegister ObjectService.
	 * @param string                $register      The dossiq register.
	 * @param array<string, mixed>  $caseType      The Woo request case type.
	 * @param string                $subjectRef    The resident.
	 * @param array<string, string> $wooRequest    The request as the case keeps it.
	 *
	 * @return string The case uuid.
	 *
	 * @throws WooRequestRefused UNAVAILABLE when the write fails.
	 */
	private function writeCase(object $objectService, string $register, array $caseType, string $subjectRef, array $wooRequest): string {
		$case = [
			'title' => $wooRequest['onderwerp'],
			'description' => $wooRequest['omschrijving'],
			'caseType' => self::CASE_TYPE_ID,
			// The statutory clock starts today: `deadline` is startDate plus
			// the type's processingDeadline (P28D).
			'startDate' => date('Y-m-d'),
			'portalSubject' => $subjectRef,
			'intakeChannel' => $wooRequest['origin'],
			'wooRequest' => $wooRequest,
		];

		$initial = trim((string)($caseType['initialStatus'] ?? ''));
		if ($initial !== '') {
			$case['status'] = $initial;
		}

		try {
			$saved = $this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): mixed => $objectService->saveObject(
					object: $case,
					register: $register,
					schema: $this->settingsService->getConfigValue('case_schema'),
				)
			);
		} catch (Throwable $e) {
			$this->logger->error('WooRequestIntake: the case could not be written', ['error' => $e->getMessage()]);
			throw new WooRequestRefused(WooRequestRefused::UNAVAILABLE, 'The case could not be written.');
		}

		$caseId = $this->idOf(saved: $saved);
		if ($caseId === '') {
			throw new WooRequestRefused(WooRequestRefused::UNAVAILABLE, 'The case was written without an id.');
		}

		return $caseId;
	}//end writeCase()

	/**
	 * One case object per dossier item.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param string               $register      The dossiq register.
	 * @param string               $caseId        The case.
	 * @param array<string, mixed> $collection    The dossier.
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
					operation: fn (): mixed => $objectService->saveObject(object: $object, register: $register, schema: $schema)
				);
			} catch (Throwable $e) {
				$this->logger->error(
					'WooRequestIntake: a dossier item could not be linked to the case',
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
			$row = $this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): ?array => $this->findObjectAsArray(
					objectService: $objectService,
					register: $this->settingsService->getWooPublicationConfigValue('woo_publication_register'),
					schema: $this->settingsService->getWooPublicationConfigValue('woo_publication_schema'),
					id: $publication,
				)
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
	 * Append the case to the dossier's `sourceOf`, once, leaving its items alone.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $collection    The dossier as read.
	 * @param string               $caseId        The case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/woo-request-intake/spec.md#requirement-the-dossier-records-the-request-it-started-req-wri-004
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
				)
			);
		} catch (Throwable $e) {
			// The case exists and is the resident's; a dossier that does not
			// learn about it loses a link, not the request.
			$this->logger->warning(
				'WooRequestIntake: the dossier could not record the case it started',
				['collection' => $collectionId, 'case' => $caseId, 'error' => $e->getMessage()]
			);
		}
	}//end recordSource()

	/**
	 * The uuid of whatever saveObject() answered.
	 *
	 * @param mixed $saved An ObjectEntity or an array.
	 *
	 * @return string
	 */
	private function idOf(mixed $saved): string {
		if (is_object($saved) === true && method_exists($saved, 'getUuid') === true) {
			return (string)$saved->getUuid();
		}

		if (is_array($saved) === true) {
			return (string)($saved['@self']['id'] ?? $saved['id'] ?? $saved['uuid'] ?? '');
		}

		return '';
	}//end idOf()
}//end class
