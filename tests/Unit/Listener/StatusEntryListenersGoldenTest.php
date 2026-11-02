<?php

/**
 * Golden test for the listeners that act when an objection enters "Hearing planned".
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\BezwaarAdviceRequestedListener;
use OCA\Dossiq\Listener\BezwaarHearingScheduledListener;
use OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService;
use OCA\Dossiq\Service\Bezwaar\HearingService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What each listener does with each kind of update, per world.
 *
 * @covers \OCA\Dossiq\Listener\BezwaarAdviceRequestedListener
 * @covers \OCA\Dossiq\Listener\BezwaarHearingScheduledListener
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
class StatusEntryListenersGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The calls, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * The worlds: the new payload, the old payload, the configured schema slug and whether the service throws.
	 *
	 * @return array<string, array{0: array<string, mixed>|null, 1: array<string, mixed>|null, 2: string, 3: bool}>
	 */
	public static function worlds(): array {
		$moved = ['id' => 'b-1', 'status' => 'Hearing planned', '@self' => ['id' => 'b-self', 'schema' => 'objection']];
		$was = ['status' => 'Received'];
		$out = [];
		$worlds = [
			'enters the status' => [$moved, $was, 'objection', false],
			'schema named by url' => [array_replace($moved, ['@self' => ['schema' => 'https://x/schemas/objection']]), $was, 'objection', false],
			'schema at the top' => [['id' => 'b-2', 'schema' => 'objection', 'status' => 'Hearing planned'], $was, 'objection', false],
			'id as uuid' => [['uuid' => 'b-3', 'schema' => 'objection', 'status' => 'Hearing planned'], $was, 'objection', false],
			'no id' => [['schema' => 'objection', 'status' => 'Hearing planned'], $was, 'objection', false],
			'other schema' => [array_replace($moved, ['@self' => ['id' => 'x', 'schema' => 'case']]), $was, 'objection', false],
			'no schema on the object' => [['id' => 'b-4', 'status' => 'Hearing planned'], $was, 'objection', false],
			'no schema configured' => [$moved, $was, '', false],
			'another status' => [array_replace($moved, ['status' => 'Decided']), $was, 'objection', false],
			'already in the status' => [$moved, ['status' => 'Hearing planned'], 'objection', false],
			'no old object' => [$moved, null, 'objection', false],
			'no new object' => [null, $was, 'objection', false],
			'the service throws' => [$moved, $was, 'objection', true],
		];
		foreach (['advice' => BezwaarAdviceRequestedListener::class, 'hearing' => BezwaarHearingScheduledListener::class] as $key => $listener) {
			foreach ($worlds as $name => $world) {
				$out[$key . ' ' . $name] = array_merge([$listener], $world);
			}

			$out[$key . ' not an update'] = [$listener, null, null, 'objection', false];
		}

		return $out;
	}//end worlds()

	/**
	 * The calls match the recording.
	 *
	 * @param class-string              $listener The listener.
	 * @param array<string, mixed>|null $new      The new payload, or null.
	 * @param array<string, mixed>|null $old      The old payload, or null.
	 * @param string                    $slug     The configured schema slug.
	 * @param bool                      $throws   Whether the service throws.
	 *
	 * @dataProvider worlds
	 *
	 * @return void
	 */
	public function testTheListenerMatchesTheRecording(string $listener, ?array $new, ?array $old, string $slug, bool $throws): void {
		$event = $this->event(new: $new, old: $old, update: str_ends_with($this->dataName(), 'not an update') === false);
		$this->listener(class: $listener, slug: $slug, throws: $throws)->handle($event);

		$name = trim(strtolower((string)preg_replace('/[^a-z]+/i', '-', $this->dataName())), '-');
		$this->assertMatchesGolden(name: 'status-entry-listeners/' . $name, value: $this->log);
	}//end testTheListenerMatchesTheRecording()

	/**
	 * The event: an update carrying the payloads, or a plain event.
	 *
	 * @param array<string, mixed>|null $new    The new payload.
	 * @param array<string, mixed>|null $old    The old payload.
	 * @param bool                      $update Whether it is an update.
	 *
	 * @return Event
	 */
	private function event(?array $new, ?array $old, bool $update): Event {
		if ($update === false) {
			return new Event();
		}

		$newEntity = $this->createMock(originalClassName: ObjectEntity::class);
		$newEntity->method('jsonSerialize')->willReturn($new ?? []);
		$oldEntity = null;
		if ($old !== null) {
			$oldEntity = $this->createMock(originalClassName: ObjectEntity::class);
			$oldEntity->method('jsonSerialize')->willReturn($old);
		}

		return new ObjectUpdatedEvent($newEntity, $oldEntity);
	}//end event()

	/**
	 * The listener over recording collaborators.
	 *
	 * @param class-string $class  The listener.
	 * @param string       $slug   The configured schema slug.
	 * @param bool         $throws Whether the service throws.
	 *
	 * @return object
	 */
	private function listener(string $class, string $slug, bool $throws): object {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key) use ($slug): string {
				$this->log[] = ['getConfigValue' => $key];
				return $slug;
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message): void {
				$this->log[] = ['debug' => $message];
			}
		);

		$record = function (string $objectionId) use ($throws): void {
			$this->log[] = ['act' => $objectionId];
			if ($throws === true) {
				throw new RuntimeException('service down');
			}
		};

		if ($class === BezwaarAdviceRequestedListener::class) {
			$service = $this->createMock(originalClassName: AdvisoryCommitteeService::class);
			$service->method('autoAssignDefaultCommittee')->willReturnCallback(
				function (string $objectionId) use ($record): array {
					$record($objectionId);
					return [];
				}
			);

			return new BezwaarAdviceRequestedListener(bacService: $service, settingsService: $settings, logger: $logger);
		}

		$service = $this->createMock(originalClassName: HearingService::class);
		$service->method('seedDefaultHearing')->willReturnCallback(
			function (string $objectionId) use ($record): ?array {
				$record($objectionId);
				return null;
			}
		);

		return new BezwaarHearingScheduledListener(hearingService: $service, settingsService: $settings, logger: $logger);
	}//end listener()
}//end class
