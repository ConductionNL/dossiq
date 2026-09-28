<?php

/**
 * Records a mail an OpenRegister flow sent about a dossiq case, on that case.
 *
 * dossiq's own mail steps are gone: OpenRegister's `openregister.send-email`
 * sends now. What dossiq still owns is the case's record of it, the stored
 * message on the case and the line on its timeline, which CaseEmailService
 * wrote whenever it sent. OpenRegister announces every mail a flow sent with
 * a FlowEmailSentEvent carrying the rendered subject and body; when the item
 * the mail was about is a dossiq case, this listener writes the same record
 * through {@see CaseEmailService::recordSentEmail()}.
 *
 * A RECORDING THAT FAILS IS LOGGED, NOT THROWN. The mail has gone; OpenRegister
 * says so itself, and a step retried because its record failed would send it
 * a second time.
 *
 * The event class is OpenRegister's, named by string and registered only when
 * it exists: an older OpenRegister does not announce sent mail.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseEmailService;
use OCA\Dossiq\Service\Support\CaseObjectReference;
use OCA\OpenRegister\Event\FlowEmailSentEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Files a flow-sent mail on its dossiq case.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */
class FlowEmailSentListener implements IEventListener {

	/**
	 * The OpenRegister event this listener is registered for.
	 *
	 * @var string
	 */
	public const EVENT = 'OCA\\OpenRegister\\Event\\FlowEmailSentEvent';

	/**
	 * Constructor.
	 *
	 * @param CaseObjectReference $cases     Recognises a dossiq case.
	 * @param CaseEmailService    $emails    Writes the case's record of a sent mail.
	 * @param IUserManager        $users     Reads a user recipient's address.
	 * @param IAppConfig          $appConfig dossiq's configured sender, for the record.
	 * @param LoggerInterface     $logger    Logger.
	 */
	public function __construct(
		private readonly CaseObjectReference $cases,
		private readonly CaseEmailService $emails,
		private readonly IUserManager $users,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record the mail when it was about a dossiq case.
	 *
	 * @param Event $event The OpenRegister event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof FlowEmailSentEvent) === false) {
			return;
		}

		$caseId = trim((string)$event->getObjectUuid());
		if ($caseId === '' || $this->cases->isCase(register: $event->getRegister(), schema: $event->getSchema()) === false) {
			return;
		}

		try {
			$this->emails->recordSentEmail(
				caseId: $caseId,
				fromAddress: $this->appConfig->getValueString(Application::APP_ID, 'email_from_address', ''),
				to: $this->recipientAddress(event: $event),
				subject: $event->getSubject(),
				body: $event->getBody(),
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: a mail a flow sent about a case could not be recorded on the case',
				['case' => $caseId, 'flow' => $event->getFlowId(), 'step' => $event->getStepName(), 'error' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * The address the mail went to.
	 *
	 * An external recipient is the address itself. A user recipient is a uid;
	 * the record names the user's address when they have one, and the uid
	 * when they do not, because a line naming nobody would be worse.
	 *
	 * @param FlowEmailSentEvent $event The event.
	 *
	 * @return string The address or uid.
	 */
	private function recipientAddress(FlowEmailSentEvent $event): string {
		$recipient = $event->getRecipient();
		if ($event->getChannelKind() !== FlowEmailSentEvent::KIND_USER) {
			return $recipient;
		}

		$address = (string)($this->users->get($recipient)?->getEMailAddress() ?? '');
		if ($address === '') {
			return $recipient;
		}

		return $address;
	}//end recipientAddress()
}//end class
