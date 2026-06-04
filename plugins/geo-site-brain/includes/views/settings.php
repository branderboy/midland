<?php
/**
 * Settings: OpenAI key + model, Neon connection, scan options, business context.
 * Secrets are shown only as "set / not set"; their values are never printed.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$o            = GSB_Settings::OPTION_PREFIX;
$openai_set   = GSB_Settings::has_openai();
$neon_set     = '' !== trim( (string) GSB_Settings::get( 'neon_dsn' ) );
$pgsql        = GSB_Vector_Store::pgsql_available();
$all_types    = get_post_types( array( 'public' => true ), 'objects' );
$selected     = GSB_Settings::indexed_post_types();
?>
<div class="wrap gsb-wrap">
	<h1><?php esc_html_e( 'Setup', 'geo-site-brain' ); ?></h1>
	<p class="gsb-sub"><?php esc_html_e( 'The only thing you need to get started is an OpenAI key. Everything else is optional.', 'geo-site-brain' ); ?></p>

	<form method="post" action="options.php">
		<?php settings_fields( GSB_Admin::GROUP ); ?>

		<h2><?php esc_html_e( 'OpenAI', 'geo-site-brain' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="gsb_openai"><?php esc_html_e( 'API key', 'geo-site-brain' ); ?></label></th>
				<td>
					<input type="password" id="gsb_openai" name="<?php echo esc_attr( $o . 'openai_api_key' ); ?>" value="" autocomplete="new-password"
						class="regular-text" placeholder="<?php echo $openai_set ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'geo-site-brain' ) : esc_attr__( 'sk-…', 'geo-site-brain' ); ?>" />
					<button type="button" class="button gsb-test" data-test="openai"><?php esc_html_e( 'Test connection', 'geo-site-brain' ); ?></button>
					<span class="gsb-test-result" data-for="openai"></span>
					<p class="description"><?php printf( esc_html__( 'Used for embeddings (%s) and the chat agent. Stored privately; never shown again.', 'geo-site-brain' ), esc_html( GSB_EMBED_MODEL ) ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gsb_model"><?php esc_html_e( 'Chat model', 'geo-site-brain' ); ?></label></th>
				<td><input type="text" id="gsb_model" name="<?php echo esc_attr( $o . 'chat_model' ); ?>" value="<?php echo esc_attr( GSB_Settings::get( 'chat_model', 'gpt-4o-mini' ) ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Model used by the Agent Chat (e.g. gpt-4o-mini, gpt-4o).', 'geo-site-brain' ); ?></p></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'AI engines for live probing', 'geo-site-brain' ); ?> <span class="gsb-count"><?php esc_html_e( 'optional', 'geo-site-brain' ); ?></span></h2>
		<p class="description"><?php esc_html_e( 'Add a key for any engine to probe the real model on the AI Visibility screen and see exactly how it describes your business. Without these, that screen shows estimates. (ChatGPT uses your OpenAI key above.)', 'geo-site-brain' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="gsb_anthropic"><?php esc_html_e( 'Anthropic (Claude) key', 'geo-site-brain' ); ?></label></th>
				<td><input type="password" id="gsb_anthropic" name="<?php echo esc_attr( $o . 'anthropic_api_key' ); ?>" value="" autocomplete="new-password" class="regular-text"
					placeholder="<?php echo '' !== trim( (string) GSB_Settings::get( 'anthropic_api_key' ) ) ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'geo-site-brain' ) : 'sk-ant-…'; ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="gsb_gemini"><?php esc_html_e( 'Google Gemini key', 'geo-site-brain' ); ?></label></th>
				<td><input type="password" id="gsb_gemini" name="<?php echo esc_attr( $o . 'gemini_api_key' ); ?>" value="" autocomplete="new-password" class="regular-text"
					placeholder="<?php echo '' !== trim( (string) GSB_Settings::get( 'gemini_api_key' ) ) ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'geo-site-brain' ) : 'AIza…'; ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="gsb_perplexity"><?php esc_html_e( 'Perplexity key', 'geo-site-brain' ); ?></label></th>
				<td><input type="password" id="gsb_perplexity" name="<?php echo esc_attr( $o . 'perplexity_api_key' ); ?>" value="" autocomplete="new-password" class="regular-text"
					placeholder="<?php echo '' !== trim( (string) GSB_Settings::get( 'perplexity_api_key' ) ) ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'geo-site-brain' ) : 'pplx-…'; ?>" />
					<p class="description"><?php esc_html_e( 'Perplexity is web-grounded, so it best reflects real AI-search visibility.', 'geo-site-brain' ); ?></p></td>
			</tr>
		</table>

		<details class="gsb-advanced">
		<summary><?php esc_html_e( 'Advanced — storage & indexing (optional)', 'geo-site-brain' ); ?></summary>

		<h2><?php esc_html_e( 'Vector storage', 'geo-site-brain' ); ?> <span class="gsb-count"><?php esc_html_e( 'optional', 'geo-site-brain' ); ?></span></h2>
		<p class="description">
			<strong><?php esc_html_e( 'By default, embeddings are stored locally in your WordPress database — no external service, no server extensions, nothing to install.', 'geo-site-brain' ); ?></strong>
			<?php esc_html_e( 'Neon (serverless Postgres + pgvector) is an optional upgrade for very large sites. If it is off or unreachable, GEO Site Brain uses the local store automatically, so the plugin always works.', 'geo-site-brain' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Use Neon', 'geo-site-brain' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $o . 'neon_enabled' ); ?>" value="1" <?php checked( 1, (int) GSB_Settings::get( 'neon_enabled' ) ); ?> />
						<?php esc_html_e( 'Store and search vectors in Neon', 'geo-site-brain' ); ?></label>
					<?php if ( ! $pgsql ) : ?>
						<p class="description gsb-bad"><?php esc_html_e( 'This server is missing the PDO pgsql driver, so Neon can\'t be reached. Embeddings will be stored locally until it is installed.', 'geo-site-brain' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gsb_neon"><?php esc_html_e( 'Connection string', 'geo-site-brain' ); ?></label></th>
				<td>
					<input type="password" id="gsb_neon" name="<?php echo esc_attr( $o . 'neon_dsn' ); ?>" value="" autocomplete="new-password"
						class="large-text" placeholder="<?php echo $neon_set ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'geo-site-brain' ) : 'postgresql://user:password@ep-xxx.neon.tech/dbname?sslmode=require'; ?>" />
					<button type="button" class="button gsb-test" data-test="neon"><?php esc_html_e( 'Test connection', 'geo-site-brain' ); ?></button>
					<span class="gsb-test-result" data-for="neon"></span>
					<p class="description"><?php esc_html_e( 'Paste the Neon connection string. The pgvector extension, table and index are created automatically on first use.', 'geo-site-brain' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Indexing', 'geo-site-brain' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Post types to index', 'geo-site-brain' ); ?></th>
				<td>
					<?php foreach ( $all_types as $type ) : ?>
						<label style="display:inline-block;margin:0 14px 6px 0;">
							<input type="checkbox" name="<?php echo esc_attr( $o . 'post_types' ); ?>[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $selected, true ) ); ?> />
							<?php echo esc_html( $type->labels->name ); ?> <code><?php echo esc_html( $type->name ); ?></code>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gsb_chunk"><?php esc_html_e( 'Max chunk size (chars)', 'geo-site-brain' ); ?></label></th>
				<td><input type="number" id="gsb_chunk" name="<?php echo esc_attr( $o . 'chunk_max_chars' ); ?>" value="<?php echo esc_attr( (int) GSB_Settings::get( 'chunk_max_chars', 1500 ) ); ?>" min="300" max="6000" class="small-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="gsb_batch"><?php esc_html_e( 'Embedding batch size', 'geo-site-brain' ); ?></label></th>
				<td><input type="number" id="gsb_batch" name="<?php echo esc_attr( $o . 'embed_batch' ); ?>" value="<?php echo esc_attr( (int) GSB_Settings::get( 'embed_batch', 64 ) ); ?>" min="1" max="256" class="small-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="gsb_k"><?php esc_html_e( 'Retrieval results (k)', 'geo-site-brain' ); ?></label></th>
				<td><input type="number" id="gsb_k" name="<?php echo esc_attr( $o . 'retrieval_k' ); ?>" value="<?php echo esc_attr( (int) GSB_Settings::get( 'retrieval_k', 8 ) ); ?>" min="1" max="50" class="small-text" />
					<p class="description"><?php esc_html_e( 'How many chunks the agent retrieves per question.', 'geo-site-brain' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Weekly reindex', 'geo-site-brain' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $o . 'weekly_reindex' ); ?>" value="1" <?php checked( 1, (int) GSB_Settings::get( 'weekly_reindex', 1 ) ); ?> /> <?php esc_html_e( 'Re-scan and re-embed the whole site once a week', 'geo-site-brain' ); ?></label></td>
			</tr>
		</table>

		</details>

		<h2><?php esc_html_e( 'Business context', 'geo-site-brain' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Tells the engine who you are. Used to map your services, service areas and AI visibility.', 'geo-site-brain' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="gsb_biz"><?php esc_html_e( 'Business name', 'geo-site-brain' ); ?></label></th>
				<td><input type="text" id="gsb_biz" name="<?php echo esc_attr( $o . 'business_name' ); ?>" value="<?php echo esc_attr( GSB_Settings::get( 'business_name' ) ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="gsb_svc"><?php esc_html_e( 'Core services', 'geo-site-brain' ); ?></label></th>
				<td><textarea id="gsb_svc" name="<?php echo esc_attr( $o . 'core_services' ); ?>" rows="5" class="large-text" placeholder="<?php esc_attr_e( "One per line, e.g.\nCommercial carpet cleaning\nTile & grout cleaning\nConcrete polishing", 'geo-site-brain' ); ?>"><?php echo esc_textarea( GSB_Settings::get( 'core_services' ) ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="gsb_loc"><?php esc_html_e( 'Service locations', 'geo-site-brain' ); ?></label></th>
				<td><textarea id="gsb_loc" name="<?php echo esc_attr( $o . 'business_locations' ); ?>" rows="5" class="large-text" placeholder="<?php esc_attr_e( "One per line, e.g.\nWashington DC\nBethesda\nArlington", 'geo-site-brain' ); ?>"><?php echo esc_textarea( GSB_Settings::get( 'business_locations' ) ); ?></textarea></td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
