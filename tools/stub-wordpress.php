<?php
/**
 * Stub WordPress minimal — uniquement pour tools/prevol-import-csv.php.
 *
 * NE PAS confondre avec les tests : le vrai contrat tourne sous WordPress
 * réel (tests/import-csv-contract.php, câblé dans le smoke Docker). Ce stub
 * existe parce que le poste de développement n'a pas de WordPress, et qu'une
 * garde qu'on ne peut pas exécuter sur sa machine ne se vérifie jamais.
 */
define('ABSPATH', __DIR__ . '/');
define('TUC_DIR', dirname(__DIR__) . '/wordpress/tuc-theme');
class WP_Error {
    public function __construct(private string $code = '', private string $msg = '') {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->msg; }
}
function is_wp_error($t): bool { return $t instanceof WP_Error; }
function sanitize_key(string $k): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($k)) ?? ''; }
function sanitize_title(string $t): string { return sanitize_key($t); }
function sanitize_text_field(string $t): string { return trim($t); }
function sanitize_textarea_field(string $t): string { return trim($t); }
function get_terms($a) { return []; }
function term_exists($t, $x) { return null; }
function __($t, $d = null) { return $t; }
function esc_html__($t, $d = null) { return $t; }
function absint($v): int { return abs((int) $v); }
function add_action(...$a) {}
function add_submenu_page(...$a) {}
function get_posts($a) { return []; }
class TUC_Options { public const VERSION = 2; }
class TUC_CPT { public const RESTO = 'tuc_resto'; public const ITEM = 'tuc_menu_item'; }
