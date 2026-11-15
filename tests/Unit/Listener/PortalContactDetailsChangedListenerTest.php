<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/portal-contact-channel-follows-the-resident/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\PortalContactDetailsChangedListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-PORTAL-016: the channel a resident picks in the portal reaches their running cases.
 */
class PortalContactDetailsChangedListenerTest extends TestCase {
	/** @var object The object service double holding the cases. */
	private object $objects;

	/** @var CaseTimeline&MockObject The timeline double. */
	private CaseTimeline&MockObject $timeline;

	/** @var array<int, array{0: string, 1: string}> Every entry as [case, message]. */
	private array $entries = [];

	/** @var LoggerInterface&MockObject The logger double. */
	private LoggerInterface&MockObject $logger;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/** @var array<string, array<string, mixed>> The cases by id. */
			public array $cases = [];

			/** @var array<int, array<string, mixed>> Every search as its filters and scope. */
			public array $searches = [];

			/** @var bool Whether the last write ran as the system. */
			public bool $asSystem = false;

			/** @var bool Whether a write ran outside the system identity. */
			public bool $wroteAsCaller = false;

			/** @var bool Whether the search throws. */
			public bool $broken = false;

			/**
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param array<string, mixed> $filters The filters.
			 * @param bool $_rbac Whether RBAC applies.
			 * @param bool $_multitenancy Whether tenancy applies.
			 *
			 * @return array<int, array<string, mixed>> The matching cases.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->broken === true) {
					throw new RuntimeException('register gone');
				}

				$this->searches[] = ['filters' => $filters, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return array_values(
					array_filter(
						$this->cases,
						static fn (array $c): bool => ($c['portalSubject'] ?? '') === ($filters['portalSubject'] ?? null)
					)
				);
			}

			/**
			 * @param string $objectId The case.
			 * @param array<string, mixed> $data The fields.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return array<string, mixed> The case.
			 */
			public function patchObject(string $objectId, array $data, string $register, string $schema): array {
				if ($this->asSystem === false) {
					$this->wroteAsCaller = true;
				}

				$this->cases[$objectId] = array_merge($this->cases[$objectId], $data);
				return $this->cases[$objectId];
			}

			/**
			 * @param callable $work The work.
			 *
			 * @return mixed The work's answer.
			 */
			public function runAsSystem(callable $work): mixed {
				$this->asSystem = true;
				try {
					return $work();
				} finally {
					$this->asSystem = false;
				}
			}
		};
		$this->objects->cases = [
			'case-run' => ['id' => 'case-run', 'portalSubject' => 'subj-anna', 'communicationChannel' => 'email', 'endDate' => null, '@self' => ['organisation' => 'org-1']],
			'case-ended' => ['id' => 'case-ended', 'portalSubject' => 'subj-anna', 'communicationChannel' => 'email', 'endDate' => '2026-09-01', '@self' => ['organisation' => 'org-1']],
			'case-other-org' => ['id' => 'case-other-org', 'portalSubject' => 'subj-anna', 'communicationChannel' => 'email', '@self' => ['organisation' => 'org-2']],
			'case-bob' => ['id' => 'case-bob', 'portalSubject' => 'subj-bob', 'communicationChannel' => 'email'],
		];
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? $default)
		);
		$this->timeline = $this->createMock(originalClassName: CaseTimeline::class);
		$this->timeline->method('record')->willReturnCallback(
			function (string $caseId, string $kind, string $message): string {
				$this->entries[] = [$caseId, $message];
				return 'entry';
			}
		);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->listener = new PortalContactDetailsChangedListener(settingsService: $settings, timeline: $this->timeline, logger: $this->logger);
	}//end setUp()

	/** @var PortalContactDetailsChangedListener The listener under test. */
	private PortalContactDetailsChangedListener $listener;

	/**
	 * An event shaped like portaliq's.
	 *
	 * @param string $channel The channel.
	 * @param string $subject The subject.
	 *
	 * @return Event The event.
	 */
	private function event(string $channel, string $subject = 'subj-anna'): Event {
		return new class($subject, $channel) extends Event {
			/**
			 * @param string $subject The subject.
			 * @param string $channel The channel.
			 */
			public function __construct(private string $subject, private string $channel) {
				parent::__construct();
			}

			/** @return string The subject. */
			public function getSubjectRef(): string {
				return $this->subject;
			}

			/** @return string The organisation. */
			public function getOrganisation(): string {
				return 'org-1';
			}

			/** @return string The channel. */
			public function getChannel(): string {
				return $this->channel;
			}
		};
	}//end event()

	/**
	 * @return void
	 */
	public function testAResidentAsksForLettersByPost(): void {
		$this->listener->handle(event: $this->event(channel: 'post'));

		$this->assertSame('post', $this->objects->cases['case-run']['communicationChannel']);
		$this->assertSame('email', $this->objects->cases['case-ended']['communicationChannel'], 'an ended case is left alone');
		$this->assertSame('email', $this->objects->cases['case-other-org']['communicationChannel'], 'another organisation is left alone');
		$this->assertSame('email', $this->objects->cases['case-bob']['communicationChannel']);
		$this->assertSame([['case-run', 'Contact channel changed to post by the applicant in the portal']], $this->entries);
		$this->assertFalse($this->objects->wroteAsCaller, 'the write runs as the system, not as the portal caller');
		$this->assertSame(['filters' => ['portalSubject' => 'subj-anna', '_limit' => 500], 'rbac' => false, 'multitenancy' => false], $this->objects->searches[0]);
	}//end testAResidentAsksForLettersByPost()

	/**
	 * @return void
	 */
	public function testAResidentAsksToBePhoned(): void {
		$this->listener->handle(event: $this->event(channel: 'phone'));

		$this->assertSame('email', $this->objects->cases['case-run']['communicationChannel']);
		$this->assertSame([['case-run', 'The applicant prefers to be phoned.']], $this->entries);
	}//end testAResidentAsksToBePhoned()

	/**
	 * The same channel again writes nothing and tells nothing.
	 *
	 * @return void
	 */
	public function testTheSameChannelAgainChangesNothing(): void {
		$this->listener->handle(event: $this->event(channel: 'email'));

		$this->assertSame([], $this->entries);
	}//end testTheSameChannelAgainChangesNothing()

	/**
	 * An unknown channel, an event without the getters, and a broken register
	 * change nothing and never throw into portaliq's request.
	 *
	 * @return void
	 */
	public function testNothingUnknownOrBrokenReachesTheCases(): void {
		$this->listener->handle(event: $this->event(channel: 'pigeon'));
		$this->listener->handle(event: new Event());
		$this->assertSame([], $this->objects->searches);

		$this->objects->broken = true;
		$this->logger->expects($this->once())->method('error');
		$this->listener->handle(event: $this->event(channel: 'post'));

		$this->assertSame('email', $this->objects->cases['case-run']['communicationChannel']);
		$this->assertSame([], $this->entries);
	}//end testNothingUnknownOrBrokenReachesTheCases()

	/**
	 * The slug the listener writes is one the case schema takes.
	 *
	 * @return void
	 */
	public function testTheCaseSchemaTakesEverySlugTheListenerWrites(): void {
		$register = new RealSchemaValidator();
		foreach (PortalContactDetailsChangedListener::CHANNELS as $slug) {
			$this->assertSame(
				[],
				$register->errors(slug: 'case', payload: ['communicationChannel' => $slug], creating: false),
				$slug
			);
		}
	}//end testTheCaseSchemaTakesEverySlugTheListenerWrites()
}//end class
