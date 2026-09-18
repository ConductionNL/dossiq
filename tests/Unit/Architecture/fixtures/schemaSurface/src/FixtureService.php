<?php

/**
 * A fixture reader, so the scanner has something to find.
 *
 * It also mentions mentionedInProse here as a bare word inside a sentence,
 * which is the toestemming case in miniature: a word in a comment is not a
 * reader, and a scanner that counted it would call a dark schema reachable.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture\Fixtures\SchemaSurface;

/**
 * Reads one schema by name.
 */
class FixtureService {
	/**
	 * The slug this service reads.
	 *
	 * @return string The slug.
	 */
	public function slug(): string {
		return 'quotedInCode';
	}//end slug()
}//end class
