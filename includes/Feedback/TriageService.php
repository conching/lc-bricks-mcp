<?php
/**
 * Deterministic first-pass feedback triage.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace LCBricksMCP\Feedback;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Categorizes comments without sending client feedback to another AI service.
 */
final class TriageService {

	/**
	 * Triage a feedback comment.
	 *
	 * @return array{category: string, risk: string, confidence: float, reason: string}
	 */
	public function triage( string $comment ): array {
		$text = strtolower( trim( $comment ) );

		$high_risk = array(
			'javascript', 'script', 'tracking', 'analytics', 'form', 'payment', 'checkout',
			'price', 'pricing', 'legal', 'privacy', 'terms', 'delete', 'remove page',
			'redirect', 'domain', 'integration', 'api', 'password', 'credential',
		);
		if ( $this->contains_any( $text, $high_risk ) ) {
			return array(
				'category'   => 'functional',
				'risk'       => 'high',
				'confidence' => 0.9,
				'reason'     => 'The request mentions behavior, sensitive content, deletion, or an integration and requires manual review.',
			);
		}

		$rules = array(
			'copy'   => array( 'copy', 'text', 'typo', 'spelling', 'grammar', 'wording', 'headline', 'title', 'paragraph', 'sentence' ),
			'asset'  => array( 'image', 'photo', 'picture', 'logo', 'icon', 'video', 'illustration', 'graphic' ),
			'layout' => array( 'spacing', 'padding', 'margin', 'align', 'alignment', 'move', 'position', 'column', 'row', 'mobile', 'tablet', 'responsive', 'width', 'height' ),
			'style'  => array( 'color', 'colour', 'font', 'weight', 'size', 'background', 'border', 'shadow', 'radius' ),
		);

		foreach ( $rules as $category => $keywords ) {
			if ( $this->contains_any( $text, $keywords ) ) {
				return array(
					'category'   => $category,
					'risk'       => 'copy' === $category ? 'low' : 'medium',
					'confidence' => 0.8,
					'reason'     => sprintf( 'Matched the deterministic %s-change vocabulary.', $category ),
				);
			}
		}

		return array(
			'category'   => 'unclear',
			'risk'       => 'unknown',
			'confidence' => 0.25,
			'reason'     => 'No safe deterministic category matched; a human or connected agent should clarify the request.',
		);
	}

	/**
	 * Whether text contains any keyword.
	 *
	 * @param array<int, string> $keywords Keywords to test.
	 */
	private function contains_any( string $text, array $keywords ): bool {
		foreach ( $keywords as $keyword ) {
			if ( str_contains( $text, $keyword ) ) {
				return true;
			}
		}
		return false;
	}
}
