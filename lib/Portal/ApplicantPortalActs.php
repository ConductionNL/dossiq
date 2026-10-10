<?php

/**
 * What dossiq does when an applicant acts on their own case in the portal.
 *
 * Portaliq raises one typed fact per act a resident takes on their own case:
 * `portal.write.client` for an amended answer, an added document or an
 * answered task, and `portal.withdraw.client` for a withdrawal. portaliq is
 * the source and does nothing further with either by design; the case app is
 * the one that has to tell the handler (dossiq#3142).
 *
 * This class is that telling, built from the two mechanisms dossiq already
 * has for inbound work rather than a third:
 *
 *  - an INTERNAL timeline entry of the `reactie-indiener` kind through
 *    {@see CaseTimeline}, the one seam that writes entries. The kind carries
 *    a follow-up, the way inbound mail does, so the case surfaces in the
 *    handler's queue until someone has looked at it; and the entry is an
 *    OpenRegister record, which is what a workflow rule reacts to.
 *  - a bell notification to the case's assignee through Nextcloud's
 *    notification manager, rendered by {@see Notifier}, the way a `notify`
 *    transition action reaches the assignee.
 *
 * A FACT ABOUT A CASE THAT IS NOT DOSSIQ'S IS LEFT ALONE. portaliq raises the
 * fact for whichever app owns the case, so the filter is the case itself: it
 * is looked up in dossiq's own register and case schema, and an id that does
 * not answer there is not ours. That is not an error and is not logged as one.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
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
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

use DateTime;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Tells the handler what the applicant did in the portal.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
class ApplicantPortalActs {

	/**
	 * The act a withdrawal is recorded under. portaliq's write acts are its
	 * own constants; this one is dossiq's, because portaliq raises the
	 * withdrawal as a separate event rather than as a fourth act.
	 *
	 * @var string
	 */
	public const ACT_WITHDRAWAL = 'withdrawal';

	/**
	 * The write acts portaliq declares on `PortalClientWriteEvent`, with the
	 * sentence the timeline entry carries for each.
	 *
	 * @var array<string, string>
	 */
	private const WRITE_ACTS = [
		'amendment' => 'The applicant changed an answer in the portal',
		'document' => 'The applicant added a document in the portal',
		'task-answer' => 'The applicant answered a task in the portal',
	];

	/**
	 * What the represented person reads on their case for each act done for
	 * them, after "Namens {party}: " (site-business-and-authorisation D4).
	 */
	private const ACTING_FOR_ACTS = [
		'amendment' => 'gegevens aangepast in het portaal',
		'document' => 'document toegevoegd in het portaal',
		'task-answer' => 'vraag beantwoord in het portaal',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService      $settings            Resolves the object service, register and case schema.
	 * @param CaseTimeline         $timeline            The one seam that writes a timeline entry.
	 * @param INotificationManager $notificationManager Nextcloud's notification manager.
	 * @param LoggerInterface      $logger              Logger.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly CaseTimeline $timeline,
		private readonly INotificationManager $notificationManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record a write the applicant made on their case and tell the assignee.
	 *
	 * @param string             $caseId     The case the write landed on.
	 * @param string             $act        `amendment`, `document` or `task-answer`.
	 * @param array<int, string> $fields     The field names the act touched.
	 * @param string             $occurredAt The moment of the write, ISO 8601.
	 * @param array<string, mixed> $mandate  The mandate the write ran under: `actingFor`, `actingForLabel`, or none.
	 *
	 * @return boolean True when the case is dossiq's and the act was recorded.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 * @spec openspec/changes/site-business-and-authorisation/specs/portal-contribution/spec.md
	 */
	public function recordWrite(string $caseId, string $act, array $fields, string $occurredAt, array $mandate=[]): bool {
		if (isset(self::WRITE_ACTS[$act]) === false) {
			$this->logger->warning(
				'Dossiq: a portal write on case {case} names an act this app does not know, {act}',
				['app' => Application::APP_ID, 'case' => $caseId, 'act' => $act],
			);
			return false;
		}

		$case = $this->case(caseId: $caseId);
		if ($case === null) {
			return false;
		}

		$touched = implode(', ', array_filter(array_map('strval', $fields), static fn (string $field): bool => $field !== ''));

		$message = self::WRITE_ACTS[$act];
		if ($touched !== '') {
			$message .= ': ' . $touched;
		}

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::APPLICANT_RESPONSE,
			message: $message,
			fields: [
				'act' => $act,
				'fields' => $touched,
				'occurredAt' => $occurredAt,
			],
			visibility: CaseTimeline::INTERNAL,
		);

		$this->tellTheRepresented(caseId: $caseId, act: $act, mandate: $mandate, occurredAt: $occurredAt);

		$this->notifyAssignee(
			case: $case,
			caseId: $caseId,
			subject: Notifier::SUBJECT_APPLICANT_RESPONDED,
			parameters: ['act' => $act],
		);

		return true;
	}//end recordWrite()

	/**
	 * A write made for someone else under a mandate is told to that person on
	 * the case's public history: "Namens {party}: ...". The mandate's label
	 * names the party; without one the party reference does. Portaliq does not
	 * hand over the acting person's name, so the entry names the party only.
	 *
	 * @param string               $caseId     The case.
	 * @param string               $act        The act.
	 * @param array<string, mixed> $mandate    The mandate the write ran under.
	 * @param string               $occurredAt The moment of the write.
	 *
	 * @return void
	 */
	private function tellTheRepresented(string $caseId, string $act, array $mandate, string $occurredAt): void {
		$party = trim((string)($mandate['actingFor'] ?? ''));
		if ($party === '') {
			return;
		}

		$label = trim((string)($mandate['actingForLabel'] ?? ''));
		if ($label === '') {
			$label = $party;
		}

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::APPLICANT_RESPONSE,
			message: 'Namens ' . $label . ': ' . self::ACTING_FOR_ACTS[$act],
			fields: ['act' => $act, 'actingFor' => $party, 'occurredAt' => $occurredAt],
			visibility: CaseTimeline::PUBLIC_ENTRY,
		);
	}//end tellTheRepresented()

	/**
	 * Record a withdrawal the applicant made from the portal and tell the assignee.
	 *
	 * @param string $caseId     The case that was withdrawn.
	 * @param string $status     The status the withdrawal landed on.
	 * @param string $reason     What the applicant said, or ''.
	 * @param string $occurredAt The moment of the withdrawal, ISO 8601.
	 *
	 * @return boolean True when the case is dossiq's and the withdrawal was recorded.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function recordWithdrawal(string $caseId, string $status, string $reason, string $occurredAt): bool {
		$case = $this->case(caseId: $caseId);
		if ($case === null) {
			return false;
		}

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::APPLICANT_RESPONSE,
			message: 'The applicant withdrew the request in the portal',
			fields: [
				'act' => self::ACT_WITHDRAWAL,
				'status' => $status,
				'reason' => $reason,
				'occurredAt' => $occurredAt,
			],
			visibility: CaseTimeline::INTERNAL,
		);

		$this->notifyAssignee(
			case: $case,
			caseId: $caseId,
			subject: Notifier::SUBJECT_APPLICANT_WITHDREW,
			parameters: ['reason' => $reason],
		);

		return true;
	}//end recordWithdrawal()

	/**
	 * The case in dossiq's own register and case schema, or null when it is not ours.
	 *
	 * @param string $caseId The case id portaliq named.
	 *
	 * @return array<string, mixed>|null The case data, or null.
	 */
	private function case(string $caseId): ?array {
		if (trim($caseId) === '') {
			return null;
		}

		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue('register');
		$schema = $this->settings->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		$object = $objectService->find($caseId, register: $register, schema: $schema);
		if (is_object($object) === false || method_exists($object, 'jsonSerialize') === false) {
			return null;
		}

		$data = $object->jsonSerialize();
		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}//end case()

	/**
	 * Put a bell notification in front of the case's assignee.
	 *
	 * A case nobody is assigned to notifies nobody; the follow-up the entry
	 * opened is what surfaces it then.
	 *
	 * @param array<string, mixed> $case       The case data.
	 * @param string               $caseId     The case id.
	 * @param string               $subject    The Notifier subject key.
	 * @param array<string, mixed> $parameters The act-specific subject parameters.
	 *
	 * @return void
	 */
	private function notifyAssignee(array $case, string $caseId, string $subject, array $parameters): void {
		$assignee = trim((string)($case['assignee'] ?? ''));
		if ($assignee === '') {
			return;
		}

		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($assignee)
			->setDateTime(new DateTime())
			->setObject('case', $caseId)
			->setSubject(
				$subject,
				array_merge(
					[
						'caseId' => $caseId,
						'identifier' => (string)($case['identifier'] ?? ''),
					],
					$parameters,
				)
			);

		$this->notificationManager->notify($notification);
	}//end notifyAssignee()
}//end class
