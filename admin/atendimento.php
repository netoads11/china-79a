<?php include 'partials/html.php' ?>

<?php
ini_set('display_errors', 0);
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

if ($_SESSION['data_adm']['status'] != '1') {
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

function update_config($data)
{
    global $mysqli;
    $qry = $mysqli->prepare("UPDATE config SET 
        telegram = ?, 
        atendimento = ?,
        whatsapp = ?,
        instagram = ?,
        facebook = ?
        WHERE id = 1");

    $qry->bind_param(
        "sssss",
        $data['telegram'],
        $data['atendimento'],
        $data['whatsapp'],
        $data['instagram'],
        $data['facebook']
    );
    return $qry->execute();
}

$toastType = null;
$toastMessage = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = [
        'telegram' => trim(htmlspecialchars($_POST['telegram'])),
        'whatsapp' => trim(htmlspecialchars($_POST['whatsapp'])),
        'facebook' => trim(htmlspecialchars($_POST['facebook'])),
        'instagram' => trim(htmlspecialchars($_POST['instagram'])),
        'atendimento' => trim(htmlspecialchars($_POST['atendimento']))
    ];

    if (update_config($data)) {
        $toastType = 'success';
        $toastMessage = 'Configurações de nomes atualizadas com sucesso!';
    } else {
        $toastType = 'error';
        $toastMessage = 'Erro ao atualizar as configurações. Tente novamente.';
    }
}

$config = get_afiliados_config();
?>

<head>
    <?php $title = "Configurações de Canais De Atendimento";
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
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title">Gerenciamento de Canais De Atendimento</h4>
                            </div>

                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="row">

                                        <!-- Telegram -->
                                        <div class="col-md-6">
                                            <div class="card mb-4">
                                                <div class="card-body">
                                                    <h5 class="card-title">
                                                        <i class="iconoir-telegram-circle"></i> Canal Do Telegram
                                                    </h5>
                                                    <p class="card-subtitle text-muted mb-2">
                                                        Digite o grupo do telegram ou chat do atendente, ele será visualizado na página de suporte do cassino.
                                                    </p>
                                                    <input type="text" name="telegram" class="form-control"
                                                        value="<?= $config['telegram'] ?>" required>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Telegram -->
                                        <div class="col-md-6">
                                            <div class="card mb-4">
                                                <div class="card-body">
                                                    <h5 class="card-title">
                                                    <i class="fa-brands fa-square-instagram"></i> Instagram
                                                    </h5>
                                                    <p class="card-subtitle text-muted mb-2">
                                                        Digite o usuario do instagram de suporte, ele será visualizado na página de suporte do cassino.
                                                    </p>
                                                    <input type="text" name="instagram" class="form-control"
                                                        value="<?= $config['instagram'] ?>" required>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Telegram -->
                                        <div class="col-md-6">
                                            <div class="card mb-4">
                                                <div class="card-body">
                                                    <h5 class="card-title">
                                                    <i class="fa-brands fa-square-whatsapp"></i> WhatsApp
                                                    </h5>
                                                    <p class="card-subtitle text-muted mb-2">
                                                        Digite o grupo do whatsapp ou numero do atendente, ele será visualizado na página de suporte do cassino.
                                                    </p>
                                                    <input type="text" name="whatsapp" class="form-control"
                                                        value="<?= $config['whatsapp'] ?>" required>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Telegram -->
                                        <div class="col-md-6">
                                            <div class="card mb-4">
                                                <div class="card-body">
                                                    <h5 class="card-title">
                                                    <i class="fa-brands fa-square-facebook"></i> Facebook
                                                    </h5>
                                                    <p class="card-subtitle text-muted mb-2">
                                                        Digite o grupo do facebook, ele será visualizado na página de suporte do cassino.
                                                    </p>
                                                    <input type="text" name="facebook" class="form-control"
                                                        value="<?= $config['facebook'] ?>" required>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Atendimento -->
                                        <div class="col-md-6">
                                            <div class="card mb-4">
                                                <div class="card-body">
                                                    <h5 class="card-title">
                                                        <i class="iconoir-chat-bubble"></i> Atendimento
                                                    </h5>
                                                    <p class="card-subtitle text-muted mb-2">
                                                        Digite o seu canal de atendimento, webchat ou chatbot, ele será visualizado na página de suporte do cassino.
                                                    </p>
                                                    <input type="text" name="atendimento" class="form-control"
                                                        value="<?= $config['atendimento'] ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="text-center">
                                        <button type="submit" class="btn btn-success mb-3">Salvar Configurações</button>
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

    <!-- Exibir o Toast baseado nas ações do formulário -->
    <?php if ($toastType && $toastMessage): ?>
        <script>
            showToast('<?= $toastType ?>', '<?= $toastMessage ?>');
        </script>
    <?php endif; ?>

</body>

</html>
