<?php

/**
 * The portal-request drain, end to end over fakes.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Command;

use OCA\Dossiq\Command\DrainIntakeObjectsCommand;
use OCA\Dossiq\Service\Intake\IntakeCaseTypes;
use OCA\Dossiq\Service\Intake\IntakeObjectDrain;
use OCA\Dossiq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Dry run by default, writes with --apply, exits 0 only when nothing is pending.
 *
 * @covers \OCA\Dossiq\Command\DrainIntakeObjectsCommand
 * @uses \OCA\Dossiq\Service\Intake\IntakeObjectDrain
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
 */
class DrainIntakeObjectsCommandTest extends TestCase {

	/**
	 * The fake object service: portal requests in, saves recorded.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The fake form submit: submits recorded.
	 *
	 * @var object
	 */
	private object $submitter;

	/**
	 * Build the command over fakes.
	 *
	 * @param bool $withSubmit Whether OpenRegister ships the form submit.
	 *
	 * @return CommandTester The tester.
	 */
	private function tester(bool $withSubmit = true): CommandTester {
		$this->objects = new class {
			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			/**
			 * @param array<string, mixed> $query         The query.
			 * @param bool                 $_rbac         Whether RBAC applies.
			 * @param bool                 $_multitenancy Whether multitenancy applies.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				if (($query['@self']['schema'] ?? null) === 9) {
					return [['id' => 'pv-1', 'kind' => 'bezwaarschrift', 'rationale' => 'Niet eens', 'submitterRef' => 's1']];
				}

				return [];
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string               $uuid     The uuid.
			 *
			 * @return array<string, mixed> The object.
			 */
			public function saveObject(array $object, string $register, string $schema, string $uuid): array {
				$this->saved[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid];
				return $object;
			}
		};

		$this->submitter = new class {
			/** @var array<int, array<string, mixed>> */
			public array $submits = [];

			/**
			 * @param array<string, mixed>      $destination The destination.
			 * @param array<string, mixed>|null $mapping     The mapping.
			 * @param array<string, mixed>      $payload     The payload.
			 * @param IUser|null                $subject     The subject.
			 *
			 * @return array<string, mixed> The answer.
			 */
			public function submit(array $destination, ?array $mapping, array $payload, ?IUser $subject = null): array {
				$this->submits[] = ['destination' => $destination, 'payload' => $payload];
				return ['id' => 'case-new', 'reference' => '2026-0001'];
			}
		};

		$settings = ['register' => '3', 'case_schema' => '5', 'portaal_verzoek_schema' => '9', 'case_type_schema' => '7'];
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($settings[$key] ?? $default)
		);
		$settingsService->method('getObjectService')->willReturn($this->objects);

		$submitter = $this->submitter;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($withSubmit, $submitter): object {
				if ($withSubmit === false) {
					throw new RuntimeException('not found');
				}

				return $submitter;
			}
		);

		$caseTypes = $this->createMock(IntakeCaseTypes::class);
		$caseTypes->method('caseTypeFor')->willReturn('aaaaaaaa-1111-4111-a111-111111111111');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		return new CommandTester(
			new DrainIntakeObjectsCommand(
				container: $container,
				settingsService: $settingsService,
				drain: new IntakeObjectDrain(caseTypes: $caseTypes),
				userSession: $session,
				groupManager: $this->createMock(IGroupManager::class),
			)
		);
	}//end tester()

	/**
	 * Without --apply nothing is written, and the pending request fails the exit code.
	 *
	 * @return void
	 */
	public function testADryRunWritesNothingAndReportsPending(): void {
		$tester = $this->tester();

		$this->assertSame(Command::FAILURE, $tester->execute([]));
		$this->assertSame([], $this->submitter->submits);
		$this->assertSame([], $this->objects->saved);
		$this->assertStringContainsString('pending 1', $tester->getDisplay());
	}//end testADryRunWritesNothingAndReportsPending()

	/**
	 * With --apply the request becomes a case in the case schema and is marked with its case id.
	 *
	 * @return void
	 */
	public function testApplyOpensTheCaseAndMarksTheRequest(): void {
		$tester = $this->tester();

		$this->assertSame(Command::SUCCESS, $tester->execute(['--apply' => true]));
		$this->assertSame(['register' => '3', 'schema' => '5'], $this->submitter->submits[0]['destination']);
		$this->assertSame('pv-1', $this->submitter->submits[0]['payload']['externalReference']);
		$this->assertSame('9', $this->objects->saved[0]['schema']);
		$this->assertSame('pv-1', $this->objects->saved[0]['uuid']);
		$this->assertSame('case-new', $this->objects->saved[0]['object']['newCaseId']);
		$this->assertStringContainsString('Delivered 1, already a case 0, refused 0, pending 0.', $tester->getDisplay());
	}//end testApplyOpensTheCaseAndMarksTheRequest()

	/**
	 * An OpenRegister without the form submit is a failure, not a silent pass.
	 *
	 * @return void
	 */
	public function testWithoutTheFormSubmitItFails(): void {
		$tester = $this->tester(withSubmit: false);

		$this->assertSame(Command::FAILURE, $tester->execute(['--apply' => true]));
		$this->assertStringContainsString('form submit', $tester->getDisplay());
	}//end testWithoutTheFormSubmitItFails()
}//end class
