<?php
/** Records-per-page control associated with the existing read-only filter form.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="scalyn-filter-group scalyn-log-page-size">
	<label for="scalyn-filter-per-page"><?php esc_html_e( 'Records per page', 'scalyn-mail-relay' ); ?></label>
	<select id="scalyn-filter-per-page" name="per_page" form="scalyn-log-filter-form">
		<?php foreach ( $page_sizes as $size ) : ?>
			<option value="<?php echo esc_attr( $size ); ?>" <?php selected( $per_page, $size ); ?>><?php echo esc_html( $size ); ?></option>
		<?php endforeach; ?>
	</select>
	<noscript><button class="button" type="submit" form="scalyn-log-filter-form"><?php esc_html_e( 'Update records', 'scalyn-mail-relay' ); ?></button></noscript>
</div>
