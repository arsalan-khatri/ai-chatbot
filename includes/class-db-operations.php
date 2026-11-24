<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_AI_Chatbot_DB_Operations {
    private $table_name;
    private $option_fields;
    private $kb_id;

    public function __construct($kb_id) {
        global $wpdb;
        $this->kb_id = sanitize_key($kb_id);
        $this->table_name = $wpdb->prefix . 'ai_kb_' . $this->kb_id;
        $this->option_fields = 'wp_ai_kb_fields_' . $this->kb_id;
    }

    /**
     * Every mutating action below is only ever invoked from the admin page in
     * class-dynamic-db-manager.php, which already requires manage_options to
     * load, but the POST handlers had no CSRF nonce of their own, so a forged
     * cross-site request submitted by a logged-in admin's browser could still
     * reach DROP TABLE / TRUNCATE-equivalent actions. Require the same nonce
     * the admin page now renders on every form before doing anything.
     */
    private function verify_request() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You are not allowed to do this.' );
        }
        check_admin_referer( 'wp_ai_kb_action' );
    }

    public function get_all_data() {
        global $wpdb;
        return $wpdb->get_results("SHOW TABLES LIKE '{$this->table_name}'") ? $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY id DESC", ARRAY_A) : [];
    }

    /** Column names that can never be a knowledge base field. */
    const RESERVED = array( 'id', 'primary', 'key', 'index', 'select', 'insert', 'update', 'delete', 'drop', 'table', 'where', 'from', 'order', 'group', 'limit' );
    const MAX_FIELDS = 30; // VARCHAR(500) columns: about 30 fit in one MySQL row with utf8mb4.
    const CSV_MAX_BYTES = 10485760; // 10 MB.
    const CSV_MAX_ROWS  = 20000;

    /**
     * Validates submitted field names. Returns array( 'fields' => [...], 'errors' => [...] ).
     * Names are lower-case letters, digits and underscores, start with a letter,
     * at most 64 characters, not reserved, not duplicated.
     */
    public static function validate_fields( $raw, $existing = array() ) {
        $fields = array();
        $errors = array();
        if ( ! is_array( $raw ) ) {
            return array( 'fields' => array(), 'errors' => array( 'No fields were received.' ) );
        }
        foreach ( $raw as $value ) {
            if ( ! is_scalar( $value ) ) {
                $errors[] = 'A field name was not text.';
                continue;
            }
            $original = trim( wp_unslash( (string) $value ) );
            if ( '' === $original ) {
                $errors[] = 'A field name is empty.';
                continue;
            }
            // Fields that already exist keep their exact name (also older names made with sanitize_key).
            $legacy = sanitize_key( $original );
            if ( '' !== $legacy && 'id' !== $legacy && in_array( $legacy, (array) $existing, true ) ) {
                if ( in_array( $legacy, $fields, true ) ) {
                    $errors[] = sprintf( 'Field "%s" appears more than once.', $original );
                } else {
                    $fields[] = $legacy;
                }
                continue;
            }
            $name = strtolower( preg_replace( '/[\s\-]+/', '_', $original ) );
            if ( ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $name ) ) {
                $errors[] = sprintf( 'Field "%s" is not valid: use letters, numbers and underscores, start with a letter, 64 characters at most.', $original );
                continue;
            }
            if ( in_array( $name, self::RESERVED, true ) ) {
                $errors[] = sprintf( 'Field "%s" is a reserved name. Please choose another name.', $original );
                continue;
            }
            if ( in_array( $name, $fields, true ) ) {
                $errors[] = sprintf( 'Field "%s" appears more than once.', $original );
                continue;
            }
            $fields[] = $name;
        }
        // Existing knowledge bases that already have more fields keep working; only adding beyond the limit is refused.
        if ( count( $fields ) > max( self::MAX_FIELDS, count( array_diff( (array) $existing, array( 'id' ) ) ) ) ) {
            $errors[] = sprintf( 'At most %d fields are allowed (each field is a database column).', self::MAX_FIELDS );
        }
        return array( 'fields' => $fields, 'errors' => $errors );
    }

    /**
     * Saves the field list. Never drops the table (that is "Delete This KB").
     * Removing a field deletes that column's data, so it only happens when the
     * admin ticks the confirmation box; otherwise nothing is changed.
     */
    public function sync_schema() {
        $this->verify_request();
        $result = $this->apply_schema( isset( $_POST['fields'] ) ? $_POST['fields'] : null, ! empty( $_POST['confirm_drop_columns'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated in validate_fields().
        $this->redirect( $result['message'], $result['ok'] ? 'success' : 'error' );
    }

    /** @return array ok (bool), message (string), dropped (string[]) */
    public function apply_schema( $raw_fields, $confirm_drop ) {
        global $wpdb;
        if ( empty( $raw_fields ) || ! is_array( $raw_fields ) ) {
            return array( 'ok' => false, 'message' => 'No fields were submitted, so nothing was changed. To remove this whole knowledge base, use "Delete This KB".', 'dropped' => array() );
        }
        $before = array();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table_name ) ) === $this->table_name ) {
            $before = (array) $wpdb->get_col( "DESCRIBE {$this->table_name}", 0 );
        }
        $check = self::validate_fields( $raw_fields, $before );
        if ( $check['errors'] ) {
            return array( 'ok' => false, 'message' => 'Schema not saved: ' . implode( ' ', array_slice( $check['errors'], 0, 5 ) ), 'dropped' => array() );
        }
        $new = $check['fields'];

        if ( false === $wpdb->query( "CREATE TABLE IF NOT EXISTS {$this->table_name} (id mediumint(9) NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB " . $wpdb->get_charset_collate() ) ) {
            return array( 'ok' => false, 'message' => 'Schema not saved: the table could not be created (' . $wpdb->last_error . ').', 'dropped' => array() );
        }
        $existing = $wpdb->get_col( "DESCRIBE {$this->table_name}", 0 );
        if ( ! is_array( $existing ) || ! in_array( 'id', $existing, true ) ) {
            return array( 'ok' => false, 'message' => 'Schema not saved: the table could not be read.', 'dropped' => array() );
        }
        $old    = array_values( array_diff( $existing, array( 'id' ) ) );
        $add    = array_values( array_diff( $new, $old ) );
        $remove = array_values( array_diff( $old, $new ) );

        if ( $remove && ! $confirm_drop ) {
            return array(
                'ok'      => false,
                'message' => 'Schema not saved. Removing or renaming fields deletes the data stored in them: ' . implode( ', ', $remove ) . '. To continue, tick "I understand that removed fields lose their data" and save again.',
                'dropped' => array(),
            );
        }

        $done_add = array();
        foreach ( $add as $f ) {
            if ( false === $wpdb->query( "ALTER TABLE {$this->table_name} ADD COLUMN `{$f}` VARCHAR(500) DEFAULT NULL" ) ) {
                // Undo the columns added in this save, so the table matches the saved field list.
                foreach ( $done_add as $undo ) {
                    $wpdb->query( "ALTER TABLE {$this->table_name} DROP COLUMN `{$undo}`" );
                }
                $hint = false !== stripos( (string) $wpdb->last_error, 'row size' ) ? ' The table has too many fields for one database row; please use fewer fields.' : '';
                return array( 'ok' => false, 'message' => 'Schema not saved: field "' . $f . '" could not be added (' . $wpdb->last_error . ').' . $hint, 'dropped' => array() );
            }
            $done_add[] = $f;
        }
        $dropped = array();
        foreach ( $remove as $f ) {
            if ( false === $wpdb->query( "ALTER TABLE {$this->table_name} DROP COLUMN `{$f}`" ) ) {
                // Keep the field listed: its column (and data) still exist.
                $new[] = $f;
                continue;
            }
            $dropped[] = $f;
        }
        update_option( $this->option_fields, array_values( array_unique( $new ) ) );
        if ( $add || $dropped ) {
            do_action( 'wp_ai_kb_bulk_changed', $this->kb_id, 'reset' ); // Columns changed: rebuild this knowledge base's vectors.
        }
        $kept = array_diff( $remove, $dropped );
        if ( $kept ) {
            return array( 'ok' => false, 'message' => 'Schema partly saved: these fields could not be removed and were kept: ' . implode( ', ', $kept ) . '.', 'dropped' => $dropped );
        }
        return array( 'ok' => true, 'message' => 'Schema saved.' . ( $dropped ? ' Removed fields: ' . implode( ', ', $dropped ) . '.' : '' ), 'dropped' => $dropped );
    }

    public function handle_csv_upload() {
        $this->verify_request();
        $file = isset( $_FILES['product_csv'] ) && is_array( $_FILES['product_csv'] ) ? $_FILES['product_csv'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            $this->redirect( 'Import failed! No file received.', 'error' );
        }
        $result = $this->import_csv_file( $file['tmp_name'], isset( $file['size'] ) ? (int) $file['size'] : 0, isset( $file['error'] ) ? (int) $file['error'] : 0 );
        $this->redirect( $result['message'], $result['ok'] ? 'success' : 'error' );
    }

    /**
     * Imports a CSV file whose header row matches the saved fields.
     *
     * @return array ok, message, imported, duplicates, invalid, failed, skipped_over_limit
     */
    public function import_csv_file( $path, $size = 0, $upload_error = 0 ) {
        global $wpdb;
        $r = array( 'ok' => false, 'message' => '', 'imported' => 0, 'duplicates' => 0, 'invalid' => 0, 'failed' => 0, 'skipped_over_limit' => 0 );

        // Only columns that were explicitly saved via "Save Schema" are legal identifiers here;
        // CSV header text is never trusted as a column name.
        $allowed_fields = get_option( $this->option_fields, array() );
        $allowed_fields = is_array( $allowed_fields ) ? $allowed_fields : array();

        if ( 0 !== $upload_error ) {
            $r['message'] = 'Import failed! The upload did not complete (error code ' . $upload_error . ').';
            return $r;
        }
        $size = $size ? $size : ( is_file( $path ) ? (int) filesize( $path ) : 0 );
        if ( $size <= 0 ) {
            $r['message'] = 'Import failed! The file is empty.';
            return $r;
        }
        if ( $size > self::CSV_MAX_BYTES ) {
            $r['message'] = 'Import failed! The file is larger than 10 MB. Please split it into smaller files.';
            return $r;
        }
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table_name ) ) !== $this->table_name ) {
            $r['message'] = 'Import failed! Save the fields (schema) first.';
            return $r;
        }
        $h = fopen( $path, 'r' );
        if ( false === $h ) {
            $r['message'] = 'Import failed! The file could not be opened.';
            return $r;
        }
        $header = fgetcsv( $h, 0, ',', '"', '\\' );
        if ( ! is_array( $header ) || array( null ) === $header ) {
            fclose( $h );
            $r['message'] = 'Import failed! The file has no header row.';
            return $r;
        }
        $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] ); // Excel's UTF-8 marker.
        $headers   = array();
        foreach ( $header as $col ) {
            $plain = sanitize_key( trim( (string) $col ) );
            $under = sanitize_key( preg_replace( '/\s+/', '_', strtolower( trim( (string) $col ) ) ) );
            // "Product Name" matches the field product_name; older exact headers keep working.
            $headers[] = in_array( $plain, $allowed_fields, true ) ? $plain : $under;
        }
        if ( count( $headers ) !== count( array_unique( $headers ) ) ) {
            fclose( $h );
            $r['message'] = 'Import failed! CSV headers must be unique.';
            return $r;
        }
        foreach ( $headers as $h_name ) {
            if ( '' === $h_name || ! in_array( $h_name, $allowed_fields, true ) ) {
                fclose( $h );
                $r['message'] = "Import failed! Unknown column '{$h_name}'. Headers must match the saved schema fields exactly.";
                return $r;
            }
        }

        $rows_seen = 0;
        while ( false !== ( $data = fgetcsv( $h, 0, ',', '"', '\\' ) ) ) {
            if ( array( null ) === $data ) {
                continue; // Blank line.
            }
            if ( ++$rows_seen > self::CSV_MAX_ROWS ) {
                $r['skipped_over_limit']++;
                continue;
            }
            if ( count( $headers ) !== count( $data ) ) {
                $r['invalid']++;
                continue;
            }
            $row = array();
            foreach ( $headers as $i => $col ) {
                $v = (string) $data[ $i ];
                if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $v, 'UTF-8' ) ) {
                    $v = mb_convert_encoding( $v, 'UTF-8', 'Windows-1252' ); // Files saved as "CSV" (not "CSV UTF-8") by Excel.
                }
                $v           = sanitize_text_field( $v );
                $row[ $col ] = function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 500 ) : substr( $v, 0, 500 );
            }
            if ( '' === implode( '', $row ) ) {
                $r['invalid']++;
                continue;
            }
            $where = array();
            $vals  = array();
            foreach ( $row as $k => $v ) {
                $where[] = "`$k` = %s";
                $vals[]  = $v;
            }
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table_name} WHERE " . implode( ' AND ', $where ), $vals ) );
            if ( null === $exists && '' !== $wpdb->last_error ) {
                $r['failed']++;
                continue;
            }
            if ( (int) $exists > 0 ) {
                $r['duplicates']++;
                continue;
            }
            if ( false === $wpdb->insert( $this->table_name, $row ) ) {
                $r['failed']++;
                continue;
            }
            $r['imported']++;
        }
        fclose( $h );
        if ( $r['imported'] ) {
            do_action( 'wp_ai_kb_bulk_changed', $this->kb_id, 'added' );
        }
        $r['ok']      = 0 === $r['failed'] && 0 === $r['skipped_over_limit'] && ( $r['imported'] > 0 || $r['duplicates'] > 0 );
        $r['message'] = sprintf( 'Imported: %d, Skipped duplicates: %d', $r['imported'], $r['duplicates'] )
            . ( $r['invalid'] ? sprintf( ', Invalid rows (wrong number of columns or empty): %d', $r['invalid'] ) : '' )
            . ( $r['failed'] ? sprintf( ', Failed to save: %d', $r['failed'] ) : '' )
            . ( $r['skipped_over_limit'] ? sprintf( ', Not imported (over the %d row limit): %d', self::CSV_MAX_ROWS, $r['skipped_over_limit'] ) : '' )
            . ( $r['ok'] ? '' : ( $r['imported'] ? '. Import was only partly completed.' : '. Nothing was imported.' ) );
        return $r;
    }

    public function update_row() {
        $this->verify_request();
        global $wpdb;
        $id = intval($_POST['update_row']);
        if ( empty($_POST['row_update'][$id]) || ! is_array($_POST['row_update'][$id]) ) {
            $this->redirect("Nothing to update.", "error");
        }
        $allowed_fields = get_option($this->option_fields, []);
        $data = array();
        foreach ($_POST['row_update'][$id] as $col => $val) {
            $col = sanitize_key($col);
            if ( ! in_array($col, $allowed_fields, true) ) continue; // ignore anything not a real column
            $data[$col] = sanitize_text_field($val);
        }
        if ( empty($data) ) {
            $this->redirect("No valid fields to update.", "error");
        }
        $wpdb->update($this->table_name, $data, ['id' => $id]);
        do_action('wp_ai_kb_row_changed', $this->kb_id, $id, 'update');
        $this->redirect("Row Updated!");
    }

    public function add_new_row() {
        $this->verify_request();
        global $wpdb;
        if (!empty($_POST['new_row'])) {
            $allowed_fields = get_option($this->option_fields, []);
            $data = array();
            foreach ($_POST['new_row'] as $col => $val) {
                $col = sanitize_key($col);
                if ( ! in_array($col, $allowed_fields, true) ) continue;
                $data[$col] = sanitize_text_field($val);
            }
            
            $is_empty = true;
            foreach ($data as $val) {
                if (trim($val) !== '') {
                    $is_empty = false;
                    break;
                }
            }
            
            if (!$is_empty) {
                $wpdb->insert($this->table_name, $data);
                do_action('wp_ai_kb_row_changed', $this->kb_id, (int) $wpdb->insert_id, 'add');
                $this->redirect("New line added successfully!");
            } else {
                $this->redirect("Please fill at least one field to add a new line.", "error");
            }
        }
        $this->redirect("Failed to add line.", "error");
    }

    public function delete_single_row() {
        $this->verify_request();
        global $wpdb;
        if ( !empty($_POST['delete_single_row']) ) {
            $id = intval($_POST['delete_single_row']);
            $wpdb->delete($this->table_name, ['id' => $id]);
            do_action('wp_ai_kb_row_changed', $this->kb_id, $id, 'delete');
            $this->redirect("Row deleted successfully!");
        }
        $this->redirect("Failed to delete row.", "error");
    }

    public function delete_rows() {
        $this->verify_request();
        global $wpdb;
        if ( !empty($_POST['bulk_ids']) ) {
            $ids = implode(',', array_map('intval', $_POST['bulk_ids']));
            $wpdb->query("DELETE FROM {$this->table_name} WHERE id IN ($ids)");
            foreach (array_map('intval', $_POST['bulk_ids']) as $deleted_id) {
                do_action('wp_ai_kb_row_changed', $this->kb_id, $deleted_id, 'delete');
            }
            $this->redirect("Selected rows deleted!");
        }
        $this->redirect("Select rows first!", "error");
    }

    private function redirect($msg, $status = 'success') {
        echo '<script>window.location.href = "' . esc_url_raw( admin_url('admin.php?page=wp-ai-chatbot-db&kb_id='.$this->kb_id.'&message='.urlencode($msg).'&status='.$status) ) . '";</script>';
        exit;
    }
}