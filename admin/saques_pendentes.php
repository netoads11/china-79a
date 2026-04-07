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
?>

<?php
include_once "validar_2fa.php";
?>

<?php
// ...existing code...

global $mysqli;

// Defina o invite_code alvo conforme o admin logado
$invite_code_bspay_1 = '';
$invite_code_bspay_2 = '';
$email_adm = $_SESSION['data_adm']['email'] ?? '';

// Busca os invite_codes das BSPay id 1 e 2
$sql_bspay1 = "SELECT invite_code FROM bspay WHERE id = 1 LIMIT 1";
$res_bspay1 = $mysqli->query($sql_bspay1);
if ($res_bspay1 && $row_bspay1 = $res_bspay1->fetch_assoc()) {
    $invite_code_bspay_1 = $row_bspay1['invite_code'];
}
$sql_bspay2 = "SELECT invite_code FROM bspay WHERE id = 2 LIMIT 1";
$res_bspay2 = $mysqli->query($sql_bspay2);
if ($res_bspay2 && $row_bspay2 = $res_bspay2->fetch_assoc()) {
    $invite_code_bspay_2 = $row_bspay2['invite_code'];
}

// Buscar todos os usuários da rede de afiliados cujo invitation_code na hierarquia leve ao invite_code_bspay
function getUsuariosDaRede($mysqli, $invite_code_bspay)
{
    $ids = [];
    $sql = "SELECT id, invite_code, invitation_code FROM usuarios";
    $res = $mysqli->query($sql);
    $usuarios = [];
    while ($row = $res->fetch_assoc()) {
        $usuarios[$row['id']] = $row;
    }
    foreach ($usuarios as $id => $user) {
        $current_code = $user['invitation_code'];
        $max_depth = 10;
        while ($current_code && $max_depth-- > 0) {
            if ($current_code == $invite_code_bspay) {
                $ids[] = $id;
                break;
            }
            // Procurar o próximo na hierarquia
            $found = false;
            foreach ($usuarios as $u) {
                if ($u['invite_code'] == $current_code) {
                    $current_code = $u['invitation_code'];
                    $found = true;
                    break;
                }
            }
            if (!$found) break;
        }
    }
    return $ids;
}

// IDs dos usuários das redes BSPay 1 e 2
$ids_rede_1 = getUsuariosDaRede($mysqli, $invite_code_bspay_1);
$ids_rede_2 = getUsuariosDaRede($mysqli, $invite_code_bspay_2);

// IDs dos usuários que não pertencem a nenhuma das redes
$sql = "SELECT id FROM usuarios";
$res = $mysqli->query($sql);
$ids_todos = [];
while ($row = $res->fetch_assoc()) {
    $ids_todos[] = $row['id'];
}
$ids_outros = array_diff($ids_todos, $ids_rede_1, $ids_rede_2);

// Seleção de IDs conforme a sessão
if ($email_adm === 'vxciian@gmail.com') {
    $ids_rede = $ids_rede_2;
} else {
    // Mostra todos os saques dos usuários que NÃO estão nas redes BSPay 1 e 2
    $ids_rede = $ids_outros;
}

$ids_placeholder = implode(',', array_fill(0, count($ids_rede), '?'));

// Seleção de IDs conforme a sessão
if ($email_adm === 'vxciian@gmail.com') {
    $ids_rede = $ids_rede_2;
} else {
    $ids_rede = array_merge($ids_outros, $ids_rede_1);
}

if (empty($ids_rede)) {
    $result_usuarios = false;
} else {
    $ids_placeholder = implode(',', array_fill(0, count($ids_rede), '?'));
    $query_usuarios = "
        SELECT ss.*, u.statusaff
        FROM solicitacao_saques ss
        INNER JOIN usuarios u ON ss.id_user = u.id
        WHERE ss.status IN (0, 3, 4)
          AND ss.id_user IN ($ids_placeholder)
        ORDER BY ss.id DESC
    ";
    $stmt = $mysqli->prepare($query_usuarios);
    $types = str_repeat('i', count($ids_rede));
    $stmt->bind_param($types, ...$ids_rede);
    $stmt->execute();
    $result_usuarios = $stmt->get_result();
}
?>

<head>
    <?php $title = "dash";
    include 'partials/title-meta.php' ?>

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

        <!-- Page Content-->
        <div class="page-content">
            <div class="container-xxl">

                <div class="row justify-content-center">
                    <div class="col-md-12 col-lg-12">
                        <div class="card">
                            <div class="card-header">
                                <div class="row align-items-center">
                                    <div class="col" style="display: flex;align-content: center;align-items: center;">
                                        <div class="tag" style="background: yellow !important;"></div>
                                        <h4 class="card-title">Saques Pendentes</h4>
                                    </div><!--end col-->
                                </div> <!--end row-->
                            </div><!--end card-header-->
                            <div class="card-body pt-0">
                                <div class="table-responsive">
                                    <table class="table  mb-0 table-centered">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Id</th>
                                                <th>Usuario</th>
                                                <th>Transação ID</th>
                                                <th>Valor</th>
                                                <th>Data/Hora</th>
                                                <th>Chave Pix</th>
                                                <th>Influenciador</th>
                                                <th>Status</th>
                                                <th>Ação</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            // global $mysqli;
                                            // // Consultar os dados da tabela solicitacao_saques com os status 0, 3 e 4
                                            // $query_usuarios = "SELECT * FROM solicitacao_saques WHERE status IN (0, 3, 4) ORDER BY id DESC";
                                            // $result_usuarios = mysqli_query($mysqli, $query_usuarios);

                                            if ($result_usuarios && mysqli_num_rows($result_usuarios) > 0) {
                                                while ($usuario = mysqli_fetch_assoc($result_usuarios)) {
                                                    // Definir o cargo_badge com base no status do saque
                                                    switch ($usuario['status']) {
                                                        case 0:
                                                            $cargo_badge = "<span class='badge bg-danger'>Em Análise</span>";
                                                            break;
                                                        case 3:
                                                            $cargo_badge = "<span class='badge bg-warning'>Em Processamento</span>";
                                                            break;
                                                        case 4:
                                                            $cargo_badge = "<span class='badge bg-success'>Aceitando</span>";
                                                            break;
                                                        default:
                                                            $cargo_badge = "<span class='badge bg-secondary'>Desconhecido</span>";
                                                            break;
                                                    }
                                                    // ID do usuário para consulta
                                                    $id_user = $usuario['id_user'];

                                                    // Consulta para buscar informações do usuário
                                                    $query_puxarmobile = "SELECT mobile FROM usuarios WHERE id = ?";
                                                    $stmt = $mysqli->prepare($query_puxarmobile);

                                                    if ($stmt) {
                                                        $stmt->bind_param("i", $id_user);
                                                        $stmt->execute();
                                                        $result_puxarmobile = $stmt->get_result();

                                                        // Verifica se houve resultado
                                                        if ($result_puxarmobile && $result_puxarmobile->num_rows > 0) {
                                                            $user_data = $result_puxarmobile->fetch_assoc();
                                                            $mobile = $user_data['mobile'];
                                                        } else {
                                                            $mobile = "N/A"; // Caso o usuário não seja encontrado
                                                        }

                                                        $stmt->close();
                                                    } else {
                                                        echo "Erro na preparação da consulta: " . $mysqli->error;
                                                    }

                                                    $chaveatt = localizarchavepix($usuario['pix']);

                                            ?>
                                                    <tr>
                                                        <td><?= $usuario['id']; ?></td>
                                                        <td>
                                                            <?= $mobile; ?>
                                                            <a href="<?= $painel_adm_ver_usuarios . encodeAll($usuario['id_user']); ?>"
                                                                class="btn btn-primary btn-icon ms-2">
                                                                <i class="ti ti-arrow-up-right"></i>
                                                            </a>
                                                        </td>
                                                        <td><?= $usuario['transacao_id']; ?></td>
                                                        <td>R$ <?= number_format($usuario['valor'], 2, ',', '.'); ?></td>
                                                        <td><?= $usuario['data_registro']; ?></td>
                                                        <td><?= $chaveatt['chave'] ?></td>
                                                        <td><?php
                                                            if ($usuario['statusaff'] == 1) {
                                                                echo "<span class='badge bg-danger'>Sim</span>";
                                                            } else {
                                                                echo "<span class='badge bg-warning'>Não</span>";
                                                            }
                                                            ?></td>
                                                        <td><?= $cargo_badge; ?></td>
                                                        <td>
                                                            <button class="btn btn-warning btn-edit-saque"
                                                                data-id="<?= $usuario['id']; ?>"
                                                                data-transacao="<?= $usuario['transacao_id']; ?>"
                                                                data-valor="<?= number_format($usuario['valor'], 2, ',', '.'); ?>"
                                                                data-chave="<?= $chaveatt['chave']; ?>"
                                                                data-usuario="<?= $mobile; ?>"
                                                                data-nome="<?= $chaveatt['realname']; ?>"
                                                                data-status="<?= $usuario['status']; ?>">Editar</button>
                                                        </td>
                                                    </tr>
                                            <?php
                                                }
                                            } else {
                                                echo "<tr><td colspan='7' class='text-center'>Sem dados disponíveis!</td></tr>";
                                            }
                                            ?>
                                        </tbody>
                                    </table><!--end /table-->
                                </div><!--end /tableresponsive-->
                            </div><!--end card-body-->
                        </div>
                    </div><!-- container -->

                    <?php include 'partials/endbar.php' ?>
                    <?php include 'partials/footer.php' ?>

                </div>
            </div>
        </div>

        <!-- Modal Editar Saque -->
        <div class="modal fade" id="modalEditarSaque" tabindex="-1" aria-labelledby="modalEditarSaqueLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalEditarSaqueLabel">Editar Saque</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form id="formEditarSaque">
                            <input type="hidden" id="saqueId" name="saqueId">
                            <input type="hidden" id="_csrf" name="_csrf" value="<?= md5(uniqid()) ?>">
                            <!-- CSRF token -->

                            <div class="mb-3">
                                <label for="transacaoId" class="form-label">Transação ID</label>
                                <input type="text" class="form-control" id="transacaoId" readonly>
                            </div>

                            <div class="mb-3">
                                <label for="valorSaque" class="form-label">Valor</label>
                                <input type="text" class="form-control" id="valorSaque" readonly>
                            </div>

                            <div class="mb-3">
                                <label for="nome" class="form-label">Nome</label>
                                <input type="text" class="form-control" id="nome" readonly>
                            </div>

                            <div class="mb-3">
                                <label for="chavePix" class="form-label">Chave Pix</label>
                                <input type="text" class="form-control" id="chavePix" readonly>
                            </div>

                            <input type="hidden" id="usuario" name="usuario">

                            <!-- Select para Status -->
                            <div class="mb-3">
                                <label for="statusSaque" class="form-label">Status</label>
                                <select class="form-select" id="statusSaque" name="statusSaque">
                                    <option value="processando">Em Processamento</option>
                                    <option value="aceitando">Aceitando</option>
                                    <option value="aprovado">Aprovado</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="2fa" class="form-label">2FA</label>
                                <input type="text" class="form-control" id="2fa" name="2fa">
                            </div>

                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-success" id="btnAtualizarSaque">Atualizar Status</button>
                        <button type="button" class="btn btn-success" id="btnAprovarSaque">Aprovar</button>
                        <button type="button" class="btn btn-danger" id="btnRecusarSaque">Recusar</button>
                    </div>
                </div>
            </div>
        </div>



        <!-- Scripts -->
        <?php include 'partials/vendorjs.php' ?>
        <script src="assets/js/app.js"></script>
        <script src="assets/libs/clipboard/clipboard.min.js"></script>
        <script src="assets/js/pages/clipboard.init.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Evento para abrir o modal e preencher as informações do saque
                const btnsEdit = document.querySelectorAll('.btn-edit-saque');
                btnsEdit.forEach(btn => {
                    btn.addEventListener('click', function() {
                        const saqueId = this.getAttribute('data-id');
                        const transacaoId = this.getAttribute('data-transacao');
                        const valor = this.getAttribute('data-valor');
                        const chavePix = this.getAttribute('data-chave');
                        const usuario = this.getAttribute('data-usuario');
                        const nome = this.getAttribute('data-nome');
                        const status = this.getAttribute('data-status'); // Adicionar o status aqui

                        // Preenche os campos do modal
                        document.getElementById('saqueId').value = saqueId;
                        document.getElementById('transacaoId').value = transacaoId;
                        document.getElementById('valorSaque').value = valor;
                        document.getElementById('chavePix').value = chavePix;
                        document.getElementById('usuario').value = usuario;
                        document.getElementById('nome').value = nome;
                        document.getElementById('statusSaque').value = status; // Preenche o status


                        // Exibe o modal
                        const modal = new bootstrap.Modal(document.getElementById('modalEditarSaque'));
                        modal.show();
                    });
                });

                // Evento para aprovar saque
                document.getElementById('btnAprovarSaque').addEventListener('click', function() {
                    const transacaoId = document.getElementById('transacaoId').value;
                    const usuario = document.getElementById('usuario').value;
                    const codigo2fa = document.getElementById('2fa').value;

                    // Enviar os dados via POST para payment_manual.php
                    const xhr = new XMLHttpRequest();
                    xhr.open("POST", `services-gateway/payment_manual.php?id=${transacaoId}&usuario=${usuario}&codigo_2fa=${codigo2fa}`, true);
                    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                    xhr.onreadystatechange = function() {
                        if (xhr.readyState === 4 && xhr.status === 200) {
                            const response = JSON.parse(xhr.responseText);

                            // Verifica o status da resposta e exibe a mensagem apropriada
                            if (response.success) {
                                showToast('success', response.message || 'Saque aprovado com sucesso!');
                                // Fechar o modal após a resposta
                                const modal = bootstrap.Modal.getInstance(document.getElementById('modalEditarSaque'));
                                modal.hide();
                                // Recarregar a página após o toast
                                setTimeout(function() {
                                    window.location.reload();
                                }, 2000);
                            } else {
                                showToast('danger', response.message || 'Erro ao aprovar o saque.');
                            }
                        } else if (xhr.readyState === 4) {
                            showToast('danger', 'Erro ao aprovar o saque.');
                        }
                    };
                    xhr.send(); // Não precisamos enviar parâmetros via body, já que o transacao_id vai na URL.
                });

                // Evento para recusar saque
                document.getElementById('btnRecusarSaque').addEventListener('click', function() {
                    const saqueId = document.getElementById('saqueId').value;
                    const _csrf = document.getElementById('_csrf').value;
                    const email_reprovado = document.getElementById('usuario').value;
                    const valor_reprovado = document.getElementById('valorSaque').value;

                    // Enviar os dados via AJAX para o servidor
                    const xhr = new XMLHttpRequest();
                    xhr.open("POST", "ajax/recusar_saque.php", true);
                    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                    xhr.onreadystatechange = function() {
                        if (xhr.readyState === 4 && xhr.status === 200) {
                            const response = JSON.parse(xhr.responseText);
                            if (response.status === 'success') {
                                showToast('success', response.message);
                                // Fechar o modal após a resposta
                                const modal = bootstrap.Modal.getInstance(document.getElementById('modalEditarSaque'));
                                modal.hide();
                                // Recarregar a página após o toast
                                setTimeout(function() {
                                    window.location.reload();
                                }, 2000); // Delay para o toast ser exibido antes de recarregar
                            } else {
                                showToast('danger', response.message);
                            }
                        }
                    };
                    xhr.send(`att-pay=1&_csrf=${_csrf}&id_pay=${saqueId}&email_reprovado=${email_reprovado}&valor_reprovado=${valor_reprovado}`);
                });

                // Evento para atualizar o status do saque
                document.getElementById('btnAtualizarSaque').addEventListener('click', function() {
                    const saqueId = document.getElementById('saqueId').value;
                    const statusSaque = document.getElementById('statusSaque').value;

                    // Enviar os dados via AJAX para atualizar o status
                    const xhr = new XMLHttpRequest();
                    xhr.open("POST", "ajax/atualizar_status_saque.php", true);
                    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                    xhr.onreadystatechange = function() {
                        if (xhr.readyState === 4 && xhr.status === 200) {
                            const response = JSON.parse(xhr.responseText);

                            if (response.success) {
                                showToast('success', response.message || 'Status do saque atualizado com sucesso!');
                                // Fechar o modal após a resposta
                                const modal = bootstrap.Modal.getInstance(document.getElementById('modalEditarSaque'));
                                modal.hide();
                                // Recarregar a página após o toast
                                setTimeout(function() {
                                    window.location.reload();
                                }, 2000);
                            } else {
                                showToast('danger', response.message || 'Erro ao atualizar o status do saque.');
                            }
                        }
                    };
                    xhr.send(`id=${saqueId}&status=${statusSaque}`); // Envia o id e o novo status
                });


                // Função para exibir toast com o estilo fornecido
                function showToast(type, message) {
                    var toastPlacement = document.getElementById('toastPlacement');
                    var toast = document.createElement('div');
                    toast.className = `toast align-items-center bg-light border-0 fade show`;
                    toast.setAttribute('role', 'alert');
                    toast.setAttribute('aria-live', 'assertive');
                    toast.setAttribute('aria-atomic', 'true');
                    toast.innerHTML = `
                <div class="toast-header">
                    
                    <h5 class="me-auto my-0">Atualização</h5>
                    <small>Agora</small>
                    <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body">${message}</div>
            `;
                    toastPlacement.appendChild(toast);

                    // Inicializar o Toast do Bootstrap
                    var bootstrapToast = new bootstrap.Toast(toast);
                    bootstrapToast.show();

                    setTimeout(function() {
                        bootstrapToast.hide(); // Esconder o toast após 3 segundos
                        setTimeout(() => toast.remove(), 500); // Remove o toast após ele sumir
                    }, 3000);
                }
            });
        </script>

        <!-- Adicione um elemento de posicionamento para o Toast -->
        <div id="toastPlacement" class="position-fixed bottom-0 end-0 p-3" style="z-index: 11"></div>


</body>
<!--end body-->

</html>
