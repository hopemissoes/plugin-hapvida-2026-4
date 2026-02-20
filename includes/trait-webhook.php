<?php
if (!defined('ABSPATH')) exit;

trait WebhookTrait {
    private function save_webhook_entry($webhook_data, $status = 'pending', $error_message = '', $response_code = null, $webhook_url = '')
    {
        try {
            error_log("💾 [DEBUG] Salvando webhook entry - Status: $status");

            $failed_webhooks = get_option($this->failed_webhooks_option, array());

            // Garante que sempre tem um ID único
            $webhook_id = 'webhook_' . time() . '_' . wp_rand(1000, 9999);

            // Schedule de retry em background: 2min, 5min, 10min
            $retry_intervals = array(2, 5, 10);
            $next_retry_minutes = isset($retry_intervals[0]) ? $retry_intervals[0] : 2;

            $entry = array(
                'id' => $webhook_id,
                'webhook_id' => $webhook_id,
                'data' => $webhook_data,
                'status' => $status,
                'attempts' => 0,
                'max_attempts' => 3,
                'created_at' => current_time('mysql'),
                'last_attempt' => current_time('mysql'),
                'error' => $error_message,
                'response_code' => $response_code,
                'webhook_url' => $webhook_url,
                'next_retry' => date('Y-m-d H:i:s', strtotime("+{$next_retry_minutes} minutes")),
                'retry_schedule' => $retry_intervals,
            );

            // Adiciona no início do array para aparecer primeiro
            array_unshift($failed_webhooks, $entry);

            // Limita a 5000 leads para manter histórico adequado
            if (count($failed_webhooks) > 5000) {
                $failed_webhooks = array_slice($failed_webhooks, 0, 5000);
            }

            $result = update_option($this->failed_webhooks_option, $failed_webhooks);

            error_log("✅ [DEBUG] Webhook salvo - ID: {$webhook_id}, Status: {$status}, Resultado: " . ($result ? 'sucesso' : 'falha'));

            return true;

        } catch (Exception $e) {
            error_log("❌ [DEBUG] Erro ao salvar webhook: " . $e->getMessage());
            return false;
        }
    }

    private function increment_submission_count()
    {
        $today = current_time('Y-m-d');
        $current_month = current_time('Y-m');

        $daily_submissions = get_option($this->daily_submissions_option, array());
        $daily_submissions[$today] = isset($daily_submissions[$today]) ? $daily_submissions[$today] + 1 : 1;
        update_option($this->daily_submissions_option, $daily_submissions);

        $monthly_submissions = get_option($this->monthly_submissions_option, array());
        $monthly_submissions[$current_month] = isset($monthly_submissions[$current_month]) ?
            $monthly_submissions[$current_month] + 1 : 1;
        update_option($this->monthly_submissions_option, $monthly_submissions);

        error_log("✅ [DEBUG] Contadores incrementados - Daily: " . $daily_submissions[$today] .
            ", Monthly: " . $monthly_submissions[$current_month]);
    }

    private function prepare_webhook_data($form_data, $vendedor)
    {
        $webhook_data = $form_data;

        $webhook_data['vendedor_nome'] = $vendedor['nome'];
        $webhook_data['vendedor_telefone'] = $vendedor['telefone'];
        $webhook_data['vendedor_id'] = isset($vendedor['vendedor_id']) ? $vendedor['vendedor_id'] : '';
        $webhook_data['grupo'] = isset($vendedor['grupo']) ? $vendedor['grupo'] : 'drv';
        $webhook_data['atendente'] = $vendedor['nome'];

        $daily_submissions = get_option($this->daily_submissions_option, array());
        $monthly_submissions = get_option($this->monthly_submissions_option, array());
        $today = current_time('Y-m-d');
        $current_month = current_time('Y-m');

        $webhook_data['contagem_diaria'] = isset($daily_submissions[$today]) ?
            $daily_submissions[$today] : 0;
        $webhook_data['contagem_mensal'] = isset($monthly_submissions[$current_month]) ?
            $monthly_submissions[$current_month] : 0;

        $webhook_data['nome'] = $form_data['name'];
        $webhook_data['quantidade_de_pessoas'] = $form_data['qtd_pessoas'];
        $webhook_data['tipo_de_plano'] = $form_data['qual_plano'];
        $webhook_data['idades'] = is_array($form_data['ages']) ?
            implode(', ', $form_data['ages']) : $form_data['ages'];

        return $webhook_data;
    }

    private function should_retry_webhook($response, $response_body = '')
    {
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $error_code = $response->get_error_code();

            $this->log("🔍 Analisando erro WP_Error - Código: {$error_code}, Mensagem: {$error_message}");

            $retry_patterns = array(
                'timed out',
                'timeout',
                'operation timed out',
                'exceeded the allotted timeout',
                'connect timed out',
                'read timed out',
                'gateway time-out',
                'request timeout',
                'error 28',
                'cURL error 28',
                'resolving timed out after \d+ milliseconds',
                'curle_operation_timedout',
                'curle_operation_timeout',
                'operation_timedout',
                'CURLE_OPERATION_TIMEDOUT',
                'connection reset',
                'connection refused',
                'could not resolve host',
                'ssl connection timeout',
                'connection timed out',
                'network is unreachable',
                'connection aborted',
                'broken pipe',
                'no route to host',
                'connection closed',
                'mongodb.*exception.*socket',
                'mongoSocketOpenException',
                'SocketTimeoutException',
                'sheets.googleapis.com.*timeout',
                'service unavailable',
                'bad gateway',
                'gateway timeout',
                '502 bad gateway',
                '503 service unavailable',
                '504 gateway timeout',
                'upstream timed out',
                'cloudflare.*timeout',
                'rate limit',
                'too many requests',
                '429 too many requests',
                'dns.*timeout',
                'could not resolve',
                'name resolution',
                'ssl.*timeout',
                'tls.*timeout',
                'handshake.*timeout'
            );

            foreach ($retry_patterns as $pattern) {
                if (preg_match('/' . $pattern . '/i', $error_message)) {
                    $this->log("✅ Erro identificado como temporário: corresponde ao padrão '{$pattern}'");
                    return true;
                }
            }

            if (strpos($error_message, '28') !== false || $error_code === 'http_request_failed') {
                $this->log("✅ Possível erro 28 detectado - permitindo retry");
                return true;
            }

            $this->log("❌ Erro não identificado como temporário - NÃO será feito retry");
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);

        if ($response_code) {
            $this->log("🔍 Analisando código HTTP: {$response_code}");

            $retry_codes = array(408, 429, 500, 502, 503, 504, 520, 521, 522, 523, 524, 525, 526, 527);

            if (in_array($response_code, $retry_codes)) {
                $this->log("✅ Código HTTP {$response_code} identificado como temporário - retry permitido");
                return true;
            }

            if (!empty($response_body)) {
                $temp_error_patterns = array(
                    'temporarily unavailable',
                    'try again later',
                    'service is busy',
                    'under maintenance',
                    'rate limit exceeded',
                    'quota exceeded',
                    'too many connections'
                );

                foreach ($temp_error_patterns as $pattern) {
                    if (stripos($response_body, $pattern) !== false) {
                        $this->log("✅ Mensagem de erro temporário detectada no corpo: '{$pattern}'");
                        return true;
                    }
                }
            }

            $no_retry_codes = array(400, 401, 403, 404, 405, 406, 409, 410, 422);

            if (in_array($response_code, $no_retry_codes)) {
                $this->log("❌ Código HTTP {$response_code} é definitivo - NÃO será feito retry");
                return false;
            }
        }

        $this->log("⚠️ Caso não identificado - por segurança, NÃO será feito retry");
        return false;
    }

    private function get_webhook_timeout_config($is_retry = false)
    {
        $base_timeout = $is_retry ? 20 : 15;

        return array(
            'timeout' => $base_timeout,
            'httpversion' => '1.1',
            'redirection' => 2,
            'blocking' => true,
            'sslverify' => false,
            'compress' => true,
            'stream' => false,
            'decompress' => true,
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'Formulario-Hapvida/2.0-Optimized',
                'Connection' => 'close',
                'Accept' => 'application/json',
                'Cache-Control' => 'no-cache'
            )
        );
    }

    /**
     * AJAX para retry manual de webhooks (para usar na admin)
     */
    public function ajax_retry_failed_webhooks()
    {
        check_ajax_referer('retry_webhooks_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        $failed_webhooks = get_option($this->failed_webhooks_option, array());
        $pending_count = count(array_filter($failed_webhooks, function ($w) {
            return in_array($w['status'], array('pending', 'pending_retry'));
        }));

        wp_send_json_success(array(
            'message' => 'Processo de retry executado com sucesso',
            'pending_webhooks' => $pending_count
        ));
    }
}
