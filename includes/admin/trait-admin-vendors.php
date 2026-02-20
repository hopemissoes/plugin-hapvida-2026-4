<?php
if (!defined('ABSPATH')) exit;

trait AdminVendorsTrait {

    // ---------------------------------------------------------------
    // Migração da estrutura de vendedores
    public function migrate_vendedores_structure()
    {
        $vendedores = get_option($this->vendedores_option, array());

        if (isset($vendedores['drv']) || isset($vendedores['seu_souza'])) {
            return; // Estrutura já migrada
        }

        $vendedores_migrated = array(
            'drv' => $vendedores,
            'seu_souza' => array()
        );
        update_option($this->vendedores_option, $vendedores_migrated);
    }

    private function render_vendors_management_frontend()
    {
        ?>
        <div class="hapvida-card vendors-management-section">
            <div class="section-header">
                <h2><i class="fas fa-users-cog"></i> Gerenciar Vendedores</h2>
                <button id="refresh-vendors-list" class="control-btn secondary small">
                    <i class="fas fa-sync-alt"></i> Atualizar
                </button>
            </div>

            <!-- Stats minimalistas -->
            <div class="vm-stats-row">
                <div class="vm-stat-pill vm-stat-active">
                    <span class="vm-stat-dot vm-dot-active"></span>
                    <span class="vm-stat-count" id="total-vendors-active">0</span>
                    <span class="vm-stat-text">Ativos</span>
                </div>
                <div class="vm-stat-pill vm-stat-inactive">
                    <span class="vm-stat-dot vm-dot-inactive"></span>
                    <span class="vm-stat-count" id="total-vendors-inactive">0</span>
                    <span class="vm-stat-text">Inativos</span>
                </div>
            </div>

            <div class="vendors-list-container">
                <div class="vendors-group" id="vendors-drv">
                    <div class="vm-group-header">
                        <span class="vm-group-label">DRV</span>
                        <span class="vm-group-count" id="drv-count">0</span>
                    </div>
                    <div class="vendors-grid" id="drv-vendors-grid">
                        <!-- Vendedores serão carregados via AJAX -->
                    </div>
                </div>

                <div class="vendors-group" id="vendors-seu-souza">
                    <div class="vm-group-header">
                        <span class="vm-group-label">Seu Souza</span>
                        <span class="vm-group-count" id="seu-souza-count">0</span>
                    </div>
                    <div class="vendors-grid" id="seu-souza-vendors-grid">
                        <!-- Vendedores serão carregados via AJAX -->
                    </div>
                </div>
            </div>
        </div>

        <style>
            /* ======================== VENDORS MANAGEMENT - MODERN MINIMAL ======================== */
            .vendors-management-section {
                margin: 20px 0;
            }

            /* Stats Row */
            .vm-stats-row {
                display: flex;
                gap: 12px;
                margin: 0 0 28px 0;
            }

            .vm-stat-pill {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 8px 16px;
                border-radius: 100px;
                background: #f8f9fb;
                border: 1px solid #edf0f4;
                font-size: 13px;
            }

            .vm-stat-dot {
                width: 8px;
                height: 8px;
                border-radius: 50%;
                flex-shrink: 0;
            }

            .vm-dot-active { background: #22c55e; }
            .vm-dot-inactive { background: #94a3b8; }

            .vm-stat-count {
                font-weight: 700;
                color: #1e293b;
                font-size: 14px;
            }

            .vm-stat-text {
                color: #64748b;
                font-weight: 500;
            }

            /* Group Header */
            .vm-group-header {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 14px;
            }

            .vm-group-label {
                font-size: 13px;
                font-weight: 600;
                color: #1e293b;
                text-transform: uppercase;
                letter-spacing: 0.6px;
            }

            .vm-group-count {
                font-size: 11px;
                font-weight: 600;
                color: #64748b;
                background: #f1f5f9;
                padding: 2px 8px;
                border-radius: 100px;
            }

            /* Vendors Group */
            .vendors-group {
                margin: 0 0 24px 0;
            }

            .vendors-group:last-child {
                margin-bottom: 0;
            }

            /* Vendors Grid */
            .vendors-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
                gap: 10px;
            }

            /* Vendor Card */
            .vendor-card {
                display: flex;
                align-items: center;
                gap: 12px;
                background: #fff;
                border: 1px solid #edf0f4;
                border-radius: 12px;
                padding: 14px 16px;
                position: relative;
                transition: border-color 0.2s, box-shadow 0.2s;
            }

            .vendor-card:hover {
                border-color: #cbd5e1;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
            }

            .vendor-card.inactive {
                opacity: 1;
                background: #fafbfc;
            }

            .vendor-card.inactive .vendor-name {
                color: #94a3b8;
            }

            .vendor-card.inactive .vendor-phone {
                color: #cbd5e1;
            }

            /* Avatar */
            .vendor-avatar {
                width: 38px;
                height: 38px;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 700;
                font-size: 14px;
                color: #fff;
                flex-shrink: 0;
                text-transform: uppercase;
            }

            .vendor-avatar.avatar-active {
                background: linear-gradient(135deg, #ff6b00, #ff8534);
            }

            .vendor-avatar.avatar-inactive {
                background: #cbd5e1;
            }

            /* Vendor Info */
            .vendor-info {
                flex: 1;
                min-width: 0;
            }

            .vendor-card .vendor-name {
                font-weight: 600;
                font-size: 14px;
                color: #1e293b;
                margin: 0 0 2px 0;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .vendor-card .vendor-phone {
                color: #94a3b8;
                font-size: 12px;
                margin: 0;
            }

            .vendor-card .vendor-category {
                display: inline-block;
                background: #f1f5f9;
                color: #64748b;
                padding: 2px 6px;
                border-radius: 4px;
                font-size: 11px;
                margin-top: 4px;
                font-weight: 500;
            }

            /* Status Indicator */
            .vendor-card .vendor-status {
                position: static;
                width: 0;
                height: 0;
                display: none;
            }

            /* Toggle Switch */
            .vm-toggle-wrap {
                flex-shrink: 0;
            }

            .vendor-card .vendor-action-btn {
                position: relative;
                width: 44px;
                height: 24px;
                border-radius: 100px;
                border: none;
                cursor: pointer;
                transition: background 0.25s;
                padding: 0;
                font-size: 0;
                color: transparent;
                outline: none;
            }

            .vendor-action-btn.toggle-status {
                background: #22c55e;
            }

            .vendor-action-btn.toggle-status::after {
                content: '';
                position: absolute;
                top: 3px;
                left: 23px;
                width: 18px;
                height: 18px;
                border-radius: 50%;
                background: #fff;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
                transition: left 0.25s;
            }

            .vendor-card.inactive .vendor-action-btn.toggle-status {
                background: #d1d5db;
            }

            .vendor-card.inactive .vendor-action-btn.toggle-status::after {
                left: 3px;
            }

            .vendor-action-btn.toggle-status:hover {
                filter: brightness(0.95);
            }

            .vendor-action-btn.toggle-status:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }

            /* Vendor Actions container hidden (we use toggle in card flow) */
            .vendor-card .vendor-actions {
                display: contents;
            }

            @media (max-width: 768px) {
                .vendors-grid {
                    grid-template-columns: 1fr;
                }

                .vm-stats-row {
                    flex-wrap: wrap;
                }
            }
        </style>

        <script>
            jQuery(document).ready(function ($) {
                var vendorsAjaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';
                var vendorsData = [];

                // Função para carregar lista de vendedores
                function loadVendorsList() {
                    $.ajax({
                        url: vendorsAjaxUrl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'get_vendors_list_frontend'
                        },
                        success: function (response) {
                            if (response && response.success) {
                                vendorsData = response.data.vendors || [];
                                renderVendors();
                            } else {
                                console.error('Vendedores: resposta inesperada', response);
                            }
                        },
                        error: function (xhr) {
                            console.error('Vendedores: erro AJAX', xhr.status, xhr.responseText);
                        }
                    });
                }

                // Função auxiliar para obter iniciais
                function getInitials(name) {
                    var parts = name.trim().split(/\s+/);
                    if (parts.length >= 2) {
                        return parts[0].charAt(0) + parts[parts.length - 1].charAt(0);
                    }
                    return parts[0].charAt(0);
                }

                // Função para renderizar vendedores
                function renderVendors() {
                    var drvGrid = $('#drv-vendors-grid');
                    var seuSouzaGrid = $('#seu-souza-vendors-grid');

                    drvGrid.empty();
                    seuSouzaGrid.empty();

                    var stats = {
                        total_ativos: 0,
                        total_inativos: 0,
                        drv_count: 0,
                        seu_souza_count: 0
                    };

                    vendorsData.forEach(function (vendor) {
                        var isActive = vendor.status === 'ativo';

                        if (isActive) {
                            stats.total_ativos++;
                        } else {
                            stats.total_inativos++;
                        }

                        if (vendor.grupo === 'drv') {
                            stats.drv_count++;
                        } else {
                            stats.seu_souza_count++;
                        }

                        var initials = getInitials(vendor.nome);

                        var vendorCard = $('<div class="vendor-card ' + (isActive ? '' : 'inactive') + '">' +
                            '<div class="vendor-status ' + (isActive ? 'active' : 'inactive') + '"></div>' +
                            '<div class="vendor-avatar ' + (isActive ? 'avatar-active' : 'avatar-inactive') + '">' + initials + '</div>' +
                            '<div class="vendor-info">' +
                            '<div class="vendor-name">' + vendor.nome + '</div>' +
                            '<div class="vendor-phone">' + vendor.telefone + '</div>' +
                            (vendor.categoria ? '<div class="vendor-category">' + vendor.categoria + '</div>' : '') +
                            '</div>' +
                            '<div class="vendor-actions">' +
                            '<div class="vm-toggle-wrap">' +
                            '<button class="vendor-action-btn toggle-status" data-vendor-id="' + vendor.id + '" data-grupo="' + vendor.grupo + '" title="' + (isActive ? 'Desativar' : 'Ativar') + '">' +
                            (isActive ? 'Desativar' : 'Ativar') +
                            '</button>' +
                            '</div>' +
                            '</div>' +
                            '</div>');

                        if (vendor.grupo === 'drv') {
                            drvGrid.append(vendorCard);
                        } else {
                            seuSouzaGrid.append(vendorCard);
                        }
                    });

                    // Atualiza estatísticas
                    $('#total-vendors-active').text(stats.total_ativos);
                    $('#total-vendors-inactive').text(stats.total_inativos);
                    $('#drv-count').text(stats.drv_count);
                    $('#seu-souza-count').text(stats.seu_souza_count);
                }

                // Toggle status do vendedor
                $(document).on('click', '.vendor-action-btn.toggle-status', function () {
                    var $btn = $(this);
                    var vendorId = $btn.data('vendor-id');
                    var grupo = $btn.data('grupo');

                    $btn.prop('disabled', true).text('Processando...');

                    $.ajax({
                        url: vendorsAjaxUrl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'toggle_vendor_status_frontend',
                            vendedor_id: vendorId,
                            grupo: grupo,
                            vendor_action: 'toggle'
                        },
                        success: function (response) {
                            if (response && response.success) {
                                loadVendorsList();
                            } else {
                                alert('Erro: ' + (response && response.data ? response.data : 'Resposta inesperada'));
                                loadVendorsList();
                            }
                        },
                        error: function (xhr) {
                            alert('Erro ao atualizar status do vendedor (HTTP ' + xhr.status + ')');
                        },
                        complete: function () {
                            $btn.prop('disabled', false);
                        }
                    });
                });

                // Botão de refresh
                $(document).on('click', '#refresh-vendors-list', function () {
                    var $btn = $(this);
                    $btn.prop('disabled', true);
                    $btn.find('i').addClass('fa-spin');
                    loadVendorsList();
                    setTimeout(function () {
                        $btn.find('i').removeClass('fa-spin');
                        $btn.prop('disabled', false);
                    }, 1500);
                });

                // Carrega vendedores ao iniciar
                loadVendorsList();

                // Auto-refresh a cada 30 segundos
                setInterval(loadVendorsList, 30000);
            });
        </script>
        <?php
    }

    private function render_seu_souza_control_frontend()
    {
        $auto_activate_enabled = get_option('hapvida_auto_activate_seu_souza', false);
        $limite_diario = intval(get_option('hapvida_seu_souza_limite_diario', 30));

        // Busca vendedores Seu Souza
        $vendedores = get_option($this->vendedores_option, array('drv' => array(), 'seu_souza' => array()));
        $seu_souza_vendors = isset($vendedores['seu_souza']) ? $vendedores['seu_souza'] : array();
        $ativos = 0;
        $inativos = 0;
        foreach ($seu_souza_vendors as $v) {
            if (isset($v['status']) && $v['status'] === 'ativo') {
                $ativos++;
            } else {
                $inativos++;
            }
        }

        // Contagem diária atual
        $today = current_time('Y-m-d');
        $daily_submissions = get_option($this->daily_submissions_option, array());
        $daily_count = isset($daily_submissions[$today]) ? intval($daily_submissions[$today]) : 0;
        $porcentagem = $limite_diario > 0 ? min(100, round(($daily_count / $limite_diario) * 100)) : 0;
        ?>
        <div class="section-header">
            <h2><i class="fas fa-user-shield"></i> Controle Seu Souza</h2>
        </div>

        <div class="seu-souza-control-grid">
            <!-- Status dos vendedores -->
            <div class="ssz-status-card">
                <div class="ssz-status-header">
                    <span class="ssz-status-icon"><i class="fas fa-users"></i></span>
                    <span class="ssz-status-title">Vendedores Seu Souza</span>
                </div>
                <div class="ssz-status-counts">
                    <div class="ssz-count-item ssz-count-active">
                        <span class="ssz-count-dot ssz-dot-active"></span>
                        <span class="ssz-count-num" id="ssz-ativos-count"><?php echo $ativos; ?></span>
                        <span class="ssz-count-label">Ativos</span>
                    </div>
                    <div class="ssz-count-item ssz-count-inactive">
                        <span class="ssz-count-dot ssz-dot-inactive"></span>
                        <span class="ssz-count-num" id="ssz-inativos-count"><?php echo $inativos; ?></span>
                        <span class="ssz-count-label">Inativos</span>
                    </div>
                </div>
                <!-- Botões de ativar/desativar todos -->
                <div class="ssz-bulk-actions">
                    <button type="button" class="ssz-bulk-btn ssz-btn-activate" id="ssz-activate-all">
                        <i class="fas fa-check-circle"></i> Ativar Todos
                    </button>
                    <button type="button" class="ssz-bulk-btn ssz-btn-deactivate" id="ssz-deactivate-all">
                        <i class="fas fa-ban"></i> Desativar Todos
                    </button>
                </div>
            </div>

            <!-- Limite diário / progresso -->
            <div class="ssz-status-card">
                <div class="ssz-status-header">
                    <span class="ssz-status-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span class="ssz-status-title">Limite Diário</span>
                </div>
                <div class="ssz-limit-info">
                    <div class="ssz-limit-numbers">
                        <span class="ssz-limit-current" id="ssz-daily-current"><?php echo $daily_count; ?></span>
                        <span class="ssz-limit-separator">/</span>
                        <span class="ssz-limit-max" id="ssz-daily-limit"><?php echo $limite_diario; ?></span>
                    </div>
                    <div class="ssz-limit-bar-bg">
                        <div class="ssz-limit-bar-fill <?php echo $porcentagem >= 100 ? 'ssz-bar-full' : ($porcentagem >= 80 ? 'ssz-bar-warning' : ''); ?>"
                             id="ssz-limit-bar"
                             style="width: <?php echo $porcentagem; ?>%;">
                        </div>
                    </div>
                    <div class="ssz-limit-desc">
                        <?php if ($daily_count >= $limite_diario): ?>
                            <span style="color: #ef4444; font-weight: 600;"><i class="fas fa-exclamation-triangle"></i> Limite atingido! Vendedores Seu Souza desativados.</span>
                        <?php else: ?>
                            <span style="color: #64748b;">Faltam <strong><?php echo ($limite_diario - $daily_count); ?></strong> submissões para o limite.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Auto-ativação status -->
            <div class="ssz-status-card">
                <div class="ssz-status-header">
                    <span class="ssz-status-icon"><i class="fas fa-clock"></i></span>
                    <span class="ssz-status-title">Auto-Ativação</span>
                </div>
                <div class="ssz-auto-status">
                    <div class="ssz-auto-badge <?php echo $auto_activate_enabled ? 'ssz-auto-on' : 'ssz-auto-off'; ?>" id="ssz-auto-badge">
                        <i class="fas <?php echo $auto_activate_enabled ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                        <span id="ssz-auto-label"><?php echo $auto_activate_enabled ? 'Ativado' : 'Desativado'; ?></span>
                    </div>
                    <p class="ssz-auto-desc">Seg-Sex, 08h-12h</p>
                </div>
            </div>
        </div>

        <style>
            .seu-souza-control-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 20px;
                margin-top: 10px;
            }
            .ssz-status-card {
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 16px;
                padding: 20px;
                transition: box-shadow 0.2s;
            }
            .ssz-status-card:hover {
                box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            }
            .ssz-status-header {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 16px;
            }
            .ssz-status-icon {
                width: 36px;
                height: 36px;
                border-radius: 10px;
                background: #fff7ed;
                color: #ff6b00;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 16px;
            }
            .ssz-status-title {
                font-weight: 700;
                font-size: 15px;
                color: #1e293b;
            }
            .ssz-status-counts {
                display: flex;
                gap: 20px;
                margin-bottom: 16px;
            }
            .ssz-count-item {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .ssz-count-dot {
                width: 10px;
                height: 10px;
                border-radius: 50%;
            }
            .ssz-dot-active { background: #22c55e; }
            .ssz-dot-inactive { background: #94a3b8; }
            .ssz-count-num {
                font-weight: 700;
                font-size: 20px;
                color: #1e293b;
            }
            .ssz-count-label {
                color: #64748b;
                font-size: 13px;
            }
            .ssz-bulk-actions {
                display: flex;
                gap: 10px;
            }
            .ssz-bulk-btn {
                flex: 1;
                padding: 10px 14px;
                border: none;
                border-radius: 10px;
                font-weight: 600;
                font-size: 13px;
                cursor: pointer;
                transition: all 0.2s;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
            }
            .ssz-btn-activate {
                background: #dcfce7;
                color: #16a34a;
            }
            .ssz-btn-activate:hover {
                background: #bbf7d0;
            }
            .ssz-btn-deactivate {
                background: #fee2e2;
                color: #ef4444;
            }
            .ssz-btn-deactivate:hover {
                background: #fecaca;
            }
            .ssz-bulk-btn:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }
            /* Limite */
            .ssz-limit-numbers {
                display: flex;
                align-items: baseline;
                gap: 4px;
                margin-bottom: 10px;
            }
            .ssz-limit-current {
                font-size: 32px;
                font-weight: 800;
                color: #1e293b;
            }
            .ssz-limit-separator {
                font-size: 20px;
                color: #94a3b8;
            }
            .ssz-limit-max {
                font-size: 20px;
                font-weight: 600;
                color: #94a3b8;
            }
            .ssz-limit-bar-bg {
                height: 8px;
                background: #f1f5f9;
                border-radius: 100px;
                overflow: hidden;
                margin-bottom: 10px;
            }
            .ssz-limit-bar-fill {
                height: 100%;
                background: #ff6b00;
                border-radius: 100px;
                transition: width 0.5s;
            }
            .ssz-limit-bar-fill.ssz-bar-warning {
                background: #f59e0b;
            }
            .ssz-limit-bar-fill.ssz-bar-full {
                background: #ef4444;
            }
            .ssz-limit-desc {
                font-size: 13px;
            }
            /* Auto-ativação */
            .ssz-auto-status {
                text-align: center;
            }
            .ssz-auto-badge {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 10px 20px;
                border-radius: 100px;
                font-weight: 700;
                font-size: 14px;
                margin-bottom: 8px;
            }
            .ssz-auto-on {
                background: #dcfce7;
                color: #16a34a;
            }
            .ssz-auto-off {
                background: #f1f5f9;
                color: #94a3b8;
            }
            .ssz-auto-desc {
                color: #94a3b8;
                font-size: 13px;
                margin: 0;
            }
            @media (max-width: 768px) {
                .seu-souza-control-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var sszAjaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';

            // Ativar todos Seu Souza
            $('#ssz-activate-all').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Ativando...');

                $.ajax({
                    url: sszAjaxUrl,
                    type: 'POST',
                    data: {
                        action: 'toggle_seu_souza_all_frontend',
                        new_status: 'ativo'
                    },
                    success: function(response) {
                        if (response.success) {
                            updateSszCounts(response.data.ativos, response.data.inativos);
                        }
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html('<i class="fas fa-check-circle"></i> Ativar Todos');
                    }
                });
            });

            // Desativar todos Seu Souza
            $('#ssz-deactivate-all').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Desativando...');

                $.ajax({
                    url: sszAjaxUrl,
                    type: 'POST',
                    data: {
                        action: 'toggle_seu_souza_all_frontend',
                        new_status: 'inativo'
                    },
                    success: function(response) {
                        if (response.success) {
                            updateSszCounts(response.data.ativos, response.data.inativos);
                        }
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html('<i class="fas fa-ban"></i> Desativar Todos');
                    }
                });
            });

            function updateSszCounts(ativos, inativos) {
                $('#ssz-ativos-count').text(ativos);
                $('#ssz-inativos-count').text(inativos);
            }

            // Auto-refresh dos dados Seu Souza a cada 30 segundos
            setInterval(function() {
                $.ajax({
                    url: sszAjaxUrl,
                    type: 'POST',
                    data: { action: 'get_seu_souza_status_frontend' },
                    success: function(response) {
                        if (response.success) {
                            updateSszCounts(response.data.ativos, response.data.inativos);
                            $('#ssz-daily-current').text(response.data.daily_count);
                            var limite = parseInt($('#ssz-daily-limit').text());
                            var pct = limite > 0 ? Math.min(100, Math.round((response.data.daily_count / limite) * 100)) : 0;
                            $('#ssz-limit-bar').css('width', pct + '%');
                            $('#ssz-limit-bar').removeClass('ssz-bar-full ssz-bar-warning');
                            if (pct >= 100) {
                                $('#ssz-limit-bar').addClass('ssz-bar-full');
                            } else if (pct >= 80) {
                                $('#ssz-limit-bar').addClass('ssz-bar-warning');
                            }
                        }
                    }
                });
            }, 30000);
        });
        </script>
        <?php
    }

    private function render_vendedor_row($index, $vendedor, $grupo = 'drv')
    {
        $status = isset($vendedor['status']) ? $vendedor['status'] : 'ativo';
        ?>
        <tr data-index="<?php echo esc_attr($index); ?>"
            class="vendedor-row <?php echo $status === 'inativo' ? 'vendedor-inativo' : ''; ?>">
            <td data-label="Grupo">
                <select name="vendedores[<?php echo esc_attr($index); ?>][grupo]" class="grupo-select" required>
                    <option value="drv" <?php selected($grupo, 'drv'); ?>>DRV</option>
                    <option value="seu_souza" <?php selected($grupo, 'seu_souza'); ?>>Seu Souza</option>
                </select>
            </td>
            <td data-label="Categoria">
                <?php if ($grupo === 'drv'): ?>
                    <select name="vendedores[<?php echo esc_attr($index); ?>][categoria]" class="categoria-select" required>
                        <option value="fixo" <?php selected(isset($vendedor['categoria']) ? $vendedor['categoria'] : '', 'fixo'); ?>>Fixo</option>
                        <option value="rotativo" <?php selected(isset($vendedor['categoria']) ? $vendedor['categoria'] : '', 'rotativo'); ?>>Rotativo</option>
                    </select>
                <?php else: ?>
                    <input type="hidden" name="vendedores[<?php echo esc_attr($index); ?>][categoria]" value="fixo">
                    <span>N/A</span>
                <?php endif; ?>
            </td>
            <!-- NOVO CAMPO: ID do Vendedor -->
            <td data-label="ID">
                <input type="text" name="vendedores[<?php echo esc_attr($index); ?>][vendedor_id]"
                    value="<?php echo isset($vendedor['vendedor_id']) ? esc_attr($vendedor['vendedor_id']) : ''; ?>"
                    placeholder="ID único" required>
            </td>
            <td data-label="Nome">
                <input type="text" name="vendedores[<?php echo esc_attr($index); ?>][nome]"
                    value="<?php echo isset($vendedor['nome']) ? esc_attr($vendedor['nome']) : ''; ?>" required>
            </td>
            <td data-label="Telefone">
                <input type="text" name="vendedores[<?php echo esc_attr($index); ?>][telefone]"
                    value="<?php echo isset($vendedor['telefone']) ? esc_attr($vendedor['telefone']) : ''; ?>" required>
            </td>
            <!-- COLUNA: Status -->
            <td data-label="Status">
                <select name="vendedores[<?php echo esc_attr($index); ?>][status]" class="status-select" required>
                    <option value="ativo" <?php selected($status, 'ativo'); ?>>✅ Ativo</option>
                    <option value="inativo" <?php selected($status, 'inativo'); ?>>❌ Inativo</option>
                </select>
            </td>
            <td data-label="Ações">
                <div class="vendedor-actions">
                    <!-- Botão Toggle Status -->
                    <button type="button" class="button button-small toggle-status-btn"
                        data-index="<?php echo esc_attr($index); ?>" data-current-status="<?php echo esc_attr($status); ?>"
                        title="<?php echo $status === 'ativo' ? 'Desativar vendedor' : 'Ativar vendedor'; ?>">
                        <?php if ($status === 'ativo'): ?>
                            <i class="dashicons dashicons-hidden"></i> Desativar
                        <?php else: ?>
                            <i class="dashicons dashicons-visibility"></i> Ativar
                        <?php endif; ?>
                    </button>

                    <!-- Botão Remover -->
                    <button type="button" class="button button-secondary remove-vendedor"
                        data-index="<?php echo esc_attr($index); ?>" title="Remover vendedor permanentemente">
                        <i class="dashicons dashicons-trash"></i> Remover
                    </button>

                    <?php
                    // Botoes Google Sheets (Criar, Atualizar, Ver)
                    $vendor_nome = isset($vendedor['nome']) ? $vendedor['nome'] : '';
                    Formulario_Hapvida_Google_Sheets::render_vendor_buttons($vendor_nome);
                    ?>
                </div>
            </td>
        </tr>
        <?php
    }

    // ---------------------------------------------------------------
    public function handle_save_vendedores()
    {
        if (!isset($_POST['vendedores_nonce']) || !wp_verify_nonce($_POST['vendedores_nonce'], 'save_vendedores')) {
            wp_die('Ação não autorizada.');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Você não tem permissão para realizar esta ação.');
        }

        $vendedores = isset($_POST['vendedores']) ? $_POST['vendedores'] : array();
        $vendedores_sanitized = array('drv' => array(), 'seu_souza' => array());

        foreach ($vendedores as $index => $vendedor) {
            if (!empty($vendedor['nome']) && !empty($vendedor['telefone']) && !empty($vendedor['grupo']) && !empty($vendedor['vendedor_id'])) {
                $grupo = sanitize_text_field($vendedor['grupo']);
                $categoria = ($grupo === 'drv' && isset($vendedor['categoria']))
                    ? sanitize_text_field($vendedor['categoria'])
                    : 'fixo';

                // Inclui o status do vendedor
                $status = isset($vendedor['status']) ? sanitize_text_field($vendedor['status']) : 'ativo';

                // NOVO: Inclui o ID do vendedor
                $vendedor_data = array(
                    'vendedor_id' => sanitize_text_field($vendedor['vendedor_id']), // NOVO CAMPO
                    'nome' => sanitize_text_field($vendedor['nome']),
                    'telefone' => sanitize_text_field($vendedor['telefone']),
                    'categoria' => $categoria,
                    'status' => $status,
                );
                $vendedores_sanitized[$grupo][] = $vendedor_data;
            }
        }

        update_option($this->vendedores_option, $vendedores_sanitized);

        // Conta vendedores ativos e inativos para feedback
        $contadores = array('drv_ativo' => 0, 'drv_inativo' => 0, 'seu_souza_ativo' => 0, 'seu_souza_inativo' => 0);
        foreach ($vendedores_sanitized as $grupo => $vendedores_grupo) {
            foreach ($vendedores_grupo as $vendedor) {
                $key = $grupo . '_' . $vendedor['status'];
                if (isset($contadores[$key])) {
                    $contadores[$key]++;
                }
            }
        }

        $message = sprintf(
            'Vendedores salvos com sucesso! DRV: %d ativos, %d inativos | Seu Souza: %d ativos, %d inativos',
            $contadores['drv_ativo'],
            $contadores['drv_inativo'],
            $contadores['seu_souza_ativo'],
            $contadores['seu_souza_inativo']
        );

        wp_redirect(admin_url('options-general.php?page=formulario-hapvida-admin&tab=vendedores&message=' . urlencode($message)));
        exit;
    }
}