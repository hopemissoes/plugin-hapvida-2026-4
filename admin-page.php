<?php
// Verifique se este arquivo está sendo acessado diretamente
if (!defined('ABSPATH')) {
    exit;
}

// *** Carrega os traits administrativos ***
require_once plugin_dir_path(__FILE__) . 'includes/admin/trait-admin-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/trait-admin-utilities.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/trait-admin-ajax.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/trait-admin-scripts.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/trait-admin-shortcode.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/trait-admin-page-renderer.php';

class Formulario_Hapvida_Admin
{
    use AdminSettingsTrait;
    use AdminUtilitiesTrait;
    use AdminAjaxTrait;
    use AdminScriptsTrait;
    use AdminShortcodeTrait;
    use AdminPageRendererTrait;

    private $option_name = 'formulario_hapvida_settings';
    private $daily_submissions_option = 'formulario_hapvida_daily_submissions';
    private $monthly_submissions_option = 'formulario_hapvida_monthly_submissions';
    private $failed_webhooks_option = 'formulario_hapvida_failed_webhooks';


    public function __construct()
    {
        $this->option_name = 'formulario_hapvida_settings';
        $this->daily_submissions_option = 'formulario_hapvida_daily_submissions';
        $this->monthly_submissions_option = 'formulario_hapvida_monthly_submissions';
        $this->failed_webhooks_option = 'formulario_hapvida_failed_webhooks';

        // Hooks administrativos
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // AJAX para backend (admin) - apenas para usuários logados
        add_action('wp_ajax_clear_submission_stats', array($this, 'ajax_clear_submission_stats'));
        add_action('wp_ajax_adjust_daily_count', array($this, 'ajax_adjust_daily_count'));

        // *** CRÍTICO: AJAX para frontend (shortcode público) ***
        // Actions que precisam funcionar COM e SEM login (nopriv é ESSENCIAL!)

        // Contagens
        add_action('wp_ajax_get_counts', array($this, 'ajax_get_counts'));
        add_action('wp_ajax_nopriv_get_counts', array($this, 'ajax_get_counts'));

        // Contagens em tempo real
        add_action('wp_ajax_get_live_counts', array($this, 'ajax_get_live_counts'));
        add_action('wp_ajax_nopriv_get_live_counts', array($this, 'ajax_get_live_counts'));

        // Buscar últimos leads (essencial para funcionar sem login)
        add_action('wp_ajax_get_recent_leads', array($this, 'ajax_get_recent_leads'));
        add_action('wp_ajax_nopriv_get_recent_leads', array($this, 'ajax_get_recent_leads'));

        // Exportar todos os leads
        add_action('wp_ajax_get_all_leads_for_export', array($this, 'ajax_get_all_leads_for_export'));
        add_action('wp_ajax_nopriv_get_all_leads_for_export', array($this, 'ajax_get_all_leads_for_export'));

        // Ajustar contagem de submissões
        add_action('wp_ajax_adjust_submission_count', array($this, 'ajax_adjust_submission_count'));
        add_action('wp_ajax_nopriv_adjust_submission_count', array($this, 'ajax_adjust_submission_count'));

        // Webhooks pendentes frontend
        add_action('wp_ajax_get_pending_webhooks_frontend', array($this, 'ajax_get_pending_webhooks_frontend'));
        add_action('wp_ajax_nopriv_get_pending_webhooks_frontend', array($this, 'ajax_get_pending_webhooks_frontend'));

        // Detalhes do webhook/lead
        add_action('wp_ajax_get_webhook_lead_details_public', array($this, 'ajax_get_webhook_lead_details_public'));
        add_action('wp_ajax_nopriv_get_webhook_lead_details_public', array($this, 'ajax_get_webhook_lead_details_public'));
    }
}

$formulario_hapvida_admin = new Formulario_Hapvida_Admin();
