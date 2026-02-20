<?php
/**
 * Sistema de Retry Automático de Webhooks em Background
 *
 * Este cron roda a cada 5 minutos e reprocessa webhooks que falharam
 * nas tentativas imediatas. Implementa backoff progressivo:
 * - Tentativa 1: 5 minutos após falha
 * - Tentativa 2: 15 minutos
 * - Tentativa 3: 30 minutos
 * - Tentativa 4: 1 hora
 * - Tentativa 5: 2 horas
 *
 * Só envia email de "Lead PERDIDO" após esgotar TODAS as tentativas.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Formulario_Hapvida_Webhook_Retry {

    const CRON_HOOK = 'formulario_hapvida_retry_webhooks';
    const CRON_INTERVAL = 'hapvida_five_minutes';
    const MAX_PROCESS_PER_RUN = 10;

    private $failed_webhooks_option = 'formulario_hapvida_failed_webhooks';
    private $settings_option_name = 'formulario_hapvida_settings';
    private $log_file;

    public function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/formulario_hapvida.log';

        // Registra intervalo de 5 minutos
        add_filter('cron_schedules', array($this, 'add_cron_interval'));

        // Registra o hook do cron
        add_action(self::CRON_HOOK, array($this, 'process_pending_retries'));

        // Agenda o cron se não estiver agendado
        add_action('init', array($this, 'schedule_retry_cron'));

        // Hook de desativação
        register_deactivation_hook(
            plugin_dir_path(__FILE__) . 'formulario-hapvida.php',
            array($this, 'deactivate')
        );
    }

    /**
     * Adiciona intervalo de 5 minutos ao WP Cron
     */
    public function add_cron_interval($schedules) {
        if (!isset($schedules[self::CRON_INTERVAL])) {
            $schedules[self::CRON_INTERVAL] = array(
                'interval' => 300, // 5 minutos
                'display' => 'A cada 5 minutos (Webhook Retry)'
            );
        }
        return $schedules;
    }

    /**
     * Agenda o cron de retry
     */
    public function schedule_retry_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
            $this->log("Cron de retry agendado - a cada 5 minutos");
        }
    }

    /**
     * Processa webhooks pendentes de retry
     * Executado a cada 5 minutos via WP Cron
     */
    public function process_pending_retries() {
        $webhooks = get_option($this->failed_webhooks_option, array());

        if (empty($webhooks)) {
            return;
        }

        $now = current_time('mysql');
        $now_timestamp = strtotime($now);
        $processed = 0;
        $success_count = 0;
        $fail_count = 0;
        $has_changes = false;

        $this->log("=== RETRY CRON: Iniciando processamento ===");

        foreach ($webhooks as $index => &$webhook) {
            // Só processa webhooks com status pending_retry
            if ($webhook['status'] !== 'pending_retry') {
                continue;
            }

            // Verifica se já passou o tempo de next_retry
            if (!empty($webhook['next_retry'])) {
                $next_retry_timestamp = strtotime($webhook['next_retry']);
                if ($next_retry_timestamp > $now_timestamp) {
                    continue; // Ainda não é hora de retentar
                }
            }

            // Verifica se excedeu max_attempts
            $max_attempts = isset($webhook['max_attempts']) ? intval($webhook['max_attempts']) : 5;
            $attempts = isset($webhook['attempts']) ? intval($webhook['attempts']) : 0;

            if ($attempts >= $max_attempts) {
                // Esgotou todas as tentativas - marca como falha permanente
                $webhook['status'] = 'permanent_failure';
                $total_attempts = $attempts + 3; // 3 imediatas + N background
                $webhook['error'] = "Esgotou todas as {$total_attempts} tentativas (3 imediatas + {$attempts} background)";
                $has_changes = true;

                $nome = isset($webhook['data']['nome']) ? $webhook['data']['nome'] : 'N/A';
                $telefone = isset($webhook['data']['telefone']) ? $webhook['data']['telefone'] : 'N/A';
                $vendedor = isset($webhook['data']['vendedor_nome']) ? $webhook['data']['vendedor_nome'] : 'N/A';
                error_log("HAPVIDA RETRY CRITICAL: Lead PERDIDO DEFINITIVAMENTE apos {$total_attempts} tentativas totais - Cliente: {$nome}, Tel: {$telefone}, Vendedor: {$vendedor}");
                $this->log("❌ FALHA PERMANENTE: Lead {$nome} ({$telefone}) perdido apos {$total_attempts} tentativas");
                $fail_count++;
                continue;
            }

            // Limita processamento por execução
            if ($processed >= self::MAX_PROCESS_PER_RUN) {
                $this->log("Limite de " . self::MAX_PROCESS_PER_RUN . " por execução atingido");
                break;
            }

            // Determina a URL do webhook
            $webhook_url = $this->get_webhook_url($webhook);
            if (empty($webhook_url)) {
                $webhook['status'] = 'permanent_failure';
                $webhook['error'] = 'URL do webhook não encontrada para retry';
                $has_changes = true;
                error_log("HAPVIDA RETRY: URL nao encontrada para webhook {$webhook['id']} - marcado como falha permanente");
                $fail_count++;
                continue;
            }

            // Tenta enviar o webhook
            $attempt_num = $attempts + 1;
            $this->log("RETRY tentativa {$attempt_num}/{$max_attempts} para webhook {$webhook['id']}");

            $result = $this->send_webhook($webhook_url, $webhook['data']);

            $webhook['attempts'] = $attempt_num;
            $webhook['last_attempt'] = current_time('mysql');
            $has_changes = true;
            $processed++;

            if ($result['success']) {
                $webhook['status'] = 'sent';
                $webhook['error'] = "Enviado com sucesso no retry {$attempt_num} em background - " . $result['message'];
                $success_count++;

                $lead_id = isset($webhook['data']['lead_id']) ? $webhook['data']['lead_id'] : 'N/A';
                $nome = isset($webhook['data']['nome']) ? $webhook['data']['nome'] : 'N/A';
                error_log("HAPVIDA RETRY: SUCESSO! Lead {$lead_id} ({$nome}) enviado no retry {$attempt_num} em background");
                $this->log("✅ RETRY SUCESSO: {$lead_id} enviado na tentativa background {$attempt_num}");

            } else {
                // Falhou - calcula próximo retry
                $retry_schedule = isset($webhook['retry_schedule']) ? $webhook['retry_schedule'] : array(5, 15, 30, 60, 120);
                $next_interval_index = min($attempt_num, count($retry_schedule) - 1);
                $next_interval = $retry_schedule[$next_interval_index];

                $webhook['next_retry'] = date('Y-m-d H:i:s', strtotime("+{$next_interval} minutes"));
                $webhook['error'] = "Retry {$attempt_num} falhou: " . $result['message'] . " - Proximo retry em {$next_interval} minutos";

                $lead_id = isset($webhook['data']['lead_id']) ? $webhook['data']['lead_id'] : 'N/A';
                error_log("HAPVIDA RETRY: FALHA na tentativa {$attempt_num} para lead {$lead_id} - {$result['message']} - Proximo retry: {$webhook['next_retry']}");

                // Se essa era a última tentativa, marca como falha permanente
                if ($attempt_num >= $max_attempts) {
                    $webhook['status'] = 'permanent_failure';
                    $total_attempts = $attempt_num + 3; // 3 imediatas + N background

                    $nome = isset($webhook['data']['nome']) ? $webhook['data']['nome'] : 'N/A';
                    $telefone = isset($webhook['data']['telefone']) ? $webhook['data']['telefone'] : 'N/A';
                    $vendedor = isset($webhook['data']['vendedor_nome']) ? $webhook['data']['vendedor_nome'] : 'N/A';
                    error_log("HAPVIDA RETRY CRITICAL: Lead PERDIDO DEFINITIVAMENTE apos {$total_attempts} tentativas totais - Cliente: {$nome}, Tel: {$telefone}, Vendedor: {$vendedor}");
                    $this->log("❌ FALHA PERMANENTE: Lead {$nome} ({$telefone}) perdido apos {$total_attempts} tentativas");
                }

                $fail_count++;
            }
        }

        if ($has_changes) {
            update_option($this->failed_webhooks_option, $webhooks);
        }

        if ($processed > 0) {
            $this->log("=== RETRY CRON: Processados {$processed} webhooks - {$success_count} sucesso, {$fail_count} falhas ===");
        }
    }

    /**
     * Envia o webhook via HTTP POST
     */
    private function send_webhook($url, $data) {
        $body = json_encode($data);

        $config = array(
            'timeout' => 20,
            'blocking' => true,
            'body' => $body,
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'Formulario-Hapvida/2.0-BackgroundRetry',
                'Connection' => 'close',
            ),
            'sslverify' => false,
            'httpversion' => '1.1',
        );

        $response = wp_remote_post($url, $config);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);

        if ($response_code >= 200 && $response_code < 300) {
            return array(
                'success' => true,
                'message' => "HTTP {$response_code}"
            );
        }

        // Erros definitivos do servidor (4xx exceto 408/429) - não adianta retentar
        if ($response_code >= 400 && $response_code < 500 && $response_code !== 408 && $response_code !== 429) {
            return array(
                'success' => false,
                'message' => "Erro definitivo HTTP {$response_code}",
                'definitive' => true
            );
        }

        $response_body = wp_remote_retrieve_body($response);
        return array(
            'success' => false,
            'message' => "HTTP {$response_code} - " . substr($response_body, 0, 200)
        );
    }

    /**
     * Determina a URL do webhook para retry
     */
    private function get_webhook_url($webhook) {
        // Primeiro tenta a URL salva diretamente no webhook
        if (!empty($webhook['webhook_url'])) {
            return $webhook['webhook_url'];
        }

        // Fallback: determina pela configuração do grupo
        $options = get_option($this->settings_option_name);
        $grupo = isset($webhook['data']['grupo']) ? $webhook['data']['grupo'] : 'drv';

        if ($grupo === 'drv') {
            return isset($options['webhook_url_drv']) ? $options['webhook_url_drv'] : '';
        } else {
            return isset($options['webhook_url_seu_souza']) ? $options['webhook_url_seu_souza'] : '';
        }
    }

    /**
     * Retorna estatísticas do retry system
     */
    public function get_retry_stats() {
        $webhooks = get_option($this->failed_webhooks_option, array());

        $stats = array(
            'pending_retry' => 0,
            'permanent_failure' => 0,
            'sent_via_retry' => 0,
            'next_scheduled' => wp_next_scheduled(self::CRON_HOOK)
                ? date('Y-m-d H:i:s', wp_next_scheduled(self::CRON_HOOK))
                : 'Não agendado',
        );

        foreach ($webhooks as $webhook) {
            if ($webhook['status'] === 'pending_retry') {
                $stats['pending_retry']++;
            } elseif ($webhook['status'] === 'permanent_failure') {
                $stats['permanent_failure']++;
            } elseif ($webhook['status'] === 'sent' && isset($webhook['attempts']) && $webhook['attempts'] > 0) {
                $stats['sent_via_retry']++;
            }
        }

        return $stats;
    }

    /**
     * Força retry imediato de todos os webhooks pendentes (para uso manual)
     */
    public function force_retry_all() {
        $webhooks = get_option($this->failed_webhooks_option, array());
        $reset_count = 0;

        foreach ($webhooks as &$webhook) {
            if ($webhook['status'] === 'pending_retry') {
                $webhook['next_retry'] = current_time('mysql'); // Torna elegível agora
                $reset_count++;
            }
        }

        if ($reset_count > 0) {
            update_option($this->failed_webhooks_option, $webhooks);
        }

        // Dispara o processamento imediato
        $this->process_pending_retries();

        return $reset_count;
    }

    /**
     * Desativa o cron ao desativar o plugin
     */
    public function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        $this->log("Cron de retry desativado");
    }

    /**
     * Log no arquivo do plugin
     */
    private function log($message) {
        $timezone = new DateTimeZone('America/Fortaleza');
        $timestamp = new DateTime('now', $timezone);
        $log_entry = "[" . $timestamp->format('Y-m-d H:i:s') . "] [RETRY] {$message}" . PHP_EOL;
        error_log($log_entry, 3, $this->log_file);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("HAPVIDA RETRY: " . $message);
        }
    }
}

// Instancia a classe de retry
$formulario_hapvida_webhook_retry = new Formulario_Hapvida_Webhook_Retry();
