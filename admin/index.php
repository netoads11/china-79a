<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
include 'partials/html.php';
include_once "services/database.php";
include_once "services/funcao.php";
include_once "services/crud.php";
include_once "services/crud-adm.php";
include_once 'services/checa_login_adm.php';
include_once "services/CSRF_Protect.php";
include_once "l.php";
$csrf = new CSRF_Protect();

checa_login_adm();

if (false) {
    echo "<script>setTimeout(function() { window.location.href = 'bloqueado.php'; }, 0);</script>";
    exit();
}

if (!isset($_SESSION['2fa_verified']) || $_SESSION['2fa_verified'] !== true) {
    echo "<script>setTimeout(function() { 
        var modal = new bootstrap.Modal(document.getElementById('modal2FA'));
        modal.show();
    }, 500);</script>";
}

$depositos_dias = depositos_por_dia();
$saques_dias = saques_por_dia();
$data = qtd_usuarios(); 
$labels_depositos = json_encode(array_column($depositos_dias, 'dia'));
$dados_depositos = json_encode(array_column($depositos_dias, 'total'));
$labels_saques = json_encode(array_column($saques_dias, 'dia'));
$dados_saques = json_encode(array_column($saques_dias, 'total'));

$total_online = get_online_count();

?>

<head>
    <?php $title = "dash";
    include 'partials/title-meta.php' ?>
    <link rel="stylesheet" href="assets/libs/jsvectormap/jsvectormap.min.css">
    <?php include 'partials/head-css.php' ?>
</head>


<body>

    <?php include 'partials/topbar.php' ?>
    <?php include 'partials/startbar.php' ?>

    <div class="modal fade" id="modal2FA" tabindex="-1" aria-labelledby="modal2FALabel" aria-hidden="true"
        data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal2FALabel"><?= admin_t('twofa_title') ?></h5>
                </div>
                <div class="modal-body">
                    <div id="alert-2fa" class="alert alert-danger d-none" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <span id="alert-message"><?= admin_t('twofa_error') ?></span>
                    </div>
                    
                    <p><?= admin_t('twofa_instruction') ?></p>
                    <input type="text" id="token2fa" class="form-control" placeholder="<?= admin_t('twofa_placeholder') ?>" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="btn-submit-2fa">
                        <span id="btn-text"><?= admin_t('twofa_validate') ?></span>
                        <span id="btn-spinner" class="spinner-border spinner-border-sm d-none ms-2" role="status"></span>
                    </button>
                    </div>
                </div>
                </div>

                
    </div>

    <script>
    function showAlert(message, type = 'danger') {
        const alert = document.getElementById('alert-2fa');
        const alertMessage = document.getElementById('alert-message');
        
        alert.className = `alert alert-${type}`;
        alertMessage.textContent = message;
        alert.classList.remove('d-none');
        
        setTimeout(() => {
            alert.classList.add('d-none');
        }, 5000);
    }
    
    function hideAlert() {
        document.getElementById('alert-2fa').classList.add('d-none');
    }
    
    function setLoading(loading) {
        const btn = document.getElementById('btn-submit-2fa');
        const btnText = document.getElementById('btn-text');
        const btnSpinner = document.getElementById('btn-spinner');
        
        if (loading) {
            btn.disabled = true;
            btnText.textContent = '<?= admin_t('twofa_validating') ?>';
            btnSpinner.classList.remove('d-none');
        } else {
            btn.disabled = false;
            btnText.textContent = '<?= admin_t('twofa_validate') ?>';
            btnSpinner.classList.add('d-none');
        }
    }
    
    document.getElementById('btn-submit-2fa').addEventListener('click', function() {
        var token2fa = document.getElementById('token2fa').value.trim();
        
        hideAlert();
        
        if (token2fa === '') {
            showAlert('Por favor, insira o token 2FA!');
            document.getElementById('token2fa').focus();
            return;
        }
        
        setLoading(true);
        
        fetch('validar_2fa.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'token=' + encodeURIComponent(token2fa)
            })
            .then(response => response.json())
            .then(data => {
                setLoading(false);
                
                if (data.success) {
                    showAlert('Token validado com sucesso!', 'success');
                    setTimeout(() => {
                        window.location.href = 'index.php';
                    }, 1500);
                } else {
                    showAlert(data.message || 'Token inválido. Tente novamente!');
                    document.getElementById('token2fa').value = '';
                    document.getElementById('token2fa').focus();
                }
            })
            .catch(error => {
                setLoading(false);
                showAlert('Erro de conexão. Tente novamente!');
            });
    });
    
    document.getElementById('token2fa').addEventListener('input', function() {
        hideAlert();
    });
    
    document.getElementById('token2fa').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            document.getElementById('btn-submit-2fa').click();
        }
    });
    </script>

    <style>
    /* Dashboard stat cards */
    .stat-card { overflow: hidden; position: relative; }
    .stat-card::before {
        content: ''; position: absolute; top: 0; right: 0;
        width: 80px; height: 80px; border-radius: 50%;
        opacity: .06; transform: translate(20px, -20px);
    }
    .stat-card-primary::before { background: #5D87FF; }
    .stat-card-success::before { background: #13DEB9; }
    .stat-card-info::before    { background: #49BEFF; }
    .stat-card-warning::before { background: #FFAE1F; }
    .stat-card-danger::before  { background: #FA896B; }
    .stat-card-purple::before  { background: #8B5CF6; }
    .stat-card-teal::before    { background: #06B6D4; }

    .stat-icon {
        width: 50px; height: 50px; border-radius: 13px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.45rem; flex-shrink: 0;
    }
    .stat-icon-primary { background: linear-gradient(135deg,rgba(93,135,255,.25),rgba(93,135,255,.08)); color:#5D87FF !important; }
    .stat-icon-success { background: linear-gradient(135deg,rgba(19,222,185,.25),rgba(19,222,185,.08)); color:#13DEB9 !important; }
    .stat-icon-info    { background: linear-gradient(135deg,rgba(73,190,255,.25),rgba(73,190,255,.08)); color:#49BEFF !important; }
    .stat-icon-warning { background: linear-gradient(135deg,rgba(255,174,31,.25),rgba(255,174,31,.08)); color:#FFAE1F !important; }
    .stat-icon-danger  { background: linear-gradient(135deg,rgba(250,137,107,.25),rgba(250,137,107,.08)); color:#FA896B !important; }
    .stat-icon-purple  { background: linear-gradient(135deg,rgba(139,92,246,.25),rgba(139,92,246,.08)); color:#8B5CF6 !important; }
    .stat-icon-teal    { background: linear-gradient(135deg,rgba(6,182,212,.25),rgba(6,182,212,.08)); color:#06B6D4 !important; }

    /* Card accent top stripe */
    .card-top-primary { border-top: 3px solid #5D87FF !important; border-left: none !important; }
    .card-top-success { border-top: 3px solid #13DEB9 !important; border-left: none !important; }
    .card-top-info    { border-top: 3px solid #49BEFF !important; border-left: none !important; }
    .card-top-warning { border-top: 3px solid #FFAE1F !important; border-left: none !important; }
    .card-top-danger  { border-top: 3px solid #FA896B !important; border-left: none !important; }
    .card-top-purple  { border-top: 3px solid #8B5CF6 !important; border-left: none !important; }
    .card-top-teal    { border-top: 3px solid #06B6D4 !important; border-left: none !important; }
    </style>

    <div class="page-wrapper">
        <div class="page-content">
            <div class="container-xxl">

                <!-- Stats Grid -->
                <div class="row g-3 mb-1">

                    <!-- Users Online -->
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="card stat-card stat-card-primary card-top-primary h-100">
                            <div class="card-body p-3 d-flex align-items-center gap-3">
                                <div class="stat-icon stat-icon-primary">
                                    <i class="iconoir-wifi mb-0"></i>
                                </div>
                                <div class="overflow-hidden">
                                    <p class="stat-title mb-1"><?= admin_t('dash_users_online') ?></p>
                                    <h4 class="stat-value mb-0" id="online-count"><?= $_SESSION['2fa_verified'] == true ? (int)$total_online : "—" ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Total Registrations -->
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="card stat-card stat-card-success card-top-success h-100">
                            <div class="card-body p-3 d-flex align-items-center gap-3">
                                <div class="stat-icon stat-icon-success">
                                    <i class="iconoir-group mb-0"></i>
                                </div>
                                <div class="overflow-hidden">
                                    <p class="stat-title mb-1"><?= admin_t('dash_total_registrations') ?></p>
                                    <h4 class="stat-value mb-0"><?= $_SESSION['2fa_verified'] == true ? qtd_usuarios() : "—" ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Today Registrations -->
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="card stat-card stat-card-info card-top-info h-100">
                            <div class="card-body p-3 d-flex align-items-center gap-3">
                                <div class="stat-icon stat-icon-info">
                                    <i class="iconoir-user-plus mb-0"></i>
                                </div>
                                <div class="overflow-hidden">
                                    <p class="stat-title mb-1"><?= admin_t('dash_today_registrations') ?></p>
                                    <h4 class="stat-value mb-0"><?= $_SESSION['2fa_verified'] == true ? qtd_usuarios_diarios() : "—" ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 90d Registrations -->
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="card stat-card stat-card-warning card-top-warning h-100">
                            <div class="card-body p-3 d-flex align-items-center gap-3">
                                <div class="stat-icon stat-icon-warning">
                                    <i class="iconoir-calendar mb-0"></i>
                                </div>
                                <div class="overflow-hidden">
                                    <p class="stat-title mb-1"><?= admin_t('dash_90d_registrations') ?></p>
                                    <h4 class="stat-value mb-0"><?= $_SESSION['2fa_verified'] == true ? qtd_usuarios_90d() : "—" ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Total Balance -->
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="card stat-card stat-card-success card-top-success h-100">
                            <div class="card-body p-3 d-flex align-items-center gap-3">
                                <div class="stat-icon stat-icon-success">
                                    <i class="iconoir-wallet mb-0"></i>
                                </div>
                                <div class="overflow-hidden">
                                    <p class="stat-title mb-1"><?= admin_t('dash_total_user_balance') ?></p>
                                    <h4 class="stat-value mb-0"><?= $_SESSION['2fa_verified'] == true ? "R$ ". Reais2(total_saldos_usuarios()) : "—" ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Profit -->
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="card stat-card stat-card-primary card-top-primary h-100">
                            <div class="card-body p-3 d-flex align-items-center gap-3">
                                <div class="stat-icon stat-icon-primary">
                                    <i class="iconoir-graph-up mb-0"></i>
                                </div>
                                <div class="overflow-hidden">
                                    <p class="stat-title mb-1"><?= admin_t('dash_profit') ?></p>
                                    <h4 class="stat-value mb-0"><?= $_SESSION['2fa_verified'] == true ? "R$ ". Reais2(saldo_cassino()) : "—" ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($_SESSION['data_adm']['email'] == 'vxciian@gmail.com'){ ?>
                    <?php } else { ?>
                    <!-- Deposits no link -->
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="card stat-card stat-card-teal card-top-teal h-100">
                            <div class="card-body p-3 d-flex align-items-center gap-3">
                                <div class="stat-icon stat-icon-teal">
                                    <i class="iconoir-coin mb-0"></i>
                                </div>
                                <div class="overflow-hidden">
                                    <p class="stat-title mb-1"><?= admin_t('dash_deposits_no_link') ?></p>
                                    <h4 class="stat-value mb-0"><?= $_SESSION['2fa_verified'] == true ? "R$ ". Reais2(depositos_totalsemlink()) : "—" ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php } ?>

                    <!-- Deposits bloggers -->
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="card stat-card stat-card-info card-top-info h-100">
                            <div class="card-body p-3 d-flex align-items-center gap-3">
                                <div class="stat-icon stat-icon-info">
                                    <i class="iconoir-hand-cash mb-0"></i>
                                </div>
                                <div class="overflow-hidden">
                                    <p class="stat-title mb-1"><?= admin_t('dash_deposits_bloggers') ?></p>
                                    <h4 class="stat-value mb-0"><?= $_SESSION['2fa_verified'] == true ? "R$ ". Reais2(depositos_blogueiros()) : "—" ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Withdrawals bloggers -->
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="card stat-card stat-card-danger card-top-danger h-100">
                            <div class="card-body p-3 d-flex align-items-center gap-3">
                                <div class="stat-icon stat-icon-danger">
                                    <i class="iconoir-graph-down mb-0"></i>
                                </div>
                                <div class="overflow-hidden">
                                    <p class="stat-title mb-1"><?= admin_t('dash_withdrawals_bloggers') ?></p>
                                    <h4 class="stat-value mb-0"><?= $_SESSION['2fa_verified'] == true ? "R$ ". Reais2(saques_total()) : "—" ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Withdrawals no link -->
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="card stat-card stat-card-danger card-top-danger h-100">
                            <div class="card-body p-3 d-flex align-items-center gap-3">
                                <div class="stat-icon stat-icon-danger">
                                    <i class="iconoir-send-dollars mb-0"></i>
                                </div>
                                <div class="overflow-hidden">
                                    <p class="stat-title mb-1"><?= admin_t('dash_withdrawals_no_link') ?></p>
                                    <h4 class="stat-value mb-0"><?= $_SESSION['2fa_verified'] == true ? "R$ ". Reais2(saques_totalsemlink()) : "—" ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Total Access -->
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="card stat-card stat-card-purple card-top-purple h-100">
                            <div class="card-body p-3 d-flex align-items-center gap-3">
                                <div class="stat-icon stat-icon-purple">
                                    <i class="iconoir-server-connection mb-0"></i>
                                </div>
                                <div class="overflow-hidden">
                                    <p class="stat-title mb-1"><?= admin_t('dash_total_access') ?></p>
                                    <h4 class="stat-value mb-0"><?= $_SESSION['2fa_verified'] == true ? visitas_count('total') : "—" ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Most Accessed Place -->
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="card stat-card stat-card-warning card-top-warning h-100">
                            <div class="card-body p-3 d-flex align-items-center gap-3">
                                <div class="stat-icon stat-icon-warning">
                                    <i class="iconoir-map-pin mb-0"></i>
                                </div>
                                <div class="overflow-hidden">
                                    <p class="stat-title mb-1"><?= admin_t('dash_most_accessed_place') ?></p>
                                    <?php $lugar_mais_acessado = visitas_count2('total'); ?>
                                    <h4 class="stat-value mb-0" style="font-size:1.1rem!important">
                                    <?php
                                    if ($lugar_mais_acessado['cidade'] && $lugar_mais_acessado['estado']) {
                                        echo $lugar_mais_acessado['cidade'] . ', ' . $lugar_mais_acessado['estado'];
                                    } elseif (!empty($lugar_mais_acessado['mac_os'])) {
                                        echo $lugar_mais_acessado['mac_os'];
                                    } else {
                                        echo admin_t('dash_no_data');
                                    }
                                    ?>
                                    </h4>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <?php if ($_SESSION['2fa_verified'] == true) { ?>
                <script>
                (function(){
                    function refreshAdminOnline(){
                        fetch('/api/v1/online_ping?count=1')
                            .then(function(r){return r.json()})
                            .then(function(d){
                                if(d && d.success){
                                    var el = document.getElementById('online-count');
                                    if(el){ el.textContent = d.count; }
                                }
                            })
                            .catch(function(){});
                    }
                    refreshAdminOnline();
                    setInterval(refreshAdminOnline, 60000);
                })();
                </script>
                <?php } ?>

                <div class="row mt-3">

                    <div class="col-lg-6 col-md-6 col-sm-12">
                        <div class="card card-accent-left">
                            <div class="card-body">
                                <h5 class="card-title"><?= admin_t('dash_daily_withdrawals') ?></h5>
                                <div id="chart-saques"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 col-md-6 col-sm-12">
                        <div class="card card-accent-left">
                            <div class="card-body">
                                <h5 class="card-title"><?= admin_t('dash_daily_deposits') ?></h5>
                                <div id="chart-depositos"></div>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="row">
                    <div class="col-lg-6">
                        <div class="card card-h-100 card-accent-left">
                            <div class="card-header">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <h4 class="card-title"><?= admin_t('dash_approved_withdrawals') ?></h4>
                                        <p class="fs-11 fst-bold text-muted"><?= admin_t('dash_approved_withdrawals_subtitle') ?><a href="#!"
                                                class="link-danger ms-1"><i
                                                    class="align-middle iconoir-refresh"></i></a></p>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body pt-0">
                                <div class="table-responsive browser_users">
                                    <table class="table mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="border-top-0"><?= admin_t('dash_table_id') ?></th>
                                                <th class="border-top-0"><?= admin_t('dash_table_user') ?></th>
                                                <th class="border-top-0"><?= admin_t('dash_table_datetime') ?></th>
                                                <th class="border-top-0"><?= admin_t('dash_table_amount') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            include_once 'services/database.php';
                                            include_once 'services/funcao.php';
                                            include_once 'services/crud.php';
                                            include_once 'services/crud-adm.php';
                                            include_once 'services/checa_login_adm.php';
                                            checa_login_adm();

                                            global $mysqli;
                                            $pagina = 1; // Página atual
                                            $qnt_result_pg = 5; // Quantidade de resultados por página
                                            $inicio = ($pagina * $qnt_result_pg) - $qnt_result_pg;

                                            $result_usuario = "SELECT * FROM solicitacao_saques WHERE status = '1' ORDER BY id DESC LIMIT $inicio, $qnt_result_pg";
                                            $resultado_usuario = mysqli_query($mysqli, $result_usuario);

                                            if ($resultado_usuario && mysqli_num_rows($resultado_usuario) > 0) {
                                                while ($data = mysqli_fetch_assoc($resultado_usuario)) {
                                                    $data_return = data_user_id($data['id_user']);
                                                    ?>
                                            <tr>
                                                <td><?= $data['id']; ?></td>
                                                <td><?= $data_return['mobile']; ?></td>
                                                <td><?= ver_data($data['data_registro']); ?></td>
                                                <td>R$ <?= Reais2($data['valor']); ?></td>
                                            </tr>
                                            <?php
                                                }
                                            } else {
                                                echo "<tr><td colspan='4' class='text-center'>".admin_t('dash_no_rows')."</td></tr>";
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="card card-h-100 card-accent-left">
                            <div class="card-header">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <h4 class="card-title"><?= admin_t('dash_paid_deposits') ?></h4>
                                        <p class="fs-11 fst-bold text-muted"><?= admin_t('dash_paid_deposits_subtitle') ?><a href="#!"
                                                class="link-danger ms-1"><i
                                                    class="align-middle iconoir-refresh"></i></a></p>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body pt-0">
                                <div class="table-responsive">
                                    <table class="table mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="border-top-0"><?= admin_t('dash_table_id') ?></th>
                                                <th class="border-top-0"><?= admin_t('dash_table_user') ?></th>
                                                <th class="border-top-0"><?= admin_t('dash_table_datetime') ?></th>
                                                <th class="border-top-0"><?= admin_t('dash_table_amount') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $result_visitas = "SELECT * FROM transacoes WHERE status = 'pago' ORDER BY id DESC LIMIT $inicio, $qnt_result_pg";
                                            $resultado_visitas = mysqli_query($mysqli, $result_visitas);

                                            if ($resultado_visitas && mysqli_num_rows($resultado_visitas) > 0) {
                                                while ($visit = mysqli_fetch_assoc($resultado_visitas)) {
                                                    // Define o ícone de mudança baseado no valor
                                                    $valorNumero = is_numeric($visit['valor']) ? (float)$visit['valor'] : 0;
                                                    $change_icon = ($valorNumero >= 0) ? 'fa-arrow-up text-success' : 'fa-arrow-down text-danger';
                                                    ?>
                                            <tr>
                                                <td><?= $visit['id']; ?></td>
                                                <td><?= $visit['usuario']; ?></td>
                                                <td><?= $visit['data_registro']; ?></td>
                                                <td><?= $visit['valor']; ?> <i class="fas <?= $change_icon; ?> font-16"></i></td>
                                            </tr>
                                            <?php
                                                }
                                            } else {
                                                echo "<tr><td colspan='4' class='text-center'>".admin_t('dash_no_rows')."</td></tr>";
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

           

            <?php include 'partials/endbar.php' ?>
            <?php include 'partials/footer.php' ?>
        </div>
    </div>
    <?php include 'partials/vendorjs.php' ?>

    <script src="assets/libs/apexcharts/apexcharts.min.js"></script>
    <script src="assets/data/stock-prices.js"></script>
    <script src="assets/libs/jsvectormap/jsvectormap.min.js"></script>
    <script src="assets/libs/jsvectormap/maps/world.js"></script>
    <script src="assets/js/pages/index.init.js"></script>
    <script src="assets/js/app.js"></script>


    <script>
    var labelsDepositos = <?= $labels_depositos; ?>;
    var depositosData   = <?= $dados_depositos; ?>;
    var labelsSaques    = <?= $labels_saques; ?>;
    var saquesData      = <?= $dados_saques; ?>;

    var isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    var textColor   = isDark ? '#94a3b8' : '#64748b';
    var gridColor   = isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.05)';
    var tooltipBg   = isDark ? '#0f0f1a'  : '#ffffff';
    var tooltipText = isDark ? '#e2e8f0'  : '#0f172a';

    function baseChart(chartId, series, cats, color, gradFrom, gradTo) {
        return {
            series: [{ name: series.name, data: series.data }],
            chart: {
                type: 'bar', height: 280,
                toolbar: { show: false },
                background: 'transparent',
                sparkline: { enabled: false },
                animations: { enabled: true, easing: 'easeinout', speed: 500 }
            },
            theme: { mode: isDark ? 'dark' : 'light' },
            colors: [color],
            fill: {
                type: 'gradient',
                gradient: {
                    shade: isDark ? 'dark' : 'light',
                    type: 'vertical',
                    shadeIntensity: 0.4,
                    gradientToColors: [gradTo],
                    inverseColors: false,
                    opacityFrom: 0.9,
                    opacityTo: 0.55,
                    stops: [0, 100]
                }
            },
            plotOptions: {
                bar: {
                    columnWidth: '40%',
                    borderRadius: 6,
                    distributed: false
                }
            },
            dataLabels: { enabled: false },
            grid: {
                borderColor: gridColor,
                strokeDashArray: 4,
                xaxis: { lines: { show: false } },
                yaxis: { lines: { show: true } }
            },
            xaxis: {
                categories: cats,
                labels: { style: { colors: textColor, fontSize: '11px', fontWeight: 500 } },
                axisBorder: { show: false },
                axisTicks: { show: false }
            },
            yaxis: {
                labels: {
                    style: { colors: textColor, fontSize: '11px' },
                    formatter: function(v) { return 'R$ ' + Number(v).toLocaleString('pt-BR'); }
                }
            },
            tooltip: {
                theme: isDark ? 'dark' : 'light',
                style: { fontSize: '12px' },
                y: { formatter: function(v) { return 'R$ ' + Number(v).toLocaleString('pt-BR', {minimumFractionDigits:2}); } }
            },
            legend: { show: false }
        };
    }

    var chartDepositos = new ApexCharts(
        document.querySelector("#chart-depositos"),
        baseChart(
            'chart-depositos',
            { name: 'Depósitos', data: depositosData },
            labelsDepositos,
            '#13DEB9', '#13DEB9', '#06b6d4'
        )
    );
    chartDepositos.render();

    var chartSaques = new ApexCharts(
        document.querySelector("#chart-saques"),
        baseChart(
            'chart-saques',
            { name: 'Saques', data: saquesData },
            labelsSaques,
            '#FA896B', '#FA896B', '#ef4444'
        )
    );
    chartSaques.render();
    </script>

</body>

</html>
