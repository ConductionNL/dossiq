<?php

/**
 * Samenwerkverzoek Service
 *
 * Manages samenwerkverzoek (cooperation request) objects for DSO omgevingsloket
 * cases where a bevoegd gezag requests input or decision-sharing from another
 * authority. Handles initiation, response processing, and per-object
 * authorization for samenwerkverzoek mutations.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Exception;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for samenwerkverzoek lifecycle management.
 *
 * Creates samenwerkverzoek objects and tracks their state through
 * aangevraagd → geaccepteerd / geweigerd transitions. Authorization
 * is admin-only per VTH policy.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T05
 */
class SamenwerkverzoekService {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The application config service
	 * @param ContainerInterface $container The DI container
	 * @param IEventDispatcher $eventDispatcher The event dispatcher
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Initiate a samenwerking request for a zaak.
	 *
	 * Creates a samenwerkverzoek object with status 'aangevraagd' and
	 * dispatches a SamenwerkverzoekInitiated event for downstream listeners.
	 *
	 * @param string $zaakId The UUID of the zaak
	 * @param string $aangezochtGezag The requested authority identifier
	 * @param string $rationale The reason for requesting cooperation
	 *
	 * @return array<string,mixed> The created samenwerkverzoek object
	 *
	 * @throws \RuntimeException When the zaak cannot be found
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T05
	 */
	public function initiateSamenwerking(
		string $zaakId,
		string $aangezochtGezag,
		string $rationale,
	): array {
		$objectService = $this->getObjectService();

		$register = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: 'register',
			default: ''
		);
		$caseSchema = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: 'case_schema',
			default: ''
		);

		$zaak = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			id: $zaakId
		);

		if ($zaak === null) {
			throw new RuntimeException('Zaak not found: ' . $zaakId);
		}

		$aanvraagRef = (string)($zaak['vergunningaanvraagRef'] ?? '');

		$verzoekSchema = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: 'dso_samenwerkverzoek_schema',
			default: 'samenwerkverzoek'
		);

		$samenwerkverzoek = [
			'zaakId' => $zaakId,
			'vergunningaanvraagRef' => $aanvraagRef,
			'aangezochtBevoegdGezag' => $aangezochtGezag,
			'rationale' => $rationale,
			'status' => 'aangevraagd',
			'aangevraagdOp' => date('c'),
		];

		$created = $objectService->saveObject(
			register: $register,
			schema: $verzoekSchema,
			object: $samenwerkverzoek
		);

		$event = new GenericEvent(
			subject: $created,
			arguments: [
				'zaakId' => $zaakId,
				'vergunningaanvraagRef' => $aanvraagRef,
				'aangezochtBevoegdGezag' => $aangezochtGezag,
			]
		);
		$this->eventDispatcher->dispatch(
			eventName: 'OCA\Procest\Event\SamenwerkverzoekInitiated',
			event: $event
		);

		$this->logger->info(
			'Procest SamenwerkverzoekService: samenwerking initiated',
			[
				'app' => Application::APP_ID,
				'zaakId' => $zaakId,
				'aangezochtBevoegdGezag' => $aangezochtGezag,
			]
		);

		return $created;
	}//end initiateSamenwerking()

	/**
	 * Respond to a pending samenwerkverzoek.
	 *
	 * Validates that the verzoek is in 'aangevraagd' status, then updates
	 * it to 'geaccepteerd' or 'geweigerd' with the provided advies text.
	 *
	 * @param string $samenwerkId The UUID of the samenwerkverzoek
	 * @param bool $accept True to accept, false to reject
	 * @param string $advies The advies/reasoning text for the response
	 *
	 * @return array<string,mixed> The updated samenwerkverzoek object
	 *
	 * @throws \RuntimeException When the verzoek cannot be found or is not in 'aangevraagd' status
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T05
	 */
	public function respondToSamenwerking(string $samenwerkId, bool $accept, string $advies): array {
		$objectService = $this->getObjectService();

		$register = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: 'register',
			default: ''
		);
		$verzoekSchema = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: 'dso_samenwerkverzoek_schema',
			default: 'samenwerkverzoek'
		);

		$verzoek = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $verzoekSchema,
			id: $samenwerkId
		);

		if ($verzoek === null) {
			throw new RuntimeException('Samenwerkverzoek not found: ' . $samenwerkId);
		}

		$currentStatus = (string)($verzoek['status'] ?? '');
		if ($currentStatus !== 'aangevraagd') {
			throw new RuntimeException(
				'Samenwerkverzoek is not in aangevraagd status; current status: ' . $currentStatus
			);
		}

		$verzoek['status'] = 'geweigerd';
		if ($accept === true) {
			$verzoek['status'] = 'geaccepteerd';
		}

		$verzoek['advies'] = $advies;
		$verzoek['gereageerdOp'] = date('c');

		$updated = $objectService->saveObject(
			register: $register,
			schema: $verzoekSchema,
			object: $verzoek
		);

		$this->logger->info(
			'Procest SamenwerkverzoekService: samenwerking responded',
			[
				'app' => Application::APP_ID,
				'samenwerkId' => $samenwerkId,
				'newStatus' => $verzoek['status'],
			]
		);

		return $updated;
	}//end respondToSamenwerking()

	/**
	 * Authorise a samenwerkverzoek mutation.
	 *
	 * Only administrators are permitted to modify samenwerkverzoek objects
	 * per VTH inter-authority collaboration policy.
	 *
	 * @param array<string,mixed> $samenwerk The samenwerkverzoek object array
	 * @param IUser $user The authenticated user
	 *
	 * @return void
	 *
	 * @throws \Exception When the user is not an administrator
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T05
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $samenwerk reserved for future ACL
	 */
	public function authorizeSamenwerkMutation(array $samenwerk, IUser $user): void {
		try {
			$groupManager = $this->container->get('OCP\IGroupManager');
			if ($groupManager->isAdmin(uid: $user->getUID()) === true) {
				return;
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Procest SamenwerkverzoekService: could not resolve IGroupManager: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
		}

		throw new Exception('Not authorized');
	}//end authorizeSamenwerkMutation()

	/**
	 * Get the ObjectService lazily from the DI container.
	 *
	 * @return object The OpenRegister ObjectService
	 *
	 * @throws \RuntimeException When the service is not available
	 */
	private function getObjectService(): object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			throw new RuntimeException(
				'OpenRegister ObjectService not available: ' . $e->getMessage(),
				0,
				$e
			);
		}
	}//end getObjectService()
}//end class
