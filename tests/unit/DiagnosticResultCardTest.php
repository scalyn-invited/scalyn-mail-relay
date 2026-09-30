<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\Components\DiagnosticResultCard;

/**
 * Tests for DiagnosticResultCard.
 */
final class DiagnosticResultCardTest extends TestCase {
	public function test_check_metadata_distinguishes_unknown_from_zero_issues_and_escapes_dates(): void {
		foreach ( array('pass'=>'0', 'warn'=>'1', 'fail'=>'1', 'unknown'=>'Not assessed', 'error'=>'Not assessed') as $status=>$issues ) {
			ob_start();
			DiagnosticResultCard::render('Check','unknown','Unknown',static function():void {},'check',array('status'=>$status,'created_at'=>'<script>date</script>'));
			$html=(string)ob_get_clean();
			$this->assertStringContainsString('<dt>Recorded issues</dt><dd>'.$issues.'</dd>',$html);
			$this->assertStringContainsString('&lt;script&gt;date&lt;/script&gt;',$html);
			$this->assertStringNotContainsString('<script>date</script>',$html);
			$this->assertStringContainsString('href="#scalyn-diagnostic-guide"',$html);
		}
	}

	/** Renders a diagnostic result card and returns its HTML. */
	private function render_card( string $heading_id = 'diagnostic-heading' ): string {
		ob_start();
		DiagnosticResultCard::render(
			'SPF Record',
			'unknown',
			'Unknown',
			static function (): void {
				echo '<p>Callback content</p>';
			},
			$heading_id
		);

		return (string) ob_get_clean();
	}

	public function test_render_links_section_to_heading_with_aria_labelledby(): void {
		$output = $this->render_card();

		$this->assertStringContainsString(
			'<section class="scalyn-card scalyn-diagnostic-card" aria-labelledby="diagnostic-heading">',
			$output
		);
		$this->assertStringContainsString( '<h3 id="diagnostic-heading">SPF Record</h3>', $output );
		$this->assertStringContainsString( '<p>Callback content</p>', $output );
	}

	public function test_render_without_heading_id_omits_aria_reference_and_heading_id(): void {
		$output = $this->render_card( '' );

		$this->assertStringContainsString(
			'<section class="scalyn-card scalyn-diagnostic-card"><details open><summary><h3>SPF Record</h3>',
			$output
		);
		$this->assertStringNotContainsString( 'aria-labelledby', $output );
		$this->assertStringNotContainsString( '<h3 id=', $output );
	}

	public function test_heading_id_is_escaped_inside_each_attribute_value(): void {
		$output = $this->render_card( 'heading" onmouseover="alert(1)' );

		$this->assertStringContainsString(
			'aria-labelledby="heading&quot; onmouseover=&quot;alert(1)"',
			$output
		);
		$this->assertStringContainsString(
			'id="heading&quot; onmouseover=&quot;alert(1)"',
			$output
		);
		$this->assertStringNotContainsString( ' onmouseover="alert(1)"', $output );
	}

	public function test_healthy_cards_are_collapsed_but_preserve_the_evidence(): void {
		ob_start();
		DiagnosticResultCard::render( 'SPF', 'healthy', 'Pass', static function (): void {
			echo '<p>Observed evidence</p>';
		} );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( '<details><summary>', $output );
		$this->assertStringNotContainsString( '<details open>', $output );
		$this->assertStringContainsString( '<p>Observed evidence</p>', $output );
		$this->assertStringContainsString( '</details></section>', $output );
	}

	public function test_actionable_and_unknown_cards_start_expanded(): void {
		foreach ( array( 'critical', 'warning', 'unknown' ) as $status ) {
			ob_start();
			DiagnosticResultCard::render( 'Check', $status, $status, static function (): void {} );
			$output = (string) ob_get_clean();
			$this->assertStringContainsString( '<details open><summary>', $output );
		}
	}

	public function test_render_escapes_text_and_sanitizes_status_class(): void {
		ob_start();
		DiagnosticResultCard::render(
			'<script>Heading</script>',
			'unknown" onclick="alert(1)',
			'<strong>Unknown</strong>',
			static function (): void {}
		);
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '&lt;script&gt;Heading&lt;/script&gt;', $output );
		$this->assertStringContainsString( '&lt;strong&gt;Unknown&lt;/strong&gt;', $output );
		$this->assertStringContainsString( 'scalyn-badge--unknownonclickalert1', $output );
		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringNotContainsString( '<strong>', $output );
		$this->assertStringNotContainsString( ' onclick=', $output );
	}
}
