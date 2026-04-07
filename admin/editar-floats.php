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
#expulsa user
checa_login_adm();
#======================================#
//inicio do script expulsa usuario bloqueado
if (false) {
    echo "<script>setTimeout(function() { window.location.href = 'bloqueado.php'; }, 0);</script>";
    exit();
}

# Função para buscar os banners
function get_banners()
{
    global $mysqli;
    $qry = "SELECT * FROM floats";
    $result = mysqli_query($mysqli, $qry);
    $banners = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $banners[] = $row;
        }
    }
    return $banners;
}

# Função para atualizar o banner
function update_banner($id, $titulo, $status, $redirect, $tipo, $img = null)
{
    global $mysqli;

    if ($img) {
        $qry = $mysqli->prepare("UPDATE floats SET titulo = ?, status = ?, redirect = ?, tipo = ?, img = ? WHERE id = ?");
        $qry->bind_param("sisssi", $titulo, $status, $redirect, $tipo, $img, $id);
    } else {
        $qry = $mysqli->prepare("UPDATE floats SET titulo = ?, status = ?, redirect = ?, tipo = ? WHERE id = ?");
        $qry->bind_param("sissi", $titulo, $status, $redirect, $tipo, $id);
    }

    return $qry->execute();
}

# Se o formulário for enviado, atualizar os dados
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = intval($_POST['id']);
    $titulo = $_POST['titulo'];
    $redirect = $_POST['redirect'];
    $tipo = intval($_POST['tipo']);
    $status = intval($_POST['status']);

    # Buscar a imagem atual no banco de dados
    $query = "SELECT img FROM floats WHERE id = ?";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $banner = $result->fetch_assoc();
    $img = $banner['img']; // Manter o nome da imagem atual

    if (!empty($_FILES['img']['name'])) {
        $upload_dir = "../uploads/";
        $original_name = basename($_FILES['img']['name']);
        $file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $allowed_extensions = ['png','jpg','jpeg','webp','gif','ico','avif','svg'];
        if (in_array($file_extension, $allowed_extensions, true)) {
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
            $mime = $finfo ? finfo_file($finfo, $_FILES['img']['tmp_name']) : ($_FILES['img']['type'] ?? '');
            if ($finfo) { finfo_close($finfo); }
            $is_image = stripos((string)$mime, 'image/') === 0;
            if ($is_image) {
                $new_img_name = time() . '_' . $original_name;
                $img_path = $upload_dir . $new_img_name;
                if (move_uploaded_file($_FILES["img"]["tmp_name"], $img_path)) {
                    $img = $new_img_name;
                } else {
                    $toastType = 'error';
                    $toastMessage = 'Erro ao enviar a imagem. Tente novamente.';
                }
            } else {
                $toastType = 'error';
                $toastMessage = 'O arquivo enviado não é uma imagem válida.';
            }
        } else {
            $toastType = 'error';
            $toastMessage = 'Extensão de arquivo não permitida.';
        }
    }


    # Atualizar o banner no banco de dados
    if (update_banner($id, $titulo, $status, $redirect, $tipo, $img)) {
        $toastType = 'success';
        $toastMessage = 'Icon Float atualizado com sucesso!';
    } else {
        $toastType = 'error';
        $toastMessage = 'Erro ao atualizar o banner. Tente novamente.';
    }
}




# Buscar os banners atuais
$banners = get_banners();
?>

<head>
    <?php $title = "Gerenciamento de Floats";
    include 'partials/title-meta.php' ?>

    <link rel="stylesheet" href="assets/libs/jsvectormap/jsvectormap.min.css">
    <?php include 'partials/head-css.php' ?>
</head>

<body>

    <!-- Top Bar Start -->
    <?php include 'partials/topbar.php' ?>
    <!-- Top Bar End -->
    <!-- leftbar-tab-menu -->
    <?php include 'partials/startbar.php' ?>
    <!-- end leftbar-tab-menu-->

    <div class="page-wrapper">
        <div class="page-content">
            <div class="container-xxl">
                <div class="row justify-content-center">
                    <div class="col-lg-12">
                        <div class="card">
                            <div class="card-header d-flex align-items-center">
                                <h4 class="card-title mb-0"><i class="ti ti-pin me-2"></i>Gerenciamento de Floats icons</h4>
                            </div>

                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h5 class="mb-1">Floats da plataforma</h5>
                                        <p class="text-muted mb-0">Gerencie os ícones flutuantes exibidos na interface do usuário.</p>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle text-nowrap mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>ID</th>
                                                <th>Imagem</th>
                                                <th>Status</th>
                                                <th>Redirecionar</th>
                                                <th>Tipo</th>
                                                <th>Data de Criação</th>
                                                <th class="text-end">Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($banners as $banner): ?>

                                                <tr>
                                                    <td><?= $banner['id']; ?></td>
                                                    <td>
                                                        <img src="/uploads/<?= $banner['img']; ?>" alt="Banner" class="rounded border" width="72">
                                                    </td>
                                                    <td>
                                                        <?php if ($banner['status'] == 1) { ?>
                                                            <span class="badge bg-success-subtle text-success">Ativo</span>
                                                        <?php } else { ?>
                                                            <span class="badge bg-secondary-subtle text-muted">Inativo</span>
                                                        <?php } ?>
                                                    </td>
                                                    <td><?= $banner['redirect']; ?></td>
                                                    <td>
                                                        <?php if ($banner['tipo'] == 0) { ?>
                                                            <span class="badge bg-success">Estático</span>
                                                        <?php } elseif ($banner['tipo'] == 1) { ?>
                                                            <span class="badge bg-warning text-dark">Deslizante</span>
                                                        <?php } elseif ($banner['tipo'] == 3) { ?>
                                                            <span class="badge bg-info text-dark">Usuário Deslogado</span>
                                                        <?php } ?>
                                                    </td>
                                                    <td><?= $banner['criado_em']; ?></td>
                                                    <td class="text-end">
                                                        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal"
                                                            data-bs-target="#editBannerModal<?= $banner['id']; ?>">
                                                            <i class="ti ti-pencil me-1"></i>Editar
                                                        </button>
                                                    </td>
                                                </tr>

                                            <!-- Modal de Edição -->
                                            <div class="modal fade" id="editBannerModal<?= $banner['id']; ?>" tabindex="-1" aria-labelledby="editBannerLabel" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title" id="editBannerLabel">Editar Float Icon</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <form method="POST" enctype="multipart/form-data">
                                                                <input type="hidden" name="id" value="<?= $banner['id']; ?>">
                                                                <div class="mb-3">
                                                                    <label for="titulo" class="form-label">Título</label>
                                                                    <input type="text" class="form-control" name="titulo" value="<?= $banner['titulo']; ?>" required>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="redirect" class="form-label">Redirecionar</label>
                                                                    <input type="text" class="form-control" name="redirect" value="<?= $banner['redirect']; ?>" required>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="tipo" class="form-label">Tipo</label>
                                                                    <select class="form-select" name="tipo">
                                                                        <option value="0" <?= $banner['tipo'] == 0 ? 'selected' : ''; ?>>Estático</option>
                                                                        <option value="1" <?= $banner['tipo'] == 1 ? 'selected' : ''; ?>>Deslizante</option>
                                                                        <option value="3" <?= $banner['tipo'] == 3 ? 'selected' : ''; ?>>Usuário Deslogado</option>
                                                                    </select>
                                                                <br>
                                                                <div class="mb-3">
                                                                    <label for="img" class="form-label">Imagem</label>
                                                                    <input type="file" class="form-control" name="img">
                                                                    <small class="text-muted">Deixe em branco se não quiser alterar a imagem.</small>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="status" class="form-label">Status</label>
                                                                    <select class="form-select" name="status">
                                                                        <option value="1" <?= $banner['status'] == 1 ? 'selected' : ''; ?>>Ativo</option>
                                                                        <option value="0" <?= $banner['status'] == 0 ? 'selected' : ''; ?>>Inativo</option>
                                                                    </select>
                                                                </div>
                                                                <div class="text-center">
                                                                    <button type="submit" class="btn btn-success">Salvar Alterações</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div><!-- end row -->
            </div><!-- container -->

            <?php include 'partials/endbar.php' ?>
            <?php include 'partials/footer.php' ?>
        </div><!-- page content -->
    </div><!-- page-wrapper -->

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
