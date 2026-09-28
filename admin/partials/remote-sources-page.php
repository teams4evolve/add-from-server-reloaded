<?php
/**
 * Remote sources admin page template.
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

/** @var array $profiles */
/** @var array|null $editing */
/** @var string $edit_id */
/** @var string $message */
/** @var array $notices */
/** @var string $form_error */
/** @var bool $storage_warning */
/** @var bool $uses_plaintext */
/** @var string $page_slug */
/** @var string $browse_id */
/** @var string $browse_path */
/** @var array|null $browse_profile */
/** @var array $browse_entries */
/** @var string $browse_error */
/** @var string $browse_parent_path */

$form_open  = (bool) $editing || ! empty( $form_error );
$tested_id  = isset( $tested_id ) ? (string) $tested_id : '';
$import_url = admin_url( 'admin.php?page=add-from-server-reloaded' );
?>
<div class="wrap afsr-admin-wrap">
	<h1><?php esc_html_e( 'Remote Sources', 'add-from-server-reloaded' ); ?></h1>

	<div id="afsr-admin-app" class="afsr-wrap afsr-pro-page">
		<header class="afsr-page-header">
			<h2 class="afsr-page-title"><?php esc_html_e( 'Remote Sources', 'add-from-server-reloaded' ); ?></h2>
			<p class="afsr-page-subtitle"><?php esc_html_e( 'Connect FTP, SFTP, or S3-compatible storage.', 'add-from-server-reloaded' ); ?></p>
		</header>

		<?php if ( ! empty( $form_error ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $form_error ); ?></p></div>
		<?php endif; ?>

		<?php if ( $message && isset( $notices[ $message ] ) && 'stage_started' !== $message && 'test_ok' !== $message ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notices[ $message ] ); ?></p></div>
		<?php endif; ?>

		<?php if ( in_array( $message, array( 'test_error', 'stage_error' ), true ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( isset( $_GET['error_msg'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['error_msg'] ) ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
		<?php endif; ?>

		<?php if ( $storage_warning || $uses_plaintext ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'Passwords are stored in the database without strong encryption. Prefer FTPS/SFTP over SSL and restrict admin access.', 'add-from-server-reloaded' ); ?></p></div>
		<?php endif; ?>

		<div class="afsr-data-card">
			<table class="afsr-data-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Type', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Host / bucket', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Path', 'add-from-server-reloaded' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'add-from-server-reloaded' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $profiles ) ) : ?>
					<tr><td colspan="5" class="afsr-empty"><?php esc_html_e( 'No remote sources configured.', 'add-from-server-reloaded' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $profiles as $id => $profile ) : ?>
						<tr>
							<td>
								<div class="afsr-row-name"><?php echo esc_html( $profile['name'] ?? $id ); ?></div>
								<?php if ( $tested_id && $tested_id === (string) $id ) : ?>
									<div class="afsr-row-meta"><span class="afsr-badge afsr-badge--success"><?php esc_html_e( 'Connected', 'add-from-server-reloaded' ); ?></span></div>
								<?php endif; ?>
							</td>
							<td><span class="afsr-type-pill"><?php echo esc_html( strtoupper( $profile['type'] ?? '' ) ); ?></span></td>
							<td><?php echo esc_html( 's3' === ( $profile['type'] ?? '' ) ? ( $profile['bucket'] ?? '' ) : ( $profile['host'] ?? '' ) ); ?></td>
							<td><span class="afsr-path-muted"><?php echo esc_html( $profile['path'] ?? $profile['prefix'] ?? '' ); ?></span></td>
							<td>
								<span class="afsr-actions">
									<a class="afsr-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . $page_slug . '&action=test&id=' . rawurlencode( $id ) ), 'test-remote-' . $id ) ); ?>"><?php esc_html_e( 'Test', 'add-from-server-reloaded' ); ?></a>
									<span class="afsr-sep">|</span>
									<a class="afsr-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . $page_slug . '&action=browse&id=' . rawurlencode( $id ) ), 'browse-remote-' . $id ) ); ?>"><?php esc_html_e( 'Browse', 'add-from-server-reloaded' ); ?></a>
									<span class="afsr-sep">|</span>
									<a class="afsr-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page_slug . '&edit=' . rawurlencode( $id ) ) ); ?>"><?php esc_html_e( 'Edit', 'add-from-server-reloaded' ); ?></a>
									<span class="afsr-sep">|</span>
									<a class="afsr-link-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . $page_slug . '&action=delete&id=' . rawurlencode( $id ) ), 'delete-remote-' . $id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this remote source?', 'add-from-server-reloaded' ) ); ?>');"><?php esc_html_e( 'Delete', 'add-from-server-reloaded' ); ?></a>
								</span>
							</td>
						</tr>
						<?php if ( $browse_id && $browse_profile && (string) $browse_id === (string) $id ) : ?>
						<tr>
							<td colspan="5" style="padding:0 16px 16px;background:#fff;">
								<div class="afsr-browse-panel">
									<p style="margin:0 0 10px;">
										<a class="afsr-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page_slug ) ); ?>">&larr; <?php esc_html_e( 'Back to remote sources', 'add-from-server-reloaded' ); ?></a>
										<?php if ( '' !== $browse_parent_path || ( '' !== $browse_path && '/' !== $browse_path ) ) : ?>
											<span class="afsr-sep">|</span>
											<a class="afsr-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . $page_slug . '&action=browse&id=' . rawurlencode( $browse_id ) . '&remote_path=' . rawurlencode( $browse_parent_path ) ), 'browse-remote-' . $browse_id ) ); ?>"><?php esc_html_e( 'Up one level', 'add-from-server-reloaded' ); ?></a>
										<?php endif; ?>
									</p>

									<?php if ( $browse_error ) : ?>
										<div class="notice notice-error"><p><?php echo esc_html( $browse_error ); ?></p></div>
									<?php endif; ?>

									<form method="post">
										<?php wp_nonce_field( 'afsrreloaded_stage_import' ); ?>
										<input type="hidden" name="profile_id" value="<?php echo esc_attr( $browse_id ); ?>" />
										<input type="hidden" name="remote_path" value="<?php echo esc_attr( $browse_path ); ?>" />

										<?php if ( \AFSRReloaded\Features::enabled( 'background' ) ) : ?>
											<p class="afsr-check-list"><label><input type="checkbox" name="background" value="1" checked="checked" /> <?php esc_html_e( 'Run import in background after staging', 'add-from-server-reloaded' ); ?></label></p>
										<?php endif; ?>

										<div class="afsr-data-card">
											<table class="afsr-data-table">
												<thead>
													<tr>
														<th style="width:36px;"><input type="checkbox" id="afsr-select-all-remote" /></th>
														<th><?php esc_html_e( 'Name', 'add-from-server-reloaded' ); ?></th>
														<th><?php esc_html_e( 'Size', 'add-from-server-reloaded' ); ?></th>
														<th><?php esc_html_e( 'Actions', 'add-from-server-reloaded' ); ?></th>
													</tr>
												</thead>
												<tbody>
												<?php if ( empty( $browse_entries ) && ! $browse_error ) : ?>
													<tr><td colspan="4" class="afsr-empty"><?php esc_html_e( 'This directory is empty.', 'add-from-server-reloaded' ); ?></td></tr>
												<?php else : ?>
													<?php foreach ( $browse_entries as $entry ) : ?>
														<?php
														$entry_name = $entry['name'] ?? '';
														$entry_path = $entry['path'] ?? '';
														$entry_type = $entry['type'] ?? 'file';
														if ( '' === $entry_name || '' === $entry_path ) {
															continue;
														}
														?>
														<tr>
															<td>
																<?php if ( 'dir' === $entry_type ) : ?>
																	<input type="checkbox" name="remote_dirs[]" value="<?php echo esc_attr( $entry_path ); ?>" />
																<?php else : ?>
																	<input type="checkbox" name="remote_files[]" value="<?php echo esc_attr( $entry_path ); ?>" />
																<?php endif; ?>
															</td>
															<td>
																<?php if ( 'dir' === $entry_type ) : ?>
																	<strong><?php echo esc_html( $entry_name ); ?>/</strong>
																<?php else : ?>
																	<?php echo esc_html( $entry_name ); ?>
																<?php endif; ?>
															</td>
															<td class="afsr-muted">
																<?php
																if ( 'file' === $entry_type && isset( $entry['size'] ) ) {
																	echo esc_html( size_format( (int) $entry['size'] ) );
																} else {
																	echo '—';
																}
																?>
															</td>
															<td>
																<?php if ( 'dir' === $entry_type ) : ?>
																	<a class="afsr-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . $page_slug . '&action=browse&id=' . rawurlencode( $browse_id ) . '&remote_path=' . rawurlencode( $entry_path ) ), 'browse-remote-' . $browse_id ) ); ?>"><?php esc_html_e( 'Open', 'add-from-server-reloaded' ); ?></a>
																<?php else : ?>
																	—
																<?php endif; ?>
															</td>
														</tr>
													<?php endforeach; ?>
												<?php endif; ?>
												</tbody>
											</table>
										</div>
										<p style="margin:12px 0 0;">
											<button type="submit" class="afsr-btn afsr-btn-primary" name="afsrreloaded_stage_import" value="1"><?php esc_html_e( 'Stage selected & import', 'add-from-server-reloaded' ); ?></button>
										</p>
									</form>
									<script>
									(function () {
										var selectAll = document.getElementById('afsr-select-all-remote');
										if (!selectAll) { return; }
										selectAll.addEventListener('change', function () {
											var boxes = document.querySelectorAll('input[name="remote_files[]"], input[name="remote_dirs[]"]');
											for (var i = 0; i < boxes.length; i++) {
												boxes[i].checked = selectAll.checked;
											}
										});
									})();
									</script>
								</div>
							</td>
						</tr>
						<?php endif; ?>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<p style="margin:16px 0;">
			<button type="button" class="afsr-btn afsr-btn-outline" id="afsr-remote-reveal-btn" data-afsr-reveal="#afsr-remote-form-panel" <?php echo $form_open ? 'hidden' : ''; ?>>
				<?php esc_html_e( 'Add remote source', 'add-from-server-reloaded' ); ?>
			</button>
		</p>

		<div id="afsr-remote-form-panel" class="afsr-form-card" <?php echo $form_open ? '' : 'hidden'; ?>>
			<h3 class="afsr-section-title" style="margin-bottom:14px;">
				<?php echo $editing ? esc_html__( 'Edit remote source', 'add-from-server-reloaded' ) : esc_html__( 'Add remote source', 'add-from-server-reloaded' ); ?>
			</h3>
			<form method="post">
				<?php wp_nonce_field( 'afsrreloaded_save_remote' ); ?>
				<?php if ( $editing ) : ?>
					<input type="hidden" name="edit_id" value="<?php echo esc_attr( $edit_id ); ?>" />
				<?php endif; ?>
				<div class="afsr-form-stack">
					<div class="afsr-field">
						<label for="afsr-remote-name"><?php esc_html_e( 'Name', 'add-from-server-reloaded' ); ?></label>
						<input type="text" id="afsr-remote-name" name="name" value="<?php echo esc_attr( $editing['name'] ?? '' ); ?>" required />
					</div>
					<div class="afsr-field">
						<label for="afsr-remote-type"><?php esc_html_e( 'Type', 'add-from-server-reloaded' ); ?></label>
						<select id="afsr-remote-type" name="type">
							<?php if ( \AFSRReloaded\Features::enabled( 'ftp_sftp' ) ) : ?>
								<option value="ftp" <?php selected( ( $editing['type'] ?? '' ), 'ftp' ); ?>>FTP</option>
								<option value="sftp" <?php selected( ( $editing['type'] ?? '' ), 'sftp' ); ?>>SFTP</option>
							<?php endif; ?>
							<?php if ( \AFSRReloaded\Features::enabled( 'cloud_storage' ) ) : ?>
								<option value="s3" <?php selected( ( $editing['type'] ?? '' ), 's3' ); ?>>S3-compatible</option>
							<?php endif; ?>
						</select>
					</div>
					<div class="afsr-field">
						<label for="afsr-remote-host"><?php esc_html_e( 'Host', 'add-from-server-reloaded' ); ?></label>
						<input type="text" id="afsr-remote-host" name="host" value="<?php echo esc_attr( $editing['host'] ?? '' ); ?>" />
					</div>
					<div class="afsr-field">
						<label for="afsr-remote-port"><?php esc_html_e( 'Port', 'add-from-server-reloaded' ); ?></label>
						<input type="number" min="1" max="65535" id="afsr-remote-port" name="port" value="<?php echo esc_attr( (string) ( $editing['port'] ?? '' ) ); ?>" />
					</div>
					<div class="afsr-field">
						<label for="afsr-remote-user"><?php esc_html_e( 'Username', 'add-from-server-reloaded' ); ?></label>
						<input type="text" id="afsr-remote-user" name="username" value="<?php echo esc_attr( $editing['username'] ?? '' ); ?>" autocomplete="off" />
					</div>
					<div class="afsr-field">
						<label for="afsr-remote-pass"><?php esc_html_e( 'Password', 'add-from-server-reloaded' ); ?></label>
						<input type="password" id="afsr-remote-pass" name="password" value="" autocomplete="new-password" />
						<?php if ( $editing ) : ?>
							<p class="afsr-field-help"><?php esc_html_e( 'Leave blank to keep the existing password.', 'add-from-server-reloaded' ); ?></p>
						<?php endif; ?>
					</div>
					<div class="afsr-field">
						<label for="afsr-remote-path"><?php esc_html_e( 'Default path / prefix', 'add-from-server-reloaded' ); ?></label>
						<input type="text" id="afsr-remote-path" name="path" value="<?php echo esc_attr( $editing['path'] ?? $editing['prefix'] ?? '' ); ?>" placeholder="/Incoming" />
					</div>
					<div class="afsr-field">
						<span class="afsr-field-label"><?php esc_html_e( 'FTP options', 'add-from-server-reloaded' ); ?></span>
						<div class="afsr-check-list">
							<label><input type="checkbox" name="ssl" value="1" <?php checked( ! empty( $editing['ssl'] ) ); ?> /> <?php esc_html_e( 'Use FTPS (SSL)', 'add-from-server-reloaded' ); ?></label>
						</div>
					</div>
					<div class="afsr-field">
						<span class="afsr-field-label"><?php esc_html_e( 'S3 options', 'add-from-server-reloaded' ); ?></span>
						<label for="afsr-remote-bucket"><?php esc_html_e( 'Bucket', 'add-from-server-reloaded' ); ?></label>
						<input type="text" id="afsr-remote-bucket" name="bucket" value="<?php echo esc_attr( $editing['bucket'] ?? '' ); ?>" style="margin-bottom:10px;" />
						<label for="afsr-remote-endpoint"><?php esc_html_e( 'Endpoint URL', 'add-from-server-reloaded' ); ?></label>
						<input type="url" id="afsr-remote-endpoint" name="endpoint" value="<?php echo esc_attr( $editing['endpoint'] ?? '' ); ?>" placeholder="https://s3.amazonaws.com" style="margin-bottom:10px;" />
						<label for="afsr-remote-region"><?php esc_html_e( 'Region', 'add-from-server-reloaded' ); ?></label>
						<input type="text" id="afsr-remote-region" name="region" value="<?php echo esc_attr( $editing['region'] ?? 'us-east-1' ); ?>" style="margin-bottom:10px;" />
						<label for="afsr-remote-access"><?php esc_html_e( 'Access key', 'add-from-server-reloaded' ); ?></label>
						<input type="text" id="afsr-remote-access" name="access_key" value="<?php echo esc_attr( $editing['access_key'] ?? '' ); ?>" autocomplete="off" style="margin-bottom:10px;" />
						<label for="afsr-remote-secret"><?php esc_html_e( 'Secret key', 'add-from-server-reloaded' ); ?></label>
						<input type="password" id="afsr-remote-secret" name="secret_key" value="" autocomplete="new-password" />
						<?php if ( $editing ) : ?>
							<p class="afsr-field-help"><?php esc_html_e( 'Leave blank to keep existing secret.', 'add-from-server-reloaded' ); ?></p>
						<?php endif; ?>
						<div class="afsr-check-list" style="margin-top:10px;">
							<label><input type="checkbox" name="path_style" value="1" <?php checked( ! empty( $editing['path_style'] ) ); ?> /> <?php esc_html_e( 'Path-style URLs', 'add-from-server-reloaded' ); ?></label>
						</div>
					</div>
					<div class="afsr-form-actions">
						<button type="submit" class="afsr-btn afsr-btn-primary" name="afsrreloaded_save_remote" value="1">
							<?php echo $editing ? esc_html__( 'Update source', 'add-from-server-reloaded' ) : esc_html__( 'Add source', 'add-from-server-reloaded' ); ?>
						</button>
						<?php if ( $editing ) : ?>
							<a class="afsr-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page_slug ) ); ?>"><?php esc_html_e( 'Cancel', 'add-from-server-reloaded' ); ?></a>
						<?php else : ?>
							<a class="afsr-link" href="#" data-afsr-hide="#afsr-remote-form-panel" data-afsr-reveal-btn="#afsr-remote-reveal-btn"><?php esc_html_e( 'Cancel', 'add-from-server-reloaded' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			</form>
		</div>

		<?php if ( ! empty( $profiles ) && ! $browse_id ) : ?>
		<section class="afsr-section">
			<h3 class="afsr-section-title"><?php esc_html_e( 'Stage & import', 'add-from-server-reloaded' ); ?></h3>
			<p class="afsr-section-subtitle"><?php esc_html_e( 'Download a remote folder into local staging, then import it like any server file.', 'add-from-server-reloaded' ); ?></p>
			<div class="afsr-form-card">
				<form method="post" class="afsr-inline-form">
					<?php wp_nonce_field( 'afsrreloaded_stage_import' ); ?>
					<div class="afsr-field">
						<label for="afsr-stage-profile"><?php esc_html_e( 'Source', 'add-from-server-reloaded' ); ?></label>
						<select id="afsr-stage-profile" name="profile_id" required>
							<?php foreach ( $profiles as $pid => $profile ) : ?>
								<option value="<?php echo esc_attr( $pid ); ?>"><?php echo esc_html( $profile['name'] ?? $pid ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="afsr-field">
						<label for="afsr-stage-path"><?php esc_html_e( 'Remote path', 'add-from-server-reloaded' ); ?></label>
						<input type="text" id="afsr-stage-path" name="remote_path" value="" placeholder="/incoming/media" required />
					</div>
					<?php if ( \AFSRReloaded\Features::enabled( 'background' ) ) : ?>
						<input type="hidden" name="background" value="1" />
					<?php endif; ?>
					<div class="afsr-field afsr-field-action">
						<button type="submit" class="afsr-btn afsr-btn-primary" name="afsrreloaded_stage_import" value="1"><?php esc_html_e( 'Stage & import', 'add-from-server-reloaded' ); ?></button>
					</div>
				</form>
			</div>

			<?php if ( 'stage_started' === $message ) : ?>
				<div class="afsr-stage-success">
					<span class="afsr-badge afsr-badge--success"><?php esc_html_e( 'Staged', 'add-from-server-reloaded' ); ?></span>
					<span>
						<?php esc_html_e( 'Files copied to staging. Ready to import.', 'add-from-server-reloaded' ); ?>
						<a class="afsr-link" href="<?php echo esc_url( $import_url ); ?>"><?php esc_html_e( 'Review & Import', 'add-from-server-reloaded' ); ?></a>
					</span>
				</div>
			<?php endif; ?>
		</section>
		<?php endif; ?>
	</div>
</div>
