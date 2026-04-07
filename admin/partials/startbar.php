<?php
if (!function_exists('admin_t')) {
    include_once "l.php";
}
?>
<div class="startbar d-print-none">
    <style>
        /* ——— Sidebar Variables ——— */
        :root {
            --sb-bg: #0d0e1a;
            --sb-bg-elevated: #12132050;
            --sb-border: rgba(255,255,255,.07);
            --sb-text: rgba(203,213,225,.88);
            --sb-muted: rgba(148,163,184,.5);
            --sb-accent: #5D87FF;
            --sb-accent-glow: rgba(93,135,255,.18);
            --sb-hover: rgba(93,135,255,.1);
            --sb-active-bg: rgba(93,135,255,.14);
            --sb-active-border: #5D87FF;
        }

        .startbar {
            position: fixed; top: 0; left: 0;
            height: 100vh; width: 230px;
            background: var(--sb-bg);
            border-right: 1px solid var(--sb-border);
            box-shadow: 4px 0 40px rgba(0,0,0,.35);
            z-index: 1040; display: flex; flex-direction: column;
        }
        .startbar .brand {
            padding: 16px 18px;
            border-bottom: 1px solid var(--sb-border);
            display: flex; align-items: center; justify-content: center;
            background: rgba(0,0,0,.25);
            backdrop-filter: blur(10px);
        }
        .startbar .logo-sm { max-height: 38px; object-fit: contain; filter: brightness(1.05); }

        .startbar-menu { flex: 1; overflow: auto; padding: 8px 10px; }
        .startbar-menu::-webkit-scrollbar { width: 3px; }
        .startbar-menu::-webkit-scrollbar-thumb { background: rgba(93,135,255,.2); border-radius: 10px; }

        .startbar-footer-card { padding: 8px 10px 12px; }

        .startbar .menu-label { padding: 14px 6px 4px; }
        .startbar .menu-label span {
            display: block; font-size: 9.5px; font-weight: 700;
            letter-spacing: .1em; text-transform: uppercase;
            color: var(--sb-muted);
        }

        .startbar .nav-item { margin: 1px 0; border-radius: 9px; }
        .startbar .nav-link {
            color: var(--sb-text);
            display: flex; align-items: center; gap: 8px;
            padding: 7px 10px; font-size: 12.5px; font-weight: 500;
            border-radius: 9px; transition: all .2s ease;
            text-decoration: none;
        }
        .startbar .nav-link .menu-icon {
            font-size: 17px; color: rgba(148,163,184,.65);
            transition: color .2s ease; flex-shrink: 0;
        }
        .startbar .nav-link span { flex: 1; }

        .startbar .nav-link:hover,
        .startbar .nav-link:focus {
            background: var(--sb-hover);
            color: #a8c0ff !important;
        }
        .startbar .nav-link:hover .menu-icon { color: var(--sb-accent) !important; }

        .startbar .nav-link[aria-expanded="true"] {
            background: var(--sb-active-bg);
            color: var(--sb-accent) !important;
            border-left: 2px solid var(--sb-active-border);
            padding-left: 8px;
        }
        .startbar .nav-link[aria-expanded="true"] .menu-icon { color: var(--sb-accent) !important; }

        .startbar .collapse .nav-link {
            padding-left: 34px; font-size: 12px;
            color: rgba(148,163,184,.75);
        }
        .startbar .collapse .nav-link:hover { color: #a8c0ff !important; background: var(--sb-hover); }

        .startbar .badge {
            vertical-align: middle; display: inline-flex;
            align-items: center; justify-content: center;
            font-size: 9px; height: 16px; line-height: 16px;
            padding: 1px 6px; border-radius: 999px;
        }
        .startbar .trail { margin-left: auto; display: inline-flex; align-items: center; gap: 6px; }
        .startbar .chev {
            font-size: 13px; color: rgba(148,163,184,.35);
            transition: transform .22s cubic-bezier(.16,1,.3,1);
        }
        .startbar .nav-link[aria-expanded="true"] .chev { transform: rotate(90deg); color: var(--sb-accent); }

        .startbar .border-dashed-bottom { border-bottom: 1px dashed var(--sb-border); margin: 10px 4px; }

        /* Active link indicator via JS (sidebarmenu.js) */
        .startbar .nav-link.active-link {
            background: var(--sb-active-bg) !important;
            color: var(--sb-accent) !important;
            border-left: 2px solid var(--sb-active-border);
            padding-left: 8px;
            box-shadow: inset 0 0 20px rgba(93,135,255,.05);
        }

        body.startbar-open { padding-left: 230px; }
        .startbar-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,.55);
            backdrop-filter: blur(4px);
            z-index: 1035; opacity: 0; visibility: hidden;
            transition: opacity .25s ease, visibility .25s ease;
        }
        .startbar.show + .startbar-overlay { opacity: 1; visibility: visible; }
        @media (min-width: 992px) { .startbar-overlay { display: none; } }
        @media (max-width: 992px) {
            .startbar { transform: translateX(-100%); transition: transform .28s cubic-bezier(.16,1,.3,1); }
            .startbar.show { transform: translateX(0); }
            body.startbar-open { padding-left: 0; }
        }
    </style>
    <script>
        (function(){
            var startbar=document.querySelector('.startbar');
            function syncDesktop(){
                if(!startbar)return;
                if(window.innerWidth>=992){
                    startbar.classList.add('show');
                    document.body.classList.add('startbar-open');
                }else{
                    startbar.classList.remove('show');
                    document.body.classList.remove('startbar-open');
                }
            }
            syncDesktop();
            window.addEventListener('resize',syncDesktop);
            var btn=document.getElementById('togglemenu');
            if(btn){
                btn.addEventListener('click',function(e){
                    if(!startbar)return;
                    if(window.innerWidth<992){
                        var isOpen=startbar.classList.toggle('show');
                        if(isOpen){
                            document.body.classList.add('startbar-open');
                        }else{
                            document.body.classList.remove('startbar-open');
                        }
                    }
                });
            }
            document.addEventListener('click',function(e){
                if(!startbar)return;
                if(window.innerWidth>=992)return;
                var isOpen=startbar.classList.contains('show');
                if(!isOpen)return;
                var toggle=document.getElementById('togglemenu');
                var clickInsideStartbar=startbar.contains(e.target);
                var clickOnToggle=toggle&&toggle.contains(e.target);
                if(!clickInsideStartbar&&!clickOnToggle){
                    startbar.classList.remove('show');
                    document.body.classList.remove('startbar-open');
                }
            });
        })();
    </script>
    <div class="brand">
        <a href="index.php" class="logo">
            <span>
                <img src="../uploads/<?= $dataconfig['logo'] ?>" alt="logo-small" class="logo-sm">
            </span>
            <span class=""></span>
        </a>
    </div>
    
    <div class="startbar-menu">
        <div class="startbar-collapse" id="startbarCollapse" data-simplebar>
            <div class="d-flex align-items-start flex-column w-100">
                <ul class="navbar-nav mb-auto w-100">
                    
                    <li class="menu-label pt-0 mt-0">
                        <span><?= admin_t('menu_reports') ?></span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard">
                            <i class="iconoir-home-simple menu-icon"></i>
                            <span><?= admin_t('menu_dashboard') ?></span>
                        </a>
                    </li>

                    
                    
                    <li class="menu-label mt-2">
                        <small class="label-border">
                            <div class="border_left hidden-xs"></div>
                            <div class="border_right"></div>
                        </small>
                        <span><?= admin_t('menu_platform') ?></span>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link" href="#sidebarMaps" data-bs-toggle="collapse" role="button"
                            aria-expanded="false" aria-controls="sidebarMaps">
                            <i class="iconoir-html5 menu-icon"></i>
                            <span><?= admin_t('menu_settings') ?></span><span class="trail"><i class="ti ti-chevron-right chev"></i></span>
                        </a>
                        <div class="collapse " id="sidebarMaps">
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link" href="configuracoes"><i class="ti ti-settings"></i><span><?= admin_t('menu_values') ?></span></a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="baus"><i class="ti ti-file-text"></i><span><?= admin_t('menu_affiliates_settings') ?></span></a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="gateway"><i class="ti ti-credit-card"></i><span><?= admin_t('menu_payments') ?></span></a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="niveis"><i class="ti ti-stars"></i><span><?= admin_t('menu_vips') ?></span></a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="checklist"><i class="ti ti-circle-check"></i><span><?= admin_t('menu_daily_checklist') ?></span></a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="cupons"><i class="ti ti-ticket"></i><span><?= admin_t('menu_coupons') ?></span></a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="gerenciamento-nomes"><i class="ti ti-users"></i><span><?= admin_t('menu_names') ?></span></a>
                                </li>
                            
                                <li class="nav-item">
                                    <a class="nav-link" href="atendimento"><i class="ti ti-headset"></i><span><?= admin_t('menu_support_channels') ?></span></a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="baixarpop"><i class="ti ti-download"></i><span><?= admin_t('menu_app_download') ?></span></a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="alterapainel"><i class="ti ti-layout"></i><span><?= admin_t('menu_change_panel') ?></span></a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="webhooks"><i class="ti ti-hierarchy-2"></i><span><?= admin_t('menu_webhooks') ?></span></a>
                                </li>
                            </ul>
                        </div>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link" href="#temas" data-bs-toggle="collapse" role="button"
                            aria-expanded="false" aria-controls="temas">
                            <i class="iconoir-design-pencil menu-icon"></i>
                            <span><?= admin_t('menu_customization') ?></span><span class="trail"><i class="ti ti-chevron-right chev"></i><span class="badge rounded text-danger bg-danger-subtle">(new)</span></span>
                        </a>
                        <div class="collapse " id="temas">
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link" href="identidade-visual"><i class="ti ti-photo"></i><span><?= admin_t('menu_platform_images') ?></span></a>
                                </li>
                            </ul>
                             <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link" href="modal"><i class="ti ti-app-window"></i><span><?= admin_t('menu_modals') ?></span></a>
                                </li>
                            </ul>
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link" href="banners"><i class="ti ti-photo"></i><span><?= admin_t('menu_banners') ?></span></a>
                                </li>
                            </ul>
                            
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link" href="promocoes"><i class="ti ti-percentage"></i><span><?= admin_t('menu_promotions') ?></span></a>
                                </li>
                            </ul>
                            
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link" href="temas"><i class="ti ti-palette"></i><span><?= admin_t('menu_themes') ?></span></a>
                                </li>
                            </ul>
                            
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link" href="linguagens"><i class="ti ti-language"></i><span><?= admin_t('menu_languages') ?></span></a>
                                </li>
                            </ul>
                            
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link" href="iconesfloat"><i class="ti ti-pin"></i><span><?= admin_t('menu_float_icons') ?></span></a>
                                </li>
                            </ul>
                            
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link" href="notificacoes"><i class="ti ti-bell"></i><span><?= admin_t('menu_general_notifications') ?></span></a>
                                </li>
                            </ul>
                        </div>
                    </li>
                    
                    
                    
                    <li class="menu-label mt-2">
                        <small class="label-border">
                            <div class="border_left hidden-xs"></div>
                            <div class="border_right"></div>
                        </small>
                        <span><?= admin_t('menu_finance') ?></span>
                    </li>
                    
                    <?php
                    $query_depositos_processamento = "SELECT COUNT(*) as total_processamento FROM transacoes WHERE status = 'processamento'";
                    $result_depositos_processamento = mysqli_query($mysqli, $query_depositos_processamento);
                    $row_depositos_processamento = mysqli_fetch_assoc($result_depositos_processamento);
                    $total_depositos_processamento = $row_depositos_processamento['total_processamento'];
                    
                    $query_depositos_aprovados = "SELECT COUNT(*) as total_aprovados FROM transacoes WHERE status = 'pago'";
                    $result_depositos_aprovados = mysqli_query($mysqli, $query_depositos_aprovados);
                    $row_depositos_aprovados = mysqli_fetch_assoc($result_depositos_aprovados);
                    $total_depositos_aprovados = $row_depositos_aprovados['total_aprovados'];
                    
                    $query_depositos_recusados = "SELECT COUNT(*) as total_recusados FROM transacoes WHERE status = 'expirado'";
                    $result_depositos_recusados = mysqli_query($mysqli, $query_depositos_recusados);
                    $row_depositos_recusados = mysqli_fetch_assoc($result_depositos_recusados);
                    $total_depositos_recusados = $row_depositos_recusados['total_recusados'];
                    
                    $total_depositos = $total_depositos_processamento + $total_depositos_aprovados + $total_depositos_recusados;
                    ?>
                    
                    <li class="nav-item">
                        <a class="nav-link" href="#sidebarElements" data-bs-toggle="collapse" role="button"
                            aria-expanded="false" aria-controls="sidebarElements">
                            <i class="iconoir-receive-dollars menu-icon"></i>
                            <span><?= admin_t('menu_deposits') ?></span><span class="trail"><i class="ti ti-chevron-right chev"></i><span class="badge rounded text-warning bg-warning-subtle"><?= $total_depositos; ?></span></span>
                        </a>
                        <div class="collapse " id="sidebarElements">
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link" href="depositos_pagos"><i class="ti ti-circle-check"></i><span><?= admin_t('menu_paid') ?></span> <span class="badge rounded text-success bg-success-subtle ms-1"><?= $total_depositos_aprovados; ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="depositos_pendentes"><i class="ti ti-hourglass"></i><span><?= admin_t('menu_pending') ?></span> <span class="badge rounded text-warning bg-warning-subtle ms-1"><?= $total_depositos_processamento; ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="depositos_expirados">
                                        <i class="ti ti-circle-x"></i><span><?= admin_t('menu_expired') ?></span>
                                        <span class="badge rounded text-danger bg-danger-subtle ms-1"><?= $total_depositos_recusados; ?></span>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>
                    
                    <?php
                    $query_saques_pendentes = "SELECT COUNT(*) as total_pendentes FROM solicitacao_saques WHERE status = '0' AND tipo_saque = '0'";
                    $result_saques_pendentes = mysqli_query($mysqli, $query_saques_pendentes);
                    $row_saques_pendentes = mysqli_fetch_assoc($result_saques_pendentes);
                    $total_saques_pendentes = $row_saques_pendentes['total_pendentes'];
                    
                    $query_saques_aprovados = "SELECT COUNT(*) as total_aprovados FROM solicitacao_saques WHERE status = '1' AND tipo_saque = '0'";
                    $result_saques_aprovados = mysqli_query($mysqli, $query_saques_aprovados);
                    $row_saques_aprovados = mysqli_fetch_assoc($result_saques_aprovados);
                    $total_saques_aprovados = $row_saques_aprovados['total_aprovados'];
                    
                    $query_saques_recusados = "SELECT COUNT(*) as total_recusados FROM solicitacao_saques WHERE status = '2' AND tipo_saque = '0'";
                    $result_saques_recusados = mysqli_query($mysqli, $query_saques_recusados);
                    $row_saques_recusados = mysqli_fetch_assoc($result_saques_recusados);
                    $total_saques_recusados = $row_saques_recusados['total_recusados'];
                    
                    $total_saques = $total_saques_pendentes + $total_saques_aprovados + $total_saques_recusados;
                    ?>
                    
                    <?php
                    $query_saques_afiliados_pendentes = "SELECT COUNT(*) as total_pendentes FROM solicitacao_saques WHERE status = '0' AND tipo_saque = '1'";
                    $result_saques_afiliados_pendentes = mysqli_query($mysqli, $query_saques_afiliados_pendentes);
                    $row_saques_afiliados_pendentes = mysqli_fetch_assoc($result_saques_afiliados_pendentes);
                    $total_saques_afiliados_pendentes = $row_saques_afiliados_pendentes['total_pendentes'];
                    
                    $query_saques_afiliados_aprovados = "SELECT COUNT(*) as total_aprovados FROM solicitacao_saques WHERE status = '1' AND tipo_saque = '1'";
                    $result_saques_afiliados_aprovados = mysqli_query($mysqli, $query_saques_afiliados_aprovados);
                    $row_saques_afiliados_aprovados = mysqli_fetch_assoc($result_saques_afiliados_aprovados);
                    $total_saques_afiliados_aprovados = $row_saques_afiliados_aprovados['total_aprovados'];
                    
                    $query_saques_afiliados_recusados = "SELECT COUNT(*) as total_recusados FROM solicitacao_saques WHERE status = '2' AND tipo_saque = '1'";
                    $result_saques_afiliados_recusados = mysqli_query($mysqli, $query_saques_afiliados_recusados);
                    $row_saques_afiliados_recusados = mysqli_fetch_assoc($result_saques_afiliados_recusados);
                    $total_saques_afiliados_recusados = $row_saques_afiliados_recusados['total_recusados'];
                    
                    $total_saques_afiliados = $total_saques_afiliados_pendentes + $total_saques_afiliados_aprovados + $total_saques_afiliados_recusados;
                    ?>

                    <li class="nav-item" style="background-color: rgba(255, 255, 255, 0.04); border-radius: 8px; margin:2px;">
                        <a class="nav-link" href="#sidebarAdvancedUI" data-bs-toggle="collapse" role="button"
                            aria-expanded="false" aria-controls="sidebarAdvancedUI">
                            <i class="iconoir-send-dollars menu-icon"></i>
                            <span><?= admin_t('menu_withdrawals') ?></span>
                            <span class="trail"><i class="ti ti-chevron-right chev"></i><span class="badge rounded text-warning bg-warning-subtle"><?= $total_saques; ?></span></span>
                        </a>
                        <div class="collapse" id="sidebarAdvancedUI">
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link" href="saques_aprovados"><i class="ti ti-circle-check"></i><span><?= admin_t('menu_paid') ?></span>
                                        <span class="badge rounded text-success bg-success-subtle ms-1"><?= $total_saques_aprovados; ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="saques_pendentes"><i class="ti ti-hourglass"></i><span><?= admin_t('menu_pending') ?></span>
                                        <span class="badge rounded text-warning bg-warning-subtle ms-1"><?= $total_saques_pendentes; ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="saques_recusados"><i class="ti ti-circle-x"></i><span><?= admin_t('menu_refused') ?></span>
                                    <span class="badge rounded text-danger bg-danger-subtle ms-1"><?= $total_saques_recusados; ?></span>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>

                    
                    
                    <li class="menu-label mt-2">
                        <small class="label-border">
                            <div class="border_left hidden-xs"></div>
                            <div class="border_right"></div>
                        </small>
                        <span><?= admin_t('menu_users_section') ?></span>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link" href="#sidebarForms" data-bs-toggle="collapse" role="button"
                            aria-expanded="false" aria-controls="sidebarForms">
                            <i class="iconoir-community menu-icon"></i>
                            <span><?= admin_t('users') ?></span><span class="trail"><i class="ti ti-chevron-right chev"></i></span>
                        </a>
                        <div class="collapse " id="sidebarForms">
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link" href="usuarios"><i class="ti ti-users"></i><span><?= admin_t('menu_all_users') ?></span></a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="afiliados"><i class="ti ti-users"></i><span><?= admin_t('menu_all_influencers') ?></span></a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="contas-demos"><i class="ti ti-device-gamepad-2"></i><span><?= admin_t('menu_create_demo_account') ?></span></a>
                                </li>
                            </ul>
                        </div>
                    </li>
                    
                    
                    
                    
                    
                    <?php
                    $query_feedbacks_pendentes = "SELECT COUNT(*) as total_pendentes FROM customer_feedback WHERE status = 'pending'";
                    $result_feedbacks_pendentes = mysqli_query($mysqli, $query_feedbacks_pendentes);
                    $row_feedbacks_pendentes = mysqli_fetch_assoc($result_feedbacks_pendentes);
                    $total_feedbacks_pendentes = $row_feedbacks_pendentes['total_pendentes'];
                    
                    $query_feedbacks_total = "SELECT COUNT(*) as total FROM customer_feedback";
                    $result_feedbacks_total = mysqli_query($mysqli, $query_feedbacks_total);
                    $row_feedbacks_total = mysqli_fetch_assoc($result_feedbacks_total);
                    $total_feedbacks = $row_feedbacks_total['total'];
                    ?>
                    
                    

                    
                    
                    <li class="menu-label mt-2">
                        <small class="label-border">
                            <div class="border_left hidden-xs"></div>
                            <div class="border_right"></div>
                        </small>
                        <span><?= admin_t('menu_history_section') ?></span>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link" href="#historicos" data-bs-toggle="collapse" role="button"
                            aria-expanded="false" aria-controls="historicos">
                            <span><?= admin_t('menu_histories') ?></span><span class="trail"><i class="ti ti-chevron-right chev"></i></span>
                        </a>
                        <div class="collapse " id="historicos">
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link" href="historicosplay"><i class="ti ti-device-gamepad-2"></i><span><?= admin_t('menu_bets') ?></span></a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="logsbonus"><i class="ti ti-percentage"></i><span><?= admin_t('menu_bonus') ?></span></a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="niveislogs"><i class="ti ti-stars"></i><span><?= admin_t('menu_levels') ?></span></a>
                                </li>
                            </ul>
                        </div>
                    </li>

                    
                    
                    <li class="menu-label mt-2">
                        <small class="label-border">
                            <div class="border_left hidden-xs"></div>
                            <div class="border_right"></div>
                        </small>
                        <span><?= admin_t('menu_games_section') ?></span>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link" href="#chavesapi" data-bs-toggle="collapse" role="button"
                            aria-expanded="false" aria-controls="chavesapi">
                            <i class="iconoir-key-plus menu-icon"></i>
                            <span><?= admin_t('menu_api_games') ?></span><span class="trail"><i class="ti ti-chevron-right chev"></i></span>
                        </a>
                        <div class="collapse " id="chavesapi">
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link" href="api"><i class="ti ti-shield-lock"></i><span><?= admin_t('menu_credentials') ?></span></a>
                                </li>
                            </ul>
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link" href="jogos"><i class="ti ti-device-gamepad-2"></i><span><?= admin_t('menu_games') ?></span></a>
                                </li>
                            </ul>
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link" href="provedores"><i class="ti ti-server"></i><span><?= admin_t('menu_providers') ?></span></a>
                                </li>
                            </ul>
                        </div>
                    </li>

                </ul>

            </div>
        </div>
    </div>
    
</div>
<div class="startbar-overlay d-print-none"></div>
