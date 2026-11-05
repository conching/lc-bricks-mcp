<?php
/**
 * Unit test: deterministic feedback triage stays local and conservative.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap-simple.php';
require_once __DIR__ . '/../../includes/Feedback/TriageService.php';

use LCBricksMCP\Feedback\TriageService;

$triage = new TriageService();

$copy = $triage->triage( 'Please fix the typo in this headline.' );
lc_assert_same( 'copy', $copy['category'], 'copy vocabulary maps to copy' );
lc_assert_same( 'low', $copy['risk'], 'copy-only request is low risk' );

$layout = $triage->triage( 'The mobile spacing and alignment need adjustment.' );
lc_assert_same( 'layout', $layout['category'], 'responsive layout vocabulary maps to layout' );
lc_assert_same( 'medium', $layout['risk'], 'layout request is medium risk' );

$functional = $triage->triage( 'Please change the checkout tracking script.' );
lc_assert_same( 'functional', $functional['category'], 'behavioral request maps to functional' );
lc_assert_same( 'high', $functional['risk'], 'behavioral request is high risk' );

$unclear = $triage->triage( 'This does not feel quite right.' );
lc_assert_same( 'unclear', $unclear['category'], 'ambiguous feedback does not guess' );
lc_assert_same( 'unknown', $unclear['risk'], 'ambiguous feedback has unknown risk' );

lc_test_done( 'FeedbackTriageTest' );
