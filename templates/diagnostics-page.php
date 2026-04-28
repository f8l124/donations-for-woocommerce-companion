<?php
/**
 * Diagnostics admin page template.
 *
 * Variables in scope (from Diagnostics_Page::render):
 *   Parent_Form_Contract_Report $report
 *   array<string,string>        $labels        contract_id => label
 *   array<string,string>        $descs         contract_id => description
 *   string                      $support_md    Markdown support report (sanitized)
 *
 * @package DFWC\Companion
 */

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Admin\Admin_Menu;
use DFWC\Companion\Admin\Diagnostics_Page;
use DFWC\Companion\Contracts\Parent_Form_Contract_Result;

$notice_class  = Diagnostics_Page::notice_class_for_status( $report->overall_status );
$overall_label = Diagnostics_Page::status_label( $report->overall_status );

$rechecked = isset( $_GET['rechecked'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag
?>
<div class="wrap dfwc-diagnostics">
	<h1><?php esc_html_e( 'Donations Companion — Diagnostics', 'dfwc-companion' ); ?></h1>

	<?php if ( $rechecked ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Diagnostics re-run. Results below reflect the latest checks.', 'dfwc-companion' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="notice <?php echo esc_attr( $notice_class ); ?> inline dfwc-diagnostics__overall">
		<p>
			<strong><?php esc_html_e( 'Overall status:', 'dfwc-companion' ); ?></strong>
			<?php echo esc_html( $overall_label ); ?>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %s: human-readable last-checked time, e.g. "5 minutes ago" */
				esc_html__( 'Last checked: %s', 'dfwc-companion' ),
				esc_html( human_time_diff( $report->checked_at ) ) . ' ' . esc_html__( 'ago', 'dfwc-companion' )
			);
			?>
		</p>
		<p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<input type="hidden" name="action" value="<?php echo esc_attr( Diagnostics_Page::RECHECK_ACTION ); ?>">
				<?php wp_nonce_field( Diagnostics_Page::RECHECK_ACTION, Diagnostics_Page::RECHECK_NONCE ); ?>
				<?php submit_button( __( 'Re-check now', 'dfwc-companion' ), 'secondary', '', false ); ?>
			</form>
		</p>
	</div>

	<h2><?php esc_html_e( 'Compatibility checks', 'dfwc-companion' ); ?></h2>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th class="column-status" style="width:80px;"><?php esc_html_e( 'Status', 'dfwc-companion' ); ?></th>
				<th><?php esc_html_e( 'Check', 'dfwc-companion' ); ?></th>
				<th><?php esc_html_e( 'Result', 'dfwc-companion' ); ?></th>
				<th><?php esc_html_e( 'Suggested action', 'dfwc-companion' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $report->results as $result ) : ?>
				<?php
				$row_label   = $labels[ $result->contract_id ] ?? $result->contract_id;
				$row_desc    = $descs[ $result->contract_id ] ?? '';
				$status_text = Diagnostics_Page::result_status_label( $result->status );
				?>
				<tr>
					<td>
						<span class="dfwc-status dfwc-status--<?php echo esc_attr( $result->status ); ?>">
							<?php echo esc_html( $status_text ); ?>
						</span>
					</td>
					<td>
						<strong><?php echo esc_html( $row_label ); ?></strong>
						<?php if ( '' !== $row_desc ) : ?>
							<br><span class="description"><?php echo esc_html( $row_desc ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $result->message ); ?></td>
					<td>
						<?php if ( '' !== $result->remediation ) : ?>
							<?php echo esc_html( $result->remediation ); ?>
						<?php else : ?>
							<span aria-hidden="true">—</span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Support report', 'dfwc-companion' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Copy and paste this report into a GitHub issue or support thread. It excludes donor data, server paths, and credentials.', 'dfwc-companion' ); ?>
	</p>
	<textarea
		readonly
		rows="20"
		cols="80"
		class="large-text code"
		onclick="this.select()"
		aria-label="<?php esc_attr_e( 'Diagnostic support report', 'dfwc-companion' ); ?>"
	><?php echo esc_textarea( $support_md ); ?></textarea>
</div>
