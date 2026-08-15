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
use OCA\OpenRegister\Contract\ObjectServiceInterface;

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
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Initiate a samenwerking request for a zaak.
	 *
	 * Creates a samenwerkverzoek object with status 'aangevraagd' and
	 * dispatches a SamenwerkverzoekInitiated event for downstream listeners.
	 *
	 * @param string $caseId The UUID of the zaak
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
		string $caseId,
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

		$case = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			id: $caseId
		);

		if ($case === null) {
			throw new RuntimeException('Zaak not found: ' . $caseId);
		}

		$requestRef = (string)($case['permitApplicationRef'] ?? '');

		$requestSchema = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: 'dso_samenwerkverzoek_schema',
			default: 'samenwerkverzoek'
		);

		$samenwerkverzoek = [
			'caseId' => $caseId,
			'permitApplicationRef' => $requestRef,
			'requestedCompetentAuthority' => $aangezochtGezag,
			'rationale' => $rationale,
			'status' => 'aangevraagd',
			'requestedOn' => date('c'),
		];

		// saveObject() returns an ObjectEntityInterface (which extends
		// JsonSerializable), never an array — returning it straight out of a
		// method declared `: array` is a TypeError.
		$created = $objectService->saveObject(
			register: $register,
			schema: $requestSchema,
			object: $samenwerkverzoek
		)->jsonSerialize();

		$event = new GenericEvent(
			subject: $created,
			arguments: [
				'caseId' => $caseId,
				'permitApplicationRef' => $requestRef,
				'requestedCompetentAuthority' => $aangezochtGezag,
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
				'caseId' => $caseId,
				'requestedCompetentAuthority' => $aangezochtGezag,
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
		$requestSchema = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: 'dso_samenwerkverzoek_schema',
			default: 'samenwerkverzoek'
		);

		$request = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $requestSchema,
			id: $samenwerkId
		);

		if ($request === null) {
			throw new RuntimeException('Samenwerkverzoek not found: ' . $samenwerkId);
		}

		$currentStatus = (string)($request['status'] ?? '');
		if ($currentStatus !== 'aangevraagd') {
			throw new RuntimeException(
				'Samenwerkverzoek is not in aangevraagd status; current status: ' . $currentStatus
			);
		}

		$request['status'] = 'geweigerd';
		if ($accept === true) {
			$request['status'] = 'geaccepteerd';
		}

		$request['advies'] = $advies;
		$request['respondedOn'] = date('c');

		// See initiateSamenwerking() — saveObject() returns an entity, not an
		// array.
		$updated = $objectService->saveObject(
			register: $register,
			schema: $requestSchema,
			object: $request
		)->jsonSerialize();

		$this->logger->info(
			'Procest SamenwerkverzoekService: samenwerking responded',
			[
				'app' => Application::APP_ID,
				'samenwerkId' => $samenwerkId,
				'newStatus' => $request['status'],
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
		// Injected (ADR-083), so this cannot fail — a property read throws
		// nothing, and phpstan reports the old try/catch as a dead catch.
		// Absence is now a CONSTRUCTION failure on the route that needed the
		// data, which is what ADR-083 rule 1 asks for.
		return $this->objectService;
	}//end getObjectService()
}//end class
