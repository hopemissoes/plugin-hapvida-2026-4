<?php
if (!defined('ABSPATH')) exit;

/**
 * Trait para Gestão de Vendedores DRV por usuário externo.
 *
 * Cria um role WordPress "hapvida_gestor_drv" que só tem acesso
 * a uma página admin dedicada para gerenciar vendedores do grupo DRV.
 */
trait AdminDrvManagerTrait {

    /**
     * Inicializa o sistema de gestão DRV.
     * Chamado no construtor da classe principal.
     */
    public function init_drv_manager()
    {
        // Garante que o role existe
        $this->ensure_drv_manager_role();

        // Menu page para o gestor DRV
        add_action('admin_menu', array($this, 'add_drv_manager_menu'));

        // AJAX handlers para salvar vendedores DRV
        add_action('wp_ajax_save_drv_vendors', array($this, 'ajax_save_drv_vendors'));
        add_action('wp_ajax_add_drv_vendor_row', array($this, 'ajax_add_drv_vendor_row'));

        // Restrições de UI para o role gestor DRV
        add_action('admin_init', array($this, 'restrict_drv_manager_access'));
        add_action('admin_head', array($this, 'hide_admin_ui_for_drv_manager'));
        add_filter('admin_footer_text', array($this, 'drv_manager_footer_text'));

        // Redireciona login direto para a página DRV
        add_filter('login_redirect', array($this, 'drv_manager_login_redirect'), 10, 3);
    }

    /**
     * Cria o role hapvida_gestor_drv se não existir.
     * Adiciona a capability ao role administrator também.
     */
    private function ensure_drv_manager_role()
    {
        $role = get_role('hapvida_gestor_drv');
        if (!$role) {
            add_role('hapvida_gestor_drv', 'Gestor DRV Hapvida', array(
                'read' => true,
                'manage_hapvida_drv' => true,
            ));
        }

        // Garante que admin também tem a capability
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap('manage_hapvida_drv')) {
            $admin->add_cap('manage_hapvida_drv');
        }
    }

    /**
     * Adiciona menu "Vendedores DRV" no admin.
     */
    public function add_drv_manager_menu()
    {
        add_menu_page(
            'Gestão Vendedores DRV',
            'Vendedores DRV',
            'manage_hapvida_drv',
            'hapvida-drv-manager',
            array($this, 'render_drv_manager_page'),
            'dashicons-groups',
            30
        );
    }

    /**
     * Redireciona login do gestor DRV direto para a página de vendedores.
     */
    public function drv_manager_login_redirect($redirect_to, $requested_redirect_to, $user)
    {
        if (is_wp_error($user) || !is_object($user)) {
            return $redirect_to;
        }
        if (in_array('hapvida_gestor_drv', (array) $user->roles)) {
            return admin_url('admin.php?page=hapvida-drv-manager');
        }
        return $redirect_to;
    }

    /**
     * Bloqueia acesso a outras páginas admin para o role gestor DRV.
     */
    public function restrict_drv_manager_access()
    {
        $user = wp_get_current_user();
        if (!in_array('hapvida_gestor_drv', (array) $user->roles)) {
            return;
        }

        global $pagenow;
        $drv_url = admin_url('admin.php?page=hapvida-drv-manager');

        // AJAX sempre permitido
        if ($pagenow === 'admin-ajax.php') {
            return;
        }

        // Página do gestor DRV - OK
        if ($pagenow === 'admin.php') {
            $page = isset($_GET['page']) ? $_GET['page'] : '';
            if ($page === 'hapvida-drv-manager') {
                return;
            }
        }

        // Qualquer outra página (index.php, profile.php, etc) -> redireciona
        wp_redirect($drv_url);
        exit;
    }

    /**
     * Esconde elementos desnecessários do admin para o gestor DRV.
     */
    public function hide_admin_ui_for_drv_manager()
    {
        $user = wp_get_current_user();
        if (!in_array('hapvida_gestor_drv', (array) $user->roles)) {
            return;
        }

        echo '<style>
            /* Esconde tudo do menu lateral exceto Vendedores DRV */
            #adminmenu li:not(.toplevel_page_hapvida-drv-manager) { display: none !important; }
            /* Esconde barra de admin desnecessária */
            #wp-admin-bar-wp-logo,
            #wp-admin-bar-site-name,
            #wp-admin-bar-comments,
            #wp-admin-bar-new-content,
            #wp-admin-bar-updates,
            #wp-admin-bar-search { display: none !important; }
            /* Esconde notices do WordPress */
            .notice:not(.hapvida-notice), .update-nag, .updated { display: none !important; }
            /* Esconde footer do WP */
            #wpfooter { display: none !important; }
        </style>';
    }

    /**
     * Substitui texto do footer para o gestor DRV.
     */
    public function drv_manager_footer_text($text)
    {
        $user = wp_get_current_user();
        if (in_array('hapvida_gestor_drv', (array) $user->roles)) {
            return '';
        }
        return $text;
    }

    /**
     * Renderiza a página de gestão de vendedores DRV.
     */
    public function render_drv_manager_page()
    {
        if (!current_user_can('manage_hapvida_drv')) {
            wp_die('Acesso negado.');
        }

        $vendedores = get_option($this->vendedores_option, array());
        $drv_vendors = isset($vendedores['drv']) ? $vendedores['drv'] : array();

        $ativos = 0;
        $inativos = 0;
        foreach ($drv_vendors as $v) {
            $status = isset($v['status']) ? $v['status'] : 'ativo';
            if ($status === 'ativo') { $ativos++; } else { $inativos++; }
        }

        $nonce = wp_create_nonce('save_drv_vendors');
        ?>
        <div class="wrap" id="drv-manager-wrap">

            <div class="drv-header">
                <h1>Gestão de Vendedores DRV</h1>
                <p class="drv-subtitle">Gerencie os vendedores do grupo DRV: ativar, desativar, editar e cadastrar.</p>
            </div>

            <!-- Stats -->
            <div class="drv-stats">
                <div class="drv-stat drv-stat-active">
                    <span class="drv-stat-dot active"></span>
                    <strong id="drv-count-ativos"><?php echo $ativos; ?></strong>
                    <span>Ativos</span>
                </div>
                <div class="drv-stat drv-stat-inactive">
                    <span class="drv-stat-dot inactive"></span>
                    <strong id="drv-count-inativos"><?php echo $inativos; ?></strong>
                    <span>Inativos</span>
                </div>
                <div class="drv-stat drv-stat-total">
                    <strong id="drv-count-total"><?php echo count($drv_vendors); ?></strong>
                    <span>Total</span>
                </div>
            </div>

            <!-- Mensagem de sucesso/erro -->
            <div id="drv-message" class="drv-message" style="display: none;"></div>

            <input type="hidden" id="drv-nonce" value="<?php echo esc_attr($nonce); ?>" />

            <!-- Tabela -->
            <div class="drv-table-wrap">
                <table class="drv-table" id="drv-vendors-table">
                    <thead>
                        <tr>
                            <th style="width: 30px;">#</th>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>Telefone</th>
                            <th>Categoria</th>
                            <th>Status</th>
                            <th style="width: 120px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($drv_vendors)): ?>
                            <?php $i = 0; foreach ($drv_vendors as $index => $v): $i++;
                                $status = isset($v['status']) ? $v['status'] : 'ativo';
                            ?>
                            <tr class="drv-row <?php echo $status === 'inativo' ? 'drv-row-inactive' : ''; ?>" data-index="<?php echo $index; ?>">
                                <td class="drv-row-num"><?php echo $i; ?></td>
                                <td>
                                    <input type="text" class="drv-input drv-field-id" value="<?php echo esc_attr(isset($v['vendedor_id']) ? $v['vendedor_id'] : ''); ?>" placeholder="ID único" />
                                </td>
                                <td>
                                    <input type="text" class="drv-input drv-field-nome" value="<?php echo esc_attr($v['nome']); ?>" placeholder="Nome do vendedor" />
                                </td>
                                <td>
                                    <input type="text" class="drv-input drv-field-telefone" value="<?php echo esc_attr($v['telefone']); ?>" placeholder="5583999471031" maxlength="13" />
                                </td>
                                <td>
                                    <select class="drv-select drv-field-categoria">
                                        <option value="fixo" <?php selected(isset($v['categoria']) ? $v['categoria'] : 'fixo', 'fixo'); ?>>Fixo</option>
                                        <option value="rotativo" <?php selected(isset($v['categoria']) ? $v['categoria'] : '', 'rotativo'); ?>>Rotativo</option>
                                    </select>
                                </td>
                                <td>
                                    <span class="drv-badge <?php echo $status === 'ativo' ? 'drv-badge-active' : 'drv-badge-inactive'; ?>">
                                        <?php echo $status === 'ativo' ? 'Ativo' : 'Inativo'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="drv-actions">
                                        <button type="button" class="drv-btn drv-btn-toggle" data-status="<?php echo esc_attr($status); ?>" title="<?php echo $status === 'ativo' ? 'Desativar' : 'Ativar'; ?>">
                                            <?php echo $status === 'ativo' ? '&#x23F8;' : '&#x25B6;'; ?>
                                        </button>
                                        <button type="button" class="drv-btn drv-btn-remove" title="Remover">&#x2716;</button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr class="drv-empty-row">
                                <td colspan="7" style="text-align: center; padding: 30px; color: #94a3b8;">
                                    Nenhum vendedor DRV cadastrado. Clique em "Adicionar Vendedor" para começar.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Botões -->
            <div class="drv-footer-actions">
                <button type="button" id="drv-add-vendor" class="drv-btn-primary drv-btn-add">
                    + Adicionar Vendedor
                </button>
                <button type="button" id="drv-save-all" class="drv-btn-primary drv-btn-save">
                    Salvar Alterações
                </button>
            </div>

        </div>

        <style>
            /* ===== DRV MANAGER STYLES ===== */
            #drv-manager-wrap {
                max-width: 960px;
                margin: 20px auto;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            }
            .drv-header { margin-bottom: 24px; }
            .drv-header h1 { font-size: 24px; font-weight: 700; color: #1e293b; margin: 0 0 4px; }
            .drv-subtitle { color: #64748b; font-size: 14px; margin: 0; }

            /* Stats */
            .drv-stats { display: flex; gap: 12px; margin-bottom: 20px; }
            .drv-stat {
                display: flex; align-items: center; gap: 8px;
                background: #fff; border: 1px solid #e2e8f0; padding: 10px 16px;
                border-radius: 8px; font-size: 14px;
            }
            .drv-stat strong { font-size: 20px; }
            .drv-stat-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
            .drv-stat-dot.active { background: #22c55e; }
            .drv-stat-dot.inactive { background: #ef4444; }
            .drv-stat-active strong { color: #166534; }
            .drv-stat-inactive strong { color: #991b1b; }
            .drv-stat-total strong { color: #1e40af; }

            /* Message */
            .drv-message {
                padding: 12px 16px; border-radius: 8px; margin-bottom: 16px;
                font-size: 14px; font-weight: 500;
            }
            .drv-message.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
            .drv-message.error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

            /* Table */
            .drv-table-wrap {
                background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
                overflow: hidden; margin-bottom: 16px;
            }
            .drv-table { width: 100%; border-collapse: collapse; font-size: 13px; }
            .drv-table thead th {
                background: #f8fafc; border-bottom: 2px solid #e2e8f0;
                padding: 10px 12px; text-align: left; font-weight: 600;
                color: #475569; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;
            }
            .drv-table tbody td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
            .drv-row-num { color: #94a3b8; font-size: 12px; text-align: center; }
            .drv-row-inactive { background: #fef2f2; }
            .drv-row-inactive .drv-input,
            .drv-row-inactive .drv-select { opacity: 0.6; }

            /* Inputs */
            .drv-input, .drv-select {
                width: 100%; padding: 6px 10px; border: 1px solid #e2e8f0;
                border-radius: 6px; font-size: 13px; color: #1e293b;
                transition: border-color 0.2s;
            }
            .drv-input:focus, .drv-select:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 2px rgba(59,130,246,0.15); }

            /* Badges */
            .drv-badge {
                display: inline-block; padding: 3px 10px; border-radius: 20px;
                font-size: 12px; font-weight: 600;
            }
            .drv-badge-active { background: #d1fae5; color: #166534; }
            .drv-badge-inactive { background: #fee2e2; color: #991b1b; }

            /* Buttons */
            .drv-actions { display: flex; gap: 6px; }
            .drv-btn {
                width: 32px; height: 32px; border: 1px solid #e2e8f0; border-radius: 6px;
                background: #fff; cursor: pointer; font-size: 14px; display: flex;
                align-items: center; justify-content: center; transition: all 0.2s;
            }
            .drv-btn:hover { background: #f1f5f9; }
            .drv-btn-toggle[data-status="ativo"] { color: #f59e0b; }
            .drv-btn-toggle[data-status="inativo"] { color: #22c55e; }
            .drv-btn-remove { color: #ef4444; }
            .drv-btn-remove:hover { background: #fef2f2; border-color: #fecaca; }

            /* Footer actions */
            .drv-footer-actions { display: flex; gap: 12px; }
            .drv-btn-primary {
                padding: 10px 20px; border: none; border-radius: 8px;
                font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s;
            }
            .drv-btn-add { background: #f1f5f9; color: #475569; }
            .drv-btn-add:hover { background: #e2e8f0; }
            .drv-btn-save { background: #2563eb; color: #fff; }
            .drv-btn-save:hover { background: #1d4ed8; }
            .drv-btn-save:disabled { background: #94a3b8; cursor: not-allowed; }

            /* Responsive */
            @media (max-width: 768px) {
                .drv-stats { flex-wrap: wrap; }
                .drv-table-wrap { overflow-x: auto; }
                .drv-footer-actions { flex-direction: column; }
            }
        </style>

        <script>
        (function($) {
            var ajaxurl = '<?php echo admin_url("admin-ajax.php"); ?>';
            var nonce = $('#drv-nonce').val();

            function renumberRows() {
                $('#drv-vendors-table tbody .drv-row').each(function(i) {
                    $(this).find('.drv-row-num').text(i + 1);
                });
            }

            function updateStats() {
                var ativos = 0, inativos = 0, total = 0;
                $('#drv-vendors-table tbody .drv-row').each(function() {
                    total++;
                    var status = $(this).find('.drv-btn-toggle').data('status');
                    if (status === 'ativo') { ativos++; } else { inativos++; }
                });
                $('#drv-count-ativos').text(ativos);
                $('#drv-count-inativos').text(inativos);
                $('#drv-count-total').text(total);
            }

            function showMessage(text, type) {
                var $msg = $('#drv-message');
                $msg.removeClass('success error').addClass(type).text(text).show();
                setTimeout(function() { $msg.fadeOut(); }, 4000);
            }

            // Formatação de telefone: apenas números, máximo 13 dígitos (ex: 5583999471031)
            function formatPhone(value) {
                return value.replace(/\D/g, '').substring(0, 13);
            }

            function validatePhone(value) {
                var clean = value.replace(/\D/g, '');
                // Deve ter entre 12 e 13 dígitos e começar com 55
                return clean.length >= 12 && clean.length <= 13 && clean.substring(0, 2) === '55';
            }

            // Força apenas números no campo telefone e limita a 13 dígitos
            $(document).on('input', '.drv-field-telefone', function() {
                var val = formatPhone($(this).val());
                $(this).val(val);
                // Feedback visual
                if (val.length > 0 && !validatePhone(val)) {
                    $(this).css('border-color', '#f59e0b');
                } else if (val.length >= 12) {
                    $(this).css('border-color', '#22c55e');
                } else {
                    $(this).css('border-color', '#e2e8f0');
                }
            });

            // Toggle status
            $(document).on('click', '.drv-btn-toggle', function() {
                var $btn = $(this);
                var $row = $btn.closest('tr');
                var current = $btn.data('status');
                var newStatus = current === 'ativo' ? 'inativo' : 'ativo';

                $btn.data('status', newStatus).attr('data-status', newStatus);
                $btn.html(newStatus === 'ativo' ? '&#x23F8;' : '&#x25B6;');
                $btn.attr('title', newStatus === 'ativo' ? 'Desativar' : 'Ativar');

                var $badge = $row.find('.drv-badge');
                $badge.removeClass('drv-badge-active drv-badge-inactive')
                    .addClass(newStatus === 'ativo' ? 'drv-badge-active' : 'drv-badge-inactive')
                    .text(newStatus === 'ativo' ? 'Ativo' : 'Inativo');

                $row.toggleClass('drv-row-inactive', newStatus === 'inativo');
                updateStats();
            });

            // Remove vendor
            $(document).on('click', '.drv-btn-remove', function() {
                var $row = $(this).closest('tr');
                var nome = $row.find('.drv-field-nome').val();
                if (!confirm('Remover o vendedor "' + nome + '"?')) return;
                $row.fadeOut(300, function() { $(this).remove(); renumberRows(); updateStats(); });
            });

            // Add vendor
            $('#drv-add-vendor').on('click', function() {
                $('.drv-empty-row').remove();

                var count = $('#drv-vendors-table tbody .drv-row').length + 1;
                var html = '<tr class="drv-row" data-index="new_' + Date.now() + '">'
                    + '<td class="drv-row-num">' + count + '</td>'
                    + '<td><input type="text" class="drv-input drv-field-id" value="" placeholder="ID único" /></td>'
                    + '<td><input type="text" class="drv-input drv-field-nome" value="" placeholder="Nome do vendedor" /></td>'
                    + '<td><input type="text" class="drv-input drv-field-telefone" value="" placeholder="5583999471031" maxlength="13" /></td>'
                    + '<td><select class="drv-select drv-field-categoria"><option value="fixo">Fixo</option><option value="rotativo">Rotativo</option></select></td>'
                    + '<td><span class="drv-badge drv-badge-active">Ativo</span></td>'
                    + '<td><div class="drv-actions">'
                    + '<button type="button" class="drv-btn drv-btn-toggle" data-status="ativo" title="Desativar">&#x23F8;</button>'
                    + '<button type="button" class="drv-btn drv-btn-remove" title="Remover">&#x2716;</button>'
                    + '</div></td></tr>';

                $('#drv-vendors-table tbody').append(html);
                updateStats();
                $('#drv-vendors-table tbody .drv-row:last .drv-field-nome').focus();
            });

            // Save all
            $('#drv-save-all').on('click', function() {
                var $btn = $(this);

                // Valida telefones antes de salvar
                var hasInvalid = false;
                $('#drv-vendors-table tbody .drv-row').each(function() {
                    var $row = $(this);
                    var tel = $.trim($row.find('.drv-field-telefone').val());
                    var nome = $.trim($row.find('.drv-field-nome').val());
                    if (!nome && !tel) return; // vazio, será ignorado

                    if (tel && !validatePhone(tel)) {
                        $row.find('.drv-field-telefone').css('border-color', '#ef4444');
                        hasInvalid = true;
                    }
                });

                if (hasInvalid) {
                    showMessage('Corrija os telefones destacados. Formato: 55 + DDD + número (12-13 dígitos). Ex: 5583999471031', 'error');
                    return;
                }

                $btn.prop('disabled', true).text('Salvando...');

                var vendors = [];
                $('#drv-vendors-table tbody .drv-row').each(function() {
                    var $row = $(this);
                    var nome = $.trim($row.find('.drv-field-nome').val());
                    var telefone = formatPhone($.trim($row.find('.drv-field-telefone').val()));
                    if (!nome || !telefone) return;

                    vendors.push({
                        vendedor_id: $.trim($row.find('.drv-field-id').val()),
                        nome: nome,
                        telefone: telefone,
                        categoria: $row.find('.drv-field-categoria').val(),
                        status: $row.find('.drv-btn-toggle').data('status')
                    });
                });

                $.post(ajaxurl, {
                    action: 'save_drv_vendors',
                    security: nonce,
                    vendors: JSON.stringify(vendors)
                }, function(response) {
                    if (response.success) {
                        showMessage('Vendedores DRV salvos com sucesso! (' + response.data.total + ' vendedores)', 'success');
                        if (response.data.new_nonce) {
                            nonce = response.data.new_nonce;
                            $('#drv-nonce').val(nonce);
                        }
                    } else {
                        showMessage('Erro: ' + (response.data || 'Erro desconhecido'), 'error');
                    }
                    $btn.prop('disabled', false).text('Salvar Alterações');
                }).fail(function() {
                    showMessage('Erro de conexão. Tente novamente.', 'error');
                    $btn.prop('disabled', false).text('Salvar Alterações');
                });
            });

        })(jQuery);
        </script>
        <?php
    }

    /**
     * AJAX: Salva vendedores DRV (apenas grupo DRV, preserva seu_souza).
     */
    public function ajax_save_drv_vendors()
    {
        if (!current_user_can('manage_hapvida_drv')) {
            wp_send_json_error('Acesso negado');
            return;
        }

        if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'save_drv_vendors')) {
            wp_send_json_error('Token de segurança inválido. Recarregue a página.');
            return;
        }

        $raw = isset($_POST['vendors']) ? wp_unslash($_POST['vendors']) : '[]';
        $vendors_input = json_decode($raw, true);

        if (!is_array($vendors_input)) {
            wp_send_json_error('Dados inválidos');
            return;
        }

        // Sanitiza os dados
        $drv_vendors = array();
        foreach ($vendors_input as $v) {
            $nome = sanitize_text_field(isset($v['nome']) ? $v['nome'] : '');
            $telefone = sanitize_text_field(isset($v['telefone']) ? $v['telefone'] : '');

            if (empty($nome) || empty($telefone)) {
                continue;
            }

            $drv_vendors[] = array(
                'vendedor_id' => sanitize_text_field(isset($v['vendedor_id']) ? $v['vendedor_id'] : ''),
                'nome' => $nome,
                'telefone' => $telefone,
                'categoria' => in_array(isset($v['categoria']) ? $v['categoria'] : '', array('fixo', 'rotativo')) ? $v['categoria'] : 'fixo',
                'status' => in_array(isset($v['status']) ? $v['status'] : '', array('ativo', 'inativo')) ? $v['status'] : 'ativo',
            );
        }

        // Lê a option completa e atualiza APENAS o grupo DRV
        $all_vendors = get_option($this->vendedores_option, array());
        $all_vendors['drv'] = $drv_vendors;
        update_option($this->vendedores_option, $all_vendors);

        error_log("HAPVIDA DRV MANAGER: Vendedores DRV atualizados por " . wp_get_current_user()->user_login . " - " . count($drv_vendors) . " vendedores");

        wp_send_json_success(array(
            'total' => count($drv_vendors),
            'new_nonce' => wp_create_nonce('save_drv_vendors'),
        ));
    }
}
