/**
 * Add From Server Reloaded - JavaScript
 *
 * Handles UI helpers and chunked / resumable bulk imports.
 *
 * @package Add From Server Reloaded
 * @since   4.0.0
 */

jQuery( document ).ready( function( $ ) {
	var data = window.afsrreloadedData || {};
	var state = {
		jobId: 0,
		running: false,
		paused: false,
		cancelled: false,
		pollTimer: null
	};

	function i18n( key, fallback ) {
		if ( data.i18n && data.i18n[ key ] ) {
			return data.i18n[ key ];
		}
		return fallback || key;
	}

	function ajax( action, payload ) {
		payload = payload || {};
		payload.action = action;
		payload.nonce = data.nonce;

		return $.ajax( {
			url: data.ajaxurl,
			method: 'POST',
			dataType: 'json',
			data: payload
		} );
	}

	function selectedValues( name ) {
		var values = [];
		$( 'input[name="' + name + '"]:checked' ).each( function() {
			values.push( $( this ).val() );
		} );
		return values;
	}

	function showProgressPanel() {
		var $panel = $( '#afsrreloaded-progress-panel' );
		$panel.prop( 'hidden', false ).show();
		$( 'html, body' ).animate( { scrollTop: $panel.offset().top - 40 }, 250 );
	}

	function setBusy( isBusy ) {
		$( '#afsrreloaded-import-form input[type="submit"]' ).prop( 'disabled', isBusy );
		$( '#afsrreloaded-pause-job' ).prop( 'disabled', ! isBusy || state.paused );
		$( '#afsrreloaded-cancel-job' ).prop( 'disabled', ! state.jobId );
	}

	function updateProgress( payload ) {
		if ( ! payload ) {
			return;
		}

		var percent = typeof payload.percent === 'number' ? payload.percent : 0;
		$( '.afsrreloaded-progress-percent' ).text( percent + '%' );
		$( '.afsrreloaded-progress-bar-fill' ).css( 'width', percent + '%' );
		$( '.afsrreloaded-progress-bar' ).attr( 'aria-valuenow', percent );

		$( '.afsrreloaded-progress-counts [data-count="imported"] span' ).text( payload.imported || 0 );
		$( '.afsrreloaded-progress-counts [data-count="duplicates"] span' ).text( payload.duplicates || 0 );
		$( '.afsrreloaded-progress-counts [data-count="errors"] span' ).text( payload.errors || 0 );
		$( '.afsrreloaded-progress-counts [data-count="skipped"] span' ).text( payload.skipped || 0 );

		var message = '';
		if ( payload.status === 'scanning' || ( payload.scan_complete === false && ! payload.is_complete ) ) {
			message = data.scanning || 'Scanning folders...';
			if ( payload.total ) {
				message += ' (' + payload.total + ' files found)';
			}
		} else if ( payload.status === 'paused' ) {
			message = data.paused || 'Import paused.';
		} else if ( payload.status === 'cancelled' ) {
			message = data.cancelled || 'Import cancelled.';
		} else if ( payload.is_complete || payload.status === 'completed' ) {
			message = data.complete || 'Import Complete!';
			message += ' ' + ( payload.imported || 0 ) + ' / ' + ( payload.total || 0 );
		} else {
			message = data.importing || 'Importing files...';
			message += ' ' + ( payload.processed || 0 ) + ' / ' + ( payload.total || 0 );
		}

		$( '.afsrreloaded-progress-message' ).text( message );
		$( '.afsrreloaded-import-status' ).text( message );

		if ( payload.errors > 0 && payload.is_complete ) {
			$( '#afsrreloaded-retry-failed' ).prop( 'hidden', false ).show();
		}

		if ( payload.recent && payload.recent.length ) {
			var lines = payload.recent.slice( 0, 12 ).map( function( item ) {
				return '<div class="afsrreloaded-log-line afsrreloaded-log-' + item.status + '"><strong>' +
					$( '<div>' ).text( item.file ).html() + '</strong>: ' +
					$( '<div>' ).text( item.message || item.status ).html() + '</div>';
			} );
			$( '.afsrreloaded-progress-log' ).html( lines.join( '' ) );
		}

		state.paused = ( payload.status === 'paused' );
		$( '#afsrreloaded-pause-job' ).prop( 'hidden', state.paused );
		$( '#afsrreloaded-resume-job' ).prop( 'hidden', ! state.paused );

		if ( payload.is_complete ) {
			state.running = false;
			setBusy( false );
			$( '#afsrreloaded-pause-job, #afsrreloaded-resume-job' ).prop( 'hidden', true );
		}
	}

	function fail( err ) {
		state.running = false;
		setBusy( false );
		var message = data.error || 'An error occurred. Please try again.';
		if ( err && err.responseJSON && err.responseJSON.data && err.responseJSON.data.message ) {
			message = err.responseJSON.data.message;
		} else if ( err && err.message ) {
			message = err.message;
		}
		$( '.afsrreloaded-progress-message' ).text( message );
		$( '.afsrreloaded-import-status' ).text( message );
		window.alert( message );
	}

	function processLoop() {
		if ( ! state.jobId || state.paused || state.cancelled || ! state.running ) {
			return;
		}

		ajax( 'afsrreloaded_process_job', { job_id: state.jobId } )
			.done( function( response ) {
				if ( ! response || ! response.success ) {
					fail( { message: ( response && response.data && response.data.message ) || data.error } );
					return;
				}

				updateProgress( response.data );

				if ( response.data.is_complete || response.data.status === 'paused' || response.data.status === 'cancelled' ) {
					state.running = false;
					setBusy( false );
					return;
				}

				// Keep pumping chunks while the page is open.
				window.setTimeout( processLoop, 50 );
			} )
			.fail( fail );
	}

	function scanLoop() {
		if ( ! state.jobId || state.paused || state.cancelled || ! state.running ) {
			return;
		}

		ajax( 'afsrreloaded_scan_job', { job_id: state.jobId } )
			.done( function( response ) {
				if ( ! response || ! response.success ) {
					fail( { message: ( response && response.data && response.data.message ) || data.error } );
					return;
				}

				updateProgress( response.data );

				if ( response.data.scan_complete ) {
					processLoop();
					return;
				}

				window.setTimeout( scanLoop, 50 );
			} )
			.fail( fail );
	}

	function startImport() {
		var files = selectedValues( 'files[]' );
		var folders = selectedValues( 'folders[]' );

		if ( ! files.length && ! folders.length ) {
			window.alert( data.selectSomething || 'Please select at least one file or folder to import.' );
			return;
		}

		var totalSelected = files.length + folders.length;
		if ( totalSelected > 50 || folders.length > 5 ) {
			var confirmMsg = 'You are about to import ';
			if ( files.length && folders.length ) {
				confirmMsg += files.length + ' file(s) and ' + folders.length + ' folder(s).';
			} else if ( files.length ) {
				confirmMsg += files.length + ' file(s).';
			} else {
				confirmMsg += folders.length + ' folder(s).';
			}
			confirmMsg += ' ' + ( data.confirmLarge || 'This may take a while. Continue?' );
			if ( ! window.confirm( confirmMsg ) ) {
				return;
			}
		}

		state.running = true;
		state.paused = false;
		state.cancelled = false;
		state.jobId = 0;
		setBusy( true );
		showProgressPanel();
		$( '#afsrreloaded-retry-failed' ).prop( 'hidden', true ).hide();
		$( '.afsrreloaded-progress-log' ).empty();
		$( '.afsrreloaded-progress-message' ).text( data.processing || 'Processing...' );

		var payload = {
			files: files,
			folders: folders,
			background: $( '#afsrreloaded-background' ).is( ':checked' ) ? 1 : 0,
			generate_metadata: $( '#afsrreloaded-defer-thumbs' ).is( ':checked' ) ? 0 : 1,
			chunk_size: data.chunkSize || 5
		};

		ajax( 'afsrreloaded_create_job', payload )
			.done( function( response ) {
				if ( ! response || ! response.success ) {
					fail( { message: ( response && response.data && response.data.message ) || data.error } );
					return;
				}

				state.jobId = response.data.job_id;
				updateProgress( response.data );

				if ( ! response.data.scan_complete ) {
					scanLoop();
				} else {
					processLoop();
				}
			} )
			.fail( fail );
	}

	// Handle "show hidden files" toggle button.
	$( '#afsrreloaded-toggle-hidden' ).on( 'click', function() {
		var $button = $( this );
		var $table = $( '.afsrreloaded-file-table' );

		if ( $table.hasClass( 'showhidden' ) ) {
			$table.removeClass( 'showhidden' );
			$button.text( 'Show Hidden Files' );
		} else {
			$table.addClass( 'showhidden' );
			$button.text( 'Hide Hidden Files' );
		}
	} );

	// Handle "show hidden files" toggle link (legacy).
	$( 'tr.hidden-toggle a' ).on( 'click', function( e ) {
		e.preventDefault();
		$( this ).parents( 'table' ).addClass( 'showhidden' );
		$( '#afsrreloaded-toggle-hidden' ).text( 'Hide Hidden Files' );
	} );

	// Handle "select all" checkboxes.
	$( '#afsrreloaded-select-all, #afsrreloaded-select-all-footer' ).on( 'change', function() {
		var isChecked = $( this ).prop( 'checked' );
		$( '.afsrreloaded-file-table tbody input[type="checkbox"]:not(:disabled):visible' ).prop( 'checked', isChecked );
		$( '#afsrreloaded-select-all, #afsrreloaded-select-all-footer' ).prop( 'checked', isChecked );
	} );

	// Sync both select-all checkboxes when individual files are selected.
	$( '.afsrreloaded-file-table tbody input[type="checkbox"]' ).on( 'change', function() {
		var totalCheckboxes = $( '.afsrreloaded-file-table tbody input[type="checkbox"]:not(:disabled)' ).length;
		var checkedCheckboxes = $( '.afsrreloaded-file-table tbody input[type="checkbox"]:not(:disabled):checked' ).length;

		$( '#afsrreloaded-select-all, #afsrreloaded-select-all-footer' ).prop(
			'checked',
			totalCheckboxes === checkedCheckboxes && totalCheckboxes > 0
		);
	} );

	// Chunked AJAX import (prevent full-page POST timeouts).
	$( '#afsrreloaded-import-form' ).on( 'submit', function( e ) {
		e.preventDefault();
		startImport();
		return false;
	} );

	$( '#afsrreloaded-pause-job' ).on( 'click', function() {
		if ( ! state.jobId ) {
			return;
		}
		ajax( 'afsrreloaded_pause_job', { job_id: state.jobId } )
			.done( function( response ) {
				if ( response && response.success ) {
					state.paused = true;
					state.running = false;
					updateProgress( response.data );
					setBusy( false );
					$( '#afsrreloaded-resume-job' ).prop( 'hidden', false ).show();
					$( '#afsrreloaded-pause-job' ).prop( 'hidden', true ).hide();
				}
			} )
			.fail( fail );
	} );

	$( '#afsrreloaded-resume-job' ).on( 'click', function() {
		if ( ! state.jobId ) {
			return;
		}
		ajax( 'afsrreloaded_resume_job', { job_id: state.jobId } )
			.done( function( response ) {
				if ( response && response.success ) {
					state.paused = false;
					state.cancelled = false;
					state.running = true;
					updateProgress( response.data );
					setBusy( true );
					$( '#afsrreloaded-pause-job' ).prop( 'hidden', false ).show();
					$( '#afsrreloaded-resume-job' ).prop( 'hidden', true ).hide();
					if ( ! response.data.scan_complete ) {
						scanLoop();
					} else {
						processLoop();
					}
				}
			} )
			.fail( fail );
	} );

	$( '#afsrreloaded-cancel-job' ).on( 'click', function() {
		if ( ! state.jobId ) {
			return;
		}
		if ( ! window.confirm( 'Cancel this import?' ) ) {
			return;
		}
		state.cancelled = true;
		state.running = false;
		ajax( 'afsrreloaded_cancel_job', { job_id: state.jobId } )
			.done( function( response ) {
				if ( response && response.success ) {
					updateProgress( response.data );
				}
				setBusy( false );
			} )
			.fail( fail );
	} );

	$( '#afsrreloaded-retry-failed' ).on( 'click', function() {
		if ( ! state.jobId ) {
			return;
		}
		state.running = true;
		state.cancelled = false;
		state.paused = false;
		setBusy( true );
		ajax( 'afsrreloaded_retry_failed', { job_id: state.jobId } )
			.done( function( response ) {
				if ( response && response.success ) {
					updateProgress( response.data );
					processLoop();
				} else {
					fail( { message: ( response && response.data && response.data.message ) || data.error } );
				}
			} )
			.fail( fail );
	} );

	// File search functionality.
	if ( $( '.afsrreloaded-file-table' ).length ) {
		$( '.afsrreloaded-file-table' ).before(
			'<div class="afsrreloaded-search-box" style="margin-bottom: 10px;">' +
			'<input type="text" id="afsrreloaded-file-search" class="regular-text" placeholder="Search files..." />' +
			'<button type="button" class="button" id="afsrreloaded-clear-search">Clear</button>' +
			'</div>'
		);

		$( document ).on( 'keydown', '#afsrreloaded-file-search', function( e ) {
			if ( e.keyCode === 13 ) {
				e.preventDefault();
				e.stopPropagation();
				return false;
			}
		} );

		$( '#afsrreloaded-file-search' ).on( 'keyup', function() {
			var searchTerm = $( this ).val().toLowerCase().trim();

			if ( searchTerm === '' ) {
				$( '.afsrreloaded-file-table tbody tr' ).not( '.hidden-toggle' ).show().css( 'opacity', '1' );
				return;
			}

			$( '.afsrreloaded-file-table tbody tr' ).each( function() {
				var $row = $( this );
				if ( $row.hasClass( 'hidden-toggle' ) ) {
					return;
				}

				var itemName = $row.find( 'label' ).text().toLowerCase();
				if ( ! itemName ) {
					itemName = $row.find( 'a' ).text().toLowerCase();
				}

				if ( itemName.indexOf( searchTerm ) !== -1 ) {
					$row.show().css( 'opacity', '1' );
				} else {
					$row.hide();
				}
			} );
		} );

		$( '#afsrreloaded-clear-search' ).on( 'click', function() {
			$( '#afsrreloaded-file-search' ).val( '' ).trigger( 'keyup' );
		} );
	}

	// File size display enhancement.
	$( '.afsrreloaded-file-table tbody tr' ).each( function() {
		var checkbox = $( this ).find( 'input[type="checkbox"]' );
		if ( checkbox.length && checkbox.prop( 'disabled' ) ) {
			$( this ).addClass( 'afsrreloaded-disabled-row' );
		}
	} );

	// Keyboard shortcuts.
	$( document ).on( 'keydown', function( e ) {
		if ( ( e.ctrlKey || e.metaKey ) && e.keyCode === 65 && $( '.afsrreloaded-file-table' ).length ) {
			e.preventDefault();
			$( '#afsrreloaded-select-all' ).prop( 'checked', true ).trigger( 'change' );
		}

		if ( e.keyCode === 27 && $( '#afsrreloaded-file-search' ).length ) {
			$( '#afsrreloaded-file-search' ).val( '' ).trigger( 'keyup' );
		}
	} );

	function updateFileCount() {
		var totalFiles = $( 'input[name="files[]"]:not(:disabled)' ).length;
		var selectedFiles = $( 'input[name="files[]"]:not(:disabled):checked' ).length;
		var totalFolders = $( 'input[name="folders[]"]:not(:disabled)' ).length;
		var selectedFolders = $( 'input[name="folders[]"]:not(:disabled):checked' ).length;
		var countText = '';

		if ( selectedFiles > 0 || selectedFolders > 0 ) {
			if ( selectedFiles > 0 && selectedFolders > 0 ) {
				countText = selectedFiles + ' file(s) and ' + selectedFolders + ' folder(s) selected';
			} else if ( selectedFiles > 0 ) {
				countText = selectedFiles + ' of ' + totalFiles + ' file(s) selected';
			} else {
				countText = selectedFolders + ' of ' + totalFolders + ' folder(s) selected';
			}
		}

		if ( $( '.afsrreloaded-file-count' ).length === 0 ) {
			$( '.afsrreloaded-import-status' ).before( '<span class="afsrreloaded-file-count" style="margin-left: 15px; color: #666;"></span>' );
		}

		$( '.afsrreloaded-file-count' ).text( countText );
	}

	$( '.afsrreloaded-file-table tbody input[type="checkbox"], #afsrreloaded-select-all, #afsrreloaded-select-all-footer' ).on( 'change', function() {
		updateFileCount();
	} );

	if ( $( '.afsrreloaded-file-table' ).length ) {
		updateFileCount();
	}

	$( '.afsrreloaded-file-table tbody tr[title]' ).each( function() {
		var title = $( this ).attr( 'title' );
		if ( title ) {
			$( this ).find( 'label' ).attr( 'title', title );
		}
	} );
} );
