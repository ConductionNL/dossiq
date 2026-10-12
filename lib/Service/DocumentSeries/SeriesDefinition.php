<?php

/**
 * Dossiq Series Definition.
 *
 * How one numbered document series is configured on a case type: the prefix
 * printed in front of a number, the order a set of documents is numbered in,
 * how wide the number is printed, and what happens to a gap.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\DocumentSeries
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
 * @spec openspec/specs/numbered-document-series/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\DocumentSeries;

use InvalidArgumentException;

/**
 * One series' configuration, read from `documentSeries.<series>` on a case type.
 *
 * It names no procedure. A package sets the values; the code only reads them.
 * A value the schema would not accept is refused here too, loudly, because a
 * series that silently falls back to a different order renumbers a set that
 * a reader has already cited.
 *
 * @spec openspec/specs/numbered-document-series/spec.md
 */
final class SeriesDefinition {

	/**
	 * Every listing numbers its documents 1..n again, so a removed document leaves no gap.
	 *
	 * @var string
	 */
	public const GAP_RENUMBER = 'renumber';

	/**
	 * A document keeps the number it was given; a removed document leaves its gap.
	 *
	 * @var string
	 */
	public const GAP_KEEP = 'keep';

	/**
	 * The gap policies a series may declare.
	 *
	 * @var array<int, string>
	 */
	public const GAP_POLICIES = [self::GAP_RENUMBER, self::GAP_KEEP];

	/**
	 * The default printed width of a number within a set.
	 *
	 * @var int
	 */
	public const DEFAULT_WIDTH = 2;

	/**
	 * The widest number a series may print.
	 *
	 * @var int
	 */
	private const MAX_WIDTH = 9;

	/**
	 * The field a kept number is stored in, unless the series names another.
	 *
	 * @var string
	 */
	public const DEFAULT_NUMBER_FIELD = 'sequence';

	/**
	 * Constructor.
	 *
	 * @param string $series The series name.
	 * @param string $prefix Printed before the number; '' for none.
	 * @param array<int, array{field: string, direction: string}> $order Sort keys, first key first.
	 * @param int $width Zero-padded width of the number.
	 * @param string $gapPolicy One of GAP_POLICIES.
	 * @param string $numberField Where a kept number is stored.
	 *
	 * @throws InvalidArgumentException When a value is outside what the schema allows.
	 */
	public function __construct(
		public readonly string $series,
		public readonly string $prefix = '',
		public readonly array $order = [],
		public readonly int $width = self::DEFAULT_WIDTH,
		public readonly string $gapPolicy = self::GAP_RENUMBER,
		public readonly string $numberField = self::DEFAULT_NUMBER_FIELD,
	) {
		if (in_array($gapPolicy, self::GAP_POLICIES, true) === false) {
			throw new InvalidArgumentException(sprintf('Series %s declares an unknown gap policy "%s".', $series, $gapPolicy));
		}

		if ($width < 1 || $width > self::MAX_WIDTH) {
			throw new InvalidArgumentException(sprintf('Series %s declares width %d; it must be 1 to %d.', $series, $width, self::MAX_WIDTH));
		}

		foreach ($order as $key) {
			if (in_array(($key['direction'] ?? ''), ['asc', 'desc'], true) === false || trim((string)($key['field'] ?? '')) === '') {
				throw new InvalidArgumentException(
					sprintf('Series %s declares an order key without a field, or a direction other than asc or desc.', $series)
				);
			}
		}
	}//end __construct()

	/**
	 * Read a series from a case type, with the caller's defaults for what it leaves out.
	 *
	 * @param array<string, mixed> $caseType The effective case type.
	 * @param string $series The series name.
	 * @param string $defaultPrefix The prefix when the case type configures none.
	 *
	 * @return self The definition.
	 *
	 * @throws InvalidArgumentException When the case type declares a value the schema refuses.
	 *
	 * @spec openspec/specs/numbered-document-series/spec.md
	 */
	public static function fromCaseType(array $caseType, string $series, string $defaultPrefix = ''): self {
		$config = ($caseType[DocumentSeriesNumberer::CASE_TYPE_KEY][$series] ?? []);
		if (is_array($config) === false) {
			$config = [];
		}

		return self::fromArray(series: $series, config: $config, defaultPrefix: $defaultPrefix);
	}//end fromCaseType()

	/**
	 * Read a series from its configuration block.
	 *
	 * @param string $series The series name.
	 * @param array<string, mixed> $config The block under `documentSeries.<series>`.
	 * @param string $defaultPrefix The prefix when the block names none.
	 *
	 * @return self The definition.
	 *
	 * @throws InvalidArgumentException When the block declares a value the schema refuses.
	 *
	 * @spec openspec/specs/numbered-document-series/spec.md
	 */
	public static function fromArray(string $series, array $config, string $defaultPrefix = ''): self {
		$prefix = $defaultPrefix;
		if (is_string(($config['prefix'] ?? null)) === true && trim($config['prefix']) !== '') {
			$prefix = trim($config['prefix']);
		}

		$order = [];
		foreach ((array)($config['order'] ?? []) as $key) {
			if (is_string($key) === true) {
				$key = ['field' => $key];
			}

			$order[] = [
				'field' => trim((string)(((array)$key)['field'] ?? '')),
				'direction' => strtolower((string)(((array)$key)['direction'] ?? 'asc')),
			];
		}

		$numberField = self::DEFAULT_NUMBER_FIELD;
		if (is_string(($config['numberField'] ?? null)) === true && trim($config['numberField']) !== '') {
			$numberField = trim($config['numberField']);
		}

		return new self(
			series: $series,
			prefix: $prefix,
			order: $order,
			width: (int)($config['width'] ?? self::DEFAULT_WIDTH),
			gapPolicy: (string)($config['gapPolicy'] ?? self::GAP_RENUMBER),
			numberField: $numberField,
		);
	}//end fromArray()
}//end class
