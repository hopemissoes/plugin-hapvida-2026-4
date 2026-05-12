<?php
if (!defined('ABSPATH')) exit;

trait FormHandlerTrait {

    public function handle_form_submission($request)
    {

        // DEBUG TEMPORÃRIO - REMOVER DEPOIS
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        ini_set('log_errors', 1);
        ini_set('error_log', WP_CONTENT_DIR . '/debug_hapvida.log');

        try {
            $start_time = microtime(true);
            $session_id = uniqid('sess_', true);

            // Extração e validação dos dados (MANTENDO ESTRUTURA ORIGINAL)
            $params = $request->get_params();

            // *** EXTRAÇÃO DOS DADOS DO FORMULÃRIO (MANTENDO ESTRUTURA ORIGINAL) ***
            $form_data = array();

            // Verifica se os dados vêm de form_fields[] ou diretamente
            if (isset($params['form_fields']) && is_array($params['form_fields'])) {
                $form_data['name'] = isset($params['form_fields']['name']) ? $params['form_fields']['name'] : '';
                $form_data['telefone'] = isset($params['form_fields']['telefone']) ? $params['form_fields']['telefone'] : '';
                $form_data['cidade'] = isset($params['form_fields']['cidade']) ? $params['form_fields']['cidade'] : '';
                $form_data['qual_plano'] = isset($params['form_fields']['qual_plano']) ? $params['form_fields']['qual_plano'] : '';
                $form_data['qtd_pessoas'] = isset($params['form_fields']['qtd_pessoas']) ? $params['form_fields']['qtd_pessoas'] : '1';
                $form_data['ages'] = isset($params['form_fields']['ages']) ? $params['form_fields']['ages'] : array();
            } else {
                $form_data['name'] = isset($params['name']) ? $params['name'] : '';
                $form_data['telefone'] = isset($params['telefone']) ? $params['telefone'] : '';
                $form_data['cidade'] = isset($params['cidade']) ? $params['cidade'] : '';
                $form_data['qual_plano'] = isset($params['qual_plano']) ? $params['qual_plano'] : '';
                $form_data['qtd_pessoas'] = isset($params['qtd_pessoas']) ? $params['qtd_pessoas'] : '1';
                $form_data['ages'] = isset($params['ages']) ? $params['ages'] : array();
            }

            // Defaults
            $defaults = array(
                'name' => '',
                'telefone' => '',
                'cidade' => '',
                'qual_plano' => '',
                'qtd_pessoas' => '1',
                'ages' => array(),
                'pagina_origem' => (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : home_url())
            );

            $form_data = array_merge($defaults, $form_data);

            // Validação de campos obrigatórios
            foreach ($this->required_fields as $field) {
                if (empty($form_data[$field])) {
                    throw new Exception("Campo obrigatório ausente: {$field}");
                }
            }

            // Formata telefone ANTES da verificação de duplicata (para hash consistente)
            $form_data['telefone'] = $this->format_phone_number($form_data['telefone']);

            // Verifica se já foi processado - BLOQUEIA duplicatas
            if ($this->is_form_processed($form_data)) {
                error_log("HAPVIDA DUPLICATA BLOQUEADA: Telefone {$form_data['telefone']} - Nome: {$form_data['name']}");
                $this->log(">>> DUPLICATA BLOQUEADA: Telefone {$form_data['telefone']}");
                throw new Exception("Este telefone já enviou um formulário recentemente. Aguarde alguns minutos antes de tentar novamente.");
            }

            // Marca como processado
            $this->mark_form_as_processed($form_data);

            // Processa dados básicos
            $form_data['data'] = current_time('d/m/Y');
            $form_data['hora'] = current_time('H:i:s');
            $form_data['timestamp'] = current_time('timestamp');
            $form_data['ages'] = $this->extract_ages_from_request($params);
            $form_data['lead_id'] = $this->generate_unique_lead_id();

            // Log dos dados
            $this->log("ðŸ“‹ DADOS DO FORMULÃRIO: ===== NOVA SUBMISSÃO =====");
            $this->log("Lead ID: {$form_data['lead_id']}");
            $this->log("Nome: {$form_data['name']}");
            $this->log("Telefone: {$form_data['telefone']}");
            $this->log("Cidade: {$form_data['cidade']}");

            // Atualiza contadores
            $this->update_submission_counts();

            // *** WEBHOOK: 1 tentativa rápida, se falhar vai pro cron ***
            $options = get_option($this->settings_option_name);
            $is_business_hours = $this->is_horario_comercial();
            $webhook_success = false;
            $webhook_state = 'failed'; // 'sent' (ok imediato) | 'queued' (na fila de retry) | 'failed' (definitivo / sem URL)

            try {
                // Prepara dados do webhook
                $webhook_data = $form_data;
                $webhook_data['url_origem'] = $form_data['pagina_origem'];

                // Contagens
                $daily_submissions = get_option($this->daily_submissions_option, array());
                $monthly_submissions = get_option($this->monthly_submissions_option, array());
                $today = current_time('Y-m-d');
                $current_month = current_time('Y-m');

                $webhook_data['contagem_diaria'] = isset($daily_submissions[$today]) ?
                    $daily_submissions[$today] : 0;
                $webhook_data['contagem_mensal'] = isset($monthly_submissions[$current_month]) ?
                    $monthly_submissions[$current_month] : 0;

                // Formata campos específicos
                $webhook_data['nome'] = $form_data['name'];
                $webhook_data['quantidade_de_pessoas'] = $form_data['qtd_pessoas'];
                $webhook_data['tipo_de_plano'] = $form_data['qual_plano'];
                $webhook_data['idades'] = is_array($form_data['ages']) ?
                    implode(', ', $form_data['ages']) : $form_data['ages'];

                // URL unica do webhook (sem grupo / sem vendedor)
                $webhook_url = isset($options['webhook_url']) ? $options['webhook_url'] : '';

                if (!empty($webhook_url)) {
                    // 1 tentativa rápida com timeout de 2s (se falhar, vai pro cron de retry)
                    error_log("HAPVIDA WEBHOOK: Tentativa imediata para lead {$form_data['lead_id']}");

                    $webhook_response = wp_remote_post($webhook_url, array(
                        'timeout' => 2,
                        'blocking' => true,
                        'body' => json_encode($webhook_data),
                        'headers' => array(
                            'Content-Type' => 'application/json',
                            'User-Agent' => 'Formulario-Hapvida/2.0',
                            'Connection' => 'close',
                        ),
                        'sslverify' => false,
                        'httpversion' => '1.1',
                    ));

                    if (!is_wp_error($webhook_response)) {
                        $response_code = wp_remote_retrieve_response_code($webhook_response);
                        if ($response_code >= 200 && $response_code < 300) {
                            // Sucesso na primeira tentativa
                            error_log("HAPVIDA WEBHOOK: SUCESSO imediato - HTTP {$response_code} - lead {$form_data['lead_id']}");
                            $webhook_success = true;
                            $webhook_state = 'sent';
                            $this->save_webhook_entry($webhook_data, 'sent', "Enviado com sucesso imediato - HTTP {$response_code}");
                        } elseif ($response_code >= 400 && $response_code < 500 && $response_code !== 408 && $response_code !== 429) {
                            // 4xx definitivo (auth/not found/bad request) - nao adianta retentar
                            $erro = "HTTP {$response_code}";
                            error_log("HAPVIDA WEBHOOK: ERRO DEFINITIVO imediato - {$erro} - lead {$form_data['lead_id']} - cancelando retries");
                            $webhook_state = 'failed';
                            $this->save_webhook_entry($webhook_data, 'permanent_failure', "Erro definitivo imediato: {$erro}", $response_code, $webhook_url);
                        } else {
                            $erro = "HTTP {$response_code}";
                            error_log("HAPVIDA WEBHOOK: FALHA imediata - {$erro} - lead {$form_data['lead_id']} - enviando pro cron");
                            $webhook_state = 'queued';
                            $this->save_webhook_entry($webhook_data, 'pending_retry', "Falha imediata: {$erro}", $response_code, $webhook_url);
                        }
                    } else {
                        $erro = $webhook_response->get_error_message();
                        error_log("HAPVIDA WEBHOOK: FALHA imediata - {$erro} - lead {$form_data['lead_id']} - enviando pro cron");
                        $webhook_state = 'queued';
                        $this->save_webhook_entry($webhook_data, 'pending_retry', "Falha imediata: {$erro}", null, $webhook_url);
                    }

                } else {
                    error_log("HAPVIDA WEBHOOK: URL NAO CONFIGURADA - lead {$form_data['lead_id']}");
                    $webhook_state = 'failed';
                    $this->save_webhook_entry($webhook_data, 'permanent_failure', "URL do webhook nao configurada");
                }

            } catch (Exception $e) {
                error_log("HAPVIDA WEBHOOK: EXCECAO para lead " . (isset($form_data['lead_id']) ? $form_data['lead_id'] : 'unknown') . ": " . $e->getMessage());
                if (isset($webhook_data)) {
                    $webhook_state = 'queued';
                    $this->save_webhook_entry($webhook_data, 'pending_retry', 'Excecao: ' . $e->getMessage(), null, isset($webhook_url) ? $webhook_url : '');
                }
            }

            // *** NOVO: ENVIA DADOS PARA API LEADP3 (NÃO-BLOQUEANTE) ***
            try {
                // CORRIGIDO: Verifica se a classe existe antes de usar
                // $leadp3_integration = get_leadp3_integration_instance();
                $leadp3_integration = null;
                if (class_exists('Formulario_Hapvida_LeadP3_Integration')) {
                    global $formulario_hapvida_leadp3;
                    $leadp3_integration = $formulario_hapvida_leadp3;
                }
                if ($leadp3_integration) {
                    // Envio assíncrono - retorna instantaneamente, sem vendedor
                    $leadp3_integration->send_to_leadp3($form_data, null);
                }
            } catch (Exception $e) {
                // Apenas registra erro - não afeta formulário
                error_log("LeadP3: " . $e->getMessage());
            }

            // Prepara resposta de sucesso (sem redirecionamento - frontend cuida)
            $response = array(
                'success' => true,
                'message' => 'Formulário processado com sucesso!',
                'webhook_status' => $webhook_state,
                'business_hours' => $is_business_hours,
                'processed_data' => array(
                    'phone_formatted' => $form_data['telefone'],
                    'submission_time' => $form_data['data'] . ' ' . $form_data['hora'],
                    'ages_extracted' => $form_data['ages'],
                    'lead_id' => $form_data['lead_id']
                )
            );

            $execution_time = (microtime(true) - $start_time) * 1000;
            $this->log("â±ï¸ Tempo de execução: {$execution_time}ms");
            $this->log("ðŸ“‹ DADOS DO FORMULÃRIO: ===== FIM DA SUBMISSÃO =====");

            return new WP_REST_Response($response, 200);

        } catch (Exception $e) {
            error_log("HAPVIDA ERROR: " . $e->getMessage());
            $this->log("âŒ ERRO: " . $e->getMessage());

            return new WP_REST_Response(array(
                'success' => false,
                'message' => $e->getMessage()
            ), 400);
        }
    }

    private function is_form_processed($form_data)
    {
        // Verificação por TELEFONE
        $telefone = isset($form_data['telefone']) ? $form_data['telefone'] : '';
        if (!empty($telefone)) {
            $telefone_clean = preg_replace('/[^0-9]/', '', $telefone);
            if (!empty($telefone_clean)) {
                $phone_key = 'processed_phone_' . md5($telefone_clean);
                if (get_transient($phone_key)) {
                    error_log("HAPVIDA DUPLICATA: Telefone {$telefone} ja foi processado recentemente");
                    return true;
                }
            }
        }

        // Verificação por NOME
        $nome = isset($form_data['name']) ? $form_data['name'] : '';
        if (!empty($nome)) {
            $nome_clean = mb_strtolower(trim($nome), 'UTF-8');
            $nome_clean = preg_replace('/\s+/', ' ', $nome_clean);
            if (!empty($nome_clean)) {
                $name_key = 'processed_name_' . md5($nome_clean);
                if (get_transient($name_key)) {
                    error_log("HAPVIDA DUPLICATA: Nome '{$nome}' ja foi processado recentemente");
                    return true;
                }
            }
        }

        return false;
    }

    private function mark_form_as_processed($form_data)
    {
        $ttl = 1800; // 30 minutos

        // Marca TELEFONE como processado
        $telefone = isset($form_data['telefone']) ? $form_data['telefone'] : '';
        if (!empty($telefone)) {
            $telefone_clean = preg_replace('/[^0-9]/', '', $telefone);
            if (!empty($telefone_clean)) {
                $phone_key = 'processed_phone_' . md5($telefone_clean);
                set_transient($phone_key, time(), $ttl);
            }
        }

        // Marca NOME como processado
        $nome = isset($form_data['name']) ? $form_data['name'] : '';
        if (!empty($nome)) {
            $nome_clean = mb_strtolower(trim($nome), 'UTF-8');
            $nome_clean = preg_replace('/\s+/', ' ', $nome_clean);
            if (!empty($nome_clean)) {
                $name_key = 'processed_name_' . md5($nome_clean);
                set_transient($name_key, time(), $ttl);
            }
        }

        error_log("HAPVIDA DUPLICATA: Telefone {$telefone} e Nome '{$nome}' marcados como processados por 30 minutos");
    }

    private function extract_ages_from_request($params)
    {
        $ages = array();

        // Verifica se ages vem como array direto
        if (isset($params['ages']) && is_array($params['ages'])) {
            return $params['ages'];
        }

        // Verifica dentro de form_fields
        if (isset($params['form_fields']['ages']) && is_array($params['form_fields']['ages'])) {
            return $params['form_fields']['ages'];
        }

        // Tenta extrair de campos individuais age_1, age_2, etc
        for ($i = 1; $i <= 10; $i++) {
            if (isset($params["age_$i"]) && !empty($params["age_$i"])) {
                $ages[] = $params["age_$i"];
            } elseif (isset($params['form_fields']["age_$i"]) && !empty($params['form_fields']["age_$i"])) {
                $ages[] = $params['form_fields']["age_$i"];
            }
        }

        // Se ainda não tem idades, tenta campo único 'idade'
        if (empty($ages)) {
            if (isset($params['idade']) && !empty($params['idade'])) {
                $ages[] = $params['idade'];
            } elseif (isset($params['form_fields']['idade']) && !empty($params['form_fields']['idade'])) {
                $ages[] = $params['form_fields']['idade'];
            }
        }

        return $ages;
    }

    private function format_phone_number($phone)
    {
        // Remove todos os caracteres não numéricos
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Se tem 11 dígitos (com DDD e 9 dígito)
        if (strlen($phone) == 11) {
            return sprintf(
                '(%s) %s-%s',
                substr($phone, 0, 2),
                substr($phone, 2, 5),
                substr($phone, 7)
            );
        }
        // Se tem 10 dígitos (com DDD sem 9 dígito)
        elseif (strlen($phone) == 10) {
            return sprintf(
                '(%s) %s-%s',
                substr($phone, 0, 2),
                substr($phone, 2, 4),
                substr($phone, 6)
            );
        }
        // Se tem 9 dígitos (celular sem DDD)
        elseif (strlen($phone) == 9) {
            return sprintf(
                '%s-%s',
                substr($phone, 0, 5),
                substr($phone, 5)
            );
        }
        // Se tem 8 dígitos (fixo sem DDD)
        elseif (strlen($phone) == 8) {
            return sprintf(
                '%s-%s',
                substr($phone, 0, 4),
                substr($phone, 4)
            );
        }

        // Retorna o telefone original se não se encaixa em nenhum formato
        return $phone;
    }

    private function validate_brazilian_phone($telefone)
    {
        // Remove todos os caracteres não numéricos
        $clean_phone = preg_replace('/[^0-9]/', '', $telefone);

        $result = array(
            'valid' => false,
            'clean' => $clean_phone,
            'formatted' => $telefone,
            'type' => null,
            'error_message' => ''
        );

        $length = strlen($clean_phone);

        // Aceita 10 ou 11 dígitos
        if ($length >= 10 && $length <= 11) {
            $ddd = substr($clean_phone, 0, 2);

            // DDD deve estar entre 11 e 99
            if (intval($ddd) >= 11 && intval($ddd) <= 99) {
                $result['valid'] = true;

                if ($length === 10) {
                    $result['type'] = 'residencial';
                    $result['formatted'] = sprintf(
                        '(%s) %s-%s',
                        substr($clean_phone, 0, 2),
                        substr($clean_phone, 2, 4),
                        substr($clean_phone, 6)
                    );
                } else { // 11 dígitos
                    $result['type'] = 'celular';
                    $result['formatted'] = sprintf(
                        '(%s) %s-%s',
                        substr($clean_phone, 0, 2),
                        substr($clean_phone, 2, 5),
                        substr($clean_phone, 7)
                    );
                }
            } else {
                $result['error_message'] = 'DDD inválido. Use um DDD entre 11 e 99. Ex: (11) 1234-5678';
            }
        } elseif ($length === 0) {
            $result['error_message'] = 'Por favor, digite seu número de telefone com DDD';
        } elseif ($length < 10) {
            $result['error_message'] = sprintf(
                'Número muito curto (%d dígitos). Digite DDD + número (mínimo 10 dígitos)',
                $length
            );
        } elseif ($length > 11) {
            $result['error_message'] = sprintf(
                'Número muito longo (%d dígitos). Máximo: 11 dígitos com DDD',
                $length
            );
        } else {
            $result['error_message'] = 'Formato inválido. Use: (DD) XXXX-XXXX';
        }

        return $result;
    }

    public function test_phone_validation()
    {
        $test_phones = array(
            '11999887766',           // Válido - celular
            '(11) 99988-7766',       // Válido - celular formatado
            '11987654321',           // Válido - celular
            '1133334444',            // Válido - fixo
            '(11) 3333-4444',        // Válido - fixo formatado
            '119988776',             // Inválido - muito curto
            '21987654321',           // Válido - RJ celular
            '11887654321',           // Inválido - fixo com 11 dígitos
            '11099887766',           // Inválido - começa com 0
            '11199887766',           // Inválido - começa com 1
            '09987654321',           // Inválido - DDD inválido
            '119999999999',          // Inválido - muito longo
        );

        $this->log("=== TESTE DE VALIDAÇÃO DE TELEFONES ===");

        foreach ($test_phones as $phone) {
            $result = $this->validate_brazilian_phone($phone);
            $status = $result['valid'] ? '✅ VÃLIDO' : 'âŒ INVÃLIDO';
            $message = $result['valid'] ?
                "Tipo: {$result['type']}, Formatado: {$result['formatted']}" :
                "Erro: {$result['error_message']}";

            $this->log("Teste: {$phone} -> {$status} - {$message}");
        }
    }

    private function update_submission_counts()
    {
        // Atualiza contador diário
        $daily_submissions = get_option($this->daily_submissions_option, array());
        $today = current_time('Y-m-d');

        if (!isset($daily_submissions[$today])) {
            $daily_submissions[$today] = 0;
        }
        $daily_submissions[$today]++;

        update_option($this->daily_submissions_option, $daily_submissions);

        // Atualiza contador mensal
        $monthly_submissions = get_option($this->monthly_submissions_option, array());
        $current_month = current_time('Y-m');

        if (!isset($monthly_submissions[$current_month])) {
            $monthly_submissions[$current_month] = 0;
        }
        $monthly_submissions[$current_month]++;

        update_option($this->monthly_submissions_option, $monthly_submissions);

        $this->log("ðŸ“Š Contadores atualizados - Diário: {$daily_submissions[$today]}, Mensal: {$monthly_submissions[$current_month]}");
    }
}