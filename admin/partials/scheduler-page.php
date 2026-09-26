<?php
/**
 * Scheduled imports admin page template.
 *
 * @package AFSRReloaded
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $view ) || ! is_array( $view ) ) {
	return;
}

extract( $view, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract

/** @var Import_Scheduler $scheduler */
/** @var array $schedules */
/** @var array $logs */
/** @var array|null $editing */
/** @var string $edit_id */
/** @var string $message */
/** @var string|false $root */
/** @var array $notices */
/** @var string $form_error */
/** @var string $page_slug */

$form_open = (bool) $editing || ! empty( $form_error );
?>
<div class="wrap afsr-admin-wrap">
	<h1><?php esc_html_e( 'Scheduled Imports', 'add-from-server-reloaded' ); ?></h1>

	<div id="afsr-admin-app" class="afsr-wrap afsr-pro-page">
		<header class="afsr-page-header">
			<h2 class="afsr-page-title"><?php esc_html_e( 'Scheduled Imports', 'add-from-server-reloaded' ); ?></h2>
			<p class="afsr-page-subtitle"><?php esc_html_e( 'Automatic imports on a recurring schedule.', 'add-from-server-reloaded' ); ?></p>
		</header>

		<?php if ( ! empty( $form_error ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $form_error ); ?></p></div>
		<?php endif; ?>

		<?php if ( $message && isset( $notices[ $message ] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notices[ $message ] ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! empty( $run_error_message ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $run_error_message ); ?></p></div>
		<?php endif; ?>

		<div class="afsr-data-card">
			<table class="afsr-data-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Folder', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Frequency', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Active', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Last run', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Next run', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'add-from-server-reloaded' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $schedules ) ) : ?>
					<tr><td colspan="7" class="afsr-empty"><?php esc_html_e( 'No schedules yet.', 'add-from-server-reloaded' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $schedules as $id => $s ) : ?>
						<tr>
							<td class="afsr-row-name"><?php echo esc_html( $s['name'] ?? $id ); ?></td>
							<td><span class="afsr-path-muted"><?php echo esc_html( (string) ( $s['folder'] ?? '' ) ); ?></span></td>
							<td>
								<?php
								$freq_key    = $s['frequency'] ?? 'daily';
								$freq_labels = isset( $frequency_labels ) && is_array( $frequency_labels ) ? $frequency_labels : array();
								echo esc_html( $freq_labels[ $freq_key ] ?? $freq_key );
								?>
							</td>
							<td>
								<?php if ( ! empty( $s['active'] ) ) : ?>
									<span class="afsr-badge afsr-badge--success"><?php esc_html_e( 'Yes', 'add-from-server-reloaded' ); ?></span>
								<?php else : ?>
									<span class="afsr-badge afsr-badge--neutral"><?php esc_html_e( 'No', 'add-from-server-reloaded' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="afsr-muted">
								<?php
								if ( ! empty( $s['last_run'] ) ) {
									echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $s['last_run'] ) );
									if ( ! empty( $s['last_message'] ) ) {
										echo '<br><span class="afsr-field-help">' . esc_html( $s['last_message'] ) . '</span>';
									}
								} else {
									echo '—';
								}
								?>
							</td>
							<td class="afsr-muted">
								<?php
								if ( ! empty( $s['active'] ) ) {
									$next = wp_next_scheduled( \AFSRReloaded\Import_Scheduler::HOOK, array( $id ) );
									if ( $next ) {
										echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $next ) );
									} else {
										echo esc_html__( 'Not scheduled', 'add-from-server-reloaded' );
									}
								} else {
									echo '—';
								}
								?>
							</td>
							<td>
								<span class="afsr-actions">
									<a class="afsr-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . $page_slug . '&action=run&id=' . rawurlencode( $id ) ), 'run-schedule-' . $id ) ); ?>"><?php esc_html_e( 'Run now', 'add-from-server-reloaded' ); ?></a>
									<span class="afsr-sep">|</span>
									<a class="afsr-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . $page_slug . '&action=toggle&id=' . rawurlencode( $id ) ), 'toggle-schedule-' . $id ) ); ?>"><?php echo ! empty( $s['active'] ) ? esc_html__( 'Deactivate', 'add-from-server-reloaded' ) : esc_html__( 'Activate', 'add-from-server-reloaded' ); ?></a>
									<span class="afsr-sep">|</span>
									<a class="afsr-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page_slug . '&edit=' . rawurlencode( $id ) ) ); ?>"><?php esc_html_e( 'Edit', 'add-from-server-reloaded' ); ?></a>
									<span class="afsr-sep">|</span>
									<a class="afsr-link-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . $page_slug . '&action=delete&id=' . rawurlencode( $id ) ), 'delete-schedule-' . $id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this schedule?', 'add-from-server-reloaded' ) ); ?>');"><?php esc_html_e( 'Delete', 'add-from-server-reloaded' ); ?></a>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<p style="margin:16px 0;">
			<button type="button" class="afsr-btn afsr-btn-outline" id="afsr-sched-reveal-btn" data-afsr-reveal="#afsr-sched-form-panel" <?php echo $form_open ? 'hidden' : ''; ?>>
				<?php esc_html_e( 'New schedule', 'add-from-server-reloaded' ); ?>
			</button>
		</p>

		<div id="afsr-sched-form-panel" class="afsr-form-card" <?php echo $form_open ? '' : 'hidden'; ?>>
			<h3 class="afsr-section-title" style="margin-bottom:14px;">
				<?php echo $editing ? esc_html__( 'Edit schedule', 'add-from-server-reloaded' ) : esc_html__( 'Add schedule', 'add-from-server-reloaded' ); ?>
			</h3>
			<form method="post">
				<?php wp_nonce_field( 'afsrreloaded_save_schedule' ); ?>
				<?php if ( $editing ) : ?>
					<input type="hidden" name="edit_id" value="<?php echo esc_attr( $edit_id ); ?>" />
				<?php endif; ?>
				<div class="afsr-form-stack">
					<div class="afsr-field">
						<label for="afsr-sched-name"><?php esc_html_e( 'Name', 'add-from-server-reloaded' ); ?></label>
						<input type="text" id="afsr-sched-name" name="name" value="<?php echo esc_attr( $editing['name'] ?? '' ); ?>" required />
					</div>
					<div class="afsr-field">
						<label for="afsr-sched-folder"><?php esc_html_e( 'Folder', 'add-from-server-reloaded' ); ?></label>
						<input type="text" id="afsr-sched-folder" name="folder" value="<?php echo esc_attr( $editing['folder'] ?? '' ); ?>" placeholder="uploads/Incoming" required autocomplete="off" />
						<p class="afsr-field-help">
							<?php
							printf(
								/* translators: %s: root path */
								esc_html__( 'Examples that work: html/wordpress/wp-content/uploads  or  %s/html/wordpress/wp-content/uploads', 'add-from-server-reloaded' ),
								esc_html( (string) $root )
							);
							?>
						</p>
					</div>
					<div class="afsr-field">
						<label for="afsr-sched-freq"><?php esc_html_e( 'Frequency', 'add-from-server-reloaded' ); ?></label>
						<select id="afsr-sched-freq" name="frequency">
							<?php
							$freq_labels = isset( $frequency_labels ) && is_array( $frequency_labels ) ? $frequency_labels : array();
							foreach ( $allowed_frequencies as $freq ) :
								$label = $freq_labels[ $freq ] ?? $freq;
								?>
								<option value="<?php echo esc_attr( $freq ); ?>" <?php selected( ( $editing['frequency'] ?? 'daily' ), $freq ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="afsr-field">
						<span class="afsr-field-label"><?php esc_html_e( 'Options', 'add-from-server-reloaded' ); ?></span>
						<div class="afsr-check-list">
							<label><input type="checkbox" name="active" value="1" <?php checked( ! isset( $editing['active'] ) || ! empty( $editing['active'] ) ); ?> /> <?php esc_html_e( 'Active', 'add-from-server-reloaded' ); ?></label>
							<label><input type="checkbox" name="generate_metadata" value="1" <?php checked( ! isset( $editing['generate_metadata'] ) || ! empty( $editing['generate_metadata'] ) ); ?> /> <?php esc_html_e( 'Generate thumbnails during import', 'add-from-server-reloaded' ); ?></label>
							<?php if ( \AFSRReloaded\Features::enabled( 'folder_preserve' ) ) : ?>
							<label><input type="checkbox" name="preserve_structure" value="1" <?php checked( ! empty( $editing['preserve_structure'] ) ); ?> /> <?php esc_html_e( 'Preserve folder structure', 'add-from-server-reloaded' ); ?></label>
							<?php endif; ?>
						</div>
					</div>
					<div class="afsr-field">
						<label for="afsr-sched-types"><?php esc_html_e( 'File types', 'add-from-server-reloaded' ); ?></label>
						<select id="afsr-sched-types" name="file_types">
							<?php
							$ft = $editing['file_types'] ?? 'all';
							foreach ( array(
								'all'       => __( 'All allowed types', 'add-from-server-reloaded' ),
								'images'    => __( 'Images', 'add-from-server-reloaded' ),
								'audio'     => __( 'Audio', 'add-from-server-reloaded' ),
								'video'     => __( 'Video', 'add-from-server-reloaded' ),
								'documents' => __( 'Documents', 'add-from-server-reloaded' ),
								'custom'    => __( 'Custom extensions', 'add-from-server-reloaded' ),
							) as $k => $label ) :
								?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $ft, $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="afsr-field-help">
							<label for="afsr-sched-exts"><?php esc_html_e( 'Custom extensions (comma-separated, for Custom):', 'add-from-server-reloaded' ); ?></label>
						</p>
						<input type="text" id="afsr-sched-exts" name="allowed_exts" value="<?php echo esc_attr( $editing['allowed_exts'] ?? '' ); ?>" placeholder="jpg,png,pdf" />
					</div>
					<div class="afsr-field">
						<label for="afsr-sched-max"><?php esc_html_e( 'Max file size (MB)', 'add-from-server-reloaded' ); ?></label>
						<input type="number" min="0" step="0.1" id="afsr-sched-max" name="max_file_size_mb" value="<?php echo esc_attr( (string) ( $editing['max_file_size_mb'] ?? 0 ) ); ?>" />
						<p class="afsr-field-help"><?php esc_html_e( '0 = no limit', 'add-from-server-reloaded' ); ?></p>
					</div>
					<?php if ( \AFSRReloaded\Features::enabled( 'advanced_duplicates' ) ) : ?>
					<div class="afsr-field">
						<label for="afsr-sched-dup"><?php esc_html_e( 'On duplicate', 'add-from-server-reloaded' ); ?></label>
						<select id="afsr-sched-dup" name="duplicate_action">
							<?php
							foreach ( array(
								'skip'    => __( 'Skip', 'add-from-server-reloaded' ),
								'replace' => __( 'Replace existing', 'add-from-server-reloaded' ),
								'rename'  => __( 'Import as new (rename)', 'add-from-server-reloaded' ),
							) as $k => $label ) :
								?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( ( $editing['duplicate_action'] ?? 'skip' ), $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<?php endif; ?>
					<div class="afsr-form-actions">
						<button type="submit" class="afsr-btn afsr-btn-primary" name="afsrreloaded_save_schedule" value="1">
							<?php echo $editing ? esc_html__( 'Update schedule', 'add-from-server-reloaded' ) : esc_html__( 'Add schedule', 'add-from-server-reloaded' ); ?>
						</button>
						<?php if ( $editing ) : ?>
							<a class="afsr-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page_slug ) ); ?>"><?php esc_html_e( 'Cancel', 'add-from-server-reloaded' ); ?></a>
						<?php else : ?>
							<a class="afsr-link" href="#" data-afsr-hide="#afsr-sched-form-panel" data-afsr-reveal-btn="#afsr-sched-reveal-btn"><?php esc_html_e( 'Cancel', 'add-from-server-reloaded' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			</form>
		</div>

		<section class="afsr-section">
			<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
				<h3 class="afsr-section-title" style="margin:0;"><?php esc_html_e( 'Recent runs', 'add-from-server-reloaded' ); ?></h3>
				<form method="post">
					<?php wp_nonce_field( 'afsrreloaded_clear_logs' ); ?>
					<button type="submit" class="afsr-btn afsr-btn-outline" name="afsrreloaded_clear_logs" value="1"><?php esc_html_e( 'Clear logs', 'add-from-server-reloaded' ); ?></button>
				</form>
			</div>
			<div class="afsr-data-card">
				<table class="afsr-data-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'add-from-server-reloaded' ); ?></th>
							<th><?php esc_html_e( 'Schedule', 'add-from-server-reloaded' ); ?></th>
							<th><?php esc_html_e( 'Status', 'add-from-server-reloaded' ); ?></th>
							<th><?php esc_html_e( 'Job', 'add-from-server-reloaded' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr><td colspan="4" class="afsr-empty"><?php esc_html_e( 'No runs logged yet.', 'add-from-server-reloaded' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( array_slice( $logs, 0, 30 ) as $log ) : ?>
							<?php
							$status      = (string) ( $log['status'] ?? '' );
							$badge       = 'afsr-badge--neutral';
							$schedule_id = (string) ( $log['schedule_id'] ?? '' );
							$schedule    = ( $schedule_id && isset( $schedules[ $schedule_id ] ) ) ? $schedules[ $schedule_id ] : null;
							$sched_name  = is_array( $schedule ) && ! empty( $schedule['name'] )
								? (string) $schedule['name']
								: ( $schedule_id ? $schedule_id : __( '(deleted schedule)', 'add-from-server-reloaded' ) );
							$freq_key    = is_array( $schedule ) ? (string) ( $schedule['frequency'] ?? '' ) : '';
							$freq_label  = ( $freq_key && isset( $frequency_labels[ $freq_key ] ) ) ? $frequency_labels[ $freq_key ] : '';
							if ( in_array( $status, array( 'started', 'completed', 'success' ), true ) ) {
								$badge = 'afsr-badge--success';
							} elseif ( in_array( $status, array( 'failed', 'error' ), true ) ) {
								$badge = 'afsr-badge--danger';
							}
							?>
							<tr>
								<td class="afsr-muted"><?php echo esc_html( ! empty( $log['timestamp'] ) ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $log['timestamp'] ) : '' ); ?></td>
								<td>
									<strong><?php echo esc_html( $sched_name ); ?></strong>
									<?php if ( $freq_label ) : ?>
										<br /><span class="afsr-muted"><?php echo esc_html( $freq_label ); ?></span>
									<?php endif; ?>
								</td>
								<td><span class="afsr-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $status ); ?></span></td>
								<td><?php echo esc_html( isset( $log['job_id'] ) ? 'Job #' . (int) $log['job_id'] : ( $log['message'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</section>
	</div>
</div>
