<?php

/**
 * Dossiq Signing Concluded Listener
 *
 * Hears how filinq concluded a signing request dossiq raised (filinq's
 * SigningConcludedEvent) and records it on the decision the request was for.
 * Filinq concludes every request it took: signed, declined, cancelled, or
 * expired once its deadline passes, so a decision waiting on a signature
 * always hears an end.
 *
 * Filinq's event is named by string, registered by name in
 * CrossAppListenerRegistrar, and read through its getters; only conclusions
 * dossiq asked for (source app dossiq) are taken. A conclusion that arrives
 * from a background job (filinq's expiry) writes as dossiq's background
 * service account.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/signing-through-filinq/specs/libresign-besluit-signing/spec.md#requirement-a-signing-request-that-ends-unsigned-is-shown-as-not-concluded
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\BeschikkingService;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records filinq's signing conclusion on the decision that asked for it.
 *
 * @spec openspec/changes/signing-through-filinq/specs/libresign-besluit-signing/spec.md#requirement-a-signing-request-that-ends-unsigned-is-shown-as-not-concluded
 *
 * @template-implements IEventListener<Event>
 */
class SigningConcludedListener implements IEventListener {
	/**
	 * Filinq's conclusion event.
	 */
	public const EVENT = 'OCA\\Filinq\\Event\\SigningConcludedEvent';

	/**
	 * Constructor.
	 *
	 * @param BeschikkingService $decisions Records the conclusion on the decision.
	 * @param BackgroundServiceAccount $serviceAccount The identity a conclusion from a background job writes as.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly BeschikkingService $decisions,
		private readonly BackgroundServiceAccount $serviceAccount,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record a conclusion of a request dossiq raised.
	 *
	 * @param Event $event Filinq's SigningConcludedEvent; any other event is ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/signing-through-filinq/specs/libresign-besluit-signing/spec.md#requirement-a-signing-request-that-ends-unsigned-is-shown-as-not-concluded
	 */
	public function handle(Event $event): void {
		$eventClass = self::EVENT;
		if (($event instanceof $eventClass) === false || $event->getSourceApp() !== Application::APP_ID) {
			return;
		}

		$decisionId = (string)($event->getSubjectId() ?? '');
		$requestId = $event->getSigningRequestId();
		if ($decisionId === '' || $requestId === '') {
			return;
		}

		try {
			$taken = $this->serviceAccount->runAsWhenNobodyIsSignedIn(
				operation: fn (): bool => $this->decisions->concludeSigning(
					decisionId: $decisionId,
					requestId: $requestId,
					status: $event->getStatus(),
					signedDocumentRef: $event->getSignedDocumentRef(),
					signedAt: $event->getSignedAt(),
				)
			);
		} catch (ServiceAccountUnavailableException $e) {
			// Already logged and told to the administrators. Nothing was written.
			return;
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: a signing conclusion could not be recorded',
				['decision' => $decisionId, 'signingRequest' => $requestId, 'error' => $e->getMessage()]
			);
			return;
		}//end try

		if ($taken !== true) {
			$this->logger->info(
				'Dossiq: a signing conclusion for a request no decision waits on was ignored',
				['decision' => $decisionId, 'signingRequest' => $requestId]
			);
		}
	}//end handle()
}//end class
