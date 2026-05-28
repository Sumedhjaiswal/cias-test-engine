<?php
namespace CIAS_LMS;

defined( 'ABSPATH' ) || exit;

class Admin {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
    }

    public static function register_menu(): void {
        add_submenu_page(
            'cias-dashboard',
            'LMS',
            'LMS',
            'manage_options',
            'cias-lms',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function render_page(): void {
        echo '<div class="wrap"><h1>CIAS LMS</h1><p>LMS admin panel coming soon.</p></div>';
    }
}
