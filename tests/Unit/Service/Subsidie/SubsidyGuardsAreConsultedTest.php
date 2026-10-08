<?php

/**
 * The two subsidy calculators are actually asked.
 *
 * `CofinancieringValidator` and `StaatssteunClassifier` shipped with the
 * subsidy chain, both with their own suites, and nothing called either. So an
 * application whose budget did not add up was accepted at intake and discovered
 * at the beschikking, if at all, by which point a decision term had been
 * running for weeks; and `subsidieBeschikking.stateAidCategory` was a declared
 * field no code ever wrote, so a grant above the de-minimis ceiling looked
 * exactly like one below it.
 *
 * Their own suites were green throughout, because a pure calculator answers the
 * same whether anybody asks it or not. These tests assert the one thing those
 * suites could not: that the answer reaches the record.
 *
 * 🔴 THE FIXTURES SPEAK THE DECLARED SHAPE, not the implementation's. Both
 * lists are declared as JSON STRINGS, co-financing rows carry `bedrag`, and
 * `budget` is a list of cost items rather than a number. Writing the fixtures
 * from the schema is what exposed that `sumBedragen()` read `amount` and that
 * casting `budget` to float yields zero: a suite written from the code would
 * have agreed with the code and neither would have agreed with the data.
 *
 * 🔴 EACH WIRING HAS A CONTROL. A guard that refused every application would
 * pass the negative test, and a classifier that stamped one category on
 * everything would pass the positive one. So the applications every caller
 * sends today, with no co-financing declared and no amounts to classify, are
 * asserted to pass through untouched.
 *
 * MUTATION-CHECKED 2026-09-18: removing the `assertCofinancieringReconciles`
 * call reddens testAnApplicationWhoseBudgetDoesNotAddUpIsRefused; removing the
 * `stateAidCategory` key from the draft reddens
 * testTheGrantIsClassifiedAgainstTheDeMinimisCeiling; and returning the
 * classifier's answer over a declared one reddens
 * testACategoryTheHandlerTypedIsKept. Restored after.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Subsidie
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/subsidieverlening-keten/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Subsidie;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Subsidie\BeschikkingService;
use OCA\Dossiq\Service\Subsidie\SubsidieService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Intake refuses a budget that does not reconcile; a draft carries its ground.
 *
 * @covers \OCA\Dossiq\Service\Subsidie\SubsidieService::createAanvraag
 * @covers \OCA\Dossiq\Service\Subsidie\BeschikkingService::createDraft
 * @uses \OCA\Dossiq\Service\Subsidie\CofinancieringValidator
 * @uses \OCA\Dossiq\Service\Subsidie\StaatssteunClassifier
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Subsidie\BeschikkingService
 * @uses \OCA\Dossiq\Service\Subsidie\SubsidieService
 *
 * @spec openspec/specs/subsidieverlening-keten/spec.md
 */
class SubsidyGuardsAreConsultedTest extends TestCase {

	/**
	 * The store both services read and write.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * An empty register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
	}//end setUp()

	/**
	 * An application whose co-financing does not reach the project total is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md
	 */
	public function testAnApplicationWhoseBudgetDoesNotAddUpIsRefused(): void {
		try {
			$this->subsidies()->createAanvraag(
				payload: [
					'subsidyScheme' => 'regeling-1',
					'requestedAmount' => 10000.0,
					'budget' => '[{"kostenpost": "bouw", "bedrag": 100000.0}]',
					'coFinancingList' => '[{"partij": "provincie", "bedrag": 5000.0}]',
				]
			);
			self::fail('A budget that does not reconcile must be refused at intake.');
		} catch (OCSBadRequestException $e) {
			self::assertStringContainsString(
				'COFIN_SUM_MISMATCH',
				$e->getMessage(),
				'The validator\'s own code travels, because a typo in the total and a missing contribution are different problems.',
			);
		}

		self::assertSame(
			[],
			$this->store->all(schema: 'subsidieAanvraag'),
			'And nothing was stored: an application accepted now is a term running now.',
		);
	}//end testAnApplicationWhoseBudgetDoesNotAddUpIsRefused()

	/**
	 * A declared co-financing that reconciles is accepted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md
	 */
	public function testAnApplicationThatReconcilesIsAccepted(): void {
		$stored = $this->subsidies()->createAanvraag(
			payload: [
				'subsidyScheme' => 'regeling-1',
				'requestedAmount' => 60000.0,
				'budget' => '[{"kostenpost": "bouw", "bedrag": 100000.0}]',
				'coFinancingList' => '[{"partij": "provincie", "bedrag": 40000.0}]',
			]
		);

		self::assertSame('received', $stored['status']);
	}//end testAnApplicationThatReconcilesIsAccepted()

	/**
	 * An application declaring no co-financing passes through untouched.
	 *
	 * The control. Without it, a guard that refused everything would pass the
	 * negative test above, and every application in the gemeente would stop.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md
	 */
	public function testAnApplicationWithNoCoFinancingIsUntouched(): void {
		$stored = $this->subsidies()->createAanvraag(
			payload: ['subsidyScheme' => 'regeling-1', 'requestedAmount' => 10000.0]
		);

		self::assertSame('received', $stored['status']);
		self::assertCount(1, $this->store->all(schema: 'subsidieAanvraag'));
	}//end testAnApplicationWithNoCoFinancingIsUntouched()

	/**
	 * A grant above the de-minimis ceiling is classified as such.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md
	 */
	public function testTheGrantIsClassifiedAgainstTheDeMinimisCeiling(): void {
		$small = $this->beschikkingen()->createDraft(
			requestId: 'aanvraag-1',
			payload: ['grantedAmount' => 1000.0],
			sequence: 1,
		);
		$large = $this->beschikkingen()->createDraft(
			requestId: 'aanvraag-2',
			payload: ['grantedAmount' => 900000.0],
			sequence: 2,
		);

		self::assertSame('de_minimis', $small['stateAidCategory']);
		self::assertSame(
			'notificatieplicht',
			$large['stateAidCategory'],
			'A grant above the ceiling looked exactly like one below it while nothing wrote this field.',
		);
	}//end testTheGrantIsClassifiedAgainstTheDeMinimisCeiling()

	/**
	 * A category the handler typed is kept.
	 *
	 * A handler who has asserted an AGVV article has looked at something the
	 * amounts do not contain, and overwriting them makes the field unusable
	 * the moment anybody uses it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md
	 */
	public function testACategoryTheHandlerTypedIsKept(): void {
		$drafted = $this->beschikkingen()->createDraft(
			requestId: 'aanvraag-3',
			payload: ['grantedAmount' => 900000.0, 'stateAidCategory' => 'daeb'],
			sequence: 3,
		);

		self::assertSame('daeb', $drafted['stateAidCategory']);
	}//end testACategoryTheHandlerTypedIsKept()

	/**
	 * The intake service under test.
	 *
	 * The REAL validator, not a double: the point of this suite is that it is
	 * consulted, and a double would let a service that decided for itself pass.
	 *
	 * @return SubsidieService The service.
	 */
	private function subsidies(): SubsidieService {
		return new SubsidieService(settingsService: $this->settings(), logger: new NullLogger());
	}//end subsidies()

	/**
	 * The beschikking service under test, with the real classifier.
	 *
	 * @return BeschikkingService The service.
	 */
	private function beschikkingen(): BeschikkingService {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new BeschikkingService(
			settingsService: $this->settings(),
			subsidyService: $this->subsidies(),
			userSession: $session,
			logger: new NullLogger(),
		);
	}//end beschikkingen()

	/**
	 * A settings service that answers the in-memory store and the slugs it holds.
	 *
	 * @return SettingsService The settings service.
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'subsidie_aanvraag_schema' => 'subsidieAanvraag',
					'subsidie_beschikking_schema' => 'subsidieBeschikking',
				];

				return ($map[$key] ?? $default);
			}
		);

		return $settings;
	}//end settings()
}//end class
