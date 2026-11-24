<?php
/**
 * Lead forms: built-in forms (saved in their own table) and external forms
 * (links, embedded pages, WordPress form shortcodes and embed code).
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Chatbot_Form_Builder {

    private $option_name = 'wp_ai_chatbot_multi_forms';

    public function register_hooks() {
        add_action( 'admin_menu', array( $this, 'add_form_builder_submenu' ) );
        add_action( 'admin_init', array( $this, 'register_form_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_form_assets' ) );
    }

    public function add_form_builder_submenu() {
        add_submenu_page( 'wp-ai-chatbot', 'Lead Forms', 'Lead Forms', 'manage_options', 'wp-ai-chatbot-form', array( $this, 'display_page' ) );
    }

    public function register_form_settings() {
        register_setting( 'wp_ai_chatbot_form_group', $this->option_name, array( $this, 'sanitize_forms' ) );
    }

    public function sanitize_forms( $input ) {
        $clean    = array();
        $previous = array();
        foreach ( (array) get_option( $this->option_name, array() ) as $old ) {
            if ( isset( $old['id'] ) ) {
                $previous[ $old['id'] ] = $old;
            }
        }
        if ( ! is_array( $input ) ) {
            return $clean;
        }
        foreach ( $input as $form ) {
            if ( ! is_array( $form ) || empty( $form['title'] ) || empty( $form['intent'] ) ) {
                continue;
            }
            $form_id = ( isset( $form['id'] ) && preg_match( '/^[a-z0-9_]{1,40}$/', (string) $form['id'] ) ) ? $form['id'] : 'form_' . substr( md5( wp_rand() . microtime() ), 0, 8 );
            $source  = ( isset( $form['source'] ) && in_array( $form['source'], WP_AI_Chatbot_External_Forms::SOURCES, true ) ) ? $form['source'] : 'builtin';

            $clean_form = array(
                'id'         => $form_id,
                'is_enabled' => ( isset( $form['is_enabled'] ) && '1' === $form['is_enabled'] ) ? '1' : '0',
                'title'      => sanitize_text_field( $form['title'] ),
                'intent'     => sanitize_text_field( $form['intent'] ),
                'source'     => $source,
                'fields'     => array(),
                'ext_url'    => '',
                'ext_button' => isset( $form['ext_button'] ) ? sanitize_text_field( $form['ext_button'] ) : '',
                'ext_note'   => isset( $form['ext_note'] ) ? sanitize_text_field( $form['ext_note'] ) : '',
                'ext_height' => isset( $form['ext_height'] ) ? max( 200, min( 900, absint( $form['ext_height'] ) ) ) : 520,
                'ext_code'   => '',
            );

            // Every type's settings are kept (not only the chosen one), so switching the type and
            // switching back never loses the fields, link or code entered before. Only the chosen
            // type is used in the chat.
            foreach ( (array) ( isset( $form['fields'] ) ? $form['fields'] : array() ) as $field ) {
                if ( empty( $field['label'] ) ) {
                    continue;
                }
                $type                   = isset( $field['type'] ) ? $field['type'] : 'text';
                $clean_form['fields'][] = array(
                    'label'       => sanitize_text_field( $field['label'] ),
                    'type'        => in_array( $type, array( 'text', 'email', 'tel', 'textarea', 'select', 'multiselect' ), true ) ? $type : 'text',
                    'placeholder' => isset( $field['placeholder'] ) ? sanitize_text_field( $field['placeholder'] ) : '',
                    'options'     => isset( $field['options'] ) ? sanitize_text_field( $field['options'] ) : '',
                    'required'    => isset( $field['required'] ) ? 'yes' : 'no',
                );
            }
            if ( 'builtin' === $source ) {
                if ( empty( $clean_form['fields'] ) ) {
                    add_settings_error( $this->option_name, 'no_fields_' . $form_id, sprintf( '"%s" has no fields yet. Add at least one field.', $clean_form['title'] ), 'error' );
                }
                $this->sync_form_table( $clean_form );
            }

            $url = isset( $form['ext_url'] ) ? esc_url_raw( trim( (string) $form['ext_url'] ), array( 'http', 'https' ) ) : '';
            if ( '' === $url && in_array( $source, array( 'link', 'directlink', 'embed' ), true ) ) {
                add_settings_error( $this->option_name, 'missing_url_' . $form_id, sprintf( '"%s" needs a valid link (https://...).', $clean_form['title'] ), 'error' );
            }
            $clean_form['ext_url'] = $url;

            $code = isset( $form['ext_code'] ) ? (string) $form['ext_code'] : '';
            if ( 'shortcode' === $source ) {
                $code = sanitize_textarea_field( $code );
            } elseif ( ! current_user_can( 'unfiltered_html' ) ) {
                // Only administrators who may post raw HTML can save scripts.
                $code = isset( $previous[ $form_id ]['ext_code'] ) && $code === $previous[ $form_id ]['ext_code'] ? $code : wp_kses_post( $code );
            }
            $clean_form['ext_code'] = trim( $code );

            $clean[] = $clean_form;
        }
        return $clean;
    }

    private function sync_form_table( $form ) {
        self::repair_table( $form );
    }

    /**
     * Makes sure a built-in form's leads table has a column for every field, and that
     * long-answer fields (Long text, Checkboxes) are TEXT columns. A field whose type was
     * changed to Long text after the table was made used to stay VARCHAR(500), so longer
     * messages were refused. Safe to run any time; never removes data.
     */
    public static function repair_table( $form ) {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'ai_leads_' . sanitize_key( $form['id'] );
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id)
        ) {$charset_collate};";
        dbDelta( $sql );

        $existing_cols = $wpdb->get_col( "DESCRIBE {$table_name}", 0 );

        $types = array();
        foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM `{$table_name}`", ARRAY_A ) as $c ) {
            if ( isset( $c['Field'], $c['Type'] ) ) {
                $types[ $c['Field'] ] = strtolower( (string) $c['Type'] );
            }
        }

        foreach ( (array) ( isset( $form['fields'] ) ? $form['fields'] : array() ) as $field ) {
            if ( empty( $field['label'] ) ) continue;
            $col = sanitize_key( str_replace( ' ', '_', strtolower( $field['label'] ) ) );
            if ( empty( $col ) ) continue;
            $long = in_array( isset( $field['type'] ) ? $field['type'] : 'text', array( 'multiselect', 'textarea' ), true );

            if ( ! in_array( $col, (array) $existing_cols, true ) ) {
                $col_type = $long ? 'TEXT' : 'VARCHAR(500)';
                $wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN `{$col}` {$col_type} DEFAULT NULL" );
            } elseif ( $long && isset( $types[ $col ] ) && 0 === strpos( $types[ $col ], 'varchar' ) ) {
                $wpdb->query( "ALTER TABLE {$table_name} MODIFY COLUMN `{$col}` TEXT DEFAULT NULL" );
            }
        }
    }

    public function enqueue_form_assets( $hook ) {
        if ( false === strpos( (string) $hook, 'wp-ai-chatbot-form' ) ) {
            return;
        }
        wp_enqueue_script( 'akdk-form-builder', WP_AI_CHATBOT_URL . 'admin/js/form-builder.js', array( 'jquery' ), WP_AI_CHATBOT_VERSION, true );
    }

    private function field_row( $f_idx, $fld_idx, $field ) {
        $name  = 'wp_ai_chatbot_multi_forms[' . $f_idx . '][fields][' . $fld_idx . ']';
        $type  = isset( $field['type'] ) ? $field['type'] : 'text';
        $types = array( 'text' => 'Short text', 'email' => 'Email address', 'tel' => 'Phone number', 'textarea' => 'Long text (message)', 'select' => 'Dropdown (pick one)', 'multiselect' => 'Checkboxes (pick several)' );
        ob_start();
        ?>
        <tr>
            <td><input type="text" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( isset( $field['label'] ) ? $field['label'] : '' ); ?>" placeholder="e.g. Your name" class="akdk-w-full"></td>
            <td><select class="ai-field-type-select akdk-w-full" name="<?php echo esc_attr( $name ); ?>[type]">
                <?php foreach ( $types as $value => $label ) : ?>
                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select></td>
            <td><input type="text" class="akdk-w-full" name="<?php echo esc_attr( $name ); ?>[placeholder]" value="<?php echo esc_attr( isset( $field['placeholder'] ) ? $field['placeholder'] : '' ); ?>" placeholder="Optional"></td>
            <td><input type="text" class="ai-field-options akdk-w-full" name="<?php echo esc_attr( $name ); ?>[options]" value="<?php echo esc_attr( isset( $field['options'] ) ? $field['options'] : '' ); ?>" placeholder="Option 1, Option 2" <?php echo in_array( $type, array( 'select', 'multiselect' ), true ) ? '' : 'hidden'; ?>></td>
            <td class="akdk-center"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[required]" value="yes" <?php checked( ! isset( $field['required'] ) || 'yes' === $field['required'] ); ?>></td>
            <td class="akdk-center"><button type="button" class="button remove-ai-field" aria-label="Remove field">&times;</button></td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /** One form card. $idx may be "__i__" for the new-form template. */
    private function form_block( $idx, $form ) {
        $base    = 'wp_ai_chatbot_multi_forms[' . $idx . ']';
        $source  = WP_AI_Chatbot_External_Forms::source_of( $form );
        $id      = isset( $form['id'] ) ? $form['id'] : '';
        $enabled = ! isset( $form['is_enabled'] ) || '1' === $form['is_enabled'];
        $height  = ! empty( $form['ext_height'] ) ? (int) $form['ext_height'] : 520;
        ob_start();
        ?>
        <div class="akdk-form-card wp-ai-form-block" data-source="<?php echo esc_attr( $source ); ?>">
            <div class="akdk-form-card-head">
                <button type="button" class="akdk-collapse-toggle" aria-expanded="true" title="Show / hide this form's settings"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                <input type="hidden" name="<?php echo esc_attr( $base ); ?>[id]" value="<?php echo esc_attr( $id ); ?>">
                <input type="text" class="akdk-form-title" name="<?php echo esc_attr( $base ); ?>[title]" value="<?php echo esc_attr( isset( $form['title'] ) ? $form['title'] : '' ); ?>" placeholder="Form name, e.g. Book a call" required>
                <span class="akdk-form-summary"></span>
                <label class="akdk-inline-check"><input type="hidden" name="<?php echo esc_attr( $base ); ?>[is_enabled]" value="0"><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[is_enabled]" value="1" <?php checked( $enabled ); ?>> Enabled</label>
                <button type="button" class="button akdk-button-ghost remove-ai-form">Delete</button>
            </div>

            <div class="akdk-form-card-body">
                <div class="akdk-form-type<?php echo $id ? ' is-locked' : ''; ?>">
                    <span class="akdk-label">What should the chatbot show?</span>
                    <?php if ( $id ) : ?>
                        <div class="akdk-type-current">
                            <span class="akdk-type-badge"></span>
                            <button type="button" class="button-link akdk-change-type">Change type</button>
                        </div>
                    <?php endif; ?>
                    <p class="akdk-type-note" hidden>Changing the type keeps what you entered for the other types, so you can switch back. Only the selected type is shown in the chat.</p>
                    <div class="akdk-type-options" role="radiogroup">
                        <?php
                        $types = array(
                            'builtin'   => array( 'Built-in form', 'Fields you add here. Leads are saved in WordPress.' ),
                            'link'      => array( 'Link / checkout', 'Button that opens a page: checkout, Calendly, WhatsApp, payment link.' ),
                            'directlink'=> array( 'Direct Link', 'Quick Action link only. Opens your URL in the same tab without adding messages to the chat.' ),
                            'embed'     => array( 'Embed a page', 'Show Google Forms, Typeform, Calendly, JotForm etc. inside the chat.' ),
                            'shortcode' => array( 'WordPress form', 'Shortcode from Contact Form 7, WPForms, Fluent Forms, Gravity Forms, WooCommerce.' ),
                            'html'      => array( 'Embed code', 'Paste HTML/script from HubSpot, Mailchimp, Zoho, Brevo and others.' ),
                        );
                        foreach ( $types as $value => $text ) :
                            ?>
                            <label class="akdk-type-option">
                                <input type="radio" class="akdk-source-radio" name="<?php echo esc_attr( $base ); ?>[source]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $source, $value ); ?>>
                                <span><strong><?php echo esc_html( $text[0] ); ?></strong><small><?php echo esc_html( $text[1] ); ?></small></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <label class="akdk-field">
                    <span>When should the chatbot show it?</span>
                    <input type="text" name="<?php echo esc_attr( $base ); ?>[intent]" value="<?php echo esc_attr( isset( $form['intent'] ) ? $form['intent'] : '' ); ?>" placeholder="e.g. The visitor wants a price quote, a meeting or to contact us" required>
                    <small>Describe the situation in a few words. The chatbot shows this form when the conversation is about it. A Quick Action button can also open it directly. <?php if ( $id ) : ?>Form code: <code>[SHOW_FORM_<?php echo esc_html( $id ); ?>]</code><?php endif; ?></small>
                </label>

                <div class="akdk-source-panel" data-for="builtin">
                    <details class="akdk-help">
                        <summary><strong>How it works</strong> (where leads go, when the visitor gets an email)</summary>
                        The visitor fills these boxes inside the chat. The answers are saved under <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ai-chatbot-leads' ) ); ?>">View Leads</a>, and you get an email right away (<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ai-chatbot-email' ) ); ?>">Lead Alerts &amp; Email</a>).
                        The chatbot does not know the visitor's email by itself: it only has it if the form has a box with the answer type <strong>Email address</strong>. A thank-you email (if turned on) goes to that address.
                    </details>
                    <p class="akdk-email-warning" hidden>⚠️ This form has no <strong>Email address</strong> box, so no thank-you email can be sent to the visitor. You will still get the lead alert.</p>
                    <table class="widefat akdk-fields-table wp-ai-fields-table">
                        <thead><tr><th>Question (label)</th><th style="width:190px">Answer type</th><th>Hint inside the box</th><th>Choices <small>(dropdown/checkboxes)</small></th><th style="width:70px" class="akdk-center">Required</th><th style="width:50px"></th></tr></thead>
                        <tbody>
                            <?php
                            foreach ( ( isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array() ) as $fld_idx => $field ) {
                                echo $this->field_row( $idx, $fld_idx, $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in field_row().
                            }
                            ?>
                        </tbody>
                    </table>
                    <button type="button" class="button add-ai-field" data-form-index="<?php echo esc_attr( $idx ); ?>">+ Add field</button>
                </div>

                <div class="akdk-source-panel akdk-grid" data-for="link directlink embed">
                    <label class="akdk-field akdk-field-wide">
                        <span>Link (URL)</span>
                        <input type="url" name="<?php echo esc_attr( $base ); ?>[ext_url]" value="<?php echo esc_attr( isset( $form['ext_url'] ) ? $form['ext_url'] : '' ); ?>" placeholder="https://calendly.com/your-name/30min">
                        <small class="akdk-only" data-for="embed">The page must allow embedding. Google Forms: use the "&lt;&gt; Embed" link. If a page refuses, use "Link / checkout" instead.</small>
                    </label>
                    <label class="akdk-field">
                        <span>Button text</span>
                        <input type="text" name="<?php echo esc_attr( $base ); ?>[ext_button]" value="<?php echo esc_attr( isset( $form['ext_button'] ) ? $form['ext_button'] : '' ); ?>" placeholder="e.g. Book a free call">
                    </label>
                </div>

                <div class="akdk-source-panel akdk-grid" data-for="shortcode html">
                    <label class="akdk-field akdk-field-wide">
                        <span class="akdk-only" data-for="shortcode">Form shortcode</span>
                        <span class="akdk-only" data-for="html">Embed code</span>
                        <textarea name="<?php echo esc_attr( $base ); ?>[ext_code]" rows="4" class="code" placeholder="<?php echo esc_attr( '[contact-form-7 id="123"]  or  <script src="..."></script>' ); ?>"><?php echo esc_textarea( isset( $form['ext_code'] ) ? $form['ext_code'] : '' ); ?></textarea>
                        <small class="akdk-only" data-for="shortcode">Copy it from your form plugin. Submissions are saved by that plugin and counted on the Dashboard.</small>
                        <small class="akdk-only" data-for="html">Paste the embed code your form provider gives you. It runs on a private page of your site inside the chat.</small>
                    </label>
                </div>

                <div class="akdk-source-panel akdk-grid" data-for="link directlink embed shortcode html">
                    <label class="akdk-field akdk-field-wide">
                        <span>Short message above it (optional)</span>
                        <input type="text" name="<?php echo esc_attr( $base ); ?>[ext_note]" value="<?php echo esc_attr( isset( $form['ext_note'] ) ? $form['ext_note'] : '' ); ?>" placeholder="e.g. Pick a time that suits you, or fill this in and we will call you">
                    </label>
                </div>

                <div class="akdk-source-panel" data-for="link directlink embed shortcode html">
                    <details class="akdk-verify">
                        <summary>Verified submissions (recommended)</summary>
                        <p class="akdk-only" data-for="shortcode">Submissions of Contact Form 7, WPForms, Fluent Forms, Gravity Forms, Ninja Forms, Forminator and Elementor forms made inside the chat are confirmed automatically by your website. Nothing to set up.</p>
                        <p>The chatbot only counts a lead as <strong>verified</strong> when the form service confirms it. Without this, completions are shown as "reported" (from the visitor's browser) and links only as "opened".</p>
                        <?php if ( $id ) : ?>
                            <?php $hook = WP_AI_Chatbot_External_Forms::webhook_url( $id ); ?>
                            <label class="akdk-field akdk-field-wide"><span>Webhook link for this form</span>
                                <input type="text" readonly value="<?php echo esc_attr( $hook ); ?>" onclick="this.select()">
                                <small>Send a POST request here when someone completes the form (from n8n, Zapier, Make, Typeform, JotForm, Tally, a payment provider...). Any JSON fields you send, such as name and email, are saved with the lead. Send a unique <code>submission_id</code> (or an <code>X-AKDK-Submission-Id</code> header) so retries are never counted twice. Keep this link private.</small>
                            </label>
                            <p><strong>Google Forms:</strong> open the form, click <em>⋮ → Apps Script</em>, paste the code below and save. Then open <em>Triggers → Add trigger</em>, choose <em>onFormSubmit</em>, event source <em>From form</em>, event type <em>On form submit</em>.</p>
                            <textarea readonly rows="8" class="code akdk-w-full" onclick="this.select()">function onFormSubmit(e) {
  var answers = { submission_id: e.response.getId() };
  e.response.getItemResponses().forEach(function (r) {
    answers[r.getItem().getTitle()] = r.getResponse();
  });
  UrlFetchApp.fetch('<?php echo esc_textarea( $hook ); ?>', {
    method: 'post', contentType: 'application/json',
    payload: JSON.stringify(answers), muteHttpExceptions: true
  });
}</textarea>
                        <?php else : ?>
                            <p><em>Save the form first to get its webhook link.</em></p>
                        <?php endif; ?>
                    </details>
                </div>

                <div class="akdk-source-panel akdk-grid" data-for="embed shortcode html">
                    <label class="akdk-field">
                        <span>Height inside the chat</span>
                        <span class="akdk-input-unit"><input type="number" min="200" max="900" name="<?php echo esc_attr( $base ); ?>[ext_height]" value="<?php echo esc_attr( $height ); ?>"><em>px</em></span>
                        <small>WordPress forms and embed code resize automatically.</small>
                    </label>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function display_page() {
        $forms = get_option( $this->option_name, array() );
        $forms = is_array( $forms ) ? $forms : array();
        ?>
        <div class="wrap akdk-wrap">
            <h1 class="akdk-page-title">Lead Forms</h1>
            <p class="akdk-page-intro">Forms the chatbot can show to turn a conversation into a lead. Build one here, or connect a form, booking page or checkout you already use.</p>
            <?php settings_errors(); ?>

            <form method="post" action="options.php" class="akdk-forms-page">
                <?php settings_fields( 'wp_ai_chatbot_form_group' ); ?>
                <?php if ( count( $forms ) > 1 ) : ?>
                    <p class="akdk-collapse-all"><button type="button" class="button-link" data-collapse="0">Expand all</button> &middot; <button type="button" class="button-link" data-collapse="1">Collapse all</button></p>
                <?php endif; ?>
                <div id="wp-ai-forms-container">
                    <?php
                    foreach ( array_values( $forms ) as $f_idx => $form ) {
                        echo $this->form_block( $f_idx, $form ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in form_block().
                    }
                    ?>
                </div>
                <?php if ( empty( $forms ) ) : ?>
                    <div class="akdk-card akdk-empty-forms"><p><strong>No forms yet.</strong> Add a built-in form, or connect Calendly, Google Forms, Contact Form 7, WPForms, a checkout link and more.</p></div>
                <?php endif; ?>
                <p><button type="button" class="button" id="add-ai-multi-form">+ Add form</button></p>
                <div class="akdk-savebar"><?php submit_button( 'Save forms', 'primary', 'submit', false ); ?></div>
            </form>

            <script type="text/template" id="akdk-form-template"><?php echo $this->form_block( '__i__', array( 'source' => 'builtin', 'fields' => array( array( 'label' => 'Name', 'type' => 'text', 'placeholder' => 'Your full name', 'required' => 'yes' ), array( 'label' => 'Email', 'type' => 'email', 'placeholder' => 'you@example.com', 'required' => 'yes' ), array( 'label' => 'Phone', 'type' => 'tel', 'placeholder' => 'e.g. 0300 1234567', 'required' => 'no' ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
            <script type="text/template" id="akdk-field-template"><?php echo $this->field_row( '__i__', '__f__', array( 'type' => 'text', 'required' => 'yes' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
        </div>
        <?php
    }
}
