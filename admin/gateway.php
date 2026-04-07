<?php include 'partials/html.php' ?>

<?php
#======================================#
ini_set('display_errors', 0);
error_reporting(E_ALL);
#======================================#
session_start();
include_once "services/database.php";
include_once 'logs/registrar_logs.php';
include_once "services/funcao.php";
include_once "services/crud.php";
include_once "services/crud-adm.php";
include_once 'services/checa_login_adm.php';
include_once "services/CSRF_Protect.php";
include_once "validar_2fa.php";
$csrf = new CSRF_Protect();
#======================================#
checa_login_adm();
#======================================#

if (false) {
    echo "<script>setTimeout(function() { window.location.href = 'bloqueado.php'; }, 0);</script>";
    exit();
}

// Função para validar 2FA do administrador logado
function validar_2fa_admin($codigo_2fa)
{
    global $mysqli;
    $admin_id = $_SESSION['data_adm']['id'];
    
    $qry = $mysqli->prepare("SELECT 2fa FROM admin_users WHERE id = ?");
    $qry->bind_param("i", $admin_id);
    $qry->execute();
    $result = $qry->get_result();
    $admin = $result->fetch_assoc();
    
    if ($admin && password_verify($codigo_2fa, $admin['2fa'])) {
        return true;
    }
    return false;
}

function get_gateways_config()
{
    global $mysqli;
    
    $NextPayQuery = "SELECT * FROM nextpay WHERE id = 1";
    $NextPayResult = mysqli_query($mysqli, $NextPayQuery);
    $NextPayConfig = mysqli_fetch_assoc($NextPayResult);
    
    $expfypayQuery = "SELECT * FROM expfypay WHERE id = 1";
    $expfypayResult = mysqli_query($mysqli, $expfypayQuery);
    $expfypayConfig = mysqli_fetch_assoc($expfypayResult);

    $bspayQuery = "SELECT * FROM bspay WHERE id = 1";
    $bspayResult = mysqli_query($mysqli, $bspayQuery);
    $bspayConfig = mysqli_fetch_assoc($bspayResult);
    
    $AurenPayQuery = "SELECT * FROM aurenpay WHERE id = 1";
    $AurenPayResult = mysqli_query($mysqli, $AurenPayQuery);
    $AurenPayConfig = mysqli_fetch_assoc($AurenPayResult);

    $VersellQuery = "SELECT * FROM versell WHERE id = 1";
    $VersellResult = mysqli_query($mysqli, $VersellQuery);
    $VersellConfig = mysqli_fetch_assoc($VersellResult);

    $InpagamentosQuery = "SELECT * FROM inpagamentos WHERE id = 1";
    $InpagamentosResult = mysqli_query($mysqli, $InpagamentosQuery);
    $InpagamentosConfig = mysqli_fetch_assoc($InpagamentosResult);

    return [
        'nextpay' => $NextPayConfig,
        'expfypay' => $expfypayConfig,
        'bspay' => $bspayConfig,
        'aurenpay' => $AurenPayConfig,
        'versell' => $VersellConfig,
        'inpagamentos' => $InpagamentosConfig
    ];
}

function update_gateway_status($selectedGateway)
{
    global $mysqli;

    // Desativar todos os gateways
    $mysqli->query("UPDATE nextpay SET ativo = 0 WHERE id = 1");
    $mysqli->query("UPDATE expfypay SET ativo = 0 WHERE id = 1");
    $mysqli->query("UPDATE bspay SET ativo = 0 WHERE id = 1");
    $mysqli->query("UPDATE aurenpay SET ativo = 0 WHERE id = 1");
    $mysqli->query("UPDATE versell SET ativo = 0 WHERE id = 1");
    $mysqli->query("UPDATE inpagamentos SET ativo = 0 WHERE id = 1");

    // Ativar o gateway selecionado
    if ($selectedGateway === 'NextPay') {
        $mysqli->query("UPDATE nextpay SET ativo = 1 WHERE id = 1");
    } elseif ($selectedGateway === 'ExpfyPay') {
        $mysqli->query("UPDATE expfypay SET ativo = 1 WHERE id = 1");
    } elseif ($selectedGateway === 'BSPay') {
        $mysqli->query("UPDATE bspay SET ativo = 1 WHERE id = 1");
    } elseif ($selectedGateway === 'AurenPay') {
        $mysqli->query("UPDATE aurenpay SET ativo = 1 WHERE id = 1");
    } elseif ($selectedGateway === 'Versell') {
        $mysqli->query("UPDATE versell SET ativo = 1 WHERE id = 1");
    } elseif ($selectedGateway === 'Inpagamentos') {
        $mysqli->query("UPDATE inpagamentos SET ativo = 1 WHERE id = 1");
    }
}

function update_config($data)
{
    global $mysqli;

    if ($data['gateway'] === 'NextPay') {
        // NextPay não permite alterar URL
        $qry = $mysqli->prepare("UPDATE nextpay SET client_id = ?, client_secret = ? WHERE id = 1");
        $qry->bind_param("ss", $data['client_id'], $data['client_secret']);
    } elseif ($data['gateway'] === 'ExpfyPay') {
        $qry = $mysqli->prepare("UPDATE expfypay SET url = ?, client_id = ?, client_secret = ? WHERE id = 1");
        $qry->bind_param("sss", $data['url'], $data['client_id'], $data['client_secret']);
    } elseif ($data['gateway'] === 'BSPay') {
        $qry = $mysqli->prepare("UPDATE bspay SET url = ?, client_id = ?, client_secret = ? WHERE id = 1");
        $qry->bind_param("sss", $data['url'], $data['client_id'], $data['client_secret']);
    } elseif ($data['gateway'] === 'AurenPay') {
        $qry = $mysqli->prepare("UPDATE aurenpay SET url = ?, client_id = ?, client_secret = ? WHERE id = 1");
        $qry->bind_param("sss", $data['url'], $data['client_id'], $data['client_secret']);
    } elseif ($data['gateway'] === 'Versell') {
        $qry = $mysqli->prepare("UPDATE versell SET url = ?, client_id = ?, client_secret = ? WHERE id = 1");
        $qry->bind_param("sss", $data['url'], $data['client_id'], $data['client_secret']);
    } elseif ($data['gateway'] === 'Inpagamentos') {
        $qry = $mysqli->prepare("UPDATE inpagamentos SET url = ?, public_key = ?, secret_key = ? WHERE id = 1");
        $qry->bind_param("sss", $data['url'], $data['client_id'], $data['client_secret']);
    }

    $success = $qry->execute();

    if ($success) {
        // update_gateway_status($data['gateway']); // Removido para controle manual
    }

    return $success;
}

function toggle_gateway_status($gateway, $status)
{
    global $mysqli;
    $status = (int)$status;
    $table = strtolower($gateway);
    
    // Mapeamento de nomes para tabelas se necessário
    if ($gateway === 'BSPay') $table = 'bspay';
    if ($gateway === 'Inpagamentos') $table = 'inpagamentos';
    // Outros já são iguais (nextpay, expfypay, aurenpay, versell)

    $stmt = $mysqli->prepare("UPDATE $table SET ativo = ? WHERE id = 1");
    if ($stmt) {
        $stmt->bind_param("i", $status);
        return $stmt->execute();
    }
    return false;
}

function get_active_gateway($mysqli)
{
    $resultNextPay = $mysqli->query("SELECT ativo FROM nextpay WHERE id = 1");
    $resultExpfyPay = $mysqli->query("SELECT ativo FROM expfypay WHERE id = 1");
    $resultBSPay = $mysqli->query("SELECT ativo FROM bspay WHERE id = 1");
    $resultAurenPay = $mysqli->query("SELECT ativo FROM aurenpay WHERE id = 1");
    $resultVersell = $mysqli->query("SELECT ativo FROM versell WHERE id = 1");
    $resultInpagamentos = $mysqli->query("SELECT ativo FROM inpagamentos WHERE id = 1");

    if ($resultNextPay && $resultExpfyPay && $resultBSPay && $resultAurenPay) {
        $nextpay = $resultNextPay->fetch_assoc();
        $expfypay = $resultExpfyPay->fetch_assoc();
        $bspay = $resultBSPay->fetch_assoc();
        $AurenPay = $resultAurenPay->fetch_assoc();
        $Versell = $resultVersell ? $resultVersell->fetch_assoc() : ['ativo' => 0];
        $Inpagamentos = $resultInpagamentos ? $resultInpagamentos->fetch_assoc() : ['ativo' => 0];

        if ($nextpay['ativo'] == 1) {
            return 'NextPay';
        } elseif ($expfypay['ativo'] == 1) {
            return 'ExpfyPay';
        } elseif ($bspay['ativo'] == 1) {
            return 'BSPay/PixUP';
        } elseif ($AurenPay['ativo'] == 1) {
            return 'AurenPay';
        } elseif ($Versell && isset($Versell['ativo']) && $Versell['ativo'] == 1) {
            return 'Versell';
        } elseif ($Inpagamentos && isset($Inpagamentos['ativo']) && $Inpagamentos['ativo'] == 1) {
            return 'Pague X';
        }
    }

    return 'Nenhum';
}

$toastType = null;
$toastMessage = '';

// Validação de 2FA para desbloquear credenciais
$credenciais_desbloqueadas = false;
if (isset($_POST['validar_2fa_visualizar'])) {
    if (validar_2fa_admin($_POST['codigo_2fa_visualizar'])) {
        $credenciais_desbloqueadas = true;
        $_SESSION['credenciais_desbloqueadas'] = true;
        $_SESSION['credenciais_timeout'] = time() + 300;
        $toastType = 'success';
        $toastMessage = admin_t('gateway_toast_unlocked');
    } else {
        $toastType = 'error';
        $toastMessage = admin_t('twofa_error');
    }
}

// Verificar se credenciais ainda estão desbloqueadas
if (isset($_SESSION['credenciais_desbloqueadas']) && isset($_SESSION['credenciais_timeout'])) {
    if (time() < $_SESSION['credenciais_timeout']) {
        $credenciais_desbloqueadas = true;
    } else {
        unset($_SESSION['credenciais_desbloqueadas']);
        unset($_SESSION['credenciais_timeout']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gateway'])) {
    // Validar 2FA antes de atualizar credenciais
    if (!isset($_POST['codigo_2fa_salvar']) || empty($_POST['codigo_2fa_salvar'])) {
        $toastType = 'error';
        $toastMessage = admin_t('gateway_toast_code_required_to_save');
    } elseif (!validar_2fa_admin($_POST['codigo_2fa_salvar'])) {
        $toastType = 'error';
        $toastMessage = admin_t('twofa_error');
    } else {
        $data = [
            'gateway' => $_POST['gateway'],
            'client_id' => $_POST['client_id'],
            'client_secret' => $_POST['client_secret'],
            'url' => isset($_POST['url']) ? $_POST['url'] : ''
        ];

        $update_success = update_config($data);

        if ($update_success) {
            $toastType = 'success';
            $toastMessage = admin_t('toast_config_updated');
        } else {
            $toastType = 'error';
            $toastMessage = admin_t('toast_config_error');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_gateway'])) {
    $gateway = $_POST['gateway_name'];
    $new_status = $_POST['new_status'];
    if (toggle_gateway_status($gateway, $new_status)) {
        $toastType = 'success';
        $toastMessage = 'Status do gateway ' . htmlspecialchars($gateway) . ' atualizado!';
    } else {
        $toastType = 'error';
        $toastMessage = admin_t('gateway_toast_status_error');
    }
}

$config = get_gateways_config();
$activeGateway = get_active_gateway($mysqli);
?>

<head>
    <?php $title = admin_t('page_gateway_title');
    include 'partials/title-meta.php' ?>
    <?php include 'partials/head-css.php' ?>
</head>

<body>

    <?php include 'partials/topbar.php' ?>
    <?php include 'partials/startbar.php' ?>

    <div class="page-wrapper">
        <div class="page-content">
            <div class="container-xxl">
                <div class="row justify-content-center">
                    <div class="col-lg-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h4 class="card-title mb-0"><?= admin_t('gateway_card_title') ?></h4>
                                <?php if (!$credenciais_desbloqueadas): ?>
                                    <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#modal2FAVisualizar">
                                        <i class="ti ti-lock me-2"></i><?= admin_t('gateway_unlock_button') ?>
                                    </button>
                                <?php else: ?>
                                    <span class="badge bg-success" style="font-size: 14px;">
                                        <i class="ti ti-lock-open me-1"></i><?= admin_t('gateway_creds_unlocked_badge') ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                                <div class="card-body payment-layout">
                                <div class="payment-header">
                                    <h4><i class="ti ti-credit-card"></i><?= admin_t('gateway_card_title') ?></h4>
                                    <p class="mb-0"><?= admin_t('gateway_card_subtitle') ?></p>
                                </div>

                                <div class="active-gateway-section">
                                    <div class="active-gateway-status"><?= admin_t('status_active') ?></div>
                                    <div>
                                        <label class="form-label mb-1"><?= admin_t('gateway_active_label') ?></label>
                                        <div class="active-gateway-value">
                                            <strong><?php echo htmlspecialchars($activeGateway); ?></strong>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!$credenciais_desbloqueadas): ?>
                                    <div class="alert alert-warning" role="alert">
                                        <i class="ti ti-lock me-2"></i>
                                        <strong><?= admin_t('gateway_creds_locked_title') ?></strong> <?= admin_t('gateway_creds_locked_text') ?>
                                    </div>
                                <?php endif; ?>

                                <div class="gateways-grid-top">
                                    <div class="gateway-card">
                                        <div class="gateway-header">
                                            <div class="gateway-title">
                                                <i class="ti ti-bolt text-primary"></i>
                                                <div>
                                                    <h5 class="gateway-name">VersellPay</h5>
                                                    <p class="gateway-description">Gateway de pagamento Versell</p>
                                                </div>
                                            </div>
                                            <div class="gateway-status <?= ($activeGateway === 'Versell') ? 'active' : 'inactive' ?>"><?= ($activeGateway === 'Versell') ? admin_t('status_active') : admin_t('status_inactive') ?></div>
                                        </div>
                                        
                                        <!-- Controle de Ativação -->
                                        <div class="px-3 pt-3">
                                            <form method="POST" class="d-flex align-items-center">
                                                <input type="hidden" name="toggle_gateway" value="1">
                                                <input type="hidden" name="gateway_name" value="Versell">
                                                <input type="hidden" name="new_status" value="<?= ($config['versell']['ativo'] == 1) ? '0' : '1' ?>">
                                                
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" role="switch" id="switchVersell" 
                                                        <?= ($config['versell']['ativo'] == 1) ? 'checked' : '' ?> 
                                                        onchange="this.form.submit()">
                                                    <label class="form-check-label" for="switchVersell">
                                                        <?= ($config['versell']['ativo'] == 1) ? admin_t('status_active') : admin_t('status_inactive') ?>
                                                    </label>
                                                </div>
                                            </form>
                                        </div>

                                        <div class="gateway-form">
                                            <form method="POST" action="" id="formVersell">
                                                <input type="hidden" name="gateway" value="Versell">
                                                <div class="mb-3">
                                                    <label class="form-label"><i class="ti ti-key"></i>Client ID</label>
                                                    <div class="input-group">
                                                        <input type="password" id="versell_client_id" name="client_id" class="form-control <?= !$credenciais_desbloqueadas ? 'credencial-bloqueada' : '' ?>" value="<?= $config['versell']['client_id'] ?? '' ?>" required <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>>
                                                        <?php if ($credenciais_desbloqueadas): ?>
                                                            <span class="input-group-text" style="cursor: pointer;" onclick="togglePassword('versell_client_id', this)"><i class="ti ti-eye"></i></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label"><i class="ti ti-shield-lock"></i>Client Secret</label>
                                                    <div class="input-group">
                                                        <input type="password" id="versell_client_secret" name="client_secret" class="form-control <?= !$credenciais_desbloqueadas ? 'credencial-bloqueada' : '' ?>" value="<?= $config['versell']['client_secret'] ?? '' ?>" required <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>>
                                                        <?php if ($credenciais_desbloqueadas): ?>
                                                            <span class="input-group-text" style="cursor: pointer;" onclick="togglePassword('versell_client_secret', this)"><i class="ti ti-eye"></i></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label"><i class="ti ti-link"></i>Endpoint</label>
                                                    <input type="text" name="url" class="form-control" value="<?= $config['versell']['url'] ?? '' ?>" required <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>>
                                                </div>
                                                <button type="button" class="save-btn" onclick="abrirModal2FASalvar('Versell')" <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>><i class="ti ti-device-floppy me-1"></i><?= admin_t('gateway_save_button_prefix') ?> Versell</button>
                                            </form>
                                        </div>
                                    </div>

                                    <div class="gateway-card">
                                        <div class="gateway-header">
                                            <div class="gateway-title">
                                                <i class="ti ti-bolt text-warning"></i>
                                                <div>
                                                    <h5 class="gateway-name">NextPay</h5>
                                                    <p class="gateway-description">Gateway de pagamento NextPay</p>
                                                </div>
                                            </div>
                                            <div class="gateway-status <?= ($activeGateway === 'NextPay') ? 'active' : 'inactive' ?>"><?= ($activeGateway === 'NextPay') ? admin_t('status_active') : admin_t('status_inactive') ?></div>
                                        </div>

                                        <!-- Controle de Ativação -->
                                        <div class="px-3 pt-3">
                                            <form method="POST" class="d-flex align-items-center">
                                                <input type="hidden" name="toggle_gateway" value="1">
                                                <input type="hidden" name="gateway_name" value="NextPay">
                                                <input type="hidden" name="new_status" value="<?= ($config['nextpay']['ativo'] == 1) ? '0' : '1' ?>">
                                                
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" role="switch" id="switchNextPay" 
                                                        <?= ($config['nextpay']['ativo'] == 1) ? 'checked' : '' ?> 
                                                        onchange="this.form.submit()">
                                                    <label class="form-check-label" for="switchNextPay">
                                                        <?= ($config['nextpay']['ativo'] == 1) ? admin_t('status_active') : admin_t('status_inactive') ?>
                                                    </label>
                                                </div>
                                            </form>
                                        </div>

                                        <div class="gateway-form">
                                            <form method="POST" action="" id="formNextPay">
                                                <input type="hidden" name="gateway" value="NextPay">
                                                <div class="mb-3">
                                                    <label class="form-label"><i class="ti ti-key"></i>Client ID</label>
                                                    <div class="input-group">
                                                        <input type="password" id="nextpay_client_id" name="client_id" class="form-control <?= !$credenciais_desbloqueadas ? 'credencial-bloqueada' : '' ?>" value="<?= $config['nextpay']['client_id'] ?>" required <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>>
                                                        <?php if ($credenciais_desbloqueadas): ?>
                                                            <span class="input-group-text" style="cursor: pointer;" onclick="togglePassword('nextpay_client_id', this)"><i class="ti ti-eye"></i></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label"><i class="ti ti-shield-lock"></i>Client Secret</label>
                                                    <div class="input-group">
                                                        <input type="password" id="nextpay_client_secret" name="client_secret" class="form-control <?= !$credenciais_desbloqueadas ? 'credencial-bloqueada' : '' ?>" value="<?= $config['nextpay']['client_secret'] ?>" required <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>>
                                                        <?php if ($credenciais_desbloqueadas): ?>
                                                            <span class="input-group-text" style="cursor: pointer;" onclick="togglePassword('nextpay_client_secret', this)"><i class="ti ti-eye"></i></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label"><i class="ti ti-link"></i>Endpoint</label>
                                                    <input type="text" name="url" class="form-control" value="<?= $config['nextpay']['url'] ?>" readonly disabled>
                                                    <small class="text-muted"><?= admin_t('gateway_nextpay_fixed_url') ?></small>
                                                </div>
                                                <button type="button" class="save-btn" onclick="abrirModal2FASalvar('NextPay')" <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>><i class="ti ti-device-floppy me-1"></i><?= admin_t('gateway_save_button_prefix') ?> NextPay</button>
                                            </form>
                                        </div>
                                    </div>

                                    <div class="gateway-card">
                                        <div class="gateway-header">
                                            <div class="gateway-title">
                                                <i class="ti ti-qrcode text-info"></i>
                                                <div>
                                                    <h5 class="gateway-name">BSPay / PixUP</h5>
                                                    <p class="gateway-description">Gateway de pagamento BSPay/PixUP</p>
                                                </div>
                                            </div>
                                            <div class="gateway-status <?= ($activeGateway === 'BSPay/PixUP' || $activeGateway === 'BSPay') ? 'active' : 'inactive' ?>"><?= ($activeGateway === 'BSPay/PixUP' || $activeGateway === 'BSPay') ? admin_t('status_active') : admin_t('status_inactive') ?></div>
                                        </div>

                                        <!-- Controle de Ativação -->
                                        <div class="px-3 pt-3">
                                            <form method="POST" class="d-flex align-items-center">
                                                <input type="hidden" name="toggle_gateway" value="1">
                                                <input type="hidden" name="gateway_name" value="BSPay">
                                                <input type="hidden" name="new_status" value="<?= ($config['bspay']['ativo'] == 1) ? '0' : '1' ?>">
                                                
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" role="switch" id="switchBSPay" 
                                                        <?= ($config['bspay']['ativo'] == 1) ? 'checked' : '' ?> 
                                                        onchange="this.form.submit()">
                                                    <label class="form-check-label" for="switchBSPay">
                                                        <?= ($config['bspay']['ativo'] == 1) ? admin_t('status_active') : admin_t('status_inactive') ?>
                                                    </label>
                                                </div>
                                            </form>
                                        </div>

                                        <div class="gateway-form">
                                            <form method="POST" action="" id="formBSPay">
                                                <input type="hidden" name="gateway" value="BSPay">
                                                <div class="mb-3">
                                                    <label class="form-label"><i class="ti ti-key"></i>Client ID</label>
                                                    <div class="input-group">
                                                        <input type="password" id="bspay_client_id" name="client_id" class="form-control <?= !$credenciais_desbloqueadas ? 'credencial-bloqueada' : '' ?>" value="<?= $config['bspay']['client_id'] ?>" required <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>>
                                                        <?php if ($credenciais_desbloqueadas): ?>
                                                            <span class="input-group-text" style="cursor: pointer;" onclick="togglePassword('bspay_client_id', this)"><i class="ti ti-eye"></i></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label"><i class="ti ti-shield-lock"></i>Client Secret</label>
                                                    <div class="input-group">
                                                        <input type="password" id="bspay_client_secret" name="client_secret" class="form-control <?= !$credenciais_desbloqueadas ? 'credencial-bloqueada' : '' ?>" value="<?= $config['bspay']['client_secret'] ?>" required <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>>
                                                        <?php if ($credenciais_desbloqueadas): ?>
                                                            <span class="input-group-text" style="cursor: pointer;" onclick="togglePassword('bspay_client_secret', this)"><i class="ti ti-eye"></i></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label"><i class="ti ti-link"></i>Endpoint</label>
                                                    <select name="url" class="form-select" required <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>>
                                                        <option value="https://api.bspay.co" <?= $config['bspay']['url'] === 'https://api.bspay.co' ? 'selected' : '' ?>>BSPay (api.bspay.co)</option>
                                                        <option value="https://api.pixupbr.com" <?= $config['bspay']['url'] === 'https://api.pixupbr.com' ? 'selected' : '' ?>>PixUP (api.pixupbr.com)</option>
                                                    </select>
                                                </div>
                                                <button type="button" class="save-btn" onclick="abrirModal2FASalvar('BSPay')" <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>><i class="ti ti-device-floppy me-1"></i><?= admin_t('gateway_save_button_prefix') ?> BSPay/PixUP</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <div class="gateways-grid-bottom mt-3">
                                    <div class="gateway-card">
                                        <div class="gateway-header">
                                            <div class="gateway-title">
                                                <i class="ti ti-credit-card text-danger"></i>
                                                <div>
                                                    <h5 class="gateway-name">ExpfyPay</h5>
                                                    <p class="gateway-description">Gateway de pagamento ExpfyPay</p>
                                                </div>
                                            </div>
                                            <div class="gateway-status <?= ($activeGateway === 'ExpfyPay') ? 'active' : 'inactive' ?>"><?= ($activeGateway === 'ExpfyPay') ? admin_t('status_active') : admin_t('status_inactive') ?></div>
                                        </div>

                                        <!-- Controle de Ativação -->
                                        <div class="px-3 pt-3">
                                            <form method="POST" class="d-flex align-items-center">
                                                <input type="hidden" name="toggle_gateway" value="1">
                                                <input type="hidden" name="gateway_name" value="ExpfyPay">
                                                <input type="hidden" name="new_status" value="<?= ($config['expfypay']['ativo'] == 1) ? '0' : '1' ?>">
                                                
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" role="switch" id="switchExpfyPay" 
                                                        <?= ($config['expfypay']['ativo'] == 1) ? 'checked' : '' ?> 
                                                        onchange="this.form.submit()">
                                                    <label class="form-check-label" for="switchExpfyPay">
                                                        <?= ($config['expfypay']['ativo'] == 1) ? admin_t('status_active') : admin_t('status_inactive') ?>
                                                    </label>
                                                </div>
                                            </form>
                                        </div>

                                        <div class="gateway-form">
                                            <form method="POST" action="" id="formExpfyPay">
                                                <input type="hidden" name="gateway" value="ExpfyPay">
                                                <div class="mb-3">
                                                    <label class="form-label"><i class="ti ti-key"></i>Public Key (Client ID)</label>
                                                    <div class="input-group">
                                                        <input type="password" id="expfypay_client_id" name="client_id" class="form-control <?= !$credenciais_desbloqueadas ? 'credencial-bloqueada' : '' ?>" value="<?= $config['expfypay']['client_id'] ?>" required <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>>
                                                        <?php if ($credenciais_desbloqueadas): ?>
                                                            <span class="input-group-text" style="cursor: pointer;" onclick="togglePassword('expfypay_client_id', this)"><i class="ti ti-eye"></i></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label"><i class="ti ti-shield-lock"></i>Secret Key (Client Secret)</label>
                                                    <div class="input-group">
                                                        <input type="password" id="expfypay_client_secret" name="client_secret" class="form-control <?= !$credenciais_desbloqueadas ? 'credencial-bloqueada' : '' ?>" value="<?= $config['expfypay']['client_secret'] ?>" required <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>>
                                                        <?php if ($credenciais_desbloqueadas): ?>
                                                            <span class="input-group-text" style="cursor: pointer;" onclick="togglePassword('expfypay_client_secret', this)"><i class="ti ti-eye"></i></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label"><i class="ti ti-link"></i>Endpoint</label>
                                                    <select name="url" class="form-select" required <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>>
                                                        <option value="https://expfypay.com" <?= $config['expfypay']['url'] === 'https://expfypay.com' ? 'selected' : '' ?>>Normal (expfypay.com)</option>
                                                        <option value="https://pro.expfypay.com" <?= $config['expfypay']['url'] === 'https://pro.expfypay.com' ? 'selected' : '' ?>>Pro (pro.expfypay.com)</option>
                                                    </select>
                                                </div>
                                                <button type="button" class="save-btn" onclick="abrirModal2FASalvar('ExpfyPay')" <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>><i class="ti ti-device-floppy me-1"></i><?= admin_t('gateway_save_button_prefix') ?> ExpfyPay</button>
                                            </form>
                                        </div>
                                    </div>

                                    <div class="gateway-card">
                                        <div class="gateway-header">
                                            <div class="gateway-title">
                                                <i class="ti ti-cash text-primary"></i>
                                                <div>
                                                    <h5 class="gateway-name">Pague X</h5>
                                                    <p class="gateway-description">Gateway de pagamento Pague X</p>
                                                </div>
                                            </div>
                                            <div class="gateway-status <?= ($activeGateway === 'Pague X') ? 'active' : 'inactive' ?>"><?= ($activeGateway === 'Pague X') ? admin_t('status_active') : admin_t('status_inactive') ?></div>
                                        </div>

                                        <!-- Controle de Ativação -->
                                        <div class="px-3 pt-3">
                                            <form method="POST" class="d-flex align-items-center">
                                                <input type="hidden" name="toggle_gateway" value="1">
                                                <input type="hidden" name="gateway_name" value="Inpagamentos">
                                                <input type="hidden" name="new_status" value="<?= (isset($config['inpagamentos']['ativo']) && $config['inpagamentos']['ativo'] == 1) ? '0' : '1' ?>">
                                                
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" role="switch" id="switchInpagamentos" 
                                                        <?= (isset($config['inpagamentos']['ativo']) && $config['inpagamentos']['ativo'] == 1) ? 'checked' : '' ?> 
                                                        onchange="this.form.submit()">
                                                    <label class="form-check-label" for="switchInpagamentos">
                                                        <?= (isset($config['inpagamentos']['ativo']) && $config['inpagamentos']['ativo'] == 1) ? admin_t('status_active') : admin_t('status_inactive') ?>
                                                    </label>
                                                </div>
                                            </form>
                                        </div>

                                        <div class="gateway-form">
                                            <form method="POST" action="" id="formInpagamentos">
                                                <input type="hidden" name="gateway" value="Inpagamentos">
                                                <div class="mb-3">
                                                    <label class="form-label"><i class="ti ti-key"></i>Public Key</label>
                                                    <div class="input-group">
                                                        <input type="password" id="inpagamentos_client_id" name="client_id" class="form-control <?= !$credenciais_desbloqueadas ? 'credencial-bloqueada' : '' ?>" value="<?= $config['inpagamentos']['public_key'] ?? '' ?>" required <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>>
                                                        <?php if ($credenciais_desbloqueadas): ?>
                                                            <span class="input-group-text" style="cursor: pointer;" onclick="togglePassword('inpagamentos_client_id', this)"><i class="ti ti-eye"></i></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label"><i class="ti ti-shield-lock"></i>Secret Key</label>
                                                    <div class="input-group">
                                                        <input type="password" id="inpagamentos_client_secret" name="client_secret" class="form-control <?= !$credenciais_desbloqueadas ? 'credencial-bloqueada' : '' ?>" value="<?= $config['inpagamentos']['secret_key'] ?? '' ?>" required <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>>
                                                        <?php if ($credenciais_desbloqueadas): ?>
                                                            <span class="input-group-text" style="cursor: pointer;" onclick="togglePassword('inpagamentos_client_secret', this)"><i class="ti ti-eye"></i></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label"><i class="ti ti-link"></i>Endpoint</label>
                                                    <input type="text" name="url" class="form-control" value="<?= $config['inpagamentos']['url'] ?? '' ?>" required <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>>
                                                </div>
                                                <button type="button" class="save-btn" onclick="abrirModal2FASalvar('Inpagamentos')" <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>><i class="ti ti-device-floppy me-1"></i><?= admin_t('gateway_save_button_prefix') ?> Pague X</button>
                                            </form>
                                        </div>
                                    </div>

                                    <div class="gateway-card">
                                        <div class="gateway-header">
                                            <div class="gateway-title">
                                                <i class="ti ti-wallet text-success"></i>
                                                <div>
                                                    <h5 class="gateway-name">AurenPay</h5>
                                                    <p class="gateway-description">Gateway de pagamento AurenPay</p>
                                                </div>
                                            </div>
                                            <div class="gateway-status <?= ($activeGateway === 'AurenPay') ? 'active' : 'inactive' ?>"><?= ($activeGateway === 'AurenPay') ? admin_t('status_active') : admin_t('status_inactive') ?></div>
                                        </div>

                                        <!-- Controle de Ativação -->
                                        <div class="px-3 pt-3">
                                            <form method="POST" class="d-flex align-items-center">
                                                <input type="hidden" name="toggle_gateway" value="1">
                                                <input type="hidden" name="gateway_name" value="AurenPay">
                                                <input type="hidden" name="new_status" value="<?= ($config['aurenpay']['ativo'] == 1) ? '0' : '1' ?>">
                                                
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" role="switch" id="switchAurenPay" 
                                                        <?= ($config['aurenpay']['ativo'] == 1) ? 'checked' : '' ?> 
                                                        onchange="this.form.submit()">
                                                    <label class="form-check-label" for="switchAurenPay">
                                                        <?= ($config['aurenpay']['ativo'] == 1) ? admin_t('status_active') : admin_t('status_inactive') ?>
                                                    </label>
                                                </div>
                                            </form>
                                        </div>

                                        <div class="gateway-form">
                                            <form method="POST" action="" id="formAurenPay">
                                                <input type="hidden" name="gateway" value="AurenPay">
                                                <div class="mb-3">
                                                    <label class="form-label"><i class="ti ti-key"></i>Client ID</label>
                                                    <div class="input-group">
                                                        <input type="password" id="aurenpay_client_id" name="client_id" class="form-control <?= !$credenciais_desbloqueadas ? 'credencial-bloqueada' : '' ?>" value="<?= $config['aurenpay']['client_id'] ?>" required <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>>
                                                        <?php if ($credenciais_desbloqueadas): ?>
                                                            <span class="input-group-text" style="cursor: pointer;" onclick="togglePassword('aurenpay_client_id', this)"><i class="ti ti-eye"></i></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label"><i class="ti ti-shield-lock"></i>Client Secret</label>
                                                    <div class="input-group">
                                                        <input type="password" id="aurenpay_client_secret" name="client_secret" class="form-control <?= !$credenciais_desbloqueadas ? 'credencial-bloqueada' : '' ?>" value="<?= $config['aurenpay']['client_secret'] ?>" required <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>>
                                                        <?php if ($credenciais_desbloqueadas): ?>
                                                            <span class="input-group-text" style="cursor: pointer;" onclick="togglePassword('aurenpay_client_secret', this)"><i class="ti ti-eye"></i></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label"><i class="ti ti-link"></i>Endpoint</label>
                                                    <input type="text" name="url" class="form-control" value="<?= $config['aurenpay']['url'] ?>" required <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>>
                                                </div>
                                                <button type="button" class="save-btn" onclick="abrirModal2FASalvar('AurenPay')" <?= !$credenciais_desbloqueadas ? 'disabled' : '' ?>><i class="ti ti-device-floppy me-1"></i><?= admin_t('gateway_save_button_prefix') ?> AurenPay</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php include 'partials/endbar.php' ?>
            <?php include 'partials/footer.php' ?>
            
        </div>
    </div>

    <!-- Modal 2FA para Visualizar Credenciais -->
    <div class="modal fade" id="modal2FAVisualizar" tabindex="-1" aria-labelledby="modal2FAVisualizarLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title" id="modal2FAVisualizarLabel">
                        <i class="ti ti-lock-open me-2"></i><?= admin_t('gateway_modal_unlock_title') ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="alert alert-info" role="alert">
                            <i class="ti ti-info-circle me-2"></i>
                            <?= admin_t('gateway_modal_unlock_info') ?>
                        </div>
                        <div class="mb-3">
                            <label for="codigo_2fa_visualizar" class="form-label"><?= admin_t('twofa_code_label') ?></label>
                            <input type="text" name="codigo_2fa_visualizar" class="form-control" placeholder="<?= admin_t('twofa_placeholder') ?>" required autofocus>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= admin_t('button_cancel') ?></button>
                        <button type="submit" name="validar_2fa_visualizar" class="btn btn-warning">
                            <i class="ti ti-lock-open me-1"></i><?= admin_t('gateway_modal_unlock_button') ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal 2FA para Salvar Alterações -->
    <div class="modal fade" id="modal2FASalvar" tabindex="-1" aria-labelledby="modal2FASalvarLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modal2FASalvarLabel">
                        <i class="ti ti-shield-lock me-2"></i><?= admin_t('gateway_modal_confirm_title') ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning" role="alert">
                        <i class="ti ti-alert-triangle me-2"></i>
                        <strong><?= admin_t('alert_attention') ?></strong> <?= admin_t('gateway_modal_confirm_warning') ?> <strong id="gatewayNome"></strong>.
                    </div>
                    <div class="mb-3">
                        <label for="codigo_2fa_salvar" class="form-label"><?= admin_t('twofa_code_label') ?></label>
                        <input type="text" id="codigo_2fa_salvar" class="form-control" placeholder="<?= admin_t('twofa_placeholder') ?>" required autofocus>
                        <small class="text-muted"><?= admin_t('gateway_modal_confirm_helper') ?></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= admin_t('button_cancel') ?></button>
                    <button type="button" class="btn btn-primary" onclick="confirmarSalvar()">
                        <i class="ti ti-check me-1"></i><?= admin_t('gateway_modal_confirm_button') ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="toastPlacement" class="toast-container position-fixed bottom-0 end-0 p-3"></div>

    <?php include 'partials/vendorjs.php' ?>
    <script src="assets/js/app.js"></script>

    <script>
        let gatewayAtual = '';

        function abrirModal2FASalvar(gateway) {
            gatewayAtual = gateway;
            let displayName = gateway;
            if (gateway === 'Inpagamentos') { displayName = 'Pague X'; }
            document.getElementById('gatewayNome').textContent = displayName;
            const modal = new bootstrap.Modal(document.getElementById('modal2FASalvar'));
            modal.show();
        }

        function confirmarSalvar() {
            const codigo2fa = document.getElementById('codigo_2fa_salvar').value;
            
            if (!codigo2fa) {
                showToast('error', '<?= admin_t('gateway_toast_code_required') ?>');
                return;
            }

            let form;
            if (gatewayAtual === 'Versell') {
                form = document.getElementById('formVersell');
            } else if (gatewayAtual === 'NextPay') {
                form = document.getElementById('formNextPay');
            } else if (gatewayAtual === 'ExpfyPay') {
                form = document.getElementById('formExpfyPay');
            } else if (gatewayAtual === 'BSPay') {
                form = document.getElementById('formBSPay');
            } else if (gatewayAtual === 'AurenPay') {
                form = document.getElementById('formAurenPay');
            } else if (gatewayAtual === 'Inpagamentos') {
                form = document.getElementById('formInpagamentos');
            }

            if (!form) {
                showToast('error', '<?= admin_t('gateway_toast_form_not_found') ?>');
                return;
            }
            
            const input2fa = document.createElement('input');
            input2fa.type = 'hidden';
            input2fa.name = 'codigo_2fa_salvar';
            input2fa.value = codigo2fa;
            form.appendChild(input2fa);
            
            form.submit();
        }

        function showToast(type, message) {
            var toastPlacement = document.getElementById('toastPlacement');
            var toast = document.createElement('div');
            toast.className = `toast align-items-center bg-light border-0 fade show`;
            toast.setAttribute('role', 'alert');
            toast.setAttribute('aria-live', 'assertive');
            toast.setAttribute('aria-atomic', 'true');
            toast.innerHTML = `
                <div class="toast-header">
                    <h5 class="me-auto my-0"><?= admin_t('gateway_toast_title') ?></h5>
                    <small><?= admin_t('gateway_toast_now') ?></small>
                    <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body">${message}</div>
            `;
            toastPlacement.appendChild(toast);

            var bootstrapToast = new bootstrap.Toast(toast);
            bootstrapToast.show();

            setTimeout(function () {
                bootstrapToast.hide();
                setTimeout(() => toast.remove(), 500);
            }, 3000);
        }

        function togglePassword(inputId, icon) {
            const input = document.getElementById(inputId);
            const iconElement = icon.querySelector('i');

            if (input.type === "password") {
                input.type = "text";
                iconElement.classList.remove('fa-eye','ti-eye');
                iconElement.classList.add('ti-eye-off');
            } else {
                input.type = "password";
                iconElement.classList.remove('fa-eye-slash','ti-eye-off');
                iconElement.classList.add('ti-eye');
            }
        }
    </script>

    <?php if ($toastType && $toastMessage): ?>
        <script>
            showToast('<?= $toastType ?>', '<?= $toastMessage ?>');
        </script>
    <?php endif; ?>

</body>

</html>
