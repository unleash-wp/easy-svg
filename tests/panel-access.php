<?php
/**
 * Who may open the panel, and which tabs they may see.
 *
 * It was one answer for everything: `manage_options`, for the top-level entry,
 * every submenu entry and the render. That is correct for an administrator and
 * wrong for the one case the add-on needs -- an editor who may swap icons and
 * nothing else. A capability per tab answers both.
 *
 * The rule that falls out of it, and the one this suite is really about: the
 * panel opens for somebody who may see AT LEAST ONE tab. A subscriber may see
 * none, so a subscriber gets nothing: no page, no entry, no bundle.
 *
 * The REST routes are untouched by any of this. Each still checks its own
 * capability, because the screen is never the authority.
 *
 * Usage: php tests/panel-access.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

/** Who the stubbed WordPress thinks is asking. Set per case below. */
$GLOBALS['caps'] = array();
function current_user_can( string $cap ): bool {
    return ! empty( $GLOBALS['caps'][ $cap ] );
}

$GLOBALS['filters'] = array();
function apply_filters( string $hook, $value, ...$args ) {
    foreach ( $GLOBALS['filters'][ $hook ] ?? array() as $cb ) {
        $value = $cb( $value, ...$args );
    }
    return $value;
}
function add_filter( string $hook, $cb, int $p = 10, int $n = 1 ): bool {
    $GLOBALS['filters'][ $hook ][] = $cb;
    return true;
}
function add_action( string $hook, $cb, int $p = 10, int $n = 1 ): bool {
    return true;
}
function __( string $text, string $domain = '' ): string {
    return $text;
}
function esc_attr( $text ): string {
    return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_url( $url ): string {
    return (string) $url;
}
function plugins_url( string $path, string $file ): string {
    return 'https://example.test/' . $path;
}
function sanitize_key( $key ): string {
    return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
}
function wp_unslash( $value ) {
    return $value;
}
function plugin_basename( string $file ): string {
    return basename( dirname( $file ) ) . '/' . basename( $file );
}
function esc_html__( string $text, string $domain = '' ): string {
    return $text;
}
function esc_html( $text ): string {
    return htmlspecialchars( (string) $text, ENT_QUOTES );
}

/*
 * Every menu page the user actually SEES.
 *
 * Capability-filtered, the way WordPress filters it: an entry registered with
 * a capability the user lacks is not drawn. Recording every registration
 * instead would make this suite about which lines ran rather than about what
 * somebody gets, and the subscriber case would then pass for the wrong reason.
 */
$GLOBALS['menu'] = array();
function add_menu_page( $page, $title, $cap, $slug, $cb = '', $icon = '', $pos = null ) {
    if ( current_user_can( (string) $cap ) ) {
        $GLOBALS['menu'][] = array( 'kind' => 'top', 'slug' => $slug, 'cap' => $cap, 'title' => $title );
    }
    return $slug;
}
function add_submenu_page( $parent, $page, $title, $cap, $slug, $cb = '' ) {
    if ( current_user_can( (string) $cap ) ) {
        $GLOBALS['menu'][] = array( 'kind' => 'sub', 'slug' => $slug, 'cap' => $cap, 'title' => $title );
    }
    return $slug;
}

require dirname( __DIR__ ) . '/includes/panel.php';

$passed = 0;
$failed = 0;
function check( string $what, bool $ok ): void {
    global $passed, $failed;
    if ( $ok ) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL  {$what}\n";
}

/**
 * Run the menu registration as a user holding exactly these capabilities.
 *
 * @param array<int,string> $caps
 * @return array<int,array<string,mixed>>
 */
function menu_for( array $caps ): array {
    $GLOBALS['caps'] = array_fill_keys( $caps, true );
    $GLOBALS['menu'] = array();
    easy_svg_panel_menu();
    return $GLOBALS['menu'];
}

/** What the panel prints for the current user. */
function render_for( array $caps ): string {
    $GLOBALS['caps'] = array_fill_keys( $caps, true );
    ob_start();
    easy_svg_panel_render();
    return (string) ob_get_clean();
}

/** The tab slugs a menu listing offers, without the panel's own entry. */
function tab_slugs( array $menu ): array {
    $out = array();
    foreach ( $menu as $item ) {
        if ( str_contains( (string) $item['slug'], '&tab=' ) ) {
            $out[] = substr( (string) $item['slug'], strpos( (string) $item['slug'], '&tab=' ) + 5 );
        }
    }
    return $out;
}

// ── An administrator: everything, as before ──────────────────────────────────

$admin = menu_for( array( 'manage_options' ) );
check( 'SILENCE: an administrator still gets the panel', array() !== $admin );
check( 'SILENCE: and both free tabs', array( 'settings', 'library' ) === tab_slugs( $admin ) );
check( 'SILENCE: and the page still renders', str_contains( render_for( array( 'manage_options' ) ), 'easy-svg-panel' ) );

// ── A subscriber: nothing, and this is the one that must never regress ───────

$subscriber = menu_for( array( 'read' ) );
check( 'SILENCE: a subscriber gets no menu at all', array() === $subscriber );
check( 'SILENCE: and no page', '' === render_for( array( 'read' ) ) );

// ── A tab that names its own capability ──────────────────────────────────────

/*
 * What the add-on does: one tab, one capability, and nothing else of the
 * panel. The filter is the same one Pro already uses for its menu entries --
 * it carries an array now, beside the plain label it carried before.
 */
add_filter(
    'easy_svg_panel_menu_tabs',
    static function ( $tabs ) {
        $tabs['swap'] = array(
            'label'      => 'Replace icons',
            'capability' => 'easy_svg_swap_icons',
        );
        return $tabs;
    }
);

$editor = menu_for( array( 'edit_posts', 'easy_svg_swap_icons' ) );
check( 'BELL: a user with one tab capability gets the panel', array() !== $editor );
check( 'BELL: and exactly that tab', array( 'swap' ) === tab_slugs( $editor ) );
check( 'BELL: not the ones that need manage_options', ! in_array( 'settings', tab_slugs( $editor ), true ) );
check( 'BELL: and the page renders for them', str_contains( render_for( array( 'easy_svg_swap_icons' ) ), 'easy-svg-panel' ) );

// The top-level entry has to be reachable by that user, or the submenu under
// it is unreachable however low its own capability is.
$top = array_values( array_filter( $editor, static fn( $i ) => 'top' === $i['kind'] ) );
check( 'BELL: the top-level entry asks for a capability they hold', 1 === count( $top ) && current_user_can( (string) $top[0]['cap'] ) );

/*
 * An administrator holds the add-on's capability as well: Pro grants it
 * through `user_has_cap` rather than writing it into a stored role, so it
 * cannot outlive the flag. So an administrator sees that tab beside the rest.
 */
$admin_now = menu_for( array( 'manage_options', 'easy_svg_swap_icons' ) );
check( 'SILENCE: an administrator sees that tab too', in_array( 'swap', tab_slugs( $admin_now ), true ) );
check( 'SILENCE: and still sees their own', in_array( 'settings', tab_slugs( $admin_now ), true ) );

// A subscriber still sees nothing, with the add-on's tab registered.
check( 'SILENCE: a subscriber still gets nothing', array() === menu_for( array( 'read' ) ) );
check( 'SILENCE: and still no page', '' === render_for( array( 'read' ) ) );

// ── A plain label keeps working: that is what an older add-on passes ─────────

add_filter(
    'easy_svg_panel_menu_tabs',
    static function ( $tabs ) {
        $tabs['legacy'] = 'An older add-on';
        return $tabs;
    }
);

check( 'BELL: a plain label still registers its tab', in_array( 'legacy', tab_slugs( menu_for( array( 'manage_options' ) ) ), true ) );
check( 'BELL: and it is an administrator tab, since it named nothing else', ! in_array( 'legacy', tab_slugs( menu_for( array( 'easy_svg_swap_icons' ) ) ), true ) );

echo 0 === $failed
    ? "all {$passed} checks passed\n"
    : "{$failed} of " . ( $passed + $failed ) . " checks FAILED\n";

exit( 0 === $failed ? 0 : 1 );
