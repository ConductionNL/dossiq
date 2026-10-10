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

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
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
	 * @param SettingsService $settingsService OpenRegister access and the configured register.
	 * @param CaseTimeline    $timeline        The one seam that writes a timeline entry.
	 * @param LoggerInterface $logger          Where a failure is reported.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTimeline $timeline,
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
			$this->follow(subject: $subject, organisation: $organisation, channel: $channel);
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
	 * @param string $subject      The portal subject reference.
	 * @param string $organisation The organisation the event names, or ''.
	 * @param string $channel      The portal channel.
	 *
	 * @return void
	 */
	private function follow(string $subject, string $organisation, string $channel): void {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return;
		}

		$this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: function () use ($objectService, $register, $schema, $subject, $organisation, $channel): void {
				$rows = $this->searchObjectsAsArraysUnscoped(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					filters: ['portalSubject' => $subject, '_limit' => self::LIMIT],
				);
				foreach ($rows as $row) {
					if ($this->isRunning(row: $row, subject: $subject, organisation: $organisation) === false) {
						continue;
					}

					$this->apply(objectService: $objectService, register: $register, schema: $schema, row: $row, channel: $channel);
				}
			}
		);
	}//end follow()

	/**
	 * Whether a row is a running case of this subject in this organisation.
	 *
	 * @param array<string, mixed> $row          The case row.
	 * @param string               $subject      The portal subject reference.
	 * @param string               $organisation The organisation the event names, or ''.
	 *
	 * @return bool True when the channel should follow.
	 */
	private function isRunning(array $row, string $subject, string $organisation): bool {
		if ((string)($row['portalSubject'] ?? '') !== $subject) {
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
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register.
	 * @param string               $schema        The case schema.
	 * @param array<string, mixed> $row           The case row.
	 * @param string               $channel       The portal channel.
	 *
	 * @return void
	 */
	private function apply(object $objectService, string $register, string $schema, array $row, string $channel): void {
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
			objectService: $objectService,
			register: $register,
			schema: $schema,
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
			kind: TimelineKinds::APPLICANT_RESPONSE,
			message: $message,
			fields: ['act' => 'contact-channel', 'channel' => $channel],
			visibility: CaseTimeline::INTERNAL,
		);
	}//end tell()
}//end class
