<?php
/**
 * Sistema de Retry Automático de Webhooks em Background
 *
 * Fluxo: formulário tenta 1x rápido (10s). Se falhar, salva na fila.
 * Este cron roda a cada 2 minutos (efetivo: ~3 min, conforme cron do servidor).
 *
 * Schedule de retry (4 tentativas, intervalos em minutos: 3, 6, 9, 12):
 * - 1ª retry: 3 min após falha imediata
 * - 2ª retry: 6 min após falha da 1ª
 * - 3ª retry: 9 min após falha da 2ª
 * - 4ª retry: 12 min após falha da 3ª
 *
 * Pior caso (alinhado ao cron de 3 min): ~30-33 min para esgotar 4 tentativas.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Formulario_Hapvida_Webhook_Retry {

    const CRON_HOOK = 'formulario_hapvida_retry_webhooks';
    const CRON_INTERVAL = 'hapvida_two_minutes';
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

        // AJAX para forçar retry de webhooks travados (admin + frontend logado/anônimo)
        add_action('wp_ajax_hapvida_force_retry_webhooks', array($this, 'ajax_force_retry'));
        add_action('wp_ajax_nopriv_hapvida_force_retry_webhooks', array($this, 'ajax_force_retry'));

        // Hook de desativação
        register_deactivation_hook(
            plugin_dir_path(__FILE__) . 'formulario-hapvida.php',
            array($this, 'deactivate')
        );
    }

    /**
     * Adiciona intervalo de 2 minutos ao WP Cron
     */
    public function add_cron_interval($schedules) {
        if (!isset($schedules[self::CRON_INTERVAL])) {
            $schedules[self::CRON_INTERVAL] = array(
                'interval' => 120, // 2 minutos
                'display' => 'A cada 2 minutos (Webhook Retry)'
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
            $this->log("Cron de retry agendado - a cada 2 minutos");
        }
    }

    /**
     * Processa webhooks pendentes de retry
     * Executado a cada 2 minutos via WP Cron (efetivo: ~3 min, conforme cron servidor)
     */
    public function process_pending_retries() {
        $this->log("process_pending_retries chamado");

        // Lock para impedir execuções simultaneas (race condition no update_option)
        $lock_key = 'hapvida_retry_cron_lock';
        if (get_transient($lock_key)) {
            $this->log("Cron de retry ja em execucao - skip");
            return;
        }
        set_transient($lock_key, time(), 300); // 5 min de safety

        try {
            $this->run_retry_processing();
        } finally {
            delete_transient($lock_key);
        }
    }

    private function run_retry_processing() {
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
            $max_attempts = isset($webhook['max_attempts']) ? intval($webhook['max_attempts']) : 4;
            $attempts = isset($webhook['attempts']) ? intval($webhook['attempts']) : 0;

            if ($attempts >= $max_attempts) {
                // Esgotou todas as tentativas - marca como falha permanente
                $webhook['status'] = 'permanent_failure';
                $total_attempts = $attempts + 1; // 1 imediata + N background
                $webhook['error'] = "Esgotou todas as {$total_attempts} tentativas (1 imediata + {$attempts} background)";
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
                $lead_id = isset($webhook['data']['lead_id']) ? $webhook['data']['lead_id'] : 'N/A';

                // Erro definitivo (4xx exceto 408/429) - nao adianta retentar
                if (!empty($result['definitive'])) {
                    $webhook['status'] = 'permanent_failure';
                    $webhook['error'] = "Erro definitivo no retry {$attempt_num}: " . $result['message'] . " - retries cancelados";
                    error_log("HAPVIDA RETRY: ERRO DEFINITIVO para lead {$lead_id} - {$result['message']} - cancelando retries");
                    $this->log("❌ ERRO DEFINITIVO: Lead {$lead_id} - {$result['message']} - retries cancelados");
                    $fail_count++;
                    continue;
                }

                // Falhou - calcula próximo retry
                $retry_schedule = isset($webhook['retry_schedule']) ? $webhook['retry_schedule'] : array(3, 6, 9, 12);
                $next_interval_index = min($attempt_num, count($retry_schedule) - 1);
                $next_interval = $retry_schedule[$next_interval_index];

                $webhook['next_retry'] = date('Y-m-d H:i:s', strtotime("+{$next_interval} minutes"));
                $webhook['error'] = "Retry {$attempt_num} falhou: " . $result['message'] . " - Proximo retry em {$next_interval} minutos";

                error_log("HAPVIDA RETRY: FALHA na tentativa {$attempt_num} para lead {$lead_id} - {$result['message']} - Proximo retry: {$webhook['next_retry']}");

                // Garante que o proximo retry vai disparar - agenda single event
                $target_time = time() + ($next_interval * 60);
                $existing = wp_next_scheduled('formulario_hapvida_retry_webhooks');
                if (!$existing || abs($existing - $target_time) > 60) {
                    wp_schedule_single_event($target_time, 'formulario_hapvida_retry_webhooks');
                }

                // Se essa era a última tentativa, marca como falha permanente
                if ($attempt_num >= $max_attempts) {
                    $webhook['status'] = 'permanent_failure';
                    $total_attempts = $attempt_num + 1; // 1 imediata + N background

                    $nome = isset($webhook['data']['nome']) ? $webhook['data']['nome'] : 'N/A';
                    $telefone = isset($webhook['data']['telefone']) ? $webhook['data']['telefone'] : 'N/A';
                    $vendedor = isset($webhook['data']['vendedor_nome']) ? $webhook['data']['vendedor_nome'] : 'N/A';
                    error_log("HAPVIDA RETRY CRITICAL: Lead PERDIDO DEFINITIVAMENTE apos {$total_attempts} tentativas totais (1 imediata + {$attempt_num} background) - Cliente: {$nome}, Tel: {$telefone}, Vendedor: {$vendedor}");
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
            $status = isset($webhook['status']) ? $webhook['status'] : '';

            // Reenvio manual: torna elegivel agora tanto os que aguardam retry
            // quanto os que ja esgotaram as tentativas (falha permanente).
            if ($status === 'pending_retry' || $status === 'permanent_failure') {
                if ($status === 'permanent_failure') {
                    // Revive a falha permanente com um novo ciclo de tentativas
                    $webhook['attempts'] = 0;
                }
                $webhook['status'] = 'pending_retry';
                $webhook['next_retry'] = current_time('mysql'); // Torna elegível agora
                $reset_count++;
            }
        }
        unset($webhook);

        if ($reset_count > 0) {
            update_option($this->failed_webhooks_option, $webhooks);
        }

        // Dispara o processamento imediato
        $this->process_pending_retries();

        return $reset_count;
    }

    /**
     * AJAX handler para forçar retry imediato de todos os pendentes.
     * Aceita request do admin e do shortcode público (com ou sem login).
     */
    public function ajax_force_retry() {
        // Sem verificacao de nonce: o shortcode de contagem e publico e pode
        // estar em cache, o que invalida o nonce e quebra o botao. A acao
        // apenas reenvia webhooks ja enfileirados (sem exposicao de dados),
        // seguindo o mesmo padrao dos demais endpoints AJAX publicos do plugin.

        // O processamento e sincrono e pode levar alguns segundos
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        // Destrava lock travado (TTL de 5min ainda assim limita abuso)
        delete_transient('hapvida_retry_cron_lock');

        $webhooks = get_option($this->failed_webhooks_option, array());
        $pending_before = 0;
        foreach ($webhooks as $w) {
            if (isset($w['status']) && $w['status'] === 'pending_retry') {
                $pending_before++;
            }
        }

        $reset_count = $this->force_retry_all();

        // Recalcula quantos seguem pendentes
        $webhooks_after = get_option($this->failed_webhooks_option, array());
        $still_pending = 0;
        $just_sent = 0;
        foreach ($webhooks_after as $w) {
            if (!isset($w['status'])) continue;
            if ($w['status'] === 'pending_retry') $still_pending++;
            elseif ($w['status'] === 'sent') $just_sent++;
        }

        wp_send_json_success(array(
            'message' => "Forçado retry em {$reset_count} webhook(s). Restam {$still_pending} pendente(s).",
            'reset_count' => $reset_count,
            'still_pending' => $still_pending,
            'pending_before' => $pending_before,
            'sent_total' => $just_sent,
        ));
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
