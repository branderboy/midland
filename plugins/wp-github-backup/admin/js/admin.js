/**
 * WP GitHub Backup Admin JavaScript
 *
 * @package WPGitHubBackup
 */

(function ($) {
	'use strict';

	/**
	 * i18n helper.
	 *
	 * Prefer the WordPress i18n package (wp.i18n.__ — loaded via
	 * wp_set_script_translations on the PHP side) when available; fall
	 * back to an identity function so the plugin still works even if
	 * @wordpress/i18n isn't registered on the page for some reason.
	 */
	var __ = ( window.wp && window.wp.i18n && typeof window.wp.i18n.__ === 'function' )
		? window.wp.i18n.__
		: function ( s ) { return s; };

	$(document).ready(function () {
		// Backup Now button.
		$('#wgb-backup-now').on('click', function () {
			var $btn = $(this);
			var $status = $('#wgb-backup-status');
			var $result = $('#wgb-backup-result');

			$btn.prop('disabled', true);
			$status.show();
			$result.hide();

			$.ajax({
				url: wgbAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wgb_run_backup',
					nonce: wgbAdmin.nonce
				},
				timeout: 600000, // 10 minute timeout
				success: function (response) {
					$status.hide();
					$btn.prop('disabled', false);

					if (response.success) {
						var data = response.data;
						var msg = 'Backup ' + data.status + '! ';
						msg += data.files_pushed + ' files pushed, ';
						msg += formatSize(data.total_size) + ' total, ';
						msg += data.duration + 's duration.';

						if (data.errors && data.errors.length > 0) {
							msg += '\nErrors: ' + data.errors.join('; ');
						}

						$result
							.text(msg)
							.removeClass('error')
							.addClass('success')
							.show();
					} else {
						$result
							.text(__( 'Backup failed:', 'wp-github-backup' ) + ' ' + response.data)
							.removeClass('success')
							.addClass('error')
							.show();
					}
				},
				error: function (xhr, status, error) {
					$status.hide();
					$btn.prop('disabled', false);
					$result
						.text(wgbAdmin.i18n.requestFailed + ' ' + error)
						.removeClass('success')
						.addClass('error')
						.show();
				}
			});
		});

		// Settings form — save via AJAX for reliability (no page redirect).
		$('#wgb-settings-form').on('submit', function (e) {
			e.preventDefault();

			var $form = $(this);
			var $statusEl = $('#wgb-settings-status');
			var $submitBtn = $form.find('button[type="submit"]');

			$submitBtn.prop('disabled', true).text( __( 'Saving...', 'wp-github-backup' ) );
			$statusEl.text('').css('color', '');

			// Build form data manually so unchecked checkboxes are included as '0'.
			var formData = {};
			formData['action'] = 'wgb_save_settings';
			formData['nonce'] = wgbAdmin.nonce;

			// Text/select fields.
			$form.find('input[type="text"], input[type="password"], input[type="email"], input[type="number"], select').each(function () {
				var name = $(this).attr('name');
				if (name) {
					formData[name] = $(this).val();
				}
			});

			// Checkboxes — send '1' if checked, '0' if not.
			$form.find('input[type="checkbox"]').each(function () {
				var name = $(this).attr('name');
				if (name) {
					formData[name] = $(this).is(':checked') ? '1' : '0';
				}
			});

			$.ajax({
				url: wgbAdmin.ajaxUrl,
				type: 'POST',
				data: formData,
				success: function (response) {
					$submitBtn.prop('disabled', false).text( __( 'Save Settings', 'wp-github-backup' ) );

					if (response.success) {
						$statusEl.text( __( 'Settings saved!', 'wp-github-backup' ) ).css('color', '#46b450');

						// Update the token indicator on the page.
						var $tokenSpan = $form.find('.wgb-token-set, span[style*="color:#dc3232"]');
						if (formData['github_token'] && formData['github_token'].length > 0) {
							$tokenSpan.text( __( 'Token is saved.', 'wp-github-backup' ) ).css({'color': '#46b450', 'font-weight': 'bold'}).removeClass().addClass('wgb-token-set');
							$form.find('#wgb-token').attr('placeholder', '••••••••••••••••').val('');
						}
					} else {
						$statusEl.text('Error: ' + (response.data || wgbAdmin.i18n.saveFailed)).css('color', '#dc3232');
					}
					setTimeout(function () { $statusEl.text(''); }, 5000);
				},
				error: function (xhr, status, error) {
					$submitBtn.prop('disabled', false).text( __( 'Save Settings', 'wp-github-backup' ) );
					$statusEl.text(wgbAdmin.i18n.error + ' ' + error).css('color', '#dc3232');
				}
			});
		});

		// Test Connection.
		$('#wgb-test-connection').on('click', function () {
			var $statusEl = $('#wgb-settings-status');
			$statusEl.text( __( 'Testing...', 'wp-github-backup' ) ).css('color', '#666');

			$.ajax({
				url: wgbAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wgb_test_connection',
					nonce: wgbAdmin.nonce
				},
				success: function (response) {
					if (response.success) {
						$statusEl.text(response.data).css('color', '#46b450');
					} else {
						$statusEl.text(response.data).css('color', '#dc3232');
					}
					setTimeout(function () {
						$statusEl.text('');
					}, 5000);
				},
				error: function () {
					$statusEl.text( __( 'Connection test failed.', 'wp-github-backup' ) ).css('color', '#dc3232');
				}
			});
		});

		// =============================================
		// Content Editor Tab
		// =============================================
		var editorCurrentPage = 1;

		function editorLoadItems(page) {
			page = page || 1;
			editorCurrentPage = page;
			var postType = $('#wgb-editor-type').val();
			var search = $('#wgb-editor-search').val();

			$('#wgb-editor-tbody').html('<tr><td colspan="5">' + wgbAdmin.i18n.loading + '</td></tr>');

			$.ajax({
				url: wgbAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wgb_get_content_items',
					nonce: wgbAdmin.nonce,
					post_type: postType,
					paged: page,
					search: search
				},
				success: function (response) {
					if (!response.success) {
						$('#wgb-editor-tbody').html('<tr><td colspan="5">' + wgbAdmin.i18n.error + ' ' + escHtml(response.data) + '</td></tr>');
						return;
					}
					var d = response.data;
					if (d.items.length === 0) {
						$('#wgb-editor-tbody').html('<tr><td colspan="5">' + wgbAdmin.i18n.noItems + '</td></tr>');
						$('#wgb-editor-pagination').html('');
						return;
					}
					var html = '';
					$.each(d.items, function (i, item) {
						html += '<tr>';
						html += '<td><strong>' + escHtml(item.title || wgbAdmin.i18n.noTitle) + '</strong></td>';
						html += '<td><span class="wgb-status wgb-status-' + escHtml(item.status) + '">' + escHtml(item.status) + '</span></td>';
						html += '<td><code>/' + escHtml(item.slug) + '</code></td>';
						html += '<td>' + escHtml(item.date) + '</td>';
						html += '<td><button type="button" class="button button-small wgb-edit-item" data-id="' + item.ID + '">' + wgbAdmin.i18n.edit + '</button></td>';
						html += '</tr>';
					});
					$('#wgb-editor-tbody').html(html);

					// Pagination.
					var pag = '';
					if (d.total_pages > 1) {
						pag += '<span class="displaying-num">' + d.total + ' ' + wgbAdmin.i18n.itemsLabel + '</span> ';
						for (var p = 1; p <= d.total_pages; p++) {
							if (p === d.page) {
								pag += '<strong>' + p + '</strong> ';
							} else {
								pag += '<a href="#" class="wgb-editor-page" data-page="' + p + '">' + p + '</a> ';
							}
						}
					}
					$('#wgb-editor-pagination').html(pag);
				}
			});
		}

		$('#wgb-editor-search-btn').on('click', function () {
			editorLoadItems(1);
		});

		$('#wgb-editor-search').on('keypress', function (e) {
			if (e.which === 13) {
				e.preventDefault();
				editorLoadItems(1);
			}
		});

		$('#wgb-editor-type').on('change', function () {
			editorLoadItems(1);
		});

		$(document).on('click', '.wgb-editor-page', function (e) {
			e.preventDefault();
			editorLoadItems($(this).data('page'));
		});

		// Edit button — load item.
		$(document).on('click', '.wgb-edit-item', function () {
			var postId = $(this).data('id');
			$('#wgb-editor-list').hide();
			$('#wgb-editor-form').show();
			$('#wgb-editor-status').text( __( 'Loading...', 'wp-github-backup' ) );

			$.ajax({
				url: wgbAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wgb_get_content_item',
					nonce: wgbAdmin.nonce,
					post_id: postId
				},
				success: function (response) {
					$('#wgb-editor-status').text('');
					if (!response.success) {
						$('#wgb-editor-status').text('Error: ' + response.data).css('color', '#dc3232');
						return;
					}
					var item = response.data;
					$('#wgb-edit-id').val(item.ID);
					$('#wgb-editor-form-title').text('Edit: ' + item.title);
					$('#wgb-edit-title').val(item.title);
					$('#wgb-edit-slug').val(item.slug);
					$('#wgb-edit-permalink').text('Permalink: ' + item.permalink);
					$('#wgb-edit-status').val(item.status);
					$('#wgb-edit-content').val(item.content);
					$('#wgb-edit-excerpt').val(item.excerpt);
					$('#wgb-edit-schema').val(item.schema_json_ld || '');

					// Categories / tags visibility.
					if (item.post_type === 'post') {
						$('#wgb-edit-cats-row, #wgb-edit-tags-row').show();
						// Check category boxes.
						$('#wgb-edit-categories input').prop('checked', false);
						if (item.categories) {
							$.each(item.categories, function (i, cat) {
								$('#wgb-edit-categories input[value="' + cat.term_id + '"]').prop('checked', true);
							});
						}
						// Tags.
						if (item.tags) {
							var tagNames = $.map(item.tags, function (t) { return t.name; });
							$('#wgb-edit-tags').val(tagNames.join(', '));
						} else {
							$('#wgb-edit-tags').val('');
						}
					} else {
						$('#wgb-edit-cats-row, #wgb-edit-tags-row').hide();
					}

					// Meta fields.
					var metaHtml = '';
					if (item.meta && item.meta.length > 0) {
						$.each(item.meta, function (i, m) {
							var val = typeof m.value === 'object' ? JSON.stringify(m.value) : m.value;
							metaHtml += '<tr class="wgb-meta-row">';
							metaHtml += '<td><input type="text" class="wgb-meta-key regular-text" value="' + escHtml(m.key) + '" /></td>';
							metaHtml += '<td><input type="text" class="wgb-meta-value large-text" value="' + escHtml(val) + '" /></td>';
							metaHtml += '<td><button type="button" class="button button-small wgb-meta-remove">Remove</button></td>';
							metaHtml += '</tr>';
						});
					}
					$('#wgb-meta-tbody').html(metaHtml);
				}
			});
		});

		// Back to list.
		$('#wgb-editor-back').on('click', function (e) {
			e.preventDefault();
			$('#wgb-editor-form').hide();
			$('#wgb-editor-list').show();
		});

		// Add meta row.
		$('#wgb-meta-add').on('click', function () {
			var row = '<tr class="wgb-meta-row">';
			row += '<td><input type="text" class="wgb-meta-key regular-text" placeholder="key" /></td>';
			row += '<td><input type="text" class="wgb-meta-value large-text" placeholder="value" /></td>';
			row += '<td><button type="button" class="button button-small wgb-meta-remove">Remove</button></td>';
			row += '</tr>';
			$('#wgb-meta-tbody').append(row);
		});

		// Remove meta row.
		$(document).on('click', '.wgb-meta-remove', function () {
			$(this).closest('tr').remove();
		});

		// Save content item.
		$('#wgb-editor-save').on('click', function () {
			var $statusEl = $('#wgb-editor-status');
			$statusEl.text( __( 'Saving...', 'wp-github-backup' ) ).css('color', '#666');

			var postId = $('#wgb-edit-id').val();

			// Collect meta.
			var metaArr = [];
			$('#wgb-meta-tbody .wgb-meta-row').each(function () {
				var key = $(this).find('.wgb-meta-key').val();
				var val = $(this).find('.wgb-meta-value').val();
				if (key) {
					metaArr.push({ key: key, value: val });
				}
			});

			// Collect category IDs.
			var catIds = [];
			$('#wgb-edit-categories input:checked').each(function () {
				catIds.push($(this).val());
			});

			var payload = {
				action: 'wgb_save_content_item',
				nonce: wgbAdmin.nonce,
				post_id: postId,
				title: $('#wgb-edit-title').val(),
				slug: $('#wgb-edit-slug').val(),
				status: $('#wgb-edit-status').val(),
				content: $('#wgb-edit-content').val(),
				excerpt: $('#wgb-edit-excerpt').val(),
				tags: $('#wgb-edit-tags').val(),
				schema_json_ld: $('#wgb-edit-schema').val(),
				meta: JSON.stringify(metaArr),
				'category_ids[]': catIds
			};

			$.ajax({
				url: wgbAdmin.ajaxUrl,
				type: 'POST',
				data: payload,
				success: function (response) {
					if (response.success) {
						$statusEl.text( __( 'Saved!', 'wp-github-backup' ) ).css('color', '#46b450');
						$('#wgb-edit-permalink').text('Permalink: ' + response.data.permalink);
						$('#wgb-edit-slug').val(response.data.slug);
					} else {
						$statusEl.text('Error: ' + response.data).css('color', '#dc3232');
					}
					setTimeout(function () { $statusEl.text(''); }, 4000);
				},
				error: function () {
					$statusEl.text( __( wgbAdmin.i18n.saveFailed, 'wp-github-backup' ) ).css('color', '#dc3232');
				}
			});
		});

		// Auto-load items if on editor tab.
		if ($('#wgb-editor-list').length && window.location.search.indexOf('tab=editor') !== -1) {
			editorLoadItems(1);
		}

		// Load Backups (Restore tab).
		$('#wgb-load-backups').on('click', function () {
			var $btn = $(this);
			var $loading = $('#wgb-backups-loading');
			var $table = $('#wgb-backups-table');

			$btn.prop('disabled', true);
			$loading.show();
			$table.hide();

			$.ajax({
				url: wgbAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wgb_get_backups',
					nonce: wgbAdmin.nonce
				},
				success: function (response) {
					$loading.hide();
					$btn.prop('disabled', false);

					if (response.success && response.data.length > 0) {
						var html = '<table class="wgb-backups-list">';
						html += '<thead><tr><th>Type</th><th>Filename</th><th>Date</th><th>Size</th><th>Action</th></tr></thead>';
						html += '<tbody>';

						$.each(response.data, function (i, file) {
							html += '<tr>';
							html += '<td>' + escHtml(file.directory) + '</td>';
							html += '<td>' + escHtml(file.name) + '</td>';
							html += '<td>' + escHtml(file.date) + '</td>';
							html += '<td>' + formatSize(file.size) + '</td>';
							html += '<td>';
							if (file.download_url) {
								html += '<a href="' + escHtml(file.download_url) + '" class="button button-small" target="_blank" rel="noopener noreferrer">Download</a>';
							}
							html += '</td>';
							html += '</tr>';
						});

						html += '</tbody></table>';
						$table.html(html).show();
					} else if (response.success) {
						$table.html('<p>No backups found in the repository.</p>').show();
					} else {
						$table.html('<p class="error">Error: ' + escHtml(response.data) + '</p>').show();
					}
				},
				error: function () {
					$loading.hide();
					$btn.prop('disabled', false);
					$table.html('<p class="error">Failed to load backups.</p>').show();
				}
			});
		});

		// =============================================
		// Deploy Tab
		// =============================================

		// Save Deploy Settings.
		$('#wgb-deploy-settings-form').on('submit', function (e) {
			e.preventDefault();

			var $form = $(this);
			var $statusEl = $('#wgb-deploy-settings-status');
			var formData = $form.serializeArray();

			formData.push({ name: 'action', value: 'wgb_save_deploy_settings' });
			formData.push({ name: 'nonce', value: wgbAdmin.nonce });

			$statusEl.text( __( 'Saving...', 'wp-github-backup' ) ).css('color', '#666');

			$.ajax({
				url: wgbAdmin.ajaxUrl,
				type: 'POST',
				data: $.param(formData),
				success: function (response) {
					if (response.success) {
						$statusEl.text( __( 'Settings saved!', 'wp-github-backup' ) ).css('color', '#46b450');
					} else {
						$statusEl.text('Error: ' + response.data).css('color', '#dc3232');
					}
					setTimeout(function () { $statusEl.text(''); }, 3000);
				},
				error: function () {
					$statusEl.text( __( 'Request failed.', 'wp-github-backup' ) ).css('color', '#dc3232');
				}
			});
		});

		// Deploy Preview.
		$('#wgb-deploy-preview').on('click', function () {
			var $btn = $(this);
			var $result = $('#wgb-deploy-preview-result');

			$btn.prop('disabled', true).text( __( 'Loading preview...', 'wp-github-backup' ) );
			$result.hide();

			$.ajax({
				url: wgbAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wgb_deploy_preview',
					nonce: wgbAdmin.nonce
				},
				timeout: 120000,
				success: function (response) {
					$btn.prop('disabled', false).text( __( 'Preview Deploy', 'wp-github-backup' ) );

					if (response.success) {
						var d = response.data;
						var html = '<div class="wgb-deploy-preview-box">';
						html += '<h4>Deploy Preview — ' + escHtml(d.target) + ' from branch <code>' + escHtml(d.branch) + '</code></h4>';
						html += '<p><strong>' + d.file_count + '</strong> files, <strong>' + formatSize(d.total_size) + '</strong> total</p>';

						if (d.files.length > 0) {
							html += '<div class="wgb-deploy-file-list">';
							html += '<table class="wgb-backups-list"><thead><tr><th>File Path</th><th>Size</th></tr></thead><tbody>';
							var shown = Math.min(d.files.length, 100);
							for (var i = 0; i < shown; i++) {
								html += '<tr><td><code>' + escHtml(d.files[i].path) + '</code></td>';
								html += '<td>' + formatSize(d.files[i].size) + '</td></tr>';
							}
							if (d.files.length > 100) {
								html += '<tr><td colspan="2"><em>... and ' + (d.files.length - 100) + ' more files</em></td></tr>';
							}
							html += '</tbody></table></div>';
						}

						html += '</div>';
						$result.html(html).show();
					} else {
						$result.html('<div class="wgb-deploy-preview-box error"><p>Error: ' + escHtml(response.data) + '</p></div>').show();
					}
				},
				error: function (xhr, status, error) {
					$btn.prop('disabled', false).text( __( 'Preview Deploy', 'wp-github-backup' ) );
					$result.html('<div class="wgb-deploy-preview-box error"><p>Request failed: ' + escHtml(error) + '</p></div>').show();
				}
			});
		});

		// Deploy Now.
		$('#wgb-deploy-now').on('click', function () {
			if (!confirm( __( 'This will overwrite files on your site with files from the GitHub repo. Are you sure?', 'wp-github-backup' ) )) {
				return;
			}

			var $btn = $(this);
			var $status = $('#wgb-deploy-status');
			var $result = $('#wgb-deploy-result');

			$btn.prop('disabled', true);
			$status.show();
			$result.hide();

			$.ajax({
				url: wgbAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wgb_run_deploy',
					nonce: wgbAdmin.nonce
				},
				timeout: 600000,
				success: function (response) {
					$status.hide();
					$btn.prop('disabled', false);

					if (response.success) {
						var d = response.data;
						var lines = [];
						lines.push('Deploy ' + d.status + ' — ' + d.duration + 's');
						lines.push('');
						lines.push('Branch:    ' + (d.branch || 'n/a') + (d.head_sha ? ' @ ' + d.head_sha.substring(0, 7) : ''));
						lines.push('Scanned:   ' + (d.scanned != null ? d.scanned : '?') + ' .html files (pages/ + posts/)');
						var imp = (d.imported != null ? d.imported : d.files);
						lines.push('Imported:  ' + imp);
						lines.push('Skipped:   ' + (d.skipped != null ? d.skipped : 0) + ' (already in sync)');

						var b = d.breakdown || {};
						var details = [];
						if (b.posts_created > 0)  details.push(b.posts_created + ' posts created');
						if (b.posts_updated > 0)  details.push(b.posts_updated + ' posts updated');
						if (b.pages_created > 0)  details.push(b.pages_created + ' pages created');
						if (b.pages_updated > 0)  details.push(b.pages_updated + ' pages updated');
						if (b.categories_set > 0) details.push(b.categories_set + ' categories assigned');
						if (b.tags_set > 0)       details.push(b.tags_set + ' tags assigned');
						if (b.seo_meta > 0)       details.push(b.seo_meta + ' with Yoast SEO meta');
						if (b.schema_data > 0)    details.push(b.schema_data + ' with structured data (JSON-LD)');
						if (details.length > 0) {
							lines.push('');
							lines.push( __( 'Breakdown:', 'wp-github-backup' ) );
							details.forEach(function (s) { lines.push('  • ' + s); });
						}

						if (d.reason) {
							lines.push('');
							lines.push( __( 'Why 0 imported:', 'wp-github-backup' ) );
							lines.push('  ' + d.reason);
						}

						if (imp === 0) {
							lines.push('');
							lines.push( __( 'Note: this button only deploys pages/*.html and posts/*.html.', 'wp-github-backup' ) );
							lines.push( __( '      .htaccess, plugin zips, and .md reports must be uploaded', 'wp-github-backup' ) );
							lines.push( __( '      to the server manually — they are never touched here.', 'wp-github-backup' ) );
						}

						if (d.errors && d.errors.length > 0) {
							lines.push('');
							lines.push( __( 'Errors:', 'wp-github-backup' ) );
							d.errors.forEach(function (e) { lines.push('  ✗ ' + e); });
						}

						// Cache purge confirmation — what got flushed so the
						// user knows the live site is serving fresh HTML.
						if (d.purged && d.purged.length > 0) {
							lines.push('');
							lines.push('Cache purged: ' + d.purged.join(', '));
						} else if (imp > 0) {
							lines.push('');
							lines.push( __( 'Cache purged: (no known caching plugin detected — nothing to flush)', 'wp-github-backup' ) );
						}

						// Live verification result — did the deploy actually
						// reach the frontend, or is a cache serving a stale copy?
						if (d.verify) {
							lines.push('');
							lines.push(d.verify.ok ? '✓ Live verify: ' + d.verify.message : '⚠ Live verify: ' + d.verify.message);
							if (d.verify.url) {
								lines.push('  Checked: ' + d.verify.url);
							}
						}

						// Per-file audit — exactly which files got written,
						// which were skipped, which failed. Truncate skipped
						// list if everything was unchanged (common case).
						if (d.audit && d.audit.length > 0) {
							var written = d.audit.filter(function (a) { return a.action === 'created' || a.action === 'updated'; });
							var skipped = d.audit.filter(function (a) { return a.action === 'skipped'; });
							var failed  = d.audit.filter(function (a) { return a.action === 'failed'; });

							if (written.length > 0) {
								lines.push('');
								lines.push('Written (' + written.length + '):');
								written.forEach(function (a) {
									lines.push('  ✓ [' + a.action + '] ' + a.path + (a.post_type ? '  → ' + a.post_type + '#' + a.post_id : ''));
								});
							}
							if (failed.length > 0) {
								lines.push('');
								lines.push('Failed (' + failed.length + '):');
								failed.forEach(function (a) {
									lines.push('  ✗ ' + a.path + ' — ' + a.reason);
								});
							}
							if (skipped.length > 0 && skipped.length <= 20) {
								lines.push('');
								lines.push('Skipped (' + skipped.length + '):');
								skipped.forEach(function (a) {
									lines.push('  · ' + a.path);
								});
							} else if (skipped.length > 20) {
								lines.push('');
								lines.push('Skipped (' + skipped.length + ' files, all with matching content hash).');
							}
						}

						var msg = lines.join('\n');

						var cls = d.status === 'success' ? 'success' : 'error';
						$result.css('white-space', 'pre-wrap').text(msg).removeClass('success error').addClass(cls).show();
					} else {
						$result.text(__( 'Deploy failed:', 'wp-github-backup' ) + ' ' + response.data).removeClass('success').addClass('error').show();
					}
				},
				error: function (xhr, status, error) {
					$status.hide();
					$btn.prop('disabled', false);
					$result.text(wgbAdmin.i18n.requestFailed + ' ' + error).removeClass('success').addClass('error').show();
				}
			});
		});

		// Update Plugin from GitHub.
		$('#wgb-update-plugin').on('click', function () {
			if (!confirm( __( 'This will overwrite plugin files (wp-github-backup & wp-claude-manager) with the latest from GitHub. Continue?', 'wp-github-backup' ) )) {
				return;
			}

			var $btn = $(this);
			var $status = $('#wgb-update-plugin-status');
			var $result = $('#wgb-update-plugin-result');

			$btn.prop('disabled', true);
			$status.show();
			$result.hide();

			$.ajax({
				url: wgbAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wgb_update_plugin',
					nonce: wgbAdmin.nonce
				},
				timeout: 300000,
				success: function (response) {
					$status.hide();
					$btn.prop('disabled', false);

					if (response.success) {
						var d = response.data;
						var msg = 'Plugin update ' + d.status + '! ' + d.updated + ' files updated.';

						if (d.errors && d.errors.length > 0) {
							msg += '\nErrors: ' + d.errors.join('; ');
						}

						var cls = d.status === 'success' ? 'success' : 'error';
						$result.css('white-space', 'pre-wrap').text(msg).removeClass('success error').addClass(cls).show();
					} else {
						$result.text('Update failed: ' + response.data).removeClass('success').addClass('error').show();
					}
				},
				error: function (xhr, status, error) {
					$status.hide();
					$btn.prop('disabled', false);
					$result.text(wgbAdmin.i18n.requestFailed + ' ' + error).removeClass('success').addClass('error').show();
				}
			});
		});

		// Deploy Latest Changes Only (incremental).
		$('#wgb-deploy-incremental').on('click', function () {
			var $btn = $(this);
			var $status = $('#wgb-deploy-status');
			var $result = $('#wgb-deploy-result');

			$btn.prop('disabled', true);
			$('#wgb-deploy-now').prop('disabled', true);
			$status.find('.wgb-progress-text').text( __( 'Deploying changes only...', 'wp-github-backup' ) );
			$status.show();
			$result.hide();

			$.ajax({
				url: wgbAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wgb_run_deploy_incremental',
					nonce: wgbAdmin.nonce
				},
				timeout: 600000,
				success: function (response) {
					$status.hide();
					$btn.prop('disabled', false);
					$('#wgb-deploy-now').prop('disabled', false);
					$status.find('.wgb-progress-text').text( __( 'Deploying...', 'wp-github-backup' ) );

					if (response.success) {
						var d = response.data;
						var msg;
						if (d.no_baseline) {
							msg = 'No baseline yet — run Deploy Now once to record the current commit, then use this button afterwards.';
						} else if (d.up_to_date) {
							msg = d.message ? d.message : 'Already up to date — no changes to deploy.';
						} else {
							msg = 'Incremental deploy ' + d.status + ' (' + d.duration + 's)\n';
							msg += d.files + ' items imported, ' + d.skipped + ' unchanged.\n';
							if (d.base_sha && d.head_sha) {
								msg += 'Range: ' + d.base_sha.substring(0, 7) + '...' + d.head_sha.substring(0, 7);
							}
						}

						if (d.errors && d.errors.length > 0) {
							msg += '\nErrors: ' + d.errors.join('; ');
						}

						var cls = (d.status === 'failed' || (d.errors && d.errors.length > 0)) ? 'error' : 'success';
						$result.css('white-space', 'pre-wrap').text(msg).removeClass('success error').addClass(cls).show();
					} else {
						$result.text('Incremental deploy failed: ' + response.data).removeClass('success').addClass('error').show();
					}
				},
				error: function (xhr, status, error) {
					$status.hide();
					$btn.prop('disabled', false);
					$('#wgb-deploy-now').prop('disabled', false);
					$status.find('.wgb-progress-text').text( __( 'Deploying...', 'wp-github-backup' ) );
					$result.text(wgbAdmin.i18n.requestFailed + ' ' + error).removeClass('success').addClass('error').show();
				}
			});
		});

		// Clean Redirected Links.
		$('#wgb-clean-redirects').on('click', function () {
			var pairs = $('#wgb-clean-redirects-pairs').val();
			if (!pairs || !pairs.trim()) {
				alert( __( 'Paste at least one old_url|new_url pair first.', 'wp-github-backup' ) );
				return;
			}

			if (!confirm( __( 'This runs a database REPLACE across post content and postmeta. There is no dry run — make a backup first if you want one. Continue?', 'wp-github-backup' ) )) {
				return;
			}

			var $btn = $(this);
			var $status = $('#wgb-clean-redirects-status');
			var $result = $('#wgb-clean-redirects-result');

			$btn.prop('disabled', true);
			$status.show();
			$result.hide();

			$.ajax({
				url: wgbAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wgb_clean_redirects',
					nonce: wgbAdmin.nonce,
					pairs: pairs
				},
				timeout: 120000,
				success: function (response) {
					$status.hide();
					$btn.prop('disabled', false);

					if (response.success) {
						var d = response.data;
						var msg = 'Replaced ' + d.total_rows_changed + ' row(s) total:\n';
						$.each(d.pairs, function (i, p) {
							msg += '  ' + p.old + '  →  ' + p.new + '\n';
							msg += '    post_content: ' + p.posts_updated + ', postmeta: ' + p.meta_updated + '\n';
						});
						$result.css('white-space', 'pre-wrap').text(msg).removeClass('error').addClass('success').show();
					} else {
						$result.text('Clean failed: ' + response.data).removeClass('success').addClass('error').show();
					}
				},
				error: function (xhr, status, error) {
					$status.hide();
					$btn.prop('disabled', false);
					$result.text(wgbAdmin.i18n.requestFailed + ' ' + error).removeClass('success').addClass('error').show();
				}
			});
		});

		// Rollback Last Deploy.
		$('#wgb-rollback-deploy').on('click', function () {
			if (!confirm( __( 'This restores every post/page touched by the last deploy to its pre-deploy WordPress revision. Continue?', 'wp-github-backup' ) )) {
				return;
			}

			var $btn = $(this);
			var $status = $('#wgb-rollback-status');
			var $result = $('#wgb-rollback-result');

			$btn.prop('disabled', true);
			$status.show();
			$result.hide();

			$.ajax({
				url: wgbAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wgb_rollback_deploy',
					nonce: wgbAdmin.nonce
				},
				timeout: 300000,
				success: function (response) {
					$status.hide();
					$btn.prop('disabled', false);

					if (response.success) {
						var d = response.data;
						var msg = 'Rollback complete. Restored ' + d.restored + ' of ' + d.total + ' posts/pages';
						if (d.skipped > 0) {
							msg += ' (' + d.skipped + ' had no pre-deploy revision and were skipped)';
						}
						msg += '.\nLast deploy: ' + d.deploy_date;

						if (d.errors && d.errors.length > 0) {
							msg += '\nErrors: ' + d.errors.join('; ');
						}

						var cls = d.errors && d.errors.length > 0 ? 'error' : 'success';
						$result.css('white-space', 'pre-wrap').text(msg).removeClass('success error').addClass(cls).show();
					} else {
						$result.text('Rollback failed: ' + response.data).removeClass('success').addClass('error').show();
					}
				},
				error: function (xhr, status, error) {
					$status.hide();
					$btn.prop('disabled', false);
					$result.text(wgbAdmin.i18n.requestFailed + ' ' + error).removeClass('success').addClass('error').show();
				}
			});
		});

		// Load Deploy History.
		$('#wgb-load-deploy-history').on('click', function () {
			var $btn = $(this);
			var $table = $('#wgb-deploy-history-table');

			$btn.prop('disabled', true).text( __( 'Loading...', 'wp-github-backup' ) );

			$.ajax({
				url: wgbAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wgb_deploy_history',
					nonce: wgbAdmin.nonce
				},
				success: function (response) {
					$btn.prop('disabled', false).text( __( 'Load History', 'wp-github-backup' ) );

					if (response.success && response.data.length > 0) {
						var html = '<table class="wp-list-table widefat fixed striped">';
						html += '<thead><tr><th>Date</th><th>Status</th><th>Target</th><th>Branch</th><th>Files</th><th>Duration</th><th>Errors</th></tr></thead>';
						html += '<tbody>';

						$.each(response.data, function (i, entry) {
							html += '<tr>';
							html += '<td>' + escHtml(entry.deploy_date) + '</td>';
							html += '<td><span class="wgb-status wgb-status-' + escHtml(entry.status) + '">' + escHtml(entry.status) + '</span></td>';
							html += '<td>' + escHtml(entry.target || '—') + '</td>';
							html += '<td><code>' + escHtml(entry.branch || '—') + '</code></td>';
							html += '<td>' + escHtml(entry.files_deployed) + '</td>';
							html += '<td>' + escHtml(entry.duration) + 's</td>';
							html += '<td>';
							if (entry.errors) {
								try {
									var errs = JSON.parse(entry.errors);
									html += escHtml(errs.join('; '));
								} catch (e) {
									html += escHtml(entry.errors);
								}
							} else {
								html += '—';
							}
							html += '</td></tr>';
						});

						html += '</tbody></table>';
						$table.html(html).show();
					} else if (response.success) {
						$table.html('<p>No deploy history found.</p>').show();
					} else {
						$table.html('<p class="error">Error loading history.</p>').show();
					}
				},
				error: function () {
					$btn.prop('disabled', false).text( __( 'Load History', 'wp-github-backup' ) );
					$table.html('<p class="error">Failed to load history.</p>').show();
				}
			});
		});
	});

	/**
	 * Format bytes to human-readable size.
	 */
	function formatSize(bytes) {
		if (bytes === 0) return '0 B';
		var sizes = ['B', 'KB', 'MB', 'GB'];
		var i = Math.floor(Math.log(bytes) / Math.log(1024));
		return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i];
	}

	/**
	 * Escape HTML entities.
	 */
	function escHtml(str) {
		if (!str) return '';
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	/* ─── AI Assistant Tab ────────────────────────────────── */

	// Save API key.
	$('#wgb-ai-save-key-btn').on('click', function () {
		var key = $('#wgb-ai-api-key').val().trim();
		if (!key) {
			$('#wgb-ai-key-status').text( __( 'Please enter an API key.', 'wp-github-backup' ) ).css('color', 'red');
			return;
		}

		var $btn = $(this);
		$btn.prop('disabled', true).text( __( 'Saving…', 'wp-github-backup' ) );

		$.post(wgbAdmin.ajaxUrl, {
			action: 'wgb_ai_save_key',
			nonce: wgbAdmin.nonce,
			api_key: key
		}).done(function (res) {
			$btn.prop('disabled', false).text( __( 'Save Key', 'wp-github-backup' ) );
			if (res.success) {
				$('#wgb-ai-key-status').text( __( 'Key saved!', 'wp-github-backup' ) ).css('color', '#46b450');
				$('#wgb-ai-analyze-btn, #wgb-ai-seo-btn').prop('disabled', false);
				$('#wgb-ai-api-key').val('').attr('placeholder', '••••••••••••••');
			} else {
				$('#wgb-ai-key-status').text('Error: ' + (res.data || 'Unknown')).css('color', 'red');
			}
		});
	});

	// Analyze content.
	$('#wgb-ai-analyze-btn').on('click', function () {
		var postId = $('#wgb-ai-post-select').val();
		if (!postId) {
			$('#wgb-ai-status').text( __( 'Select a page first.', 'wp-github-backup' ) ).css('color', 'red');
			return;
		}

		var $btn = $(this);
		$btn.prop('disabled', true).text( __( 'Analyzing…', 'wp-github-backup' ) );
		$('#wgb-ai-status').text( __( 'Claude is analyzing your content…', 'wp-github-backup' ) ).css('color', '#2271b1');
		$('#wgb-ai-results').html('');

		$.post(wgbAdmin.ajaxUrl, {
			action: 'wgb_ai_analyze_content',
			nonce: wgbAdmin.nonce,
			post_id: postId
		}).done(function (res) {
			$btn.prop('disabled', false).text( __( 'Analyze Content', 'wp-github-backup' ) );
			if (res.success) {
				$('#wgb-ai-status').text( __( 'Analysis complete!', 'wp-github-backup' ) ).css('color', '#46b450');
				var d = res.data;
				var html = '<div style="background:#f0f6fc;border:1px solid #2271b1;border-radius:6px;padding:16px 20px;margin-top:12px;">';
				html += '<h3 style="margin-top:0;">Content Analysis</h3>';
				html += '<p><strong>Deploy Ready:</strong> ' + (d.ready ? '<span style="color:#46b450;">Yes</span>' : '<span style="color:red;">No</span>') + '</p>';
				html += '<p><strong>SEO Score:</strong> ' + (d.seo_score || 'N/A') + '/10</p>';
				html += '<p><strong>Word Count:</strong> ~' + (d.word_count || 'N/A') + '</p>';

				if (d.issues && d.issues.length) {
					html += '<h4>Issues</h4><ul>';
					$.each(d.issues, function (i, issue) {
						html += '<li style="color:red;">' + escHtml(issue) + '</li>';
					});
					html += '</ul>';
				}

				if (d.suggestions && d.suggestions.length) {
					html += '<h4>Suggestions</h4><ul>';
					$.each(d.suggestions, function (i, s) {
						html += '<li>' + escHtml(s) + '</li>';
					});
					html += '</ul>';
				}

				html += '</div>';
				$('#wgb-ai-results').html(html);
			} else {
				$('#wgb-ai-status').text('Error: ' + (res.data || 'Unknown')).css('color', 'red');
			}
		});
	});

	// Generate SEO meta.
	$('#wgb-ai-seo-btn').on('click', function () {
		var postId = $('#wgb-ai-post-select').val();
		if (!postId) {
			$('#wgb-ai-status').text( __( 'Select a page first.', 'wp-github-backup' ) ).css('color', 'red');
			return;
		}

		var $btn = $(this);
		$btn.prop('disabled', true).text( __( 'Generating…', 'wp-github-backup' ) );
		$('#wgb-ai-status').text( __( 'Generating SEO metadata…', 'wp-github-backup' ) ).css('color', '#2271b1');
		$('#wgb-ai-results').html('');

		$.post(wgbAdmin.ajaxUrl, {
			action: 'wgb_ai_generate_seo',
			nonce: wgbAdmin.nonce,
			post_id: postId
		}).done(function (res) {
			$btn.prop('disabled', false).text( __( 'Generate SEO Meta', 'wp-github-backup' ) );
			if (res.success) {
				$('#wgb-ai-status').text( __( 'SEO meta generated!', 'wp-github-backup' ) ).css('color', '#46b450');
				var d = res.data;
				var html = '<div style="background:#f0f6fc;border:1px solid #2271b1;border-radius:6px;padding:16px 20px;margin-top:12px;">';
				html += '<h3 style="margin-top:0;">Generated SEO Metadata</h3>';
				html += '<table class="form-table">';
				html += '<tr><th>SEO Title</th><td><code>' + escHtml(d.seo_title || '') + '</code> (' + (d.seo_title || '').length + ' chars)</td></tr>';
				html += '<tr><th>Meta Description</th><td><code>' + escHtml(d.meta_description || '') + '</code> (' + (d.meta_description || '').length + ' chars)</td></tr>';
				html += '<tr><th>Focus Keyword</th><td><code>' + escHtml(d.focus_keyword || '') + '</code></td></tr>';
				html += '</table>';
				html += '<p class="description">Copy these values into your SEO plugin (Yoast/Rank Math) or use WP Claude Manager to apply them automatically.</p>';
				html += '</div>';
				$('#wgb-ai-results').html(html);
			} else {
				$('#wgb-ai-status').text('Error: ' + (res.data || 'Unknown')).css('color', 'red');
			}
		});
	});

})(jQuery);
