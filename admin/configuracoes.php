<?php include 'partials/html.php' ?>
<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
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

checa_login_adm();

if (false) {
    echo "<script>setTimeout(function() { window.location.href = 'bloqueado.php'; }, 0);</script>";
    exit();
}

function get_afiliados_config()
{
    global $mysqli;
    $qry = "SELECT * FROM config WHERE id=1";
    $result = mysqli_query($mysqli, $qry);
    return mysqli_fetch_assoc($result);
}

function get_payment_methods()
{
    global $mysqli;
    $qry = "SELECT * FROM pay_type_sub_list ORDER BY sort_order ASC";
    $result = mysqli_query($mysqli, $qry);
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    return $data;
}

function update_config($data)
{
    global $mysqli;
    $qry = $mysqli->prepare("UPDATE config SET 
        minsaque = ?, 
        maxsaque = ?, 
        saque_automatico = ?, 
        rollover = ?, 
        mindep = ?, 
        jackpot = ?,
        limite_saque = ?
        WHERE id = 1");

    $qry->bind_param(
        "ddddsdd",
        $data['minsaque'],
        $data['maxsaque'],
        $data['saque_automatico'],
        $data['rollover'],
        $data['mindep'],
        $data['jackpot'],
        $data['limite_saque']
    );
    return $qry->execute();
}

function update_payment_method($id, $data)
{
    global $mysqli;
    
    // Calculate min/max from fixed_amount
    $amounts = explode(',', $data['fixed_amount']);
    $min = floatval($amounts[0]);
    $max = floatval(end($amounts));
    
    $qry = $mysqli->prepare("UPDATE pay_type_sub_list SET 
        name = ?, 
        tags = ?, 
        status = ?, 
        description = ?, 
        fixed_amount = ?, 
        min_amount = ?, 
        max_amount = ?, 
        bonus_active = ?
        WHERE id = ?");
        
    $qry->bind_param(
        "ssissddii",
        $data['name'],
        $data['tags'],
        $data['status'],
        $data['description'],
        $data['fixed_amount'],
        $min,
        $max,
        $data['bonus_active'],
        $id
    );
    return $qry->execute();
}

$toastType = null;
$toastMessage = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action']) && $_POST['action'] == 'update_payment_methods') {
        $success = true;
        foreach ($_POST['payment'] as $id => $p) {
            $p['status'] = isset($p['status']) ? 1 : 0;
            $p['bonus_active'] = isset($p['bonus_active']) ? 1 : 0;
            if (!update_payment_method($id, $p)) {
                $success = false;
            }
        }
        
        if ($success) {
            $toastType = 'success';
            $toastMessage = admin_t('toast_payment_methods_updated');
        } else {
            $toastType = 'error';
            $toastMessage = admin_t('toast_payment_methods_error');
        }
    } else {
        if (isset($_POST['global_bonus'])) {
            $bonus = floatval($_POST['global_bonus']);
            $mysqli->query("UPDATE pay_type_sub_list SET tag_value = '$bonus'");
        }
        $data = [
            'minsaque' => floatval($_POST['minsaque']),
            'maxsaque' => floatval($_POST['maxsaque']),
            'saque_automatico' => floatval($_POST['saque_automatico']),
            'mindep' => $_POST['mindep'],
            'jackpot' => floatval($_POST['jackpot'] ?? 0),
            'rollover' => floatval($_POST['rollover']),
            'limite_saque' => floatval($_POST['limite_saque']),
            'comissao' => floatval($_POST['comissao'] ?? 0)
        ];

        if (update_config($data)) {
            $toastType = 'success';
            $toastMessage = admin_t('toast_config_updated');
        } else {
            $toastType = 'error';
            $toastMessage = admin_t('toast_config_error');
        }
    }
}

$config = get_afiliados_config();
$paymentMethods = get_payment_methods();
?>

<head>
    <?php $title = admin_t('page_config_affiliates_title');
    include 'partials/title-meta.php' ?>

    <link rel="stylesheet" href="assets/libs/jsvectormap/jsvectormap.min.css">
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
                        <div class="card rounded-4 shadow-sm">
                            <div class="card-header bg-transparent border-bottom-0 pt-4 px-4">
                                <h4 class="card-title fw-bold mb-0"><?= admin_t('page_config_title') ?></h4>
                                <p class="text-muted fs-13 mb-0"><?= admin_t('page_config_subtitle') ?></p>
                            </div>

                            <div class="card-body p-4">
                                <form method="POST" action="">
                                    <div class="row g-4">
                                        
                                        <!-- Saque Mínimo -->
                                        <div class="col-md-6 col-lg-4">
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">
                                                    <i class="iconoir-wallet me-1 text-primary"></i> <?= admin_t('label_min_withdraw') ?>
                                                </label>
                                                <input type="text" name="minsaque" class="form-control"
                                                    value="<?= $config['minsaque'] ?>" required>
                                                <div class="form-text text-muted fs-12"><?= admin_t('help_min_withdraw') ?></div>
                                            </div>
                                        </div>
                                        
                                        <!-- Saque Máximo -->
                                        <div class="col-md-6 col-lg-4">
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">
                                                    <i class="iconoir-wallet me-1 text-primary"></i> <?= admin_t('label_max_withdraw') ?>
                                                </label>
                                                <input type="text" name="maxsaque" class="form-control"
                                                    value="<?= $config['maxsaque'] ?>" required>
                                                <div class="form-text text-muted fs-12"><?= admin_t('help_max_withdraw') ?></div>
                                            </div>
                                        </div>

                                        <!-- Saque Automático -->
                                        <div class="col-md-6 col-lg-4">
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">
                                                    <i class="iconoir-flash me-1 text-warning"></i> <?= admin_t('label_auto_withdraw') ?>
                                                </label>
                                                <input type="text" name="saque_automatico" class="form-control"
                                                    value="<?= $config['saque_automatico'] ?>">
                                                <div class="form-text text-muted fs-12"><?= admin_t('help_auto_withdraw') ?></div>
                                            </div>
                                        </div>

                                         <!-- Limite Diário de Saques -->
                                         <div class="col-md-6 col-lg-4">
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">
                                                    <i class="iconoir-timer me-1 text-danger"></i> <?= admin_t('label_daily_limit') ?>
                                                </label>
                                                <input type="text" name="limite_saque" class="form-control"
                                                    value="<?= $config['limite_saque'] ?>" required>
                                                <div class="form-text text-muted fs-12"><?= admin_t('help_daily_limit') ?></div>
                                            </div>
                                        </div>

                                        <!-- Bônus Global -->
                                        <div class="col-md-6 col-lg-4">
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">
                                                    <i class="iconoir-piggy-bank me-1 text-success"></i> <?= admin_t('label_deposit_bonus') ?>
                                                </label>
                                                <?php 
                                                $currentBonus = 0;
                                                if (!empty($paymentMethods)) {
                                                    $currentBonus = $paymentMethods[0]['tag_value'];
                                                }
                                                ?>
                                                <input type="text" name="global_bonus" class="form-control"
                                                    value="<?= $currentBonus ?>" required>
                                                <input type="hidden" name="mindep" value="<?= $config['mindep'] ?>">
                                                <div class="form-text text-muted fs-12"><?= admin_t('help_deposit_bonus') ?></div>
                                            </div>
                                        </div>

                                        <!-- Rollover -->
                                        <div class="col-md-6 col-lg-4">
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">
                                                    <i class="iconoir-percentage-circle me-1 text-info"></i> <?= admin_t('label_rollover') ?>
                                                </label>
                                                <input type="text" name="rollover" class="form-control"
                                                    value="<?= $config['rollover'] ?>" required>
                                                <div class="form-text text-muted fs-12"><?= admin_t('help_rollover') ?></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mt-5">
                                        <div class="col-12 text-center">
                                            <button type="submit" class="btn btn-primary rounded-3 px-5 py-2 fw-semibold">
                                                <i class="iconoir-check me-2"></i> <?= admin_t('button_save_settings') ?>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card rounded-4 shadow-sm mt-4">
                            <div class="card-header bg-transparent border-bottom-0 pt-4 px-4">
                                <h4 class="card-title fw-bold mb-0"><?= admin_t('card_payment_methods_title') ?></h4>
                                <p class="text-muted fs-13 mb-0"><?= admin_t('card_payment_methods_subtitle') ?></p>
                            </div>
                            <div class="card-body p-4">
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="update_payment_methods">
                                    
                                    <?php foreach($paymentMethods as $index => $pay): ?>
                                    <div class="border rounded-3 p-3 mb-4 bg-light-subtle">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h5 class="mb-0 fw-bold text-primary">#<?= $pay['sort_order'] ?> - <?= $pay['name'] ?></h5>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="payment[<?= $pay['id'] ?>][status]" 
                                                    <?= $pay['status'] ? 'checked' : '' ?> role="switch">
                                                <label class="form-check-label"><?= admin_t('label_active') ?></label>
                                            </div>
                                        </div>
                                        
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label"><?= admin_t('label_display_name') ?></label>
                                                <input type="text" class="form-control" name="payment[<?= $pay['id'] ?>][name]" value="<?= $pay['name'] ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label"><?= admin_t('label_tag') ?></label>
                                                <select class="form-select" name="payment[<?= $pay['id'] ?>][tags]">
                                                    <option value="RECOMMEND" <?= $pay['tags'] == 'RECOMMEND' ? 'selected' : '' ?>>RECOMMEND</option>
                                                    <option value="GIVE_AWAY" <?= $pay['tags'] == 'GIVE_AWAY' ? 'selected' : '' ?>>GIVE_AWAY</option>
                                                </select>
                                            </div>
                                            
                                            <div class="col-12">
                                                <label class="form-label"><?= admin_t('label_fixed_values') ?></label>
                                                <textarea class="form-control" name="payment[<?= $pay['id'] ?>][fixed_amount]" rows="2"><?= $pay['fixed_amount'] ?></textarea>
                                                <div class="form-text"><?= admin_t('help_fixed_values') ?></div>
                                            </div>
                                            
                                            <div class="col-12">
                                                <label class="form-label"><?= admin_t('label_description') ?></label>
                                                <textarea class="form-control" name="payment[<?= $pay['id'] ?>][description]" rows="2"><?= $pay['description'] ?></textarea>
                                            </div>
                                            
                                            <div class="col-12">
                                                 <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" name="payment[<?= $pay['id'] ?>][bonus_active]" 
                                                        <?= $pay['bonus_active'] ? 'checked' : '' ?> role="switch">
                                                    <label class="form-check-label"><?= admin_t('label_bonus_active') ?></label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>

                                    <div class="row mt-3">
                                        <div class="col-12 text-center">
                                            <button type="submit" class="btn btn-primary rounded-3 px-5 py-2 fw-semibold">
                                                <i class="iconoir-check me-2"></i> <?= admin_t('button_update_payment_methods') ?>
                                            </button>
                                        </div>
                                    </div>
                                </form>
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
    <script src="assets/js/app.js"></script>

    <script>
        function showToast(type, message){window.showToast(type,message);}
    </script>

    <?php if ($toastType && $toastMessage): ?>
        <script>
            showToast('<?= $toastType ?>', '<?= $toastMessage ?>');
        </script>
    <?php endif; ?>

</body>
</html>
