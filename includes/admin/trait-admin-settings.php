<?php
if (!defined('ABSPATH')) exit;

trait AdminSettingsTrait {

    public function add_admin_menu()
    {
        // Menu principal
        add_options_page(
            'Formulário Hapvida',
            'Formulário Hapvida',
            'manage_options',
            'formulario-hapvida-admin',
            array($this, 'render_admin_page')
        );
    }

    public function register_settings()
    {
        register_setting('formulario_hapvida_settings', $this->option_name, array($this, 'sanitize_settings'));

        // Seção principal de configurações
        add_settings_section(
            'formulario_hapvida_general',
            'Configurações do Plugin',
            array($this, 'section_general_callback'),
            'formulario-hapvida-admin'
        );

        // Campo URL do Webhook (unica para todos os leads)
        add_settings_field(
            'webhook_url',
            'URL do Webhook',
            array($this, 'webhook_url_callback'),
            'formulario-hapvida-admin',
            'formulario_hapvida_general'
        );

        // Campo Lista de Cidades
        add_settings_field(
            'cidades',
            'Lista de Cidades',
            array($this, 'cidades_callback'),
            'formulario-hapvida-admin',
            'formulario_hapvida_general'
        );

        // redirect_obrigado tem form dedicado na aba de configurações, não registra aqui para evitar duplicação

    }

    /**
     * Sanitiza e mescla configurações para evitar perda de dados
     *
     * IMPORTANTE: Como temos 2 formulários separados (Relatórios e Webhooks)
     * salvando na mesma option, precisamos mesclar os dados novos com os existentes
     * para não perder configurações ao salvar um dos formulários.
     */
    public function sanitize_settings($input)
    {
        // Busca as configurações atuais
        $current_settings = get_option($this->option_name, array());

        // Mescla os dados novos com os existentes
        // array_merge() vai sobrescrever apenas os campos presentes em $input
        // mantendo os campos que não estão em $input
        $merged_settings = array_merge($current_settings, $input);

        // Sanitiza todos os campos
        $sanitized = array();

        foreach ($merged_settings as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = array_map('sanitize_text_field', $value);
            } else {
                // URLs especiais
                if (strpos($key, 'webhook_url') !== false) {
                    $sanitized[$key] = esc_url_raw($value);
                }
                // Senhas (não sanitizar para não quebrar caracteres especiais)
                elseif (strpos($key, 'password') !== false) {
                    $sanitized[$key] = $value;
                }
                // Campo de cidades (textarea com múltiplas linhas)
                elseif ($key === 'cidades') {
                    $sanitized[$key] = sanitize_textarea_field($value);
                }
                // Outros campos de texto
                else {
                    $sanitized[$key] = sanitize_text_field($value);
                }
            }
        }

        return $sanitized;
    }


    private function render_settings_section()
    {
        echo '<form method="post" action="options.php">';
        settings_fields('formulario_hapvida_group');
        do_settings_sections('formulario_hapvida');
        submit_button('💾 Salvar Configurações');
        echo '</form>';
    }

    public function section_general_callback()
    {

    }

    public function webhook_url_callback()
    {
        $options = get_option($this->option_name);
        $webhook_url = isset($options['webhook_url']) ? $options['webhook_url'] : '';
        echo "<input type='url' class='regular-text' name='{$this->option_name}[webhook_url]' value='{$webhook_url}' data-label='URL do Webhook' />";
        echo "<p class='description'>URL para onde todos os leads sao enviados.</p>";
    }

    public function cidades_callback()
    {
        $options = get_option($this->option_name);
        $cidades = isset($options['cidades']) ? $options['cidades'] : '';

        echo '<div style="margin-bottom: 15px;">';
        echo "<textarea name='{$this->option_name}[cidades]' rows='8' cols='60' style='width: 100%; min-height: 200px; padding: 10px; border: 2px solid #ddd; border-radius: 6px; font-family: monospace; font-size: 14px; line-height: 1.8; white-space: pre-wrap; resize: vertical;' placeholder='Digite uma cidade por linha...'>" . esc_textarea($cidades) . "</textarea>";
        echo '</div>';

        echo '<div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 15px; margin-top: 10px;">';
        echo '<h4 style="margin: 0 0 10px 0; color: #495057;">📍 Como adicionar cidades:</h4>';
        echo '<p style="margin: 5px 0; font-size: 14px;">Informe <strong>uma cidade por linha</strong>. Exemplos:</p>';
        echo '<div style="background: #ffffff; border: 1px solid #ddd; border-radius: 4px; padding: 10px; margin: 10px 0; font-family: monospace; font-size: 13px; color: #333;">';
        echo 'Fortaleza<br>';
        echo 'Recife<br>';
        echo 'Salvador<br>';
        echo 'João Pessoa<br>';
        echo 'Natal<br>';
        echo 'Maceió';
        echo '</div>';
        echo '<p style="margin: 5px 0; font-size: 13px; color: #6c757d;"><strong>Dica:</strong> As cidades aparecerão na mesma ordem que você digitá-las aqui.</p>';
        echo '</div>';
    }

    public function admin_username_callback()
    {
        $options = get_option($this->option_name);
        $admin_username = isset($options['admin_username']) ? esc_attr($options['admin_username']) : '';

        echo "<input type='text' class='regular-text' name='{$this->option_name}[admin_username]' value='{$admin_username}' data-label='Usuário Admin (Relatórios)' />";
        echo "<p class='description'>Usuário para acessar a página de relatórios de leads. <strong>Importante:</strong> Configure este usuário e senha para proteger o acesso aos relatórios.</p>";
    }

    public function admin_password_callback()
    {
        $options = get_option($this->option_name);
        $admin_password = isset($options['admin_password']) ? esc_attr($options['admin_password']) : '';

        echo "<input type='password' class='regular-text' name='{$this->option_name}[admin_password]' value='{$admin_password}' data-label='Senha Admin (Relatórios)' autocomplete='new-password' />";
        echo "<p class='description'>Senha para acessar a página de relatórios de leads. <strong>Use uma senha forte!</strong> ";
        echo "Para acessar os relatórios, adicione o shortcode <code>[hapvida_reports]</code> em qualquer página.</p>";
    }
}
