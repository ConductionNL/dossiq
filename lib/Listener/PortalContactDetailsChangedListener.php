<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\Contracts\CaseTimelineInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TimelineKindsInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A resident's contact channel, chosen in the portal, reaches their running cases.
 *
 * Portaliq raises `PortalContactDetailsChangedEvent` once per change of a
 * resident's contact channel (`portal`, `email`, `phone`, `post`). Every case
 * of that portal subject that has not ended takes the matching dossiq channel
 * in `communicationChannel`, which is what `CaseTypeAcknowledgement::channelFor()`
 * reads as the applicant's own choice. Each change is a timeline entry, so the
 * handler sees why the next letter goes another way. A phone preference changes
 * no channel (dossiq sends no letter by phone) and is told on the timeline.
 * Ended cases are left alone.
 *
 * Registered by class name, like every portaliq seam: portaliq is optional,
 * the getters are read by `method_exists`, and nothing here throws into
 * portaliq's request.
 *
 * @spec openspec/changes/portal-contact-channel-follows-the-resident/specs/portal-contribution/spec.md
 *
 * @template-implements IEventListener<Event>
 */
class PortalContactDetailsChangedListener implements IEventListener {
	use SearchesObjects;

	/**
	 * The portaliq event, by name.
	 */
	public const EVENT = 'OCA\\Portaliq\\Event\\PortalContactDetailsChangedEvent';

	/**
	 * The portal channel each dossiq channel is, for the channels a letter goes by.
	 */
	public const CHANNELS = ['portal' => 'portal', 'email' => 'email', 'post' => 'post'];

	/**
	 * The portal channel that changes no letter.
	 */
	public const PHONE = 'phone';

	/**
	 * How many cases of one resident are read at most.
	 */
	private const LIMIT = 500;

	/**
	 * @param SettingsServiceInterface $settingsService OpenRegister access and the configured register.
	 * @param CaseTimelineInterface    $timeline        The one seam that writes a timeline entry.
	 * @param LoggerInterface $logger          Where a failure is reported.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly CaseTimelineInterface $timeline,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Hear the event and carry the channel onto the running cases.
	 *
	 * @param Event $event The portaliq event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-contact-channel-follows-the-resident/specs/portal-contribution/spec.md
	 */
	public function handle(Event $event): void {
		if (method_exists($event, 'getSubjectRef') === false || method_exists($event, 'getChannel') === false) {
			return;
		}

		$subject = trim((string)$event->getSubjectRef());
		$channel = trim((string)$event->getChannel());
		$organisation = '';
		if (method_exists($event, 'getOrganisation') === true) {
			$organisation = trim((string)$event->getOrganisation());
		}

		if ($subject === '' || ($channel !== self::PHONE && isset(self::CHANNELS[$channel]) === false)) {
			return;
		}

		try {
			$this->follow(change: new ContactChannelChange(subject: $subject, organisation: $organisation, channel: $channel));
		} catch (Throwable $e) {
			$this->logger->error(
				'PortalContactDetailsChangedListener: the contact channel could not reach the running cases',
				['error' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Set the channel on every running case of the subject, as the system.
	 *
	 * @param ContactChannelChange $change The subject, the organisation and the portal channel.
	 *
	 * @return void
	 */
	private function follow(ContactChannelChange $change): void {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return;
		}

		$this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: function () use ($objectService, $register, $schema, $change): void {
				$this->followIn(objectService: $objectService, register: $register, schema: $schema, change: $change);
			}
		);
	}//end follow()

	/**
	 * Set the channel on every running case of the subject in the case register.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register.
	 * @param string               $schema        The case schema.
	 * @param ContactChannelChange $change        The subject, the organisation and the portal channel.
	 *
	 * @return void
	 */
	private function followIn(object $objectService, string $register, string $schema, ContactChannelChange $change): void {
		$rows = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['portalSubject' => $change->subject, '_limit' => self::LIMIT],
		);
		$cases = new RunningCaseStore(objectService: $objectService, register: $register, schema: $schema);
		foreach ($rows as $row) {
			if ($this->isRunning(row: $row, change: $change) === false) {
				continue;
			}

			$this->apply(cases: $cases, row: $row, channel: $change->channel);
		}
	}//end followIn()

	/**
	 * Whether a row is a running case of this subject in this organisation.
	 *
	 * @param array<string, mixed> $row    The case row.
	 * @param ContactChannelChange $change The portal subject and the organisation the event names, or ''.
	 *
	 * @return bool True when the channel should follow.
	 */
	private function isRunning(array $row, ContactChannelChange $change): bool {
		$organisation = $change->organisation;
		if ((string)($row['portalSubject'] ?? '') !== $change->subject) {
			return false;
		}

		if (trim((string)($row['endDate'] ?? '')) !== '') {
			return false;
		}

		$owner = trim((string)(($row['@self'] ?? [])['organisation'] ?? ''));

		return $organisation === '' || $owner === '' || $owner === $organisation;
	}//end isRunning()

	/**
	 * Write the channel on one case and tell its timeline.
	 *
	 * @param RunningCaseStore     $cases   The object service, register and case schema.
	 * @param array<string, mixed> $row     The case row.
	 * @param string               $channel The portal channel.
	 *
	 * @return void
	 */
	private function apply(RunningCaseStore $cases, array $row, string $channel): void {
		$caseId = trim((string)($row['id'] ?? (($row['@self'] ?? [])['id'] ?? '')));
		if ($caseId === '') {
			return;
		}

		if ($channel === self::PHONE) {
			$this->tell(caseId: $caseId, message: 'The applicant prefers to be phoned.', channel: $channel);
			return;
		}

		$dossiqChannel = self::CHANNELS[$channel];
		if ((string)($row['communicationChannel'] ?? '') === $dossiqChannel) {
			return;
		}

		$this->patchObjectAsArray(
			objectService: $cases->objectService,
			register: $cases->register,
			schema: $cases->schema,
			id: $caseId,
			changes: ['communicationChannel' => $dossiqChannel],
		);
		$this->tell(
			caseId: $caseId,
			message: 'Contact channel changed to ' . $dossiqChannel . ' by the applicant in the portal',
			channel: $dossiqChannel
		);
	}//end apply()

	/**
	 * Put the change on the case's timeline, for the handler.
	 *
	 * @param string $caseId  The case.
	 * @param string $message The sentence.
	 * @param string $channel The channel it names.
	 *
	 * @return void
	 */
	private function tell(string $caseId, string $message, string $channel): void {
		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKindsInterface::APPLICANT_RESPONSE,
			message: $message,
			fields: ['act' => 'contact-channel', 'channel' => $channel],
			visibility: CaseTimelineInterface::INTERNAL,
		);
	}//end tell()
}//end class
