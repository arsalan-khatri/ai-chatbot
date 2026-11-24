<?php
/**
 * Leads Manager - Flat Table Per Form
 * 
 * Logic:
 * - Table name = wp_ai_leads_{form_id}  (the form ID never changes)
 * - Renaming a form only changes the tab title; the table stays the same
 * - Deleting a form keeps its table; the tab turns red (orphaned) and offers a Delete Table button
 * - A new form gets a tab only once its table exists
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_AI_Chatbot_Leads_Manager {

    public function register_hooks() {
        add_action( 'admin_menu', array( $this, 'add_leads_submenu' ) );
        add_action( 'admin_init', array( $this, 'handle_csv_export' ) );
        add_action( 'admin_init', array( $this, 'handle_lead_admin_action' ) );
        add_action( 'admin_init', array( $this, 'handle_pdf_export' ) );
        add_action( 'admin_init', array( $this, 'handle_json_export' ) );
    }

    public function add_leads_submenu() {
        add_submenu_page(
            'wp-ai-chatbot', 'Chatbot Leads', 'View Leads',
            'manage_options', 'wp-ai-chatbot-leads',
            array( $this, 'display_leads_page' )
        );
    }

    /**
     * Fetch every wp_ai_leads_* table from the database.
     * For each table, check whether a matching form still exists.
     * Returns array of:
     *   [ table, form_id, form_title, is_orphan (bool) ]
     */
    private function get_all_lead_tables() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'ai_leads_';
        $tables = $wpdb->get_col( "SHOW TABLES LIKE '{$prefix}%'" );

        // form_id → title map (saved forms se)
        $saved_forms = get_option( 'wp_ai_chatbot_multi_forms', array() );
        $form_map    = array(); // form_id => title
        foreach ( $saved_forms as $f ) {
            $form_map[ sanitize_key( $f['id'] ) ] = $f['title'];
        }

        $result = array();
        foreach ( $tables as $tbl ) {
            $form_id   = str_replace( $prefix, '', $tbl );
            $is_orphan = ! isset( $form_map[ $form_id ] );
            // Orphan hone pe table slug se title banao (readable)
            $title = $is_orphan
                ? ucwords( str_replace( '_', ' ', $form_id ) ) . ' [Deleted Form]'
                : $form_map[ $form_id ];

            $result[] = array(
                'table'      => $tbl,
                'form_id'    => $form_id,
                'form_title' => $title,
                'is_orphan'  => $is_orphan,
            );
        }
        return $result;
    }

    // Delete a table (used for orphaned tabs).
    public function handle_delete_table() {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'wp-ai-chatbot-leads' ) return;
        if ( ! isset( $_GET['action'] ) || $_GET['action'] !== 'delete_table' ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'delete_lead_table' ) ) return;

        $form_id = isset( $_GET['form_tab'] ) ? sanitize_key( $_GET['form_tab'] ) : '';
        if ( empty( $form_id ) ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'ai_leads_' . $form_id;
        $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

        wp_redirect( admin_url( 'admin.php?page=wp-ai-chatbot-leads&deleted=1' ) );
        exit;
    }

    // JSON export: retains original database keys, values, IDs and timestamps.
    public function handle_json_export() {
        if ( ! isset( $_GET['page'], $_GET['action'] ) || 'wp-ai-chatbot-leads' !== $_GET['page'] || 'export_json' !== $_GET['action'] ) return;
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        $form_id = isset( $_GET['form_tab'] ) ? sanitize_key( wp_unslash( $_GET['form_tab'] ) ) : '';
        if ( ! $form_id ) wp_die( 'Invalid form.' );
        check_admin_referer( 'akdk_json_' . $form_id );

        // Resolve only from known lead tables, preventing arbitrary table access.
        $selected = null;
        foreach ( $this->get_all_lead_tables() as $info ) {
            if ( $info['form_id'] === $form_id ) { $selected = $info; break; }
        }
        if ( ! $selected ) wp_die( 'Lead table not found.' );

        global $wpdb;
        $rows = $wpdb->get_results( "SELECT * FROM `{$selected['table']}` ORDER BY created_at DESC", ARRAY_A );
        if ( $wpdb->last_error ) wp_die( 'Unable to export lead records.' );
        $payload = array(
            'form_id'      => $form_id,
            'form_title'   => $selected['form_title'],
            'exported_at'  => gmdate( 'c' ),
            'total'        => count( $rows ),
            'columns'      => $this->lead_columns( $selected['table'] ),
            'leads'        => $rows,
        );
        $json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
        if ( false === $json ) wp_die( 'Could not encode lead records.' );
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="leads-' . sanitize_file_name( sanitize_title( $selected['form_title'] ) ) . '-' . gmdate( 'Y-m-d' ) . '.json"' );
        header( 'X-Content-Type-Options: nosniff' );
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON export payload.
        exit;
    }

    // CSV Export
    public function handle_csv_export() {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'wp-ai-chatbot-leads' ) return;
        if ( ! isset( $_GET['action'] ) || $_GET['action'] !== 'export_csv' ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'export_leads_csv' ) ) return;

        $form_id = isset( $_GET['form_tab'] ) ? sanitize_key( $_GET['form_tab'] ) : '';
        if ( empty( $form_id ) ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'ai_leads_' . $form_id;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) return;

        $rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY created_at DESC", ARRAY_A );
        if ( empty( $rows ) ) {
            $columns = array_values( array_diff( $this->lead_columns( $table ), array( 'id', 'created_at' ) ) );
            $rows = array();
        }

        $skip_cols  = array( 'id', 'created_at' );
        $field_cols = $rows ? array_diff( array_keys( $rows[0] ), $skip_cols ) : $columns;

        // Title for filename
        $saved_forms = get_option( 'wp_ai_chatbot_multi_forms', array() );
        $file_title  = $form_id;
        foreach ( $saved_forms as $f ) {
            if ( sanitize_key( $f['id'] ) === $form_id ) { $file_title = $f['title']; break; }
        }

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="leads-' . sanitize_file_name( sanitize_title( $file_title ) ) . '-' . gmdate('Y-m-d') . '.csv"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );
        fprintf( $out, chr(0xEF).chr(0xBB).chr(0xBF) );

        fputcsv( $out, array_merge(
            array( 'ID', 'Date & Time' ),
            array_map( function($c){ return ucfirst( str_replace('_',' ',$c) ); }, $field_cols )
        ) );

        foreach ( $rows as $row ) {
            $csv = array( '#' . $row['id'], $row['created_at'] );
            foreach ( $field_cols as $c ) $csv[] = self::csv_safe( isset( $row[$c] ) ? $row[$c] : '' );
            fputcsv( $out, $csv );
        }
        fclose( $out );
        exit;
    }

    /**
     * Neutralizes spreadsheet formula injection: a cell starting with =, @, tab or CR,
     * or with + or - followed by something that is not a plain number (so phone numbers
     * like +92 300 1234567 and amounts like -500 stay unchanged), gets a leading quote.
     */
    public static function csv_safe( $v ) {
        $v = (string) $v;
        if ( '' === $v ) return $v;
        // Spreadsheet engines may ignore leading whitespace/control characters before formulas.
        // Preserve raw originals in JSON/database; escape only the exported CSV representation.
        $probe = preg_replace( '/^[\x00-\x20\x{FEFF}]+/u', '', $v );
        if ( is_string( $probe ) && preg_match( '/^[=+@\-]/u', $probe ) ) return "'" . $v;
        return $v;
    }

    /** Validate tables against the discovered plugin-owned lead tables, not arbitrary input. */
    private function selected_table() {
        $form_id = isset( $_REQUEST['form_tab'] ) ? sanitize_key( wp_unslash( $_REQUEST['form_tab'] ) ) : '';
        if ( ! $form_id ) return false;
        foreach ( $this->get_all_lead_tables() as $item ) {
            if ( $item['form_id'] === $form_id ) return $item;
        }
        return false;
    }

    private function lead_columns( $table ) {
        global $wpdb;
        return array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ) );
    }

    private function action_redirect( $form_id, $notice ) {
        wp_safe_redirect( add_query_arg( array(
            'page' => 'wp-ai-chatbot-leads', 'form_tab' => $form_id,
            'lead_notice' => $notice,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /** Server-side CRUD, protected by admin capability, POST and per-action nonces. */
    public function handle_lead_admin_action() {
        if ( ! isset( $_POST['akdk_leads_action'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        $action = sanitize_key( wp_unslash( $_POST['akdk_leads_action'] ) );
        if ( ! in_array( $action, array( 'save_row', 'delete_row', 'delete_column', 'delete_table', 'bulk_delete_rows', 'bulk_delete_columns', 'rename_column', 'add_column' ), true ) ) return;
        $selected = $this->selected_table();
        if ( ! $selected ) wp_die( 'Lead table not found.' );
        check_admin_referer( 'akdk_leads_' . $action . '_' . $selected['form_id'] );
        global $wpdb;
        $table = $selected['table'];
        $columns = $this->lead_columns( $table );
        $fields = array_values( array_diff( $columns, array( 'id', 'created_at' ) ) );
        $id = isset( $_POST['lead_id'] ) ? absint( $_POST['lead_id'] ) : 0;
        $notice = 'error';
        if ( 'save_row' === $action ) {
            $data = array();
            $incoming = isset( $_POST['lead_fields'] ) && is_array( $_POST['lead_fields'] ) ? wp_unslash( $_POST['lead_fields'] ) : array();
            foreach ( $fields as $field ) {
                $data[$field] = isset( $incoming[$field] ) && is_scalar( $incoming[$field] ) ? sanitize_textarea_field( $incoming[$field] ) : '';
            }
            if ( $id ) {
                $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE id=%d", $id ) );
                $ok = $exists ? $wpdb->update( $table, $data, array( 'id' => $id ) ) : false;
            } else {
                $ok = $wpdb->insert( $table, $data );
            }
            $notice = false !== $ok ? 'saved' : 'error';
        } elseif ( 'delete_row' === $action && $id ) {
            $notice = false !== $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ) ? 'row_deleted' : 'error';
        } elseif ( 'delete_column' === $action ) {
            $column = isset( $_POST['column'] ) ? sanitize_key( wp_unslash( $_POST['column'] ) ) : '';
            if ( in_array( $column, $fields, true ) ) {
                // Do not drop fields still defined on a live form: its schema sync would recreate them.
                $in_use = false;
                foreach ( (array) get_option( 'wp_ai_chatbot_multi_forms', array() ) as $form ) {
                    if ( ! empty( $form['id'] ) && sanitize_key( $form['id'] ) === $selected['form_id'] ) {
                        foreach ( (array) ( isset( $form['fields'] ) ? $form['fields'] : array() ) as $field ) {
                            $form_col = sanitize_key( str_replace( ' ', '_', strtolower( isset( $field['label'] ) ? $field['label'] : '' ) ) );
                            if ( $form_col === $column ) $in_use = true;
                        }
                    }
                }
                if ( $in_use ) {
                    $notice = 'field_in_use';
                } else {
                    $notice = false !== $wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `{$column}`" ) ? 'column_deleted' : 'error';
                }
            }
        } elseif ( 'bulk_delete_rows' === $action ) {
            $ids = isset( $_POST['lead_ids'] ) && is_array( $_POST['lead_ids'] ) ? array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['lead_ids'] ) ) ) ) : array();
            if ( $ids ) {
                $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                $result = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE id IN ({$placeholders})", $ids ) );
                $notice = false === $result ? 'error' : 'rows_deleted';
            } else $notice = 'nothing_selected';
        } elseif ( 'rename_column' === $action || 'add_column' === $action || 'bulk_delete_columns' === $action ) {
            $forms = (array) get_option( 'wp_ai_chatbot_multi_forms', array() );
            $form_index = null;
            foreach ( $forms as $i => $form ) {
                if ( isset( $form['id'] ) && sanitize_key( $form['id'] ) === $selected['form_id'] ) { $form_index = $i; break; }
            }
            $column_for_label = static function( $label ) { return sanitize_key( str_replace( ' ', '_', strtolower( $label ) ) ); };
            if ( null === $form_index ) {
                $notice = 'invalid_column';
            } elseif ( 'rename_column' === $action ) {
                $old = isset($_POST['column']) ? sanitize_key(wp_unslash($_POST['column'])) : '';
                $label = isset($_POST['new_label']) ? sanitize_text_field(wp_unslash($_POST['new_label'])) : '';
                $new = $column_for_label($label);
                if ( !in_array($old, $fields, true) || !$new || strlen($new)>60 || in_array($new, array('id','created_at'), true) || ($old!==$new && in_array($new,$columns,true)) ) {
                    $notice='invalid_column';
                } else {
                    $field_index = null;
                    if ( null !== $form_index ) foreach ( (array) $forms[$form_index]['fields'] as $k=>$form_field ) {
                        if ( $column_for_label($form_field['label'] ?? '') === $old ) { $field_index=$k; break; }
                    }
                    if ($old!==$new) {
                        // CHANGE preserves the existing SQL column type and its stored values.
                        $meta=$wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s",$old),ARRAY_A);
                        $type= $meta['Type'] ?? '';
                        if (!preg_match('/^(?:varchar\([0-9]+\)|text|mediumtext|longtext|tinytext|int\([0-9]+\)|bigint\([0-9]+\))$/i',$type)) {
                            $notice='invalid_column';
                        } else {
                            $sql="ALTER TABLE `{$table}` CHANGE COLUMN `{$old}` `{$new}` {$type} ".(($meta['Null']??'YES')==='YES'?'NULL':'NOT NULL');
                            if (false===$wpdb->query($sql)) $notice='error';
                            else $notice='column_renamed';
                        }
                    } else $notice='column_renamed';
                    if ('column_renamed'===$notice && null!==$field_index) {
                        $forms[$form_index]['fields'][$field_index]['label']=$label;
                        update_option('wp_ai_chatbot_multi_forms',$forms);
                        $stored=(array)get_option('wp_ai_chatbot_multi_forms',array());
                        if (($stored[$form_index]['fields'][$field_index]['label']??null)!==$label) {
                            if ($old!==$new) $wpdb->query("ALTER TABLE `{$table}` CHANGE COLUMN `{$new}` `{$old}` {$type} ".(($meta['Null']??'YES')==='YES'?'NULL':'NOT NULL'));
                            $notice='error';
                        }
                    }
                }
            } elseif ('add_column' === $action) {
                $label=isset($_POST['new_label']) ? sanitize_text_field(wp_unslash($_POST['new_label'])) : '';
                $new=$column_for_label($label);
                if (!$new || strlen($new)>60 || in_array($new,$columns,true) || in_array($new,array('id','created_at'),true)) $notice='invalid_column';
                else {
                    $ok=$wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$new}` TEXT DEFAULT NULL");
                    $notice=false===$ok?'error':'column_added';
                    if ('column_added'===$notice && null!==$form_index) {
                        // Mirror the six field types supported by the native Lead Forms builder.
                        $allowed_types = array('text','email','tel','textarea','select','multiselect');
                        $requested_type = isset($_POST['new_type']) ? sanitize_key(wp_unslash($_POST['new_type'])) : 'text';
                        $type = in_array($requested_type, $allowed_types, true) ? $requested_type : 'text';
                        $hint = isset($_POST['new_placeholder']) ? sanitize_text_field(wp_unslash($_POST['new_placeholder'])) : '';
                        $choices = isset($_POST['new_options']) ? sanitize_text_field(wp_unslash($_POST['new_options'])) : '';
                        $required = !empty($_POST['new_required']) ? 'yes' : 'no';
                        $forms[$form_index]['fields'][]=array('label'=>$label,'type'=>$type,'placeholder'=>$hint,'options'=>in_array($type,array('select','multiselect'),true)?$choices:'','required'=>$required);
                        update_option('wp_ai_chatbot_multi_forms',$forms);
                        $stored=(array)get_option('wp_ai_chatbot_multi_forms',array());
                        $expected=count($forms[$form_index]['fields']);
                        if (count($stored[$form_index]['fields']??array())!==$expected) {
                            $wpdb->query("ALTER TABLE `{$table}` DROP COLUMN `{$new}`");
                            $notice='error';
                        }
                    }
                }
            } else {
                $selected_cols=isset($_POST['columns']) && is_array($_POST['columns']) ? array_unique(array_map('sanitize_key',wp_unslash($_POST['columns']))) : array();
                $selected_cols=array_values(array_intersect($fields,$selected_cols));
                if (!$selected_cols) $notice='nothing_selected';
                else {
                    // Keep one field for active forms so their builder remains usable.
                    if (null!==$form_index) {
                        $kept=array_values(array_filter((array)$forms[$form_index]['fields'],static function($f) use($selected_cols,$column_for_label) {return !in_array($column_for_label($f['label']??''),$selected_cols,true);}));
                        if (!$kept) { $notice='last_form_field'; }
                    }
                    if ($notice!=='last_form_field') {
                        // One ALTER statement avoids half-completed bulk drops. MySQL DDL cannot
                        // be rolled back via a normal transaction, so validate first.
                        $clauses = array_map( static function( $col ) { return 'DROP COLUMN `' . $col . '`'; }, $selected_cols );
                        $sql = "ALTER TABLE `{$table}` " . implode( ', ', $clauses );
                        // Destructive DDL is not transaction-safe in MySQL: preserve all lead
                        // values in an independent backup table before attempting a DROP.
                        $backup = $wpdb->prefix . 'ai_leadbak_' . gmdate( 'ymdHis' ) . '_' . wp_rand( 1000, 9999 );
                        $backup_ok = false !== $wpdb->query( "CREATE TABLE `{$backup}` LIKE `{$table}`" );
                        if ( $backup_ok ) {
                            $backup_ok = false !== $wpdb->query( "INSERT INTO `{$backup}` SELECT * FROM `{$table}`" );
                        }
                        if ( $backup_ok ) {
                            $source_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
                            $backup_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$backup}`" );
                            $backup_ok = ( $source_count === $backup_count );
                        }
                        if ( ! $backup_ok ) {
                            $notice = 'error';
                            error_log( 'AKDK Leads: could not back up table before column deletion: ' . $selected['form_id'] );
                        }
                        $ok = $backup_ok && false !== $wpdb->query( $sql );
                        if ( $ok ) {
                            $backups = (array) get_option( 'akdk_lead_column_backups', array() );
                            $backups[] = array( 'form_id' => $selected['form_id'], 'backup_table' => $backup, 'created_utc' => gmdate( 'c' ) );
                            update_option( 'akdk_lead_column_backups', $backups, false );
                        }
                        if ( $ok && null !== $form_index ) {
                            $forms[$form_index]['fields'] = $kept;
                            $saved = update_option( 'wp_ai_chatbot_multi_forms', $forms );
                            // A false return can mean unchanged or failed; verify the stored form.
                            $stored = (array) get_option( 'wp_ai_chatbot_multi_forms', array() );
                            if ( ! isset( $stored[$form_index]['fields'] ) || $stored[$form_index]['fields'] !== $kept ) {
                                // Never recreate dropped columns as EMPTY columns: that would
                                // conceal irreversible data loss. Surface a specific reconciliation
                                // failure so an administrator can restore from the database backup.
                                error_log( 'AKDK Leads: form metadata synchronization failed after bulk SQL drop for form ' . $selected['form_id'] );
                                $notice = 'error';
                            } else {
                                $notice = 'columns_deleted';
                            }
                        } else {
                            $notice = $ok ? 'columns_deleted' : 'error';
                        }
                    }
                }
            }
        } elseif ( 'delete_table' === $action ) {
            // An active built-in form needs its table to collect leads. Allow only orphan deletion.
            if ( $selected['is_orphan'] ) {
                $ok = $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
                $this->action_redirect( '', false !== $ok ? 'table_deleted' : 'error' );
            }
            $notice = 'active_table';
        }
        $this->action_redirect( $selected['form_id'], $notice );
    }

    /** Small dependency-free PDF writer for lead reports (WinAnsi text fallback). */
    private static function pdf_text( $s ) {
        $s = wp_strip_all_tags( (string) $s );
        $s = preg_replace( '/[\r\n\t]+/u', ' ', $s );
        if ( function_exists( 'iconv' ) ) {
            $converted = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $s         = false !== $converted ? $converted : $s;
        }
        $s = preg_replace( '/[^\x20-\x7E\xA0-\xFF]/', '?', (string) $s );
        return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $s );
    }

    public function handle_pdf_export() {
        if ( ! isset( $_GET['page'], $_GET['action'] ) || 'wp-ai-chatbot-leads' !== $_GET['page'] || 'export_pdf' !== $_GET['action'] ) return;
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        $selected = $this->selected_table();
        if ( ! $selected ) wp_die( 'Lead table not found.' );
        check_admin_referer( 'akdk_pdf_' . $selected['form_id'] );
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT * FROM `{$selected['table']}` ORDER BY created_at DESC", ARRAY_A );
        $pages = array(); $lines = array();
        $lines[] = 'AK Deep Knowledge - Lead Export';
        $lines[] = 'Form: ' . $selected['form_title'] . ' | Total: ' . count( $rows );
        $lines[] = 'Generated: ' . gmdate( 'Y-m-d H:i' ) . ' UTC';
        $lines[] = str_repeat( '-', 85 );
        foreach ( $rows as $row ) {
            $lines[] = 'Lead #' . $row['id'] . ' | ' . $row['created_at'];
            foreach ( $row as $key => $value ) {
                if ( in_array( $key, array( 'id', 'created_at' ), true ) ) continue;
                $line = ucfirst( str_replace( '_', ' ', $key ) ) . ': ' . (string) $value;
                $line = preg_replace( '/\s+/u', ' ', $line );
                // Split as UTF-8 (pdf_text() converts each piece once for the PDF font).
                $chunks = function_exists( 'mb_str_split' ) ? mb_str_split( $line, 94 ) : str_split( $line, 94 );
                foreach ( $chunks as $chunk ) $lines[] = $chunk;
            }
            $lines[] = '';
        }
        $pages = array_chunk( $lines, 55 );
        if ( ! $pages ) $pages = array( array( 'No leads.' ) );
        $objects = array();
        $add = function( $content ) use ( &$objects ) { $objects[] = $content; return count( $objects ); };
        $catalog = $add( '' ); $root = $add( '' );
        $font = $add( '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>' );
        $page_ids = array();
        foreach ( $pages as $page_lines ) {
            $stream = "BT /F1 10 Tf 12 TL 40 800 Td\n";
            foreach ( $page_lines as $line ) $stream .= '(' . self::pdf_text( $line ) . ") Tj T*\n";
            $stream .= 'ET';
            $content_id = $add( '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream" );
            $page_ids[] = $add( '<< /Type /Page /Parent ' . $root . ' 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ' . $font . ' 0 R >> >> /Contents ' . $content_id . ' 0 R >>' );
        }
        $objects[$root-1] = '<< /Type /Pages /Kids [' . implode( ' ', array_map( function($id){return $id . ' 0 R';}, $page_ids ) ) . '] /Count ' . count($page_ids) . ' >>';
        $objects[$catalog-1] = '<< /Type /Catalog /Pages ' . $root . ' 0 R >>';
        $pdf = "%PDF-1.4\n"; $offsets = array(0);
        foreach ( $objects as $i => $obj ) { $offsets[] = strlen( $pdf ); $pdf .= ($i+1) . " 0 obj\n" . $obj . "\nendobj\n"; }
        $xref = strlen( $pdf );
        $pdf .= "xref\n0 " . (count($objects)+1) . "\n0000000000 65535 f \n";
        foreach ( array_slice( $offsets, 1 ) as $offset ) $pdf .= sprintf( "%010d 00000 n \n", $offset );
        $pdf .= "trailer\n<< /Size " . (count($objects)+1) . ' /Root ' . $catalog . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="leads-' . sanitize_file_name( $selected['form_id'] ) . '.pdf"' );
        header( 'Content-Length: ' . strlen($pdf) );
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
        exit;
    }

    /** Summary card for external forms (their submissions live in the connected service). */
    private function render_external_summary() {
        $forms    = get_option( 'wp_ai_chatbot_multi_forms', array() );
        $external = array();
        foreach ( (array) $forms as $f ) {
            if ( ! empty( $f['id'] ) && WP_AI_Chatbot_External_Forms::is_external( $f ) ) {
                $external[] = $f;
            }
        }
        if ( empty( $external ) ) {
            return;
        }
        $counts = WP_AI_Chatbot_External_Forms::counts( 'WHERE 1=1' );
        echo '<div class="akdk-card" style="margin-top:12px;"><h2>Connected forms &amp; links</h2>';
        echo '<p>Submissions to these are saved by the connected service (Calendly, Google Forms, your form plugin, checkout). The chatbot counts how often they were shown, opened and completed.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Form</th><th>Type</th><th>Shown</th><th>Opened</th><th>Reported by browser</th><th>Bookings</th><th>Add to cart</th><th>Verified leads</th></tr></thead><tbody>';
        foreach ( $external as $f ) {
            $c = isset( $counts[ $f['id'] ] ) ? $counts[ $f['id'] ] : array();
            $n = function ( $k ) use ( $c ) { return isset( $c[ $k ] ) ? (int) $c[ $k ] : 0; };
            printf(
                '<tr><td><strong>%1$s</strong></td><td>%2$s</td><td>%3$d</td><td>%4$d</td><td>%5$d</td><td>%6$d</td><td>%7$d</td><td><strong>%8$d</strong></td></tr>',
                esc_html( $f['title'] ),
                esc_html( WP_AI_Chatbot_External_Forms::source_label( WP_AI_Chatbot_External_Forms::source_of( $f ) ) ),
                $n( 'view' ), $n( 'click' ), $n( 'submitted' ), $n( 'booked' ), $n( 'cart' ), $n( 'confirmed' )
            );
        }
        echo '</tbody></table>';
        echo '<p class="description" style="margin-top:8px;"><strong>Verified leads</strong> are confirmed by the form service or your website (webhook or form plugin). "Reported" and "Bookings" come from the visitor\'s browser and are not verified. Set up verification under Lead Forms, "Verified submissions".</p>';

        $recent = WP_AI_Chatbot_External_Forms::recent_confirmed( 20 );
        if ( $recent ) {
            $titles = wp_list_pluck( $external, 'title', 'id' );
            echo '<h3 style="margin-top:16px;">Latest verified submissions</h3><table class="widefat striped"><thead><tr><th>Date</th><th>Form</th><th>Details</th></tr></thead><tbody>';
            foreach ( $recent as $r ) {
                $data  = json_decode( (string) $r->data, true );
                $parts = array();
                foreach ( (array) $data as $k => $v ) {
                    if ( 'via' !== $k ) {
                        $parts[] = '<strong>' . esc_html( $k ) . ':</strong> ' . esc_html( $v );
                    }
                }
                printf(
                    '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td></tr>',
                    esc_html( $r->created_at ),
                    esc_html( isset( $titles[ $r->form_id ] ) ? $titles[ $r->form_id ] : $r->form_id ),
                    $parts ? implode( '<br>', $parts ) : '<em>Confirmed by your form plugin</em>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
                );
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    public function display_leads_page() {
        global $wpdb;

        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        // Success notices
        if ( isset( $_GET['deleted'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Table deleted successfully.</p></div>';
        }

        if ( isset( $_GET['lead_notice'] ) ) {
            $notices = array( 'saved' => 'Lead saved.', 'row_deleted' => 'Lead deleted.', 'rows_deleted' => 'Selected leads deleted.', 'columns_deleted'=>'Selected columns deleted and form fields synchronized.', 'column_renamed'=>'Column renamed; form field label updated.', 'column_added'=>'Column added; form field added.', 'invalid_column'=>'Invalid or duplicate column name.', 'nothing_selected'=>'Select at least one item.', 'last_form_field'=>'Cannot remove every field of an active form.', 'column_deleted' => 'Column deleted.', 'table_deleted' => 'Lead table deleted.', 'field_in_use' => 'This column is used by the active form. Remove its field in Lead Forms first, then delete the column here.', 'active_table' => 'Active form tables cannot be deleted. Delete the form first to preserve collection safety.', 'error' => 'Operation could not be completed.' );
            $code = sanitize_key( wp_unslash( $_GET['lead_notice'] ) );
            if ( isset( $notices[$code] ) ) echo '<div class="notice notice-' . ( in_array( $code, array( 'error','field_in_use','active_table' ), true ) ? 'warning' : 'success' ) . '"><p>' . esc_html( $notices[$code] ) . '</p></div>';
        }
        $all_tables     = $this->get_all_lead_tables();
        $active_form_id = isset( $_GET['form_tab'] ) ? sanitize_key( $_GET['form_tab'] ) : '';
        if ( empty( $active_form_id ) && ! empty( $all_tables ) ) {
            $active_form_id = $all_tables[0]['form_id'];
        }

        // Active table info
        $active_info = array();
        foreach ( $all_tables as $t ) {
            if ( $t['form_id'] === $active_form_id ) { $active_info = $t; break; }
        }
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Chatbot Leads</h1>
            <?php $this->render_external_summary(); ?>

            <?php if ( empty( $all_tables ) ) : ?>
                <div style="margin-top:30px; padding:40px; text-align:center; background:#fff; border:1px solid #ddd; border-radius:8px;">
                    <p style="color:#888; font-size:15px; margin:0;">
                        No leads from built-in forms yet. Add a form under <strong>Lead Forms</strong>; visitors can then submit it in the chat.
                    </p>
                </div>
                </div><?php return; endif; ?>

            <!-- TABS -->
            <div style="margin-top:20px; border-bottom:1px solid #ccd0d4; display:flex; flex-wrap:wrap; gap:4px;">
                <?php foreach ( $all_tables as $t ) :
                    $is_active  = ( $active_form_id === $t['form_id'] );
                    $tab_url    = admin_url( 'admin.php?page=wp-ai-chatbot-leads&form_tab=' . $t['form_id'] );
                    $orphan     = $t['is_orphan'];

                    // Styling
                    $tab_bg     = $is_active ? '#fff' : ( $orphan ? '#fff5f5' : '#f0f0f0' );
                    $tab_border = $is_active ? '2px solid ' . ( $orphan ? '#d63638' : '#fd9400' ) . '; border-bottom:1px solid #fff' : '1px solid #ccd0d4; border-bottom:none';
                    $tab_color  = $orphan ? '#d63638' : ( $is_active ? '#0a0a0a' : '#444' );
                    $tab_weight = $is_active ? '700' : '400';
                ?>
                    <a href="<?php echo esc_url( $tab_url ); ?>"
                       style="display:inline-flex; align-items:center; gap:6px; padding:8px 16px; background:<?php echo $tab_bg; ?>; border:<?php echo $tab_border; ?>; color:<?php echo $tab_color; ?>; font-weight:<?php echo $tab_weight; ?>; font-size:13px; text-decoration:none; border-radius:4px 4px 0 0; position:relative; top:1px;">
                        <?php if ( $orphan ) : ?><span title="Form deleted, data still exists">⚠</span><?php endif; ?>
                        <?php echo esc_html( $t['form_title'] ); ?>
                        <?php
                        // Row count badge
                        $cnt = $wpdb->get_var( "SELECT COUNT(*) FROM `{$t['table']}`" );
                        if ( $cnt > 0 ) {
                            echo '<span style="background:' . ($orphan?'#d63638':'#0a0a0a') . '; color:#fff; border-radius:10px; padding:1px 7px; font-size:10px; font-weight:700;">' . $cnt . '</span>';
                        }
                        ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- CONTENT PANEL -->
            <div style="background:#fff; border:1px solid #ccd0d4; border-top:none; padding:16px 20px;">

            <?php if ( empty( $active_info ) ) : ?>
                <p style="color:#888;">Select a tab above.</p>
            <?php else :
                $tbl        = $active_info['table'];
                $is_orphan  = $active_info['is_orphan'];
                $rows       = $wpdb->get_results( "SELECT * FROM `{$tbl}` ORDER BY created_at DESC", ARRAY_A );
                $field_cols = array_values( array_diff( $this->lead_columns( $tbl ), array( 'id', 'created_at' ) ) );

                $export_url = wp_nonce_url(
                    admin_url( 'admin.php?page=wp-ai-chatbot-leads&action=export_csv&form_tab=' . $active_form_id ),
                    'export_leads_csv'
                );
                $delete_url = wp_nonce_url(
                    admin_url( 'admin.php?page=wp-ai-chatbot-leads&action=delete_table&form_tab=' . $active_form_id ),
                    'delete_lead_table'
                );
            ?>

                <!-- Orphan Warning Bar -->
                <?php if ( $is_orphan ) : ?>
                <div style="background:#fff5f5; border:1px solid #f5c6cb; border-radius:6px; padding:10px 15px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center;">
                    <span style="color:#d63638; font-weight:600;">
                        ⚠ This form has been deleted. Data is preserved in table <code><?php echo esc_html($tbl); ?></code>
                    </span>

                </div>
                <?php endif; ?>

                <!-- Header row -->
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <p style="margin:0; color:#555; font-size:13px;">
                        Table: <code style="font-size:11px;"><?php echo esc_html($tbl); ?></code>
                        &nbsp;|&nbsp; Total: <strong><?php echo count($rows); ?></strong>
                    </p>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                      <button type="button" class="button button-primary" onclick="document.getElementById('akdk-add-lead').hidden=false;this.hidden=true">+ Add Lead</button>
                      <a class="button" href="<?php echo esc_url($export_url); ?>">Export CSV</a>
                      <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url('admin.php?page=wp-ai-chatbot-leads&action=export_json&form_tab='.$active_form_id), 'akdk_json_'.$active_form_id ) ); ?>">Export JSON</a>
                      <a class="button" href="<?php echo esc_url( wp_nonce_url(admin_url('admin.php?page=wp-ai-chatbot-leads&action=export_pdf&form_tab='.$active_form_id),'akdk_pdf_'.$active_form_id) ); ?>">Export PDF</a>
                      <?php if ($is_orphan): ?>
                      <form method="post" onsubmit="return confirm('Permanently delete this entire lead table and all submissions?');" style="display:inline;">
                        <?php wp_nonce_field('akdk_leads_delete_table_'.$active_form_id); ?>
                        <input type="hidden" name="form_tab" value="<?php echo esc_attr($active_form_id); ?>"><input type="hidden" name="akdk_leads_action" value="delete_table">
                        <button class="button" style="color:#b32d2e;">Delete Table</button>
                      </form>
                      <?php endif; ?>
                    </div>
                </div>

                <div id="akdk-add-lead" hidden style="padding:16px;border:1px solid #ddd;background:#f7f7f7;margin:12px 0;">
                  <h3>Add new lead</h3>
                  <form method="post">
                    <?php wp_nonce_field('akdk_leads_save_row_'.$active_form_id); ?>
                    <input type="hidden" name="form_tab" value="<?php echo esc_attr($active_form_id); ?>"><input type="hidden" name="akdk_leads_action" value="save_row">
                    <?php foreach($field_cols as $col): ?>
                      <p><label><strong><?php echo esc_html(ucwords(str_replace('_',' ',$col))); ?></strong><br><textarea rows="2" style="width:100%;max-width:650px;" name="lead_fields[<?php echo esc_attr($col); ?>]"></textarea></label></p>
                    <?php endforeach; ?>
                    <button class="button button-primary">Save Lead</button> <button type="button" class="button" onclick="document.getElementById('akdk-add-lead').hidden=true">Cancel</button>
                  </form>
                </div>
                <style>
                  .akdk-leads-workspace{--akdk-line:#e2e5e9;--akdk-ink:#1d2327;--akdk-accent:#fc9504;color:var(--akdk-ink)}
                  .akdk-leads-panel{border:1px solid var(--akdk-line);border-radius:12px;background:#fff;margin:16px 0;overflow:hidden}
                  .akdk-leads-panel>summary{list-style:none;cursor:pointer;padding:16px 20px;background:#fafbfc;display:flex;align-items:center;justify-content:space-between;font-size:14px;font-weight:700}
                  .akdk-leads-panel>summary::-webkit-details-marker{display:none}
                  .akdk-leads-panel>summary:after{content:'▾';font-size:16px;color:#667085}
                  .akdk-leads-panel[open]>summary:after{content:'▴'}
                  .akdk-leads-panel[open]>summary{border-bottom:1px solid var(--akdk-line)}
                  .akdk-leads-panel-body{padding:18px 20px}
                  .akdk-leads-panel .description{margin:0 0 16px;color:#667085}
                  .akdk-field-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;align-items:end}
                  .akdk-field-grid .akdk-field-full{grid-column:1/-1}
                  .akdk-field-grid label,.akdk-col-title{display:block;font-weight:600;font-size:12px;margin-bottom:6px;color:#344054}
                  .akdk-field-grid input[type=text],.akdk-field-grid select{width:100%;max-width:none;min-height:38px}
                  .akdk-col-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
                  .akdk-col-item{display:flex;align-items:center;gap:10px;padding:10px;border:1px solid var(--akdk-line);border-radius:9px;min-width:0}
                  .akdk-col-item code{color:#667085;overflow-wrap:anywhere;font-size:11px}
                  .akdk-col-item form{display:flex;align-items:center;gap:8px;flex:1;min-width:0;flex-wrap:wrap;margin:0}
                  .akdk-col-item input[type=text]{min-width:120px;flex:1;max-width:100%;height:34px}
                  .akdk-leads-divider{height:1px;background:var(--akdk-line);margin:20px 0}
                  .akdk-bulk-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 14px;background:#fafbfc;border:1px solid var(--akdk-line);border-radius:9px;margin-bottom:12px}
                  .akdk-leads-workspace .akdk-row-check,.akdk-leads-workspace .akdk-col-check{margin:0;vertical-align:middle}
                  .akdk-leads-workspace th:first-child,.akdk-leads-workspace td:first-child{text-align:center;vertical-align:middle;width:42px;min-width:42px;box-sizing:border-box}
                  .akdk-leads-workspace .wp-list-table th,.akdk-leads-workspace .wp-list-table td{vertical-align:middle}
                  @media(max-width:900px){.akdk-field-grid,.akdk-col-list{grid-template-columns:minmax(0,1fr)}}
                </style>
                <div class="akdk-leads-workspace">
                <details class="akdk-leads-panel">
                  <summary><span>Column settings <span style="font-weight:400;color:#667085">· Add fields, rename, or remove columns</span></span></summary>
                  <div class="akdk-leads-panel-body">
                    <p class="description">New columns are also added to the matching Lead Form. Changes to labels update the form builder. Deleting columns permanently deletes their saved values.</p>
                    <?php if (!$is_orphan): ?>
                    <h3 style="margin:0 0 14px">Add a new form field / column</h3>
                    <form method="post" style="margin:0">
                      <?php wp_nonce_field('akdk_leads_add_column_'.$active_form_id); ?>
                      <input type="hidden" name="form_tab" value="<?php echo esc_attr($active_form_id); ?>"><input type="hidden" name="akdk_leads_action" value="add_column">
                      <div class="akdk-field-grid">
                        <div><label for="akdk-new-label">Column name / Question label</label><input id="akdk-new-label" type="text" name="new_label" required maxlength="60" placeholder="e.g. Preferred contact time"></div>
                        <div><label for="akdk-new-type">Answer type</label><select id="akdk-new-type" name="new_type" onchange="document.getElementById('akdk-new-choices').hidden=!['select','multiselect'].includes(this.value)">
                            <option value="text">Short text</option><option value="email">Email address</option><option value="tel">Phone number</option><option value="textarea">Long text (message)</option><option value="select">Dropdown (pick one)</option><option value="multiselect">Checkboxes (pick several)</option>
                        </select></div>
                        <div><label for="akdk-new-placeholder">Placeholder / Hint inside the box</label><input id="akdk-new-placeholder" type="text" name="new_placeholder" placeholder="e.g. Monday afternoon"></div>
                        <div id="akdk-new-choices" hidden><label for="akdk-options">Choices (comma separated)</label><input id="akdk-options" type="text" name="new_options" placeholder="Option 1, Option 2, Option 3"></div>
                        <div class="akdk-field-full" style="display:flex;gap:18px;align-items:center;flex-wrap:wrap"><label style="display:inline-flex;align-items:center;gap:7px;margin:0;font-size:13px"><input type="checkbox" name="new_required" value="yes"> Required field</label><button class="button button-primary">+ Add Column &amp; Form Field</button></div>
                      </div>
                    </form>
                    <?php else: ?><p class="description">This is a deleted form's archived table. Adding fields to an inactive form is disabled.</p><?php endif; ?>
                    <div class="akdk-leads-divider"></div>
                    <h3 style="margin:0 0 12px">Rename existing columns</h3>
                    <div class="akdk-col-list">
                    <?php foreach($field_cols as $col): ?>
                      <div class="akdk-col-item">
                        <div style="min-width:0;flex:1"><span class="akdk-col-title"><?php echo esc_html(ucwords(str_replace('_',' ',$col))); ?></span><code><?php echo esc_html($col); ?></code></div>
                        <form method="post">
                          <?php wp_nonce_field('akdk_leads_rename_column_'.$active_form_id); ?>
                          <input type="hidden" name="form_tab" value="<?php echo esc_attr($active_form_id); ?>"><input type="hidden" name="akdk_leads_action" value="rename_column"><input type="hidden" name="column" value="<?php echo esc_attr($col); ?>">
                          <input type="text" name="new_label" required maxlength="60" value="<?php echo esc_attr(ucwords(str_replace('_',' ',$col))); ?>" aria-label="New label for <?php echo esc_attr($col); ?>"><button class="button">Rename</button>
                        </form>
                      </div>
                    <?php endforeach; ?>
                    </div>
                    <div class="akdk-leads-divider"></div>
                    <h3 style="margin:0 0 12px">Delete columns</h3>
                    <form method="post" onsubmit="return confirm('Permanently delete the selected columns and their saved data?');">
                      <?php wp_nonce_field('akdk_leads_bulk_delete_columns_'.$active_form_id); ?>
                      <input type="hidden" name="form_tab" value="<?php echo esc_attr($active_form_id); ?>"><input type="hidden" name="akdk_leads_action" value="bulk_delete_columns">
                      <div class="akdk-bulk-bar"><label style="display:flex;align-items:center;gap:7px"><input type="checkbox" onchange="document.querySelectorAll('.akdk-col-check').forEach(c=>c.checked=this.checked)"> Select all columns</label><button class="button" style="color:#b32d2e">Delete Selected Columns</button></div>
                      <div style="display:flex;flex-wrap:wrap;gap:10px 18px">
                      <?php foreach($field_cols as $col): ?><label style="display:inline-flex;align-items:center;gap:6px"><input class="akdk-col-check" type="checkbox" name="columns[]" value="<?php echo esc_attr($col); ?>"> <?php echo esc_html(ucwords(str_replace('_',' ',$col))); ?></label><?php endforeach; ?>
                      </div>
                    </form>
                  </div>
                </details>
                <div class="akdk-leads-panel" style="padding:16px 20px">
                  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px"><h3 style="margin:0">Lead rows <span style="font-size:12px;font-weight:400;color:#667085">(<?php echo count($rows); ?> records)</span></h3><span style="font-size:12px;color:#667085">Select rows in the table to delete in bulk</span></div>
                  <?php if($rows): ?>
                  <form method="post" id="akdk-bulk-rows" onsubmit="return confirm('Permanently delete all selected lead rows?');" class="akdk-bulk-bar">
                    <?php wp_nonce_field('akdk_leads_bulk_delete_rows_'.$active_form_id); ?>
                    <input type="hidden" name="form_tab" value="<?php echo esc_attr($active_form_id); ?>"><input type="hidden" name="akdk_leads_action" value="bulk_delete_rows">
                    <label style="display:flex;align-items:center;gap:7px"><input type="checkbox" onchange="document.querySelectorAll('.akdk-row-check').forEach(c=>c.checked=this.checked)"> Select all rows</label>
                    <button class="button" style="color:#b32d2e">Delete Selected Rows</button>
                  </form>
                  <?php endif; ?>
                <!-- Data table -->
                <?php if ( ! empty( $rows ) ) : ?>
                <div style="overflow-x:auto;">
                <table class="wp-list-table widefat striped" style="border-collapse:collapse;">
                    <thead style="background:#f6f7f7;">
                        <tr>
                            <th style="width:34px"><input type="checkbox" aria-label="Select all rows" onchange="document.querySelectorAll('.akdk-row-check').forEach(c=>c.checked=this.checked)"></th><th style="padding:10px 12px; border:1px solid #ddd; width:45px;">#ID</th>
                            <?php foreach ( $field_cols as $col ) : ?>
                                <th style="padding:10px 12px; border:1px solid #ddd;">
                                    <?php echo esc_html( ucfirst( str_replace('_',' ',$col) ) ); ?>
                                </th>
                            <?php endforeach; ?>
                            <th style="padding:10px 12px; border:1px solid #ddd; width:150px;">Date & Time</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><input class="akdk-row-check" form="akdk-bulk-rows" type="checkbox" name="lead_ids[]" value="<?php echo (int)$row['id']; ?>" aria-label="Select lead <?php echo (int)$row['id']; ?>"></td><td style="padding:9px 12px; border:1px solid #eee; font-weight:600;">#<?php echo esc_html($row['id']); ?></td>
                            <?php foreach ( $field_cols as $col ) :
                                $val   = isset( $row[$col] ) ? $row[$col] : '';
                                $parts = ( strpos($val,',') !== false )
                                    ? array_filter( array_map('trim', explode(',', $val)) )
                                    : array();
                                echo '<td style="padding:9px 12px; border:1px solid #eee;">';
                                if ( count($parts) > 1 ) {
                                    foreach ($parts as $p) {
                                        echo '<span style="display:inline-block;background:#e8f0fe;color:#1a73e8;padding:2px 9px;border-radius:12px;font-size:11px;margin:2px 2px 2px 0;">' . esc_html($p) . '</span>';
                                    }
                                } elseif ( $val !== '' ) {
                                    echo esc_html($val);
                                } else {
                                    echo '<span style="color:#bbb;">N/A</span>';
                                }
                                echo '</td>';
                            endforeach; ?>
                            <td style="padding:9px 12px; border:1px solid #eee; color:#888; font-size:12px; white-space:nowrap;">
                                <?php echo esc_html( date('d M Y, h:i A', strtotime($row['created_at'])) ); ?>
                            </td>
                            <td style="white-space:nowrap;">
                              <button type="button" class="button button-small" onclick="var e=document.getElementById('edit-<?php echo (int)$row['id']; ?>');e.hidden=!e.hidden;">Edit</button>
                              <form method="post" style="display:inline" onsubmit="return confirm('Delete this lead permanently?');">
                                <?php wp_nonce_field('akdk_leads_delete_row_'.$active_form_id); ?>
                                <input type="hidden" name="form_tab" value="<?php echo esc_attr($active_form_id); ?>"><input type="hidden" name="akdk_leads_action" value="delete_row"><input type="hidden" name="lead_id" value="<?php echo (int)$row['id']; ?>"><button class="button button-small" style="color:#b32d2e;">Delete</button>
                              </form>
                            </td>
                        </tr>
                        <tr id="edit-<?php echo (int)$row['id']; ?>" hidden><td colspan="<?php echo count($field_cols)+3; ?>" style="padding:14px;background:#fff8eb;">
                        <form method="post">
                          <?php wp_nonce_field('akdk_leads_save_row_'.$active_form_id); ?>
                          <input type="hidden" name="form_tab" value="<?php echo esc_attr($active_form_id); ?>"><input type="hidden" name="akdk_leads_action" value="save_row"><input type="hidden" name="lead_id" value="<?php echo (int)$row['id']; ?>">
                          <?php foreach($field_cols as $col): ?><p><label><strong><?php echo esc_html(ucwords(str_replace('_',' ',$col))); ?></strong><br><textarea rows="2" style="width:100%;max-width:650px;" name="lead_fields[<?php echo esc_attr($col); ?>]"><?php echo esc_textarea(isset($row[$col])?$row[$col]:''); ?></textarea></label></p><?php endforeach; ?>
                          <button class="button button-primary">Update Lead</button> <button type="button" class="button" onclick="document.getElementById('edit-<?php echo (int)$row['id']; ?>').hidden=true">Cancel</button>
                        </form></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else : ?>
                <div style="padding:30px; text-align:center; background:#f9f9f9; border-radius:6px;">
                    <p style="color:#888; margin:0;">No leads yet for <strong><?php echo esc_html($active_info['form_title']); ?></strong>.</p>
                </div>
                <?php endif; ?>
                </div><!-- /lead rows panel -->
                </div><!-- /leads workspace -->

            <?php endif; ?>
            </div><!-- /panel -->
        </div><!-- /wrap -->
        <?php
    }
}
