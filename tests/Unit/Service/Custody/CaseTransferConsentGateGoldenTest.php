<?php

/**
 * CaseTransferConsentGate golden test
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Custody
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Custody;

use OCA\Dossiq\Service\Custody\CaseTransferConsentGate;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden test for CaseTransferConsentGate::assess(): the verdict, every store and settings call, and the logs.
 *
 * @covers \OCA\Dossiq\Service\Custody\CaseTransferConsentGate
 * @uses   \OCA\Dossiq\Service\Custody\ConsentStanding
 */
class CaseTransferConsentGateGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Run one assessment in a world and observe it.
	 *
	 * @param array<string, mixed> $world The settings, the stored objects and consents, and the hand-off.
	 *
	 * @return array<string, mixed> The verdict, the calls in order and the log lines.
	 */
	private function observe(array $world): array {
		$calls = [];
		$logger = new class extends AbstractLogger {
			/**
			 * The log lines.
			 *
			 * @var array<int, mixed>
			 */
			public array $lines = [];

			/**
			 * Keep one log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed>       $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [$level, (string)$message, $context];
			}
		};

		$store = new class($world, $calls) {
			/**
			 * @param array<string, mixed> $world The world.
			 * @param array<int, mixed>    $calls The call log.
			 */
			public function __construct(private array $world, private array &$calls) {
			}

			/**
			 * Answer one stored object by schema and id.
			 *
			 * @param int|string $id       The id.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return mixed The object.
			 */
			public function find(int|string $id, int|string $register = '', int|string $schema = ''): mixed {
				$this->calls[] = ['find', $id, $register, $schema];
				$found = ($this->world['objects'][$schema][$id] ?? 'missing');
				if ($found === 'missing') {
					throw new DoesNotExistException('not found');
				}

				if ($found === 'throw') {
					throw new RuntimeException('store down');
				}

				return $found;
			}

			/**
			 * Answer the consents on a case.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return mixed The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): mixed {
				$this->calls[] = ['searchObjectsBySlug', $register, $schema, $filters];
				if (($this->world['consents'] ?? null) === 'throw') {
					throw new RuntimeException('search down');
				}

				return ($this->world['consents'] ?? []);
			}
		};

		$config = ($world['config'] ?? []) + [
			'register' => 'dossiq',
			'case_schema' => 'case',
			'case_type_schema' => 'caseType',
			'consent_schema' => 'toestemming',
		];
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturnCallback(
			static function () use (&$calls, $world, $store): ?object {
				$calls[] = ['getObjectService'];
				if (($world['noStore'] ?? false) === true) {
					return null;
				}

				return $store;
			}
		);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = '') use (&$calls, $config): string {
				$calls[] = ['getConfigValue', $key];
				return ($config[$key] ?? $default);
			}
		);

		$gate = new CaseTransferConsentGate(settingsService: $settings, logger: $logger);
		$verdict = $gate->assess(
			caseId: ($world['caseId'] ?? 'case-1'),
			sourceOrganisation: ($world['source'] ?? 'gemeente-delft'),
			receivingOrg: ($world['receiver'] ?? 'zorgpartner-bv'),
			atDate: ($world['at'] ?? '2026-05-01'),
		);

		$observed = ['verdict' => $verdict, 'calls' => $calls, 'logs' => $logger->lines];
		if (($world['askRequired'] ?? false) === true) {
			$observed['required'] = $gate->consentRequiredInside(caseId: ($world['caseId'] ?? 'case-1'));
		}

		return $observed;
	}//end observe()

	/**
	 * A consent row.
	 *
	 * @param array<string, mixed> $fields The fields that differ from the default consent.
	 *
	 * @return array<string, mixed> The consent.
	 */
	private function consent(array $fields = []): array {
		return $fields + [
			'id' => 'consent-1',
			'caseId' => 'case-1',
			'grantedDate' => '2026-01-10',
			'validTo' => '2026-12-31',
			'withdrawn' => false,
			'recipientParties' => ['zorgpartner-bv'],
			'tegegevens' => ['ondersteuningsplan', ' ', 'beschikking '],
			'scope' => 'Alleen het ondersteuningsplan',
		];
	}//end consent()

	/**
	 * Every path through assess().
	 *
	 * @return void
	 */
	public function testGoldenAssess(): void {
		$objects = [
			'case' => ['case-1' => ['id' => 'case-1', 'caseType' => 'ct-wmo'], 'case-2' => ['id' => 'case-2', 'caseType' => 'ct-jeugd']],
			'caseType' => ['ct-wmo' => ['id' => 'ct-wmo'], 'ct-jeugd' => ['id' => 'ct-jeugd', 'consentRequiredInsideOrganisation' => true]],
		];
		$inside = [
			'objects' => $objects,
			'receiver' => ' Gemeente-Delft ',
			'consents' => [$this->consent(fields: ['recipientParties' => ['gemeente-delft']])],
		];
		$base = ['objects' => $objects, 'consents' => [$this->consent()]];
		$worlds = [
			'covering consent' => $base,
			'internal move, no consent needed' => $inside,
			'internal move, case type needs consent' => ['caseId' => 'case-2'] + $inside,
			'internal move, case type needs consent, none recorded' => ['caseId' => 'case-2', 'consents' => []] + $inside,
			'internal move, consent flag not boolean' => [
				'objects' => ['caseType' => ['ct-wmo' => ['consentRequiredInsideOrganisation' => 'true']]] + $objects,
			] + $inside,
			'internal move, case missing' => ['caseId' => 'case-404'] + $inside,
			'internal move, case without a case type' => ['objects' => ['case' => ['case-1' => ['id' => 'case-1']]] + $objects] + $inside,
			'internal move, case read throws' => ['objects' => ['case' => ['case-1' => 'throw']] + $objects] + $inside,
			'internal move, no store' => ['noStore' => true] + $inside,
			'internal move, no register' => ['config' => ['register' => '']] + $inside,
			'internal move, no case type schema' => ['config' => ['case_type_schema' => '']] + $inside,
			'internal move, empty case id' => ['caseId' => '  '] + $inside,
			'empty source crosses' => ['source' => ''] + $base,
			'empty receiver' => ['receiver' => '  '] + $base,
			'no consents' => ['consents' => []] + $base,
			'consent for another receiver' => ['consents' => [$this->consent(fields: ['recipientParties' => ['ander-bv']])]] + $base,
			'receiver matched without case or spaces' => ['receiver' => ' ZorgPartner-BV '] + $base,
			'recipient parties as a string' => ['consents' => [$this->consent(fields: ['recipientParties' => ' Zorgpartner-bv'])]] + $base,
			'scope falls back to its text' => ['consents' => [$this->consent(fields: ['tegegevens' => [' ']])]] + $base,
			'no scope at all' => ['consents' => [$this->consent(fields: ['tegegevens' => [], 'scope' => ' '])]] + $base,
			'until trimmed' => ['consents' => [$this->consent(fields: ['validTo' => ' 2026-12-31 '])]] + $base,
			'lapsed' => ['consents' => [$this->consent(fields: ['validTo' => '2026-04-01'])]] + $base,
			'withdrawn' => ['consents' => [$this->consent(fields: ['withdrawn' => true])]] + $base,
			'withdrawn as a string is withdrawn' => ['consents' => [$this->consent(fields: ['withdrawn' => 'true'])]] + $base,
			'lapsed and withdrawn' => [
				'consents' => [$this->consent(fields: ['withdrawn' => true]), $this->consent(fields: ['id' => 'c2', 'validTo' => '2026-03-01'])],
			] + $base,
			'last lapse named' => [
				'consents' => [$this->consent(fields: ['validTo' => '2026-02-01']), $this->consent(fields: ['id' => 'c2', 'validTo' => '2026-03-01'])],
			] + $base,
			'not yet granted' => ['consents' => [$this->consent(fields: ['grantedDate' => '2026-06-01'])]] + $base,
			'granted on the day' => ['at' => '2026-01-10', 'consents' => [$this->consent()]] + $base,
			'ends on the day' => ['at' => '2026-12-31', 'consents' => [$this->consent()]] + $base,
			'unreadable dates do not cover' => ['consents' => [$this->consent(fields: ['grantedDate' => 'soon', 'validTo' => 'later'])]] + $base,
			'no dates cover' => ['consents' => [$this->consent(fields: ['grantedDate' => '', 'validTo' => ''])]] + $base,
			'first covering consent wins' => [
				'consents' => [
					$this->consent(fields: ['validTo' => '2026-02-01']),
					$this->consent(fields: ['id' => 'c2', 'tegegevens' => ['dossier']]),
					$this->consent(fields: ['id' => 'c3']),
				],
			] + $base,
			'unreadable moment is now' => [
				'at' => 'yesterday-ish',
				'consents' => [$this->consent(fields: ['grantedDate' => '2000-01-01', 'validTo' => '2999-01-01'])],
			] + $base,
			'empty moment is now' => ['at' => '', 'consents' => [$this->consent(fields: ['grantedDate' => '2000-01-01', 'validTo' => '2999-01-01'])]] + $base,
			'consent search throws' => ['consents' => 'throw'] + $base,
			'no consent schema' => ['config' => ['consent_schema' => '']] + $base,
			'no store across' => ['noStore' => true] + $base,
		];

		$observed = [];
		foreach ($worlds as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden(name: 'case-transfer-consent-assess', value: $observed);
	}//end testGoldenAssess()

	/**
	 * A consent whose start or end date cannot be read does not cover the hand-off.
	 *
	 * @return void
	 */
	public function testAnUnreadableDateDoesNotCover(): void {
		$objects = ['case' => ['case-1' => ['id' => 'case-1', 'caseType' => 'ct-wmo']], 'caseType' => ['ct-wmo' => ['id' => 'ct-wmo']]];
		foreach (['grantedDate', 'validTo'] as $field) {
			$verdict = $this->observe(world: ['objects' => $objects, 'consents' => [$this->consent(fields: [$field => 'soon'])]])['verdict'];
			$this->assertFalse(condition: $verdict['allowed'], message: $field);
			$this->assertSame(expected: CaseTransferConsentGate::NO_CONSENT, actual: $verdict['rule'], message: $field);
		}

		$verdict = $this->observe(
			world: ['objects' => $objects, 'consents' => [$this->consent(fields: ['validTo' => 'later']), $this->consent(fields: ['id' => 'c2'])]]
		)['verdict'];
		$this->assertTrue(condition: $verdict['allowed'], message: 'a readable consent after it still covers');
		$this->assertSame(expected: 'c2', actual: $verdict['consent']['id']);
	}//end testAnUnreadableDateDoesNotCover()

	/**
	 * The truthy forms the register can hold mark a consent withdrawn; the falsy forms do not.
	 *
	 * @return void
	 */
	public function testTruthyWithdrawnFormsAreWithdrawn(): void {
		$objects = ['case' => ['case-1' => ['id' => 'case-1', 'caseType' => 'ct-wmo']], 'caseType' => ['ct-wmo' => ['id' => 'ct-wmo']]];
		foreach ([true, 'true', ' TRUE ', 1, '1'] as $value) {
			$verdict = $this->observe(world: ['objects' => $objects, 'consents' => [$this->consent(fields: ['withdrawn' => $value])]])['verdict'];
			$this->assertFalse(condition: $verdict['allowed'], message: var_export($value, true));
			$this->assertSame(expected: CaseTransferConsentGate::CONSENT_WITHDRAWN, actual: $verdict['rule'], message: var_export($value, true));
		}

		foreach ([false, 'false', 0, '0', '', null] as $value) {
			$verdict = $this->observe(world: ['objects' => $objects, 'consents' => [$this->consent(fields: ['withdrawn' => $value])]])['verdict'];
			$this->assertTrue(condition: $verdict['allowed'], message: var_export($value, true));
		}
	}//end testTruthyWithdrawnFormsAreWithdrawn()

	/**
	 * A move inside one organisation is refused when the case or its case type cannot be read, or the case has none.
	 *
	 * @return void
	 */
	public function testAnUnreadableInternalMoveIsRefused(): void {
		$objects = [
			'case' => ['case-1' => ['id' => 'case-1', 'caseType' => 'ct-wmo']],
			'caseType' => ['ct-wmo' => ['id' => 'ct-wmo']],
		];
		$inside = ['objects' => $objects, 'receiver' => 'gemeente-delft', 'consents' => []];
		$worlds = [
			'case read throws' => ['objects' => ['case' => ['case-1' => 'throw']] + $objects] + $inside,
			'case missing' => ['caseId' => 'case-404'] + $inside,
			'case type read throws' => ['objects' => ['caseType' => ['ct-wmo' => 'throw']] + $objects] + $inside,
			'case type missing' => ['objects' => ['caseType' => []] + $objects] + $inside,
			'no store' => ['noStore' => true] + $inside,
			'no register' => ['config' => ['register' => '']] + $inside,
			'no case schema' => ['config' => ['case_schema' => '']] + $inside,
			'no case type schema' => ['config' => ['case_type_schema' => '']] + $inside,
			'empty case id' => ['caseId' => ' '] + $inside,
			'case without a case type' => ['objects' => ['case' => ['case-1' => ['id' => 'case-1']]] + $objects] + $inside,
		];

		foreach ($worlds as $name => $world) {
			$observed = $this->observe(world: $world);
			$this->assertFalse(condition: $observed['verdict']['allowed'], message: $name);
			$this->assertSame(expected: CaseTransferConsentGate::CONSENT_UNCHECKED, actual: $observed['verdict']['rule'], message: $name);
			$this->assertSame(
				expected: 'Consent could not be checked, so the case was not handed on.',
				actual: $observed['verdict']['sentence'],
				message: $name
			);
			$this->assertFalse(condition: $observed['verdict']['crossesOrganisation'], message: $name);
			$this->assertSame(expected: 'warning', actual: ($observed['logs'][0][0] ?? null), message: $name);
		}

		$unreadable = $this->observe(world: ['askRequired' => true] + $worlds['case missing']);
		$this->assertTrue(condition: $unreadable['required'], message: 'unreadable is required');
		$demanding = ['caseType' => ['ct-wmo' => ['consentRequiredInsideOrganisation' => true]]] + $objects;
		$this->assertTrue(
			condition: $this->observe(world: ['askRequired' => true, 'objects' => $demanding] + $inside)['required'],
			message: 'the case type demands consent'
		);

		$readable = $this->observe(world: ['askRequired' => true] + $inside);
		$this->assertFalse(condition: $readable['required'], message: 'a readable case type without the rule is not required');
		$this->assertTrue(condition: $readable['verdict']['allowed'], message: 'a readable case type without the rule');
		$noType = $this->observe(world: ['askRequired' => true] + $worlds['case without a case type']);
		$this->assertTrue(condition: $noType['required'], message: 'a case without a case type needs consent');
	}//end testAnUnreadableInternalMoveIsRefused()
}//end class
