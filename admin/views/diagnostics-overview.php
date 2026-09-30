<?php
/**
 * Evidence-backed category navigation; no network probes or fabricated scores.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
$categories = array(
	array(
		'title'       => __( 'DNS', 'scalyn-mail-relay' ),
		'keys'        => array( 'mx' ),
		'target'      => 'scalyn-diagnostics-dns-heading',
		'description' => __( 'Mail-routing records for the checked domain.', 'scalyn-mail-relay' ),
	),
	array(
		'title'       => __( 'Authentication', 'scalyn-mail-relay' ),
		'keys'        => array( 'spf', 'dkim', 'dmarc' ),
		'target'      => 'scalyn-diagnostics-dns-heading',
		'description' => __( 'Published SPF, DKIM and DMARC records, not actual message authentication.', 'scalyn-mail-relay' ),
	),
	array(
		'title'       => __( 'Provider · TLS · Certificates', 'scalyn-mail-relay' ),
		'keys'        => array( 'smtp_tls' ),
		'target'      => 'scalyn-diagnostics-provider-heading',
		'description' => __( 'One combined SMTP/TLS check, not a complete provider-health assessment.', 'scalyn-mail-relay' ),
	),
);
?>
<nav class="scalyn-grid scalyn-diagnostic-categories" aria-label="<?php esc_attr_e( 'Diagnostic categories', 'scalyn-mail-relay' ); ?>">
	<?php foreach ( $categories as $category ) : ?>
		<?php
		$issues          = 0;
		$unknown         = 0;
		$category_status = 'healthy';
		foreach ( $category['keys'] as $key ) {
			$check_status = $diagnostics[ $key ]['status'] ?? 'unknown';
			if ( in_array( $check_status, array( 'warn', 'fail' ), true ) ) {
				++$issues;
				$category_status = 'fail' === $check_status ? 'critical' : ( 'critical' === $category_status ? 'critical' : 'warning' );
			} elseif ( 'pass' !== $check_status ) {
				++$unknown;
			}
		}
		if ( $unknown && ! $issues ) {
			$category_status = 'unknown';
		}
		$category_labels = array(
			'healthy'  => __( 'Recorded checks passed', 'scalyn-mail-relay' ),
			'warning'  => __( 'Warning recorded', 'scalyn-mail-relay' ),
			'critical' => __( 'Failure recorded', 'scalyn-mail-relay' ),
			'unknown'  => __( 'Incomplete evidence', 'scalyn-mail-relay' ),
		);
		?>
		<section class="scalyn-card">
			<h2><?php echo esc_html( $category['title'] ); ?></h2>
			<?php \Scalyn\MailRelay\Admin\Components\StatusBadge::render( $category_status, $category_labels[ $category_status ] ); ?>
			<p><?php echo esc_html( $category['description'] ); ?></p>
			<p><?php echo esc_html( sprintf( /* translators: 1: recorded warning/failure count, 2: checks without conclusive evidence. */ __( 'Recorded issues: %1$d · Not assessed: %2$d', 'scalyn-mail-relay' ), $issues, $unknown ) ); ?></p>
			<a href="#<?php echo esc_attr( $category['target'] ); ?>"><?php esc_html_e( 'Review checks', 'scalyn-mail-relay' ); ?></a>
		</section>
	<?php endforeach; ?>
</nav>
<p class="description"><?php esc_html_e( 'Counts describe the retained findings below, not live health. System and deliverability assessments are not implemented here. Use Run Diagnostics Now to rerun all supported checks; individual check reruns are not available.', 'scalyn-mail-relay' ); ?></p>
