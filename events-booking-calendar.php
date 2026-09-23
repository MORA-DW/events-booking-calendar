<?php
/**
 * Plugin Name: Events Booking Calendar
 * Description: Kalendarz wydarzeń z rezerwacją miejsc — zarządzanie salami, markami, rezerwacjami
 * Version:     2.2.0
 * Author:      Daniel Wojtala (MORA)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'EBC_VERSION',    '2.2.0' );
define( 'EBC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EBC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Register query vars so caching plugins and WP don't strip them
add_filter( 'query_vars', function( $qv ) {
    $qv[] = 'ebc_month';
    $qv[] = 'ebc_brand';
    $qv[] = 'ebc_ics';
    return $qv;
} );

// ─── ICS DOWNLOAD ENDPOINT ───────────────────────────────────────────────────

add_action( 'template_redirect', 'ebc_maybe_serve_ics' );
function ebc_maybe_serve_ics() {
    $event_id = (int) get_query_var( 'ebc_ics' );
    if ( ! $event_id ) return;

    $event = get_post( $event_id );
    if ( ! $event || $event->post_type !== 'ebc_event' ) return;

    $date = get_post_meta( $event_id, '_ebc_date',       true );
    $ts   = get_post_meta( $event_id, '_ebc_time_start', true ) ?: '09:00';
    $te   = get_post_meta( $event_id, '_ebc_time_end',   true );
    $rid  = get_post_meta( $event_id, '_ebc_room_id',    true );
    $room = $rid ? ebc_get_room( $rid ) : null;

    if ( ! $date ) wp_die( 'Brak daty wydarzenia.' );

    $tz       = get_option( 'timezone_string' ) ?: 'Europe/Warsaw';
    $dt_start = new DateTime( "{$date}T{$ts}", new DateTimeZone( $tz ) );
    $dt_end   = $te ? new DateTime( "{$date}T{$te}", new DateTimeZone( $tz ) ) : ( clone $dt_start )->modify( '+1 hour' );

    $dt_start->setTimezone( new DateTimeZone( 'UTC' ) );
    $dt_end->setTimezone(   new DateTimeZone( 'UTC' ) );

    $uid     = 'ebc-' . $event_id . '@' . parse_url( home_url(), PHP_URL_HOST );
    $summary = ebc_ics_escape( get_the_title( $event_id ) );
    $loc     = $room ? ebc_ics_escape( $room->name . ( $room->address ? ', ' . $room->address : '' ) ) : '';
    $desc    = ebc_ics_escape( get_the_excerpt( $event_id ) );
    $now     = gmdate( 'Ymd\THis\Z' );

    $ics  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Events Booking Calendar//EN\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n";
    $ics .= "BEGIN:VEVENT\r\n";
    $ics .= "UID:{$uid}\r\n";
    $ics .= "DTSTAMP:{$now}\r\n";
    $ics .= "DTSTART:{$dt_start->format('Ymd\THis\Z')}\r\n";
    $ics .= "DTEND:{$dt_end->format('Ymd\THis\Z')}\r\n";
    $ics .= "SUMMARY:{$summary}\r\n";
    if ( $loc )  $ics .= "LOCATION:{$loc}\r\n";
    if ( $desc ) $ics .= "DESCRIPTION:{$desc}\r\n";
    $ics .= "END:VEVENT\r\nEND:VCALENDAR\r\n";

    $filename = sanitize_file_name( get_the_title( $event_id ) ) ?: 'wydarzenie';
    header( 'Content-Type: text/calendar; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '.ics"' );
    header( 'Cache-Control: no-cache, must-revalidate' );
    echo $ics;
    exit;
}

function ebc_ics_escape( $str ) {
    $str = str_replace( ['\\', ';', ','], ['\\\\', '\\;', '\\,'], $str );
    return str_replace( ["\r\n", "\r", "\n"], '\\n', $str );
}

function ebc_google_calendar_url( $event_id ) {
    $date = get_post_meta( $event_id, '_ebc_date',       true );
    $ts   = get_post_meta( $event_id, '_ebc_time_start', true ) ?: '09:00';
    $te   = get_post_meta( $event_id, '_ebc_time_end',   true );
    $rid  = get_post_meta( $event_id, '_ebc_room_id',    true );
    $room = $rid ? ebc_get_room( $rid ) : null;

    if ( ! $date ) return '';

    $tz       = get_option( 'timezone_string' ) ?: 'Europe/Warsaw';
    $dt_start = new DateTime( "{$date}T{$ts}", new DateTimeZone( $tz ) );
    $dt_end   = $te ? new DateTime( "{$date}T{$te}", new DateTimeZone( $tz ) ) : ( clone $dt_start )->modify( '+1 hour' );

    $dt_start->setTimezone( new DateTimeZone( 'UTC' ) );
    $dt_end->setTimezone(   new DateTimeZone( 'UTC' ) );

    return 'https://www.google.com/calendar/render?' . http_build_query( [
        'action'   => 'TEMPLATE',
        'text'     => get_the_title( $event_id ),
        'dates'    => $dt_start->format( 'Ymd\THis\Z' ) . '/' . $dt_end->format( 'Ymd\THis\Z' ),
        'location' => $room ? $room->name . ( $room->address ? ', ' . $room->address : '' ) : '',
        'details'  => get_the_excerpt( $event_id ),
    ] );
}

// ─── ACTIVATION & UPGRADE ────────────────────────────────────────────────────

register_activation_hook( __FILE__, 'ebc_activate' );
add_action( 'plugins_loaded', 'ebc_maybe_upgrade' );

function ebc_maybe_upgrade() {
    if ( get_option('ebc_db_version') === EBC_VERSION ) return;
    global $wpdb;
    // guests column (added in 2.1.x)
    $col = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}ebc_bookings LIKE 'guests'" );
    if ( empty( $col ) ) {
        $wpdb->query( "ALTER TABLE {$wpdb->prefix}ebc_bookings ADD COLUMN guests text DEFAULT '' AFTER notes" );
    }
    // waitlist table (added in 2.2.0)
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ebc_waitlist (
        id         bigint(20)   NOT NULL AUTO_INCREMENT,
        event_id   bigint(20)   NOT NULL,
        first_name varchar(100) NOT NULL,
        last_name  varchar(100) NOT NULL,
        email      varchar(200) NOT NULL,
        notified   tinyint(1)   NOT NULL DEFAULT 0,
        created_at datetime     DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY event_id (event_id)
    ) {$charset};" );
    update_option( 'ebc_db_version', EBC_VERSION );
}

function ebc_activate() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ebc_bookings (
        id         bigint(20)   NOT NULL AUTO_INCREMENT,
        event_id   bigint(20)   NOT NULL,
        first_name varchar(100) NOT NULL,
        last_name  varchar(100) NOT NULL,
        email      varchar(200) NOT NULL,
        phone      varchar(50)  DEFAULT '',
        company    varchar(200) DEFAULT '',
        seats      int(11)      NOT NULL DEFAULT 1,
        status     varchar(20)  NOT NULL DEFAULT 'pending',
        notes      text         DEFAULT '',
        guests     text         DEFAULT '',
        created_at datetime     DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY event_id (event_id)
    ) {$charset};" );

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ebc_rooms (
        id          int(11)      NOT NULL AUTO_INCREMENT,
        name        varchar(200) NOT NULL,
        description text         DEFAULT '',
        address     varchar(500) DEFAULT '',
        max_seats   int(11)      DEFAULT 20,
        sort_order  int(11)      DEFAULT 0,
        PRIMARY KEY (id)
    ) {$charset};" );

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ebc_waitlist (
        id         bigint(20)   NOT NULL AUTO_INCREMENT,
        event_id   bigint(20)   NOT NULL,
        first_name varchar(100) NOT NULL,
        last_name  varchar(100) NOT NULL,
        email      varchar(200) NOT NULL,
        notified   tinyint(1)   NOT NULL DEFAULT 0,
        created_at datetime     DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY event_id (event_id)
    ) {$charset};" );

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ebc_brands (
        id         int(11)      NOT NULL AUTO_INCREMENT,
        name       varchar(200) NOT NULL,
        color      varchar(20)  DEFAULT '#2563eb',
        logo_url   varchar(500) DEFAULT '',
        sort_order int(11)      DEFAULT 0,
        PRIMARY KEY (id)
    ) {$charset};" );

    // Insert defaults on first install
    $rc = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ebc_rooms" );
    if ( ! $rc ) {
        foreach ( [
            ['Experience Room',1], ['Showroom Shure',2], ['DiGiCo',3], ['Meyer',4],
        ] as $r ) {
            $wpdb->insert( "{$wpdb->prefix}ebc_rooms", ['name'=>$r[0],'max_seats'=>20,'sort_order'=>$r[1]] );
        }
    }
    $bc = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ebc_brands" );
    if ( ! $bc ) {
        foreach ( [
            ['Meyer Sound','#e63946',1], ['DiGiCo','#2563eb',2], ['Shure','#16a34a',3],
        ] as $b ) {
            $wpdb->insert( "{$wpdb->prefix}ebc_brands", ['name'=>$b[0],'color'=>$b[1],'sort_order'=>$b[2]] );
        }
    }

    add_option( 'ebc_admin_emails',   get_option('admin_email') );
    add_option( 'ebc_email_from',     get_option('admin_email') );
    add_option( 'ebc_email_from_name',get_option('blogname') );
    add_option( 'ebc_logo_url', 'https://polsound.pl/wp-content/uploads/2024/04/polsound_logo_header_comp_new.png' );
    update_option( 'ebc_db_version', EBC_VERSION );
}

// Run dbDelta on upgrade
add_action( 'plugins_loaded', function() {
    if ( get_option('ebc_db_version') !== EBC_VERSION ) ebc_activate();
} );

// ─── DB HELPERS ───────────────────────────────────────────────────────────────

function ebc_get_rooms() {
    global $wpdb;
    return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ebc_rooms ORDER BY sort_order ASC, name ASC" );
}

function ebc_get_brands() {
    global $wpdb;
    return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ebc_brands ORDER BY sort_order ASC, name ASC" );
}

function ebc_get_room( $id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ebc_rooms WHERE id=%d", $id ) );
}

function ebc_get_brand( $id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ebc_brands WHERE id=%d", $id ) );
}

function ebc_rooms_map() {
    $map = [];
    foreach ( ebc_get_rooms() as $r ) $map[$r->id] = $r->name;
    return $map;
}

function ebc_brands_map() {
    $map = [];
    foreach ( ebc_get_brands() as $b ) $map[$b->id] = $b->name;
    return $map;
}

function ebc_brand_color( $brand_id ) {
    $b = ebc_get_brand( $brand_id );
    return $b ? $b->color : '#888';
}

function ebc_get_booked_seats( $event_id ) {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(seats),0) FROM {$wpdb->prefix}ebc_bookings WHERE event_id=%d AND status!='cancelled'",
        $event_id
    ) );
}

function ebc_get_available_seats( $event_id ) {
    $max    = (int) get_post_meta( $event_id, '_ebc_max_seats', true );
    $booked = ebc_get_booked_seats( $event_id );
    return max( 0, $max - $booked );
}

function ebc_polish_month( $m ) {
    return ['','Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec',
            'Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'][(int)$m] ?? '';
}

function ebc_get_admin_emails() {
    $raw = get_option( 'ebc_admin_emails', get_option('admin_email') );
    $emails = array_filter( array_map( 'trim', preg_split('/[\r\n,]+/', $raw) ), 'is_email' );
    return array_values( $emails );
}

// ─── CPT ─────────────────────────────────────────────────────────────────────

add_action( 'init', 'ebc_register_cpt' );
function ebc_register_cpt() {
    register_post_type( 'ebc_event', [
        'labels'       => [
            'name'=>'Wydarzenia','singular_name'=>'Wydarzenie','add_new'=>'Dodaj nowe',
            'add_new_item'=>'Dodaj nowe wydarzenie','edit_item'=>'Edytuj wydarzenie',
            'all_items'=>'Wszystkie wydarzenia','search_items'=>'Szukaj wydarzeń',
        ],
        'public'        => true,
        'has_archive'   => false,
        'menu_icon'     => 'dashicons-calendar-alt',
        'menu_position' => 5,
        'supports'      => ['title','editor','excerpt','thumbnail'],
        'show_in_rest'  => true,
    ] );
}

// ─── META BOX ────────────────────────────────────────────────────────────────

add_action( 'add_meta_boxes', function() {
    add_meta_box( 'ebc_event_details', 'Szczegóły wydarzenia', 'ebc_event_details_cb', 'ebc_event', 'normal', 'high' );
} );

function ebc_event_details_cb( $post ) {
    wp_nonce_field( 'ebc_save_event', 'ebc_nonce' );
    $date      = get_post_meta( $post->ID, '_ebc_date',       true );
    $ts        = get_post_meta( $post->ID, '_ebc_time_start', true );
    $te        = get_post_meta( $post->ID, '_ebc_time_end',   true );
    $room_id   = get_post_meta( $post->ID, '_ebc_room_id',    true );
    $brand_id  = get_post_meta( $post->ID, '_ebc_brand_id',   true );
    $max_seats = get_post_meta( $post->ID, '_ebc_max_seats',  true ) ?: 20;
    $rooms     = ebc_get_rooms();
    $brands    = ebc_get_brands();
    ?>
    <table class="form-table">
        <tr><th><label for="ebc_date">Data *</label></th>
            <td><input type="date" id="ebc_date" name="ebc_date" value="<?php echo esc_attr($date); ?>" required class="regular-text"></td></tr>
        <tr><th><label for="ebc_time_start">Godzina rozpoczęcia *</label></th>
            <td><input type="time" id="ebc_time_start" name="ebc_time_start" value="<?php echo esc_attr($ts); ?>" required></td></tr>
        <tr><th><label for="ebc_time_end">Godzina zakończenia</label></th>
            <td><input type="time" id="ebc_time_end" name="ebc_time_end" value="<?php echo esc_attr($te); ?>"></td></tr>
        <tr><th><label for="ebc_room_id">Sala *</label></th>
            <td><select id="ebc_room_id" name="ebc_room_id" required>
                <option value="">-- wybierz salę --</option>
                <?php foreach ($rooms as $r): ?>
                    <option value="<?php echo $r->id; ?>" <?php selected($room_id,$r->id); ?>><?php echo esc_html($r->name); ?></option>
                <?php endforeach; ?>
                </select>
                <p class="description"><a href="<?php echo admin_url('edit.php?post_type=ebc_event&page=ebc-rooms'); ?>">Zarządzaj salami →</a></p>
            </td></tr>
        <tr><th><label for="ebc_brand_id">Marka *</label></th>
            <td><select id="ebc_brand_id" name="ebc_brand_id" required>
                <option value="">-- wybierz markę --</option>
                <?php foreach ($brands as $b): ?>
                    <option value="<?php echo $b->id; ?>" <?php selected($brand_id,$b->id); ?>><?php echo esc_html($b->name); ?></option>
                <?php endforeach; ?>
                </select>
                <p class="description"><a href="<?php echo admin_url('edit.php?post_type=ebc_event&page=ebc-brands'); ?>">Zarządzaj markami →</a></p>
            </td></tr>
        <tr><th><label for="ebc_max_seats">Maks. liczba miejsc *</label></th>
            <td><input type="number" id="ebc_max_seats" name="ebc_max_seats" value="<?php echo esc_attr($max_seats); ?>" min="1" max="9999" class="small-text">
                <p class="description">Domyślna wartość pobierana z ustawień sali. Możesz nadpisać dla tego wydarzenia.</p>
            </td></tr>
    </table>
    <?php
}

add_action( 'save_post_ebc_event', function( $post_id ) {
    if ( ! isset($_POST['ebc_nonce']) || ! wp_verify_nonce($_POST['ebc_nonce'],'ebc_save_event') ) return;
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( ! current_user_can('edit_post',$post_id) ) return;
    foreach ( ['date','time_start','time_end','room_id','brand_id','max_seats'] as $f ) {
        if ( isset($_POST['ebc_'.$f]) )
            update_post_meta( $post_id, '_ebc_'.$f, sanitize_text_field($_POST['ebc_'.$f]) );
    }
    // Auto-fill max_seats from room if not set
    if ( empty($_POST['ebc_max_seats']) && ! empty($_POST['ebc_room_id']) ) {
        $room = ebc_get_room( (int)$_POST['ebc_room_id'] );
        if ($room) update_post_meta( $post_id, '_ebc_max_seats', $room->max_seats );
    }
} );

// ─── ADMIN COLUMNS ───────────────────────────────────────────────────────────

add_filter( 'manage_ebc_event_posts_columns', function( $cols ) {
    $new = [];
    foreach ( $cols as $k => $v ) {
        $new[$k] = $v;
        if ( $k === 'title' ) {
            $new['ebc_date']  = 'Data';
            $new['ebc_room']  = 'Sala';
            $new['ebc_brand'] = 'Marka';
            $new['ebc_seats'] = 'Miejsca';
        }
    }
    return $new;
} );

add_action( 'manage_ebc_event_posts_custom_column', function( $col, $post_id ) {
    switch ($col) {
        case 'ebc_date':
            $d  = get_post_meta($post_id,'_ebc_date',true);
            $ts = get_post_meta($post_id,'_ebc_time_start',true);
            echo $d ? date('d.m.Y',strtotime($d)).($ts?" $ts":'') : '—';
            break;
        case 'ebc_room':
            $rid = get_post_meta($post_id,'_ebc_room_id',true);
            $r   = $rid ? ebc_get_room($rid) : null;
            echo $r ? esc_html($r->name) : '—';
            break;
        case 'ebc_brand':
            $bid   = get_post_meta($post_id,'_ebc_brand_id',true);
            $brand = $bid ? ebc_get_brand($bid) : null;
            if ($brand) {
                echo '<span style="display:inline-flex;align-items:center;gap:6px">';
                echo '<span style="width:10px;height:10px;border-radius:50%;background:'.esc_attr($brand->color).';display:inline-block"></span>';
                echo esc_html($brand->name).'</span>';
            } else echo '—';
            break;
        case 'ebc_seats':
            $max    = (int)get_post_meta($post_id,'_ebc_max_seats',true);
            $booked = ebc_get_booked_seats($post_id);
            $avail  = max(0,$max-$booked);
            echo "<strong>{$booked}/{$max}</strong> <span style='color:#16a34a'>({$avail} wol.)</span>";
            break;
    }
}, 10, 2 );

// ─── ADMIN MENUS ─────────────────────────────────────────────────────────────

add_action( 'admin_menu', function() {
    add_submenu_page('edit.php?post_type=ebc_event','Sale','Sale','manage_options','ebc-rooms','ebc_rooms_page');
    add_submenu_page('edit.php?post_type=ebc_event','Marki','Marki','manage_options','ebc-brands','ebc_brands_page');
    add_submenu_page('edit.php?post_type=ebc_event','Rezerwacje','Rezerwacje','manage_options','ebc-bookings','ebc_bookings_page');
    add_submenu_page('edit.php?post_type=ebc_event','Lista oczekujących','Lista oczekujących','manage_options','ebc-waitlist','ebc_waitlist_page');
    add_submenu_page('edit.php?post_type=ebc_event','Ustawienia','Ustawienia','manage_options','ebc-settings','ebc_settings_page');
} );

// ─── ADMIN ASSETS ────────────────────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( strpos($hook,'ebc') === false && get_current_screen()->post_type !== 'ebc_event' ) return;
    wp_enqueue_style( 'wp-color-picker' );
    wp_enqueue_media();
    wp_enqueue_style( 'ebc-admin', EBC_PLUGIN_URL.'assets/admin.css', [], EBC_VERSION );
    wp_enqueue_script( 'ebc-admin', EBC_PLUGIN_URL.'assets/admin.js', ['jquery','wp-color-picker'], EBC_VERSION, true );
} );

// ─── AJAX: GET FRESH SEAT COUNTS (bypasses page cache) ───────────────────────

add_action('wp_ajax_nopriv_ebc_get_seats','ebc_ajax_get_seats');
add_action('wp_ajax_ebc_get_seats',       'ebc_ajax_get_seats');
function ebc_ajax_get_seats() {
    check_ajax_referer('ebc_booking','nonce');
    global $wpdb;
    $ids    = array_map('intval', (array)($_POST['ids']??[]));
    $result = [];
    foreach ( $ids as $id ) {
        if ( ! $id ) continue;
        $max      = (int)get_post_meta($id,'_ebc_max_seats',true);
        $avail    = ebc_get_available_seats($id);
        $waitlist = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ebc_waitlist WHERE event_id=%d AND notified=0", $id
        ));
        $result[$id] = ['avail'=>$avail,'max'=>$max,'waitlist'=>$waitlist];
    }
    wp_send_json_success($result);
}

// ─── ROOMS PAGE ──────────────────────────────────────────────────────────────

function ebc_rooms_page() {
    global $wpdb;
    $table  = $wpdb->prefix . 'ebc_rooms';
    $action = sanitize_text_field( $_GET['action'] ?? 'list' );
    $eid    = (int)( $_GET['id'] ?? 0 );

    // Save
    if ( isset($_POST['ebc_save_room']) && wp_verify_nonce($_POST['ebc_room_nonce'],'ebc_room') ) {
        $data = [
            'name'        => sanitize_text_field($_POST['room_name']),
            'description' => sanitize_textarea_field($_POST['room_description']),
            'address'     => sanitize_text_field($_POST['room_address']),
            'max_seats'   => max(1,(int)$_POST['room_max_seats']),
            'sort_order'  => (int)$_POST['room_sort_order'],
        ];
        if ( $eid ) { $wpdb->update($table,$data,['id'=>$eid]); $msg = 'Sala zaktualizowana.'; }
        else        { $wpdb->insert($table,$data); $msg = 'Sala dodana.'; }
        echo '<div class="notice notice-success is-dismissible"><p>'.$msg.'</p></div>';
        $action = 'list'; $eid = 0;
    }

    // Delete
    if ( $action==='delete' && $eid && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'],'ebc_del_room_'.$eid) ) {
        $wpdb->delete($table,['id'=>$eid]);
        echo '<div class="notice notice-success is-dismissible"><p>Sala usunięta.</p></div>';
        $action = 'list'; $eid = 0;
    }

    $edit_room = ($action==='edit' && $eid) ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$eid)) : null;
    $rooms     = $wpdb->get_results("SELECT * FROM $table ORDER BY sort_order ASC, name ASC");
    ?>
    <div class="wrap">
        <h1><?php echo $edit_room ? 'Edytuj salę' : 'Sale'; ?></h1>

        <!-- List -->
        <?php if (!$edit_room): ?>
        <table class="wp-list-table widefat fixed striped" style="max-width:900px">
            <thead><tr><th>Nazwa</th><th>Adres</th><th>Maks. miejsc</th><th>Kolejność</th><th>Akcje</th></tr></thead>
            <tbody>
            <?php if (empty($rooms)): ?><tr><td colspan="5">Brak sal. Dodaj pierwszą poniżej.</td></tr><?php endif; ?>
            <?php foreach ($rooms as $r): ?>
                <tr>
                    <td><strong><?php echo esc_html($r->name); ?></strong><?php if($r->description) echo '<br><small>'.esc_html($r->description).'</small>'; ?></td>
                    <td><?php echo esc_html($r->address) ?: '—'; ?></td>
                    <td><?php echo $r->max_seats; ?></td>
                    <td><?php echo $r->sort_order; ?></td>
                    <td>
                        <a href="<?php echo admin_url('edit.php?post_type=ebc_event&page=ebc-rooms&action=edit&id='.$r->id); ?>">Edytuj</a>
                        &nbsp;|&nbsp;
                        <a href="<?php echo wp_nonce_url(admin_url('edit.php?post_type=ebc_event&page=ebc-rooms&action=delete&id='.$r->id),'ebc_del_room_'.$r->id); ?>"
                           onclick="return confirm('Na pewno usunąć tę salę?')" style="color:#d63638">Usuń</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <br>
        <?php endif; ?>

        <!-- Add / Edit Form -->
        <div class="ebc-form-box" style="max-width:620px">
            <h2><?php echo $edit_room ? 'Edytuj salę: '.esc_html($edit_room->name) : 'Dodaj nową salę'; ?></h2>
            <form method="post">
                <?php wp_nonce_field('ebc_room','ebc_room_nonce'); ?>
                <table class="form-table">
                    <tr><th><label>Nazwa *</label></th>
                        <td><input type="text" name="room_name" value="<?php echo esc_attr($edit_room->name??''); ?>" required class="regular-text"></td></tr>
                    <tr><th><label>Opis</label></th>
                        <td><textarea name="room_description" rows="3" class="large-text"><?php echo esc_textarea($edit_room->description??''); ?></textarea></td></tr>
                    <tr><th><label>Adres / lokalizacja</label></th>
                        <td><input type="text" name="room_address" value="<?php echo esc_attr($edit_room->address??''); ?>" class="large-text"></td></tr>
                    <tr><th><label>Domyślna maks. liczba miejsc *</label></th>
                        <td><input type="number" name="room_max_seats" value="<?php echo esc_attr($edit_room->max_seats??20); ?>" min="1" class="small-text"></td></tr>
                    <tr><th><label>Kolejność wyświetlania</label></th>
                        <td><input type="number" name="room_sort_order" value="<?php echo esc_attr($edit_room->sort_order??0); ?>" class="small-text"></td></tr>
                </table>
                <?php if ($eid): ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?php echo $eid; ?>"><?php endif; ?>
                <?php submit_button($edit_room?'Zapisz zmiany':'Dodaj salę','primary','ebc_save_room'); ?>
                <?php if ($edit_room): ?><a href="<?php echo admin_url('edit.php?post_type=ebc_event&page=ebc-rooms'); ?>" class="button">Anuluj</a><?php endif; ?>
            </form>
        </div>
    </div>
    <?php
}

// ─── BRANDS PAGE ─────────────────────────────────────────────────────────────

function ebc_brands_page() {
    global $wpdb;
    $table  = $wpdb->prefix . 'ebc_brands';
    $action = sanitize_text_field( $_GET['action'] ?? 'list' );
    $eid    = (int)( $_GET['id'] ?? 0 );

    if ( isset($_POST['ebc_save_brand']) && wp_verify_nonce($_POST['ebc_brand_nonce'],'ebc_brand') ) {
        $data = [
            'name'       => sanitize_text_field($_POST['brand_name']),
            'color'      => sanitize_hex_color($_POST['brand_color']) ?: '#2563eb',
            'logo_url'   => esc_url_raw($_POST['brand_logo_url']),
            'sort_order' => (int)$_POST['brand_sort_order'],
        ];
        if ($eid) { $wpdb->update($table,$data,['id'=>$eid]); $msg='Marka zaktualizowana.'; }
        else      { $wpdb->insert($table,$data); $msg='Marka dodana.'; }
        echo '<div class="notice notice-success is-dismissible"><p>'.$msg.'</p></div>';
        $action='list'; $eid=0;
    }

    if ($action==='delete' && $eid && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'],'ebc_del_brand_'.$eid)) {
        $wpdb->delete($table,['id'=>$eid]);
        echo '<div class="notice notice-success is-dismissible"><p>Marka usunięta.</p></div>';
        $action='list'; $eid=0;
    }

    $edit_brand = ($action==='edit' && $eid) ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$eid)) : null;
    $brands     = $wpdb->get_results("SELECT * FROM $table ORDER BY sort_order ASC, name ASC");
    ?>
    <div class="wrap">
        <h1><?php echo $edit_brand ? 'Edytuj markę' : 'Marki'; ?></h1>

        <?php if (!$edit_brand): ?>
        <table class="wp-list-table widefat fixed striped" style="max-width:800px">
            <thead><tr><th>Marka</th><th>Kolor</th><th>Logo</th><th>Kolejność</th><th>Akcje</th></tr></thead>
            <tbody>
            <?php if (empty($brands)): ?><tr><td colspan="5">Brak marek.</td></tr><?php endif; ?>
            <?php foreach ($brands as $b): ?>
                <tr>
                    <td><strong><?php echo esc_html($b->name); ?></strong></td>
                    <td><span style="display:inline-flex;align-items:center;gap:8px">
                        <span style="width:20px;height:20px;border-radius:4px;background:<?php echo esc_attr($b->color); ?>;display:inline-block;border:1px solid #ccc"></span>
                        <?php echo esc_html($b->color); ?>
                    </span></td>
                    <td><?php if($b->logo_url): ?><img src="<?php echo esc_url($b->logo_url); ?>" style="max-height:30px;max-width:80px"><?php else: ?>—<?php endif; ?></td>
                    <td><?php echo $b->sort_order; ?></td>
                    <td>
                        <a href="<?php echo admin_url('edit.php?post_type=ebc_event&page=ebc-brands&action=edit&id='.$b->id); ?>">Edytuj</a>
                        &nbsp;|&nbsp;
                        <a href="<?php echo wp_nonce_url(admin_url('edit.php?post_type=ebc_event&page=ebc-brands&action=delete&id='.$b->id),'ebc_del_brand_'.$b->id); ?>"
                           onclick="return confirm('Na pewno usunąć tę markę?')" style="color:#d63638">Usuń</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <br>
        <?php endif; ?>

        <div class="ebc-form-box" style="max-width:580px">
            <h2><?php echo $edit_brand ? 'Edytuj markę: '.esc_html($edit_brand->name) : 'Dodaj nową markę'; ?></h2>
            <form method="post">
                <?php wp_nonce_field('ebc_brand','ebc_brand_nonce'); ?>
                <table class="form-table">
                    <tr><th><label>Nazwa *</label></th>
                        <td><input type="text" name="brand_name" value="<?php echo esc_attr($edit_brand->name??''); ?>" required class="regular-text"></td></tr>
                    <tr><th><label>Kolor</label></th>
                        <td><input type="text" name="brand_color" value="<?php echo esc_attr($edit_brand->color??'#2563eb'); ?>" class="ebc-color-picker"></td></tr>
                    <tr><th><label>Logo</label></th>
                        <td>
                            <div style="display:flex;gap:8px;align-items:center">
                                <input type="text" name="brand_logo_url" id="brand_logo_url" value="<?php echo esc_attr($edit_brand->logo_url??''); ?>" class="large-text ebc-logo-url-input" placeholder="https://...">
                                <button type="button" class="button ebc-upload-logo">Wybierz z biblioteki</button>
                            </div>
                            <?php if(!empty($edit_brand->logo_url)): ?>
                                <img src="<?php echo esc_url($edit_brand->logo_url); ?>" class="ebc-logo-preview" style="max-height:50px;max-width:150px;margin-top:8px;display:block">
                            <?php else: ?>
                                <img src="" class="ebc-logo-preview" style="max-height:50px;max-width:150px;margin-top:8px;display:none">
                            <?php endif; ?>
                        </td></tr>
                    <tr><th><label>Kolejność</label></th>
                        <td><input type="number" name="brand_sort_order" value="<?php echo esc_attr($edit_brand->sort_order??0); ?>" class="small-text"></td></tr>
                </table>
                <?php if($eid): ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?php echo $eid; ?>"><?php endif; ?>
                <?php submit_button($edit_brand?'Zapisz zmiany':'Dodaj markę','primary','ebc_save_brand'); ?>
                <?php if($edit_brand): ?><a href="<?php echo admin_url('edit.php?post_type=ebc_event&page=ebc-brands'); ?>" class="button">Anuluj</a><?php endif; ?>
            </form>
        </div>
    </div>
    <?php
}

// ─── BOOKINGS PAGE ───────────────────────────────────────────────────────────

function ebc_bookings_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'ebc_bookings';

    // ── Single action
    if ( isset($_POST['ebc_single_action']) && wp_verify_nonce($_POST['ebc_single_nonce'],'ebc_single_action') ) {
        $bid    = (int)$_POST['booking_id'];
        $action = sanitize_text_field($_POST['ebc_single_action']);
        if ( in_array($action,['confirmed','cancelled','pending']) ) {
            $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$bid));
            $wpdb->update($table,['status'=>$action],['id'=>$bid]);
            if ($old && $old->status !== $action) {
                ebc_send_status_email($bid,$action,$old);
            }
            echo '<div class="notice notice-success is-dismissible"><p>Status zaktualizowany.</p></div>';
        }
        if ($action==='delete') {
            $wpdb->delete($table,['id'=>$bid]);
            echo '<div class="notice notice-success is-dismissible"><p>Rezerwacja usunięta.</p></div>';
        }
    }

    // ── Bulk action
    if ( isset($_POST['ebc_bulk_submit']) && wp_verify_nonce($_POST['ebc_bulk_nonce'],'ebc_bulk_action') ) {
        $ids    = array_map('intval', (array)($_POST['booking_ids'] ?? []));
        $bulk   = sanitize_text_field($_POST['bulk_action'] ?? '');
        $notify = !empty($_POST['bulk_notify']);
        if ($ids && in_array($bulk,['confirmed','cancelled','pending','delete'])) {
            $count = 0;
            foreach ($ids as $bid) {
                if ($bulk==='delete') {
                    $wpdb->delete($table,['id'=>$bid]);
                } else {
                    $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$bid));
                    $wpdb->update($table,['status'=>$bulk],['id'=>$bid]);
                    if ($notify && $old && $old->status !== $bulk) {
                        ebc_send_status_email($bid,$bulk,$old);
                    }
                }
                $count++;
            }
            $label = ['confirmed'=>'potwierdzono','cancelled'=>'anulowano','pending'=>'ustawiono jako oczekujące','delete'=>'usunięto'];
            echo '<div class="notice notice-success is-dismissible"><p>Zbiorowo '.esc_html($label[$bulk]??$bulk).': <strong>'.$count.'</strong> rezerwacji.</p></div>';
        }
    }

    // ── Filters
    $filter_event  = (int)($_GET['event_id']??0);
    $filter_status = sanitize_text_field($_GET['status']??'');

    $where_parts = [];
    if ($filter_event)  $where_parts[] = $wpdb->prepare("b.event_id=%d",$filter_event);
    if ($filter_status) $where_parts[] = $wpdb->prepare("b.status=%s",$filter_status);
    $where = $where_parts ? 'WHERE '.implode(' AND ',$where_parts) : '';

    $bookings = $wpdb->get_results("SELECT b.*,p.post_title AS event_title FROM $table b LEFT JOIN {$wpdb->posts} p ON b.event_id=p.ID $where ORDER BY b.created_at DESC");
    $events   = get_posts(['post_type'=>'ebc_event','numberposts'=>-1,'meta_key'=>'_ebc_date','orderby'=>'meta_value','order'=>'ASC']);

    $status_labels = ['confirmed'=>'Potwierdzona','pending'=>'Oczekująca','cancelled'=>'Anulowana'];
    $status_colors = ['confirmed'=>'#00a32a','pending'=>'#dba617','cancelled'=>'#d63638'];

    // Export CSV
    if (isset($_GET['ebc_export']) && current_user_can('manage_options')) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="rezerwacje.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output','w');
        fputcsv($out,['ID','Wydarzenie','Imię','Nazwisko','Email','Telefon','Firma','Miejsca','Dodatkowi uczestnicy','Status','Data rezerwacji'],';');
        foreach ($bookings as $b) {
            $guests_str = '';
            if (!empty($b->guests)) {
                $gg = json_decode($b->guests, true);
                if (is_array($gg)) $guests_str = implode(', ', array_map(fn($g)=>"{$g['first_name']} {$g['last_name']}", $gg));
            }
            fputcsv($out,[$b->id,$b->event_title,$b->first_name,$b->last_name,$b->email,$b->phone,$b->company,$b->seats,$guests_str,$status_labels[$b->status]??$b->status,date('d.m.Y H:i',strtotime($b->created_at))],';');
        }
        fclose($out); exit;
    }
    ?>
    <div class="wrap">
        <h1>Rezerwacje</h1>

        <!-- Filters -->
        <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px">
            <input type="hidden" name="post_type" value="ebc_event">
            <input type="hidden" name="page" value="ebc-bookings">
            <select name="event_id" onchange="this.form.submit()">
                <option value="">— wszystkie wydarzenia —</option>
                <?php foreach ($events as $ev): $d=get_post_meta($ev->ID,'_ebc_date',true); ?>
                    <option value="<?php echo $ev->ID; ?>" <?php selected($filter_event,$ev->ID); ?>><?php echo esc_html($ev->post_title); ?> (<?php echo $d; ?>)</option>
                <?php endforeach; ?>
            </select>
            <select name="status" onchange="this.form.submit()">
                <option value="">— wszystkie statusy —</option>
                <?php foreach ($status_labels as $k=>$v): ?>
                    <option value="<?php echo $k; ?>" <?php selected($filter_status,$k); ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>
            <a href="<?php echo esc_url(add_query_arg(['ebc_export'=>1])); ?>" class="button">⬇ Eksportuj CSV</a>
        </form>

        <?php if (empty($bookings)): ?>
            <p>Brak rezerwacji spełniających kryteria.</p>
        <?php else:
            $total_seats  = array_sum(array_column($bookings,'seats'));
            $total_count  = count($bookings);
        ?>
        <p><strong>Wyników:</strong> <?php echo $total_count; ?> rezerwacji, <strong><?php echo $total_seats; ?></strong> miejsc łącznie.</p>

        <!-- Bulk action form (outer wrapper) -->
        <form method="post" id="ebc-bulk-form">
            <?php wp_nonce_field('ebc_bulk_action','ebc_bulk_nonce'); ?>
            <div class="tablenav top" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
                <select name="bulk_action">
                    <option value="">— akcja zbiorcza —</option>
                    <option value="confirmed">✔ Potwierdź zaznaczone</option>
                    <option value="pending">⏳ Ustaw jako oczekujące</option>
                    <option value="cancelled">✖ Anuluj zaznaczone</option>
                    <option value="delete">🗑 Usuń zaznaczone</option>
                </select>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px">
                    <input type="checkbox" name="bulk_notify" value="1" checked>
                    Wyślij powiadomienia email
                </label>
                <button type="submit" name="ebc_bulk_submit" class="button button-primary">Zastosuj</button>
            </div>

            <table class="wp-list-table widefat fixed striped" style="font-size:13px">
                <thead>
                    <tr>
                        <th style="width:30px"><input type="checkbox" id="ebc-select-all" title="Zaznacz wszystkie"></th>
                        <th>Wydarzenie / data</th>
                        <th>Uczestnik</th>
                        <th>Email / tel.</th>
                        <th>Firma</th>
                        <th style="width:60px">Miejsca</th>
                        <th style="width:110px">Status</th>
                        <th style="width:120px">Data zgłoszenia</th>
                        <th style="width:130px">Akcja</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($bookings as $b):
                    $sc = $status_colors[$b->status]??'#333';
                    $sl = $status_labels[$b->status]??$b->status;
                    $event_date = '';
                    $events_query = get_post_meta($b->event_id,'_ebc_date',true);
                    if ($events_query) $event_date = date('d.m.Y',strtotime($events_query));
                ?>
                <tr>
                    <td><input type="checkbox" name="booking_ids[]" value="<?php echo $b->id; ?>"></td>
                    <td>
                        <strong><?php echo esc_html($b->event_title); ?></strong>
                        <?php if($event_date) echo '<br><small style="color:#888">'.$event_date.'</small>'; ?>
                    </td>
                    <td>
                        <?php
                        echo esc_html("{$b->first_name} {$b->last_name}");
                        if (!empty($b->guests)) {
                            $extra = json_decode($b->guests, true);
                            if (is_array($extra)) {
                                foreach ($extra as $g) {
                                    echo '<br><small style="color:#888">+ '.esc_html("{$g['first_name']} {$g['last_name']}").'</small>';
                                }
                            }
                        }
                        ?>
                    </td>
                    <td>
                        <a href="mailto:<?php echo esc_attr($b->email); ?>"><?php echo esc_html($b->email); ?></a>
                        <?php if($b->phone) echo '<br><small>'.esc_html($b->phone).'</small>'; ?>
                    </td>
                    <td><?php echo esc_html($b->company)?:'—'; ?></td>
                    <td style="text-align:center"><?php echo $b->seats; ?></td>
                    <td><span style="color:<?php echo $sc; ?>;font-weight:700"><?php echo $sl; ?></span></td>
                    <td><?php echo date('d.m.Y H:i',strtotime($b->created_at)); ?></td>
                    <td>
                        <!-- Individual action (separate form, outside bulk) -->
                        <div class="ebc-single-action" data-id="<?php echo $b->id; ?>">
                            <select class="ebc-quick-status" data-id="<?php echo $b->id; ?>">
                                <option value="">— zmień —</option>
                                <option value="confirmed">✔ Potwierdź</option>
                                <option value="pending">⏳ Oczekująca</option>
                                <option value="cancelled">✖ Anuluj</option>
                                <option value="delete">🗑 Usuń</option>
                            </select>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </form>

        <!-- Hidden single-action form -->
        <form method="post" id="ebc-single-form" style="display:none">
            <?php wp_nonce_field('ebc_single_action','ebc_single_nonce'); ?>
            <input type="hidden" name="booking_id" id="ebc-single-bid">
            <input type="hidden" name="ebc_single_action" id="ebc-single-action">
        </form>

        <?php endif; ?>
    </div>
    <script>
    jQuery(function($){
        $('#ebc-select-all').on('change',function(){
            $('input[name="booking_ids[]"]').prop('checked',this.checked);
        });
        $(document).on('change','.ebc-quick-status',function(){
            var action = $(this).val();
            if (!action) return;
            var label = {confirmed:'Potwierdzić?',pending:'Ustawić jako oczekującą?',cancelled:'Anulować?',delete:'Usunąć tę rezerwację?'};
            if (!confirm(label[action]||'Czy na pewno?')) { $(this).val(''); return; }
            $('#ebc-single-bid').val($(this).data('id'));
            $('#ebc-single-action').val(action);
            $('#ebc-single-form').submit();
        });
    });
    </script>
    <?php
}

// ─── SETTINGS PAGE ───────────────────────────────────────────────────────────

function ebc_settings_page() {
    if ( isset($_POST['ebc_save_settings']) && wp_verify_nonce($_POST['ebc_settings_nonce'],'ebc_settings') ) {
        update_option( 'ebc_email_from',      sanitize_email($_POST['ebc_email_from']) );
        update_option( 'ebc_email_from_name', sanitize_text_field($_POST['ebc_email_from_name']) );
        update_option( 'ebc_admin_emails',    sanitize_textarea_field($_POST['ebc_admin_emails']) );
        update_option( 'ebc_logo_url',        esc_url_raw($_POST['ebc_logo_url']) );
        foreach ( ['new','confirmed','cancelled'] as $tpl ) {
            if ( isset($_POST['ebc_email_tpl_'.$tpl]) ) {
                update_option( 'ebc_email_tpl_'.$tpl, wp_kses_post($_POST['ebc_email_tpl_'.$tpl]) );
            }
        }
        echo '<div class="notice notice-success is-dismissible"><p>Ustawienia zapisane.</p></div>';
    }

    $from      = get_option( 'ebc_email_from',      get_option('admin_email') );
    $from_name = get_option( 'ebc_email_from_name', get_option('blogname') );
    $admin_em  = get_option( 'ebc_admin_emails',    get_option('admin_email') );
    $logo_url  = get_option( 'ebc_logo_url',        'https://polsound.pl/wp-content/uploads/2024/04/polsound_logo_header_comp_new.png' );

    $tpl_labels = [
        'new'       => '1. Zgłoszenie przyjęte (wysyłany od razu po zapisaniu formularza)',
        'confirmed' => '2. Rezerwacja potwierdzona (wysyłany po zmianie statusu na "Potwierdzona")',
        'cancelled'  => '3. Rezerwacja anulowana (wysyłany po zmianie statusu na "Anulowana")',
    ];
    ?>
    <div class="wrap">
        <h1>Ustawienia kalendarza</h1>
        <form method="post">
            <?php wp_nonce_field('ebc_settings','ebc_settings_nonce'); ?>
            <table class="form-table">
                <tr><th><label>Email nadawcy</label></th>
                    <td><input type="email" name="ebc_email_from" value="<?php echo esc_attr($from); ?>" class="regular-text"></td></tr>
                <tr><th><label>Nazwa nadawcy</label></th>
                    <td><input type="text" name="ebc_email_from_name" value="<?php echo esc_attr($from_name); ?>" class="regular-text"></td></tr>
                <tr><th><label>Odbiorcy powiadomień<br><small>(po jednym adresie w linii)</small></label></th>
                    <td>
                        <textarea name="ebc_admin_emails" rows="4" class="regular-text" placeholder="admin@firma.pl&#10;koordynator@firma.pl"><?php echo esc_textarea($admin_em); ?></textarea>
                        <p class="description">Każda osoba z tej listy dostanie email o nowym zgłoszeniu i każdej zmianie statusu.</p>
                    </td></tr>
                <tr><th><label>Logo w emailach (URL)</label></th>
                    <td>
                        <div style="display:flex;gap:8px;align-items:center">
                            <input type="text" name="ebc_logo_url" id="ebc_logo_url" value="<?php echo esc_attr($logo_url); ?>" class="large-text ebc-logo-url-input">
                            <button type="button" class="button ebc-upload-logo">Wybierz</button>
                        </div>
                        <?php if($logo_url): ?>
                            <img src="<?php echo esc_url($logo_url); ?>" class="ebc-logo-preview" style="max-height:40px;max-width:200px;margin-top:8px;display:block">
                        <?php else: ?>
                            <img src="" class="ebc-logo-preview" style="display:none">
                        <?php endif; ?>
                    </td></tr>
            </table>

            <h2 style="margin-top:30px">Szablony emaili do uczestnika</h2>
            <p class="description" style="margin-bottom:16px">
                Dostępne zmienne: <code>{first_name}</code> <code>{last_name}</code>
                <code>{event_title}</code> <code>{event_date}</code> <code>{event_time}</code>
                <code>{event_location}</code> <code>{seats}</code> <code>{add_to_calendar}</code>
            </p>

            <?php
            // Output hidden textareas with default content (esc_textarea avoids any encoding issue)
            // JS reads these when user clicks "Przywróć domyślny"
            foreach ( ['new','confirmed','cancelled'] as $tpl ):
                $saved   = get_option( 'ebc_email_tpl_'.$tpl, ebc_default_tpl($tpl) );
                $default = ebc_default_tpl($tpl);
            ?>
            <div style="margin-bottom:28px;padding:18px 20px;background:#fff;border:1px solid #ccd0d4;border-radius:4px">
                <h3 style="margin:0 0 10px;font-size:14px"><?php echo esc_html($tpl_labels[$tpl]); ?></h3>
                <textarea name="ebc_email_tpl_<?php echo $tpl; ?>"
                          id="ebc-tpl-<?php echo $tpl; ?>"
                          rows="14" class="large-text"
                          style="font-family:monospace;font-size:12px"><?php echo esc_textarea($saved); ?></textarea>
                <p style="margin:6px 0 0">
                    <button type="button" class="button"
                            onclick="ebcResetTpl('<?php echo $tpl; ?>')">
                        &#8635; Przywróć domyślny
                    </button>
                </p>
                <!-- Hidden textarea with raw default — avoids json_encode / unicode issues -->
                <textarea id="ebc-default-<?php echo $tpl; ?>"
                          style="display:none"><?php echo esc_textarea($default); ?></textarea>
            </div>
            <?php endforeach; ?>

            <?php submit_button('Zapisz ustawienia','primary','ebc_save_settings'); ?>
        </form>
        <script>
        function ebcResetTpl(type) {
            if (!confirm('Przywrócić domyślny szablon? Twoje zmiany zostaną utracone.')) return;
            document.getElementById('ebc-tpl-' + type).value =
                document.getElementById('ebc-default-' + type).value;
        }
        </script>
        <hr>
        <h2>Jak używać?</h2>
        <p>Wstaw shortcode <code>[events_calendar]</code> na dowolnej stronie.</p>
    </div>
    <?php
}

// ─── FRONTEND ASSETS ─────────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', function() {
    wp_enqueue_style(  'ebc-style',    EBC_PLUGIN_URL.'assets/style.css',    [], EBC_VERSION );
    wp_enqueue_script( 'ebc-calendar', EBC_PLUGIN_URL.'assets/calendar.js',  ['jquery'], EBC_VERSION, true );
    wp_localize_script('ebc-calendar','ebc_ajax',['ajax_url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('ebc_booking')]);
} );

// ─── CALENDAR INNER RENDERER (used by shortcode + AJAX) ──────────────────────

function ebc_render_calendar_inner( $year, $month, $filter_brand ) {
    $brands    = ebc_get_brands();
    $rooms     = ebc_get_rooms();
    $rooms_map = [];
    foreach ($rooms  as $r) $rooms_map[$r->id]  = $r->name;
    $brands_map = [];
    foreach ($brands as $b) $brands_map[$b->id] = $b;

    $meta_query = [['key'=>'_ebc_date','value'=>sprintf('%04d-%02d',$year,$month),'compare'=>'LIKE']];
    if ($filter_brand) $meta_query[] = ['key'=>'_ebc_brand_id','value'=>$filter_brand,'compare'=>'='];

    $events = get_posts([
        'post_type'=>'ebc_event','numberposts'=>-1,
        'meta_query'=>$meta_query,'meta_key'=>'_ebc_date',
        'orderby'=>'meta_value','order'=>'ASC','post_status'=>'publish',
    ]);

    $events_by_day = [];
    foreach ($events as $ev) {
        $d = get_post_meta($ev->ID,'_ebc_date',true);
        if ($d) $events_by_day[(int)date('j',strtotime($d))][] = $ev;
    }

    $today         = date('Y-m-d');
    $first_day     = mktime(0,0,0,$month,1,$year);
    $days_in_month = (int)date('t',$first_day);
    $start_dow     = (int)date('N',$first_day);
    $end_dow       = (int)date('N',mktime(0,0,0,$month,$days_in_month,$year));

    ob_start();
    ?>
    <div class="ebc-calendar">
        <div class="ebc-calendar-header">
            <?php foreach (['Pon','Wt','Śr','Czw','Pt','Sob','Ndz'] as $dn): ?>
                <div class="ebc-day-name"><?php echo $dn; ?></div>
            <?php endforeach; ?>
        </div>
        <div class="ebc-calendar-grid">
            <?php
            for ($i=1;$i<$start_dow;$i++) echo '<div class="ebc-day ebc-day-empty"></div>';
            for ($day=1;$day<=$days_in_month;$day++) {
                $date_str = sprintf('%04d-%02d-%02d',$year,$month,$day);
                $is_today = ($date_str===$today);
                $day_evs  = $events_by_day[$day]??[];
                echo '<div class="ebc-day'.($is_today?' ebc-today':'').($day_evs?' ebc-has-events':'').'">';
                echo '<div class="ebc-day-number">'.$day.'</div>';
                foreach ($day_evs as $ev) {
                    $bid   = get_post_meta($ev->ID,'_ebc_brand_id',true);
                    $brand = $brands_map[$bid] ?? null;
                    $color = $brand ? $brand->color : '#888';
                    $ts    = get_post_meta($ev->ID,'_ebc_time_start',true);
                    $avail = ebc_get_available_seats($ev->ID);
                    $so    = ($avail<=0) ? ' ebc-sold-out' : '';
                    echo '<div class="ebc-event'.$so.'" style="border-left:3px solid '.esc_attr($color).'" data-event-id="'.$ev->ID.'">';
                    if ($ts) echo '<span class="ebc-event-cal-time">'.esc_html($ts).'</span> ';
                    echo '<span>'.esc_html(get_the_title($ev->ID)).'</span>';
                    if ($so) echo ' <span class="ebc-tag-full">pełne</span>';
                    echo '</div>';
                }
                echo '</div>';
            }
            for ($i=$end_dow+1;$i<=7;$i++) echo '<div class="ebc-day ebc-day-empty"></div>';
            ?>
        </div>
    </div>

    <?php if (!empty($events)): ?>
    <div class="ebc-events-list">
        <h3>Wydarzenia — <?php echo ebc_polish_month($month).' '.$year; ?></h3>
        <?php foreach ($events as $ev):
            $bid      = get_post_meta($ev->ID,'_ebc_brand_id',true);
            $rid      = get_post_meta($ev->ID,'_ebc_room_id',true);
            $date     = get_post_meta($ev->ID,'_ebc_date',true);
            $ts       = get_post_meta($ev->ID,'_ebc_time_start',true);
            $te       = get_post_meta($ev->ID,'_ebc_time_end',true);
            $max      = (int)get_post_meta($ev->ID,'_ebc_max_seats',true);
            $avail    = ebc_get_available_seats($ev->ID);
            $brand    = $brands_map[$bid] ?? null;
            $color    = $brand ? $brand->color : '#888';
            $time_str = $ts ? $ts.($te?" – $te":'') : '';
            $room_name = $rooms_map[$rid] ?? '';
        ?>
        <div class="ebc-event-card" style="border-left:4px solid <?php echo esc_attr($color); ?>">
            <div class="ebc-event-card-header">
                <div class="ebc-event-card-meta">
                    <span class="ebc-event-date-badge"><?php echo date('d.m.Y',strtotime($date)); ?></span>
                    <?php if($time_str): ?><span class="ebc-event-time-badge"><?php echo esc_html($time_str); ?></span><?php endif; ?>
                    <?php if($brand): ?>
                        <?php if($brand->logo_url): ?>
                            <img src="<?php echo esc_url($brand->logo_url); ?>"
                                 style="max-height:22px;max-width:80px;vertical-align:middle;object-fit:contain"
                                 alt="<?php echo esc_attr($brand->name); ?>">
                        <?php else: ?>
                            <span class="ebc-badge" style="background:<?php echo esc_attr($color); ?>"><?php echo esc_html($brand->name); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if($room_name): ?><span class="ebc-badge ebc-badge-loc"><?php echo esc_html($room_name); ?></span><?php endif; ?>
                </div>
                <div class="ebc-seats-info" data-event-id="<?php echo $ev->ID; ?>" data-max="<?php echo $max; ?>">
                    <?php
                    $fill_pct   = $max > 0 ? round(($max - $avail) / $max * 100) : 100;
                    $bar_class  = $avail <= 0 ? 'ebc-bar-full' : ($avail / max(1,$max) > .5 ? 'ebc-bar-ok' : ($avail / max(1,$max) > .1 ? 'ebc-bar-low' : 'ebc-bar-critical'));
                    ?>
                    <div class="ebc-seats-bar <?php echo $bar_class; ?>">
                        <div class="ebc-seats-bar-fill" style="width:<?php echo $fill_pct; ?>%"></div>
                    </div>
                    <?php if($avail>0): ?>
                        <span class="ebc-seats-avail"><?php echo $avail; ?>&nbsp;/&nbsp;<?php echo $max; ?> wolnych</span>
                    <?php else: ?>
                        <span class="ebc-seats-full">Brak miejsc</span>
                    <?php endif; ?>
                </div>
            </div>
            <h4 class="ebc-event-card-title"><?php echo esc_html(get_the_title($ev->ID)); ?></h4>
            <?php $ex=get_the_excerpt($ev->ID); if($ex): ?><p class="ebc-event-card-excerpt"><?php echo esc_html($ex); ?></p><?php endif; ?>
            <?php if($avail>0): ?>
            <button class="ebc-btn-book"
                data-event-id="<?php echo $ev->ID; ?>"
                data-event-title="<?php echo esc_attr(get_the_title($ev->ID)); ?>"
                data-event-date="<?php echo esc_attr(date('d.m.Y',strtotime($date))); ?>"
                data-event-time="<?php echo esc_attr($time_str); ?>"
                data-event-location="<?php echo esc_attr($room_name); ?>"
                data-max-seats="<?php echo $avail; ?>">
                Zarezerwuj miejsce
            </button>
            <?php else: ?>
            <button class="ebc-btn-book ebc-btn-no-seats" disabled>Brak wolnych miejsc</button>
            <button class="ebc-btn-waitlist"
                data-event-id="<?php echo $ev->ID; ?>"
                data-event-title="<?php echo esc_attr(get_the_title($ev->ID)); ?>"
                data-event-date="<?php echo esc_attr(date('d.m.Y',strtotime($date))); ?>"
                data-event-time="<?php echo esc_attr($time_str); ?>"
                data-event-location="<?php echo esc_attr($room_name); ?>">
                📋 Lista oczekujących
            </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="ebc-no-events"><p>Brak wydarzeń w tym miesiącu<?php echo $filter_brand?' dla wybranej marki':''; ?>.</p></div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

// ─── AJAX: LOAD MONTH (replaces page navigation) ─────────────────────────────

add_action('wp_ajax_nopriv_ebc_load_month','ebc_ajax_load_month');
add_action('wp_ajax_ebc_load_month',       'ebc_ajax_load_month');
function ebc_ajax_load_month() {
    check_ajax_referer('ebc_booking','nonce');
    $year  = max(2020,min(2035,(int)($_POST['year']??0)));
    $month = max(1,   min(12,  (int)($_POST['month']??0)));
    $brand = (int)($_POST['brand']??0);
    if (!$year||!$month) wp_send_json_error(['message'=>'Nieprawidłowe dane.']);
    wp_send_json_success([
        'html'       => ebc_render_calendar_inner($year,$month,$brand),
        'title'      => ebc_polish_month($month).' '.$year,
        'prev_month' => date('Y-m',mktime(0,0,0,$month-1,1,$year)),
        'next_month' => date('Y-m',mktime(0,0,0,$month+1,1,$year)),
    ]);
}

// ─── SHORTCODE [events_calendar] ─────────────────────────────────────────────

add_shortcode( 'events_calendar', 'ebc_calendar_shortcode' );
function ebc_calendar_shortcode() {
    $brands       = ebc_get_brands();
    $current_month= sanitize_text_field($_GET['ebc_month'] ?? date('Y-m'));
    $filter_brand = (int)($_GET['ebc_brand'] ?? 0);
    if (!preg_match('/^\d{4}-\d{2}$/',$current_month)) $current_month = date('Y-m');
    [$year,$month] = array_map('intval',explode('-',$current_month));
    $prev = date('Y-m',mktime(0,0,0,$month-1,1,$year));
    $next = date('Y-m',mktime(0,0,0,$month+1,1,$year));

    ob_start();
    ?>
    <div class="ebc-wrapper" id="ebc-top">

        <!-- Filters -->
        <div class="ebc-filters">
            <div class="ebc-filter-group">
                <label>Marka:</label>
                <select id="ebc-brand-filter">
                    <option value="0">Wszystkie marki</option>
                    <?php foreach ($brands as $b): ?>
                        <option value="<?php echo $b->id; ?>" <?php selected($filter_brand,$b->id); ?>><?php echo esc_html($b->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ebc-legend">
                <?php foreach ($brands as $b): ?>
                    <span class="ebc-legend-item">
                        <span class="ebc-dot" style="background:<?php echo esc_attr($b->color); ?>"></span>
                        <?php if($b->logo_url): ?>
                            <img src="<?php echo esc_url($b->logo_url); ?>" style="max-height:28px;max-width:100px;vertical-align:middle;object-fit:contain" alt="<?php echo esc_attr($b->name); ?>">
                        <?php else: ?>
                            <span><?php echo esc_html($b->name); ?></span>
                        <?php endif; ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Navigation: plain <button> elements, no <a> links -->
        <div class="ebc-nav">
            <button type="button" class="ebc-nav-btn ebc-month-nav" data-month="<?php echo esc_attr($prev); ?>">&laquo; Poprzedni</button>
            <h2 class="ebc-month-title" id="ebc-month-title"><?php echo ebc_polish_month($month).' '.$year; ?></h2>
            <button type="button" class="ebc-nav-btn ebc-month-nav" data-month="<?php echo esc_attr($next); ?>">Następny &raquo;</button>
        </div>

        <!-- Dynamic calendar content — replaced by AJAX on navigation -->
        <div id="ebc-calendar-content"
             data-year="<?php echo $year; ?>"
             data-month="<?php echo $month; ?>"
             data-brand="<?php echo $filter_brand; ?>">
            <?php echo ebc_render_calendar_inner($year,$month,$filter_brand); ?>
        </div>

        <!-- Modal: JS moves this to <body> on init to escape theme stacking contexts -->
        <div id="ebc-modal" class="ebc-modal" role="dialog" aria-modal="true" aria-labelledby="ebc-modal-title">
            <div class="ebc-modal-inner">
                <button class="ebc-modal-close" aria-label="Zamknij">&times;</button>
                <h3 id="ebc-modal-title">Rezerwacja</h3>
                <div class="ebc-modal-event-info" id="ebc-modal-info"></div>
                <div id="ebc-booking-result"></div>
                <form id="ebc-booking-form" novalidate>
                    <input type="hidden" id="ebc-event-id" name="event_id" value="">
                    <div class="ebc-form-row ebc-form-row-2col">
                        <div><label for="ebc-first-name">Imię *</label>
                            <input type="text" id="ebc-first-name" name="first_name" required autocomplete="given-name"></div>
                        <div><label for="ebc-last-name">Nazwisko *</label>
                            <input type="text" id="ebc-last-name" name="last_name" required autocomplete="family-name"></div>
                    </div>
                    <div class="ebc-form-row">
                        <label for="ebc-email">Email *</label>
                        <input type="email" id="ebc-email" name="email" required autocomplete="email">
                    </div>
                    <div class="ebc-form-row ebc-form-row-2col ebc-form-row-2col-contact">
                        <div><label for="ebc-phone">Telefon</label>
                            <input type="tel" id="ebc-phone" name="phone" autocomplete="tel"></div>
                        <div><label for="ebc-company">Firma</label>
                            <input type="text" id="ebc-company" name="company" autocomplete="organization"></div>
                    </div>
                    <div class="ebc-form-row ebc-form-row-seats">
                        <label for="ebc-seats">Liczba miejsc *</label>
                        <input type="number" id="ebc-seats" name="seats" min="1" max="10" value="1" required>
                        <p class="ebc-field-hint" id="ebc-seats-hint"></p>
                    </div>
                    <div id="ebc-extra-guests"></div>
                    <div class="ebc-form-row ebc-form-row-notes">
                        <label for="ebc-notes">Uwagi / pytania</label>
                        <textarea id="ebc-notes" name="notes" rows="3"></textarea>
                    </div>
                    <div class="ebc-form-row ebc-rodo">
                        <label>
                            <input type="checkbox" name="rodo" id="ebc-rodo" required>
                            Wyrażam zgodę na przetwarzanie moich danych osobowych w celu obsługi rezerwacji. *
                        </label>
                    </div>
                    <button type="submit" class="ebc-btn-submit">Wyślij zgłoszenie</button>
                </form>
            </div>
        </div>

    </div>
    <?php
    return ob_get_clean();
}

// ─── AJAX: GET EVENT ──────────────────────────────────────────────────────────

add_action('wp_ajax_nopriv_ebc_get_event','ebc_ajax_get_event');
add_action('wp_ajax_ebc_get_event',       'ebc_ajax_get_event');
function ebc_ajax_get_event() {
    check_ajax_referer('ebc_booking','nonce');
    $id    = (int)$_POST['event_id'];
    $event = get_post($id);
    if (!$event||$event->post_type!=='ebc_event') wp_send_json_error();
    $rid   = get_post_meta($id,'_ebc_room_id',true);
    $bid   = get_post_meta($id,'_ebc_brand_id',true);
    $room  = $rid ? ebc_get_room($rid)  : null;
    $brand = $bid ? ebc_get_brand($bid) : null;
    $date  = get_post_meta($id,'_ebc_date',true);
    $ts    = get_post_meta($id,'_ebc_time_start',true);
    $te    = get_post_meta($id,'_ebc_time_end',true);
    $avail = ebc_get_available_seats($id);
    wp_send_json_success([
        'title'    => get_the_title($id),
        'date'     => $date?date('d.m.Y',strtotime($date)):'',
        'time'     => $ts.($te?" – $te":''),
        'location' => $room ? $room->name : '',
        'brand'    => $brand ? $brand->name : '',
        'color'    => $brand ? $brand->color : '#888',
        'avail'    => $avail,
    ]);
}

// ─── AJAX: SUBMIT BOOKING ─────────────────────────────────────────────────────

add_action('wp_ajax_nopriv_ebc_book','ebc_handle_booking');
add_action('wp_ajax_ebc_book',       'ebc_handle_booking');
function ebc_handle_booking() {
    check_ajax_referer('ebc_booking','nonce');
    $event_id = (int)($_POST['event_id']??0);
    $first    = sanitize_text_field($_POST['first_name']??'');
    $last     = sanitize_text_field($_POST['last_name']??'');
    $email    = sanitize_email($_POST['email']??'');
    $phone    = sanitize_text_field($_POST['phone']??'');
    $company  = sanitize_text_field($_POST['company']??'');
    $seats    = max(1,(int)($_POST['seats']??1));
    $notes    = sanitize_textarea_field($_POST['notes']??'');

    if (!$event_id||!$first||!$last||!$email) wp_send_json_error(['message'=>'Proszę wypełnić wszystkie wymagane pola.']);
    if (!is_email($email)) wp_send_json_error(['message'=>'Nieprawidłowy adres email.']);
    if (empty($_POST['rodo'])) wp_send_json_error(['message'=>'Proszę zaakceptować zgodę na przetwarzanie danych osobowych.']);

    // Duplicate booking check
    global $wpdb;
    $already = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}ebc_bookings WHERE event_id=%d AND email=%s AND status!='cancelled'",
        $event_id, $email
    ));
    if ($already) wp_send_json_error(['message'=>'Rezerwacja z tym adresem email już istnieje dla tego wydarzenia.']);

    // Atomic seat check inside a transaction to prevent race conditions
    $wpdb->query('START TRANSACTION');
    $max_seats = (int)get_post_meta($event_id, '_ebc_max_seats', true);
    $booked    = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(seats),0) FROM {$wpdb->prefix}ebc_bookings WHERE event_id=%d AND status!='cancelled' FOR UPDATE",
        $event_id
    ));
    $avail = max(0, $max_seats - $booked);
    if ($seats > $avail) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error(['message'=>"Dostępne są tylko {$avail} wolne miejsca."]);
    }

    // Collect additional guest names (persons 2..N)
    $guests_json = '';
    if ($seats > 1) {
        $raw_guests = isset($_POST['guests']) && is_array($_POST['guests']) ? $_POST['guests'] : [];
        $guests_list = [];
        for ($i = 0; $i < $seats - 1; $i++) {
            $gf = sanitize_text_field($raw_guests[$i]['first_name'] ?? '');
            $gl = sanitize_text_field($raw_guests[$i]['last_name']  ?? '');
            if (!$gf || !$gl) wp_send_json_error(['message'=>'Proszę podać imię i nazwisko każdego uczestnika.']);
            $guests_list[] = ['first_name'=>$gf,'last_name'=>$gl];
        }
        $guests_json = wp_json_encode($guests_list);
    }

    $inserted = $wpdb->insert($wpdb->prefix.'ebc_bookings',[
        'event_id'=>$event_id,'first_name'=>$first,'last_name'=>$last,'email'=>$email,
        'phone'=>$phone,'company'=>$company,'seats'=>$seats,'notes'=>$notes,'guests'=>$guests_json,'status'=>'pending',
    ]);
    if ($inserted===false) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error(['message'=>'Błąd podczas zapisywania. Spróbuj ponownie.']);
    }
    $wpdb->query('COMMIT');

    $booking_id = $wpdb->insert_id;

    // Email to participant
    ebc_email_participant($booking_id,'new');
    // Email to admins
    ebc_email_admins_new_booking($booking_id);

    wp_send_json_success(['message'=>"Zgłoszenie przyjęte! Potwierdzimy je wkrótce. Wysłaliśmy informację na adres <strong>{$email}</strong>."]);
}

// ─── EMAIL FUNCTIONS ──────────────────────────────────────────────────────────

function ebc_get_event_data( $booking_id ) {
    global $wpdb;
    $b = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ebc_bookings WHERE id=%d",$booking_id));
    if (!$b) return null;
    $event = get_post($b->event_id);
    if (!$event) return null;
    $rid   = get_post_meta($b->event_id,'_ebc_room_id',true);
    $bid   = get_post_meta($b->event_id,'_ebc_brand_id',true);
    $room  = $rid ? ebc_get_room($rid) : null;
    $brand = $bid ? ebc_get_brand($bid) : null;
    $date  = get_post_meta($b->event_id,'_ebc_date',true);
    $ts    = get_post_meta($b->event_id,'_ebc_time_start',true);
    $te    = get_post_meta($b->event_id,'_ebc_time_end',true);
    return [
        'booking'    => $b,
        'event'      => $event,
        'room'       => $room,
        'brand'      => $brand,
        'date_str'   => $date ? date('d.m.Y',strtotime($date)) : '—',
        'time_str'   => $ts ? $ts.($te?" – $te":'') : '—',
        'room_name'  => $room ? $room->name : '—',
        'room_addr'  => $room ? $room->address : '',
        'brand_name' => $brand ? $brand->name : '—',
        'brand_color'=> $brand ? $brand->color : '#2563eb',
    ];
}

function ebc_email_headers() {
    $name  = get_option('ebc_email_from_name',get_option('blogname'));
    $email = get_option('ebc_email_from',get_option('admin_email'));
    return ["Content-Type: text/html; charset=UTF-8","From: {$name} <{$email}>"];
}

function ebc_email_wrapper( $body ) {
    $logo_url = get_option('ebc_logo_url','');
    $logo = $logo_url ? "<img src=\"{$logo_url}\" alt=\"\" style=\"max-height:48px;max-width:200px;display:block;margin:0 auto\">" : '';
    return '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f0f0f0;color:#222">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f0f0f0;padding:24px 0">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%">
  <tr><td style="background:#1a1a2e;padding:22px 32px;text-align:center;border-radius:8px 8px 0 0">'.$logo.'</td></tr>
  <tr><td style="background:#ffffff;padding:32px 36px;border-left:1px solid #e0e0e0;border-right:1px solid #e0e0e0">'.$body.'</td></tr>
  <tr><td style="background:#f4f4f4;padding:14px 32px;text-align:center;font-size:12px;color:#888;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 8px 8px">
    Ta wiadomość została wygenerowana automatycznie &mdash; prosimy na nią nie odpowiadać.
  </td></tr>
</table>
</td></tr>
</table>
</body></html>';
}

function ebc_event_box( $d ) {
    $addr = $d['room_addr'] ? '<br><span style="color:#888;font-size:13px">'.esc_html($d['room_addr']).'</span>' : '';
    return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0">
<tr><td style="background:#f5f7ff;border-left:4px solid '.esc_attr($d['brand_color']).';padding:16px 20px;border-radius:4px">
  <p style="margin:0 0 4px;font-size:18px;font-weight:700;color:#1a1a2e">'.esc_html($d['event']->post_title).'</p>
  <table cellpadding="0" cellspacing="0" border="0" style="margin-top:10px">
    <tr><td style="padding:3px 0;font-size:14px;color:#555"><strong style="color:#333;width:100px;display:inline-block">📅 Data:</strong> '.esc_html($d['date_str']).'</td></tr>
    <tr><td style="padding:3px 0;font-size:14px;color:#555"><strong style="color:#333;width:100px;display:inline-block">🕐 Godzina:</strong> '.esc_html($d['time_str']).'</td></tr>
    <tr><td style="padding:3px 0;font-size:14px;color:#555"><strong style="color:#333;width:100px;display:inline-block">📍 Sala:</strong> '.esc_html($d['room_name']).$addr.'</td></tr>
    <tr><td style="padding:3px 0;font-size:14px;color:#555"><strong style="color:#333;width:100px;display:inline-block">🏷 Marka:</strong> '.esc_html($d['brand_name']).'</td></tr>
    <tr><td style="padding:3px 0;font-size:14px;color:#555"><strong style="color:#333;width:100px;display:inline-block">👥 Miejsca:</strong> '.esc_html($d['booking']->seats).'</td></tr>
  </table>
'.( (!empty($d['booking']->guests) && ($extra_g = json_decode($d['booking']->guests, true)) && is_array($extra_g)) ?
'<p style="margin:12px 0 4px;font-size:13px;font-weight:700;color:#333">Uczestnicy:</p>
<p style="margin:2px 0;font-size:13px;color:#555">1. '.esc_html("{$d['booking']->first_name} {$d['booking']->last_name}").'</p>'
. implode('', array_map(function($g,$i){ return '<p style="margin:2px 0;font-size:13px;color:#555">'.($i+2).'. '.esc_html("{$g['first_name']} {$g['last_name']}").'</p>'; }, $extra_g, array_keys($extra_g)))
: '').'
</td></tr></table>';
}

function ebc_email_participant( $booking_id, $type = 'new' ) {
    $d = ebc_get_event_data($booking_id);
    if (!$d) return;
    $b = $d['booking'];

    // Type-specific templates (new in v2.1.1) — fall back to legacy single template
    $template = get_option( 'ebc_email_tpl_' . $type )
             ?: get_option( 'ebc_email_template' )   // legacy single template
             ?: ebc_default_tpl( $type );

    // {status_message} kept for backward-compat with custom legacy templates
    $status_messages = [
        'new'       => 'Twoje zg&#322;oszenie zosta&#322;o przyj&#281;te. <strong>Potwierdzimy je wkr&oacute;tce</strong> &mdash; otrzymasz kolejny email.',
        'confirmed' => 'Twoje zg&#322;oszenie zosta&#322;o <strong style="color:#00a32a">potwierdzone</strong>! Czekamy na Ciebie.',
        'cancelled' => 'Twoje zg&#322;oszenie zosta&#322;o <strong style="color:#d63638">anulowane</strong>. W razie pyta&#324; prosimy o kontakt.',
    ];
    $status_message = $status_messages[$type] ?? '';

    $google_url = ebc_google_calendar_url( $b->event_id );
    $ics_url    = add_query_arg( 'ebc_ics', $b->event_id, home_url('/') );
    $cal_html   = $google_url
        ? '<table cellpadding="0" cellspacing="0" border="0" style="margin:20px 0"><tr>
  <td style="padding:0 10px 0 0">
    <a href="'.esc_url($google_url).'" target="_blank" rel="noopener" style="background:#4285F4;color:#ffffff;padding:10px 18px;border-radius:5px;text-decoration:none;font-size:13px;font-weight:700;display:inline-block;font-family:Arial,Helvetica,sans-serif">&#128197; Dodaj do Google Calendar</a>
  </td>
  <td>
    <a href="'.esc_url($ics_url).'" style="background:#555555;color:#ffffff;padding:10px 18px;border-radius:5px;text-decoration:none;font-size:13px;font-weight:700;display:inline-block;font-family:Arial,Helvetica,sans-serif">&#128229; Pobierz ICS (Apple / Outlook)</a>
  </td>
</tr></table>'
        : '';

    $guests_html = '';
    if (!empty($b->guests)) {
        $gg = json_decode($b->guests, true);
        if (is_array($gg) && count($gg)) {
            $guests_html = '<p style="margin:10px 0 4px;font-size:13px;font-weight:700;color:#333">Uczestnicy:</p>'
                . '<p style="margin:2px 0;font-size:13px;color:#555">1. '.esc_html("{$b->first_name} {$b->last_name}").'</p>'
                . implode('', array_map(function($g,$i){
                    return '<p style="margin:2px 0;font-size:13px;color:#555">'.($i+2).'. '.esc_html("{$g['first_name']} {$g['last_name']}").'</p>';
                }, $gg, array_keys($gg)));
        }
    }

    $body = str_replace(
        ['{first_name}','{last_name}','{event_title}','{event_date}','{event_time}','{event_location}','{seats}','{guests_list}','{status_message}','{add_to_calendar}'],
        [$b->first_name,$b->last_name,$d['event']->post_title,$d['date_str'],$d['time_str'],$d['room_name'],$b->seats,$guests_html,$status_message,$cal_html],
        $template
    );

    // Fallback: if the saved template pre-dates v2.1.0 and has no {add_to_calendar},
    // inject the buttons just before the closing </body> tag.
    if ( $cal_html && strpos( $template, '{add_to_calendar}' ) === false ) {
        $body = str_replace( '</body>', $cal_html . '</body>', $body );
    }

    $subjects = [
        'new'       => 'Zgłoszenie przyjęte: '.$d['event']->post_title,
        'confirmed' => 'Potwierdzenie rezerwacji: '.$d['event']->post_title,
        'cancelled' => 'Rezerwacja anulowana: '.$d['event']->post_title,
    ];

    wp_mail($b->email, $subjects[$type]??'Informacja o rezerwacji', $body, ebc_email_headers());
}

function ebc_email_admins_new_booking( $booking_id ) {
    $d = ebc_get_event_data($booking_id);
    if (!$d) return;
    $b = $d['booking'];

    $event_box = ebc_event_box($d);
    $body = ebc_email_wrapper(
        '<p style="font-size:16px;font-weight:700;color:#1a1a2e;margin:0 0 4px">Nowe zgłoszenie</p>
         <p style="color:#555;margin:0 0 20px;font-size:14px">Właśnie wpłynęło nowe zgłoszenie na wydarzenie.</p>'
        . $event_box .
        '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:20px">
           <tr><td style="background:#f9f9f9;border:1px solid #e0e0e0;padding:16px 20px;border-radius:4px">
             <p style="margin:0 0 6px;font-size:14px;font-weight:700;color:#333">Dane uczestnika</p>
             <p style="margin:3px 0;font-size:14px;color:#555">👤 '.esc_html("{$b->first_name} {$b->last_name}").'</p>
             <p style="margin:3px 0;font-size:14px;color:#555">✉ <a href="mailto:'.esc_attr($b->email).'" style="color:#2563eb">'.esc_html($b->email).'</a></p>
             '.($b->phone?'<p style="margin:3px 0;font-size:14px;color:#555">📞 '.esc_html($b->phone).'</p>':'').
             ($b->company?'<p style="margin:3px 0;font-size:14px;color:#555">🏢 '.esc_html($b->company).'</p>':'').
             ($b->notes?'<p style="margin:10px 0 0;font-size:13px;color:#888;border-top:1px solid #eee;padding-top:8px">Uwagi: '.esc_html($b->notes).'</p>':'').
           '</td></tr></table>
           <p style="margin:20px 0 0;text-align:center">
             <a href="'.admin_url('edit.php?post_type=ebc_event&page=ebc-bookings').'" style="background:#2563eb;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:700;font-size:14px;display:inline-block">Zarządzaj rezerwacjami →</a>
           </p>'
    );

    wp_mail(ebc_get_admin_emails(), 'Nowe zgłoszenie: '.$d['event']->post_title, $body, ebc_email_headers());
}

function ebc_send_status_email( $booking_id, $new_status, $old_booking = null ) {
    $d = ebc_get_event_data($booking_id);
    if (!$d) return;
    $b = $d['booking'];

    // Email to participant
    if (in_array($new_status,['confirmed','cancelled'])) {
        ebc_email_participant($booking_id,$new_status);
    }

    // Notify first waitlist person when seats become available
    if ($new_status === 'cancelled') {
        ebc_notify_waitlist($b->event_id);
    }

    // Email to admins
    $labels = ['confirmed'=>'Potwierdzona','cancelled'=>'Anulowana','pending'=>'Oczekująca'];
    $colors = ['confirmed'=>'#00a32a','cancelled'=>'#d63638','pending'=>'#dba617'];
    $label  = $labels[$new_status]??$new_status;
    $color  = $colors[$new_status]??'#888';

    $event_box = ebc_event_box($d);
    $body = ebc_email_wrapper(
        '<p style="font-size:16px;font-weight:700;color:#1a1a2e;margin:0 0 4px">Zmiana statusu rezerwacji</p>
         <p style="margin:0 0 20px;font-size:14px;color:#555">Status zmieniony na: <strong style="color:'.$color.'">'.$label.'</strong></p>'
        . $event_box .
        '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:16px">
           <tr><td style="background:#f9f9f9;border:1px solid #e0e0e0;padding:14px 20px;border-radius:4px;font-size:14px">
             👤 '.esc_html("{$b->first_name} {$b->last_name}").' &nbsp;|&nbsp;
             ✉ <a href="mailto:'.esc_attr($b->email).'" style="color:#2563eb">'.esc_html($b->email).'</a>
             '.($b->phone?'&nbsp;|&nbsp; 📞 '.esc_html($b->phone):'').'
           </td></tr></table>'
    );

    wp_mail(ebc_get_admin_emails(), 'Status rezerwacji: '.$label.' — '.$d['event']->post_title, $body, ebc_email_headers());
}

// ─── THREE SEPARATE PARTICIPANT EMAIL TEMPLATES (v2.1.1) ─────────────────────

function ebc_default_tpl( $type ) {
    $logo_url = get_option( 'ebc_logo_url', 'https://polsound.pl/wp-content/uploads/2024/04/polsound_logo_header_comp_new.png' );
    $logo     = $logo_url
        ? '<img src="' . esc_url($logo_url) . '" alt="" style="max-height:48px;max-width:200px;display:block;margin:0 auto">'
        : '';

    // Shared email envelope — uses HTML entities for special chars to avoid encoding issues in textarea
    $open = '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f0f0f0;color:#222">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f0f0f0;padding:24px 0">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%">
<tr><td style="background:#1a1a2e;padding:22px 32px;text-align:center;border-radius:8px 8px 0 0">' . $logo . '</td></tr>
<tr><td style="background:#ffffff;padding:32px 36px;border-left:1px solid #e0e0e0;border-right:1px solid #e0e0e0">';

    $close = '</td></tr>
<tr><td style="background:#f4f4f4;padding:14px 32px;text-align:center;font-size:12px;color:#888;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 8px 8px">
  Ta wiadomo&#347;&#263; zosta&#322;a wygenerowana automatycznie &mdash; prosimy na ni&#261; nie odpowiada&#263;.
</td></tr>
</table></td></tr></table>
</body></html>';

    // Shared event details box
    $box = '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0">
<tr><td style="background:#f5f7ff;border-left:4px solid #2563eb;padding:16px 20px;border-radius:4px">
  <p style="margin:0 0 4px;font-size:18px;font-weight:700;color:#1a1a2e">{event_title}</p>
  <table cellpadding="0" cellspacing="0" border="0" style="margin-top:10px">
    <tr><td style="padding:3px 0;font-size:14px;color:#555"><strong style="color:#333;width:100px;display:inline-block">&#128197; Data:</strong> {event_date}</td></tr>
    <tr><td style="padding:3px 0;font-size:14px;color:#555"><strong style="color:#333;width:100px;display:inline-block">&#128336; Godzina:</strong> {event_time}</td></tr>
    <tr><td style="padding:3px 0;font-size:14px;color:#555"><strong style="color:#333;width:100px;display:inline-block">&#128205; Sala:</strong> {event_location}</td></tr>
    <tr><td style="padding:3px 0;font-size:14px;color:#555"><strong style="color:#333;width:100px;display:inline-block">&#128101; Miejsc:</strong> {seats}</td></tr>
  </table>
  {guests_list}
</td></tr></table>';

    $bodies = [

        'new' =>
            '<p style="font-size:22px;font-weight:700;color:#1a1a2e;margin:0 0 6px">Dzi&#281;kujemy za zg&#322;oszenie!</p>' .
            '<p style="font-size:15px;color:#555;margin:0 0 4px">Cze&#347;&#263; <strong>{first_name} {last_name}</strong>,</p>' .
            '<p style="font-size:15px;color:#333;margin:0 0 20px">Twoje zg&#322;oszenie zosta&#322;o przyj&#281;te. <strong>Potwierdzimy je wkr&oacute;tce</strong> &mdash; dostaniesz od nas kolejnego maila.</p>' .
            $box .
            '{add_to_calendar}' .
            '<p style="font-size:14px;color:#666;margin:20px 0 0">W razie pyta&#324; lub konieczno&#347;ci anulowania prosimy o kontakt.<br>Do zobaczenia!</p>',

        'confirmed' =>
            '<p style="font-size:22px;font-weight:700;color:#00a32a;margin:0 0 6px">Rezerwacja potwierdzona! &#10003;</p>' .
            '<p style="font-size:15px;color:#555;margin:0 0 4px">Cze&#347;&#263; <strong>{first_name} {last_name}</strong>,</p>' .
            '<p style="font-size:15px;color:#333;margin:0 0 20px">Twoje zg&#322;oszenie zosta&#322;o <strong style="color:#00a32a">potwierdzone</strong>. Czekamy na Ciebie!</p>' .
            $box .
            '{add_to_calendar}' .
            '<p style="font-size:14px;color:#666;margin:20px 0 0">W razie pyta&#324; prosimy o kontakt. Do zobaczenia!</p>',

        'cancelled' =>
            '<p style="font-size:22px;font-weight:700;color:#d63638;margin:0 0 6px">Rezerwacja anulowana</p>' .
            '<p style="font-size:15px;color:#555;margin:0 0 4px">Cze&#347;&#263; <strong>{first_name} {last_name}</strong>,</p>' .
            '<p style="font-size:15px;color:#333;margin:0 0 20px">Informujemy, &#380;e Twoje zg&#322;oszenie na poni&#380;sze wydarzenie zosta&#322;o <strong style="color:#d63638">anulowane</strong>.</p>' .
            $box .
            '<p style="font-size:14px;color:#666;margin:20px 0 0">W razie pyta&#324; lub ch&#281;ci ponownej rejestracji prosimy o kontakt. Przepraszamy za niedogodno&#347;ci.</p>',
    ];

    return $open . ( $bodies[$type] ?? $bodies['new'] ) . $close;
}

// Backward-compat alias
function ebc_default_participant_template() { return ebc_default_tpl('new'); }

// ─── WAITLIST: JOIN ──────────────────────────────────────────────────────────

add_action('wp_ajax_nopriv_ebc_join_waitlist','ebc_handle_join_waitlist');
add_action('wp_ajax_ebc_join_waitlist',       'ebc_handle_join_waitlist');
function ebc_handle_join_waitlist() {
    check_ajax_referer('ebc_booking','nonce');
    global $wpdb;
    $event_id = (int)($_POST['event_id']??0);
    $first    = sanitize_text_field($_POST['first_name']??'');
    $last     = sanitize_text_field($_POST['last_name']??'');
    $email    = sanitize_email($_POST['email']??'');

    if (!$event_id||!$first||!$last||!$email) wp_send_json_error(['message'=>'Proszę wypełnić wszystkie wymagane pola.']);
    if (!is_email($email)) wp_send_json_error(['message'=>'Nieprawidłowy adres email.']);
    if (empty($_POST['rodo'])) wp_send_json_error(['message'=>'Proszę zaakceptować zgodę na przetwarzanie danych osobowych.']);

    // Check event is actually full
    $avail = ebc_get_available_seats($event_id);
    if ($avail > 0) wp_send_json_error(['message'=>'Są jeszcze wolne miejsca — możesz zarezerwować bezpośrednio.']);

    // Check duplicate
    $already = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}ebc_waitlist WHERE event_id=%d AND email=%s AND notified=0",
        $event_id, $email
    ));
    if ($already) wp_send_json_error(['message'=>'Twój adres email jest już na liście oczekujących dla tego wydarzenia.']);

    $wpdb->insert($wpdb->prefix.'ebc_waitlist',[
        'event_id'=>$event_id,'first_name'=>$first,'last_name'=>$last,'email'=>$email,
    ]);

    // Confirmation email
    $event = get_post($event_id);
    if ($event) {
        $date = get_post_meta($event_id,'_ebc_date',true);
        $date_str = $date ? date('d.m.Y',strtotime($date)) : '';
        $logo_url = get_option('ebc_logo_url','');
        $logo = $logo_url ? '<img src="'.esc_url($logo_url).'" alt="" style="max-height:48px;max-width:200px;display:block;margin:0 auto">' : '';
        $body = '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f0f0f0;color:#222">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f0f0f0;padding:24px 0">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%">
<tr><td style="background:#1a1a2e;padding:22px 32px;text-align:center;border-radius:8px 8px 0 0">'.$logo.'</td></tr>
<tr><td style="background:#ffffff;padding:32px 36px;border-left:1px solid #e0e0e0;border-right:1px solid #e0e0e0">
<p style="font-size:22px;font-weight:700;color:#1a1a2e;margin:0 0 6px">Zostałeś dodany do listy oczekujących</p>
<p style="font-size:15px;color:#555;margin:0 0 20px">Cześć <strong>'.esc_html("{$first} {$last}").'</strong>,</p>
<p style="font-size:15px;color:#333;margin:0 0 20px">Zapisaliśmy Cię na listę oczekujących na wydarzenie <strong>'.esc_html($event->post_title).'</strong>'.($date_str?" ({$date_str})":'').'. Gdy pojawi się wolne miejsce, wyślemy Ci osobną wiadomość.</p>
<p style="font-size:14px;color:#666;margin:20px 0 0">W razie pytań prosimy o kontakt.</p>
</td></tr>
<tr><td style="background:#f4f4f4;padding:14px 32px;text-align:center;font-size:12px;color:#888;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 8px 8px">
  Ta wiadomość została wygenerowana automatycznie &mdash; prosimy na nią nie odpowiadać.
</td></tr>
</table></td></tr></table>
</body></html>';
        wp_mail($email,'Lista oczekujących: '.get_the_title($event_id),$body,ebc_email_headers());
    }

    wp_send_json_success(['message'=>"Zostałeś dodany do listy oczekujących. Powiadomimy Cię na adres <strong>{$email}</strong>, gdy pojawi się wolne miejsce."]);
}

// ─── WAITLIST: NOTIFY FIRST IN QUEUE ────────────────────────────────────────

function ebc_notify_waitlist( $event_id ) {
    $avail = ebc_get_available_seats($event_id);
    if ($avail <= 0) return;

    global $wpdb;
    $person = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ebc_waitlist WHERE event_id=%d AND notified=0 ORDER BY created_at ASC LIMIT 1",
        $event_id
    ));
    if (!$person) return;

    $wpdb->update($wpdb->prefix.'ebc_waitlist',['notified'=>1],['id'=>$person->id]);

    $event = get_post($event_id);
    if (!$event) return;
    $date     = get_post_meta($event_id,'_ebc_date',true);
    $ts       = get_post_meta($event_id,'_ebc_time_start',true);
    $date_str = $date ? date('d.m.Y',strtotime($date)) : '';
    $time_str = $ts ? " o {$ts}" : '';
    $booking_url = home_url('/');

    $logo_url = get_option('ebc_logo_url','');
    $logo = $logo_url ? '<img src="'.esc_url($logo_url).'" alt="" style="max-height:48px;max-width:200px;display:block;margin:0 auto">' : '';

    $body = '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f0f0f0;color:#222">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f0f0f0;padding:24px 0">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%">
<tr><td style="background:#1a1a2e;padding:22px 32px;text-align:center;border-radius:8px 8px 0 0">'.$logo.'</td></tr>
<tr><td style="background:#ffffff;padding:32px 36px;border-left:1px solid #e0e0e0;border-right:1px solid #e0e0e0">
<p style="font-size:22px;font-weight:700;color:#f59e0b;margin:0 0 6px">&#128276; Pojawiło się wolne miejsce!</p>
<p style="font-size:15px;color:#555;margin:0 0 4px">Cześć <strong>'.esc_html("{$person->first_name} {$person->last_name}").'</strong>,</p>
<p style="font-size:15px;color:#333;margin:0 0 20px">Informujemy, że pojawiło się wolne miejsce na wydarzenie, na które czekałeś/-aś:</p>
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px">
<tr><td style="background:#f5f7ff;border-left:4px solid #f59e0b;padding:16px 20px;border-radius:4px">
  <p style="margin:0 0 4px;font-size:18px;font-weight:700;color:#1a1a2e">'.esc_html($event->post_title).'</p>
  '.($date_str ? '<p style="margin:4px 0;font-size:14px;color:#555">&#128197; '.$date_str.$time_str.'</p>' : '').'
</td></tr></table>
<p style="margin:0 0 24px;text-align:center">
  <a href="'.esc_url($booking_url).'" style="background:#f59e0b;color:#fff;padding:14px 28px;border-radius:6px;text-decoration:none;font-weight:700;font-size:15px;display:inline-block">Zarezerwuj teraz &rarr;</a>
</p>
<p style="font-size:13px;color:#888;margin:0">Nie zwlekaj &mdash; miejsca mogą się szybko wypełnić.</p>
</td></tr>
<tr><td style="background:#f4f4f4;padding:14px 32px;text-align:center;font-size:12px;color:#888;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 8px 8px">
  Ta wiadomość została wygenerowana automatycznie &mdash; prosimy na nią nie odpowiadać.
</td></tr>
</table></td></tr></table>
</body></html>';

    wp_mail($person->email,'Wolne miejsce: '.get_the_title($event_id),$body,ebc_email_headers());
}

// ─── WAITLIST: ADMIN PAGE ────────────────────────────────────────────────────

function ebc_waitlist_page() {
    global $wpdb;
    $table  = $wpdb->prefix . 'ebc_waitlist';

    // Delete
    if (isset($_GET['action']) && $_GET['action']==='delete' && isset($_GET['id']) && isset($_GET['_wpnonce'])) {
        $wid = (int)$_GET['id'];
        if (wp_verify_nonce($_GET['_wpnonce'],'ebc_del_wait_'.$wid)) {
            $wpdb->delete($table,['id'=>$wid]);
            echo '<div class="notice notice-success is-dismissible"><p>Wpis usunięty.</p></div>';
        }
    }

    $filter_event = (int)($_GET['event_id']??0);
    $where = $filter_event ? $wpdb->prepare('WHERE w.event_id=%d',$filter_event) : '';

    $items = $wpdb->get_results("SELECT w.*,p.post_title AS event_title FROM $table w LEFT JOIN {$wpdb->posts} p ON w.event_id=p.ID $where ORDER BY w.created_at ASC");
    $events = get_posts(['post_type'=>'ebc_event','numberposts'=>-1,'meta_key'=>'_ebc_date','orderby'=>'meta_value','order'=>'ASC']);
    ?>
    <div class="wrap">
        <h1>Lista oczekujących</h1>
        <form method="get" style="display:flex;gap:10px;align-items:center;margin-bottom:16px">
            <input type="hidden" name="post_type" value="ebc_event">
            <input type="hidden" name="page" value="ebc-waitlist">
            <select name="event_id" onchange="this.form.submit()">
                <option value="">— wszystkie wydarzenia —</option>
                <?php foreach ($events as $ev): ?>
                    <option value="<?php echo $ev->ID; ?>" <?php selected($filter_event,$ev->ID); ?>><?php echo esc_html($ev->post_title); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <table class="wp-list-table widefat fixed striped" style="font-size:13px">
            <thead>
                <tr>
                    <th>Wydarzenie</th>
                    <th>Uczestnik</th>
                    <th>Email</th>
                    <th style="width:100px">Status</th>
                    <th style="width:140px">Data zapisu</th>
                    <th style="width:80px">Akcja</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="6" style="text-align:center;color:#888;padding:20px">Brak wpisów na liście oczekujących.</td></tr>
            <?php endif; ?>
            <?php foreach ($items as $w): ?>
                <tr>
                    <td><strong><?php echo esc_html($w->event_title); ?></strong></td>
                    <td><?php echo esc_html("{$w->first_name} {$w->last_name}"); ?></td>
                    <td><a href="mailto:<?php echo esc_attr($w->email); ?>"><?php echo esc_html($w->email); ?></a></td>
                    <td><?php echo $w->notified
                        ? '<span style="color:#888">Powiadomiony</span>'
                        : '<span style="color:#f59e0b;font-weight:700">Oczekuje</span>'; ?>
                    </td>
                    <td><?php echo date('d.m.Y H:i',strtotime($w->created_at)); ?></td>
                    <td>
                        <a href="<?php echo wp_nonce_url(admin_url('edit.php?post_type=ebc_event&page=ebc-waitlist&action=delete&id='.$w->id),'ebc_del_wait_'.$w->id); ?>"
                           onclick="return confirm('Usunąć ten wpis?')" style="color:#d63638">Usuń</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
