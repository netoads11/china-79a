<?php include 'partials/html.php' ?>

<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();
include_once "services/database.php";
include_once "services/funcao.php";
include_once 'logs/registrar_logs.php';
include_once "services/crud.php";
include_once "services/crud-adm.php";
include_once "validar_2fa.php";
include_once "services/CSRF_Protect.php";
include_once 'services/checa_login_adm.php';
$csrf = new CSRF_Protect();

checa_login_adm();

// Função para buscar jogos com filtros
function get_games($limit, $offset, $search = '', $filter_api = '', $filter_provider = '')
{
    global $mysqli;
    
    $where_conditions = ["1=1"];
    
    if (!empty($search)) {
        $search_safe = $mysqli->real_escape_string($search);
        $where_conditions[] = "game_name LIKE '%$search_safe%'";
    }
    
    if (!empty($filter_api)) {
        $filter_api_safe = $mysqli->real_escape_string($filter_api);
        $where_conditions[] = "api = '$filter_api_safe'";
    } else {
        // Mostrar apenas jogos que têm API definida
        $where_conditions[] = "(api = 'PGClone' OR api = 'iGameWin' OR api = 'PPClone' OR api = 'Drakon' OR api = 'PlayFiver')";
    }
    
    if (!empty($filter_provider)) {
        $filter_provider_safe = $mysqli->real_escape_string($filter_provider);
        $where_conditions[] = "provider = '$filter_provider_safe'";
    }
    
    $where_clause = implode(" AND ", $where_conditions);
    
    $qry = "SELECT * FROM games WHERE $where_clause ORDER BY id DESC LIMIT $limit OFFSET $offset";
    $result = mysqli_query($mysqli, $qry);
    $games = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $games[] = $row;
    }
    return $games;
}

function count_games($search = '', $filter_api = '', $filter_provider = '')
{
    global $mysqli;
    
    $where_conditions = ["1=1"];
    
    if (!empty($search)) {
        $search_safe = $mysqli->real_escape_string($search);
        $where_conditions[] = "game_name LIKE '%$search_safe%'";
    }
    
    if (!empty($filter_api)) {
        $filter_api_safe = $mysqli->real_escape_string($filter_api);
        $where_conditions[] = "api = '$filter_api_safe'";
    } else {
        // Contar apenas jogos que têm API definida
        $where_conditions[] = "(api = 'PGClone' OR api = 'iGameWin' OR api = 'PPClone' OR api = 'Drakon' OR api = 'PlayFiver')";
    }
    
    if (!empty($filter_provider)) {
        $filter_provider_safe = $mysqli->real_escape_string($filter_provider);
        $where_conditions[] = "provider = '$filter_provider_safe'";
    }
    
    $where_clause = implode(" AND ", $where_conditions);
    
    $qry = "SELECT COUNT(*) as total FROM games WHERE $where_clause";
    $result = mysqli_query($mysqli, $qry);
    return mysqli_fetch_assoc($result)['total'];
}

function add_game($data)
{
    global $mysqli;
    
    $qry = $mysqli->prepare("INSERT INTO games (game_name, game_code, banner, provider, type, game_type, api, status, popular) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $qry->bind_param(
        "sssssssii",
        $data['game_name'],
        $data['game_code'],
        $data['banner'],
        $data['provider'],
        $data['type'],
        $data['game_type'],
        $data['api'],
        $data['status'],
        $data['popular']
    );
    
    return $qry->execute();
}

function update_game($data)
{
    global $mysqli;
    
    $qry = $mysqli->prepare("UPDATE games SET 
        game_name = ?, 
        game_code = ?, 
        banner = ?, 
        provider = ?, 
        type = ?, 
        game_type = ?, 
        api = ?,
        status = ?,
        popular = ?
        WHERE id = ?");
    
    $qry->bind_param(
        "ssssssssii",
        $data['game_name'],
        $data['game_code'],
        $data['banner'],
        $data['provider'],
        $data['type'],
        $data['game_type'],
        $data['api'],
        $data['status'],
        $data['popular'],
        $data['id']
    );
    
    return $qry->execute();
}

function delete_game($id)
{
    global $mysqli;
    $qry = $mysqli->prepare("DELETE FROM games WHERE id = ?");
    $qry->bind_param("i", $id);
    return $qry->execute();
}

// Função para buscar providers únicos
function get_providers()
{
    global $mysqli;
    $qry = "SELECT DISTINCT provider FROM games WHERE (api = 'PGClone' OR api = 'iGameWin' OR api = 'PPClone' OR api = 'Drakon' OR api = 'PlayFiver') AND provider IS NOT NULL AND provider != '' ORDER BY provider ASC";
    $result = mysqli_query($mysqli, $qry);
    $providers = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $providers[] = $row['provider'];
    }
    return $providers;
}

$toastType = null;
$toastMessage = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['delete_game'])) {
        $game_id = intval($_POST['game_id']);
        if (delete_game($game_id)) {
            $toastType = 'success';
            $toastMessage = 'Jogo excluído com sucesso!';
        } else {
            $toastType = 'error';
            $toastMessage = 'Erro ao excluir o jogo.';
        }
    } elseif (isset($_POST['add_game'])) {
        $data = [
            'game_name' => $_POST['game_name'],
            'game_code' => $_POST['game_code'],
            'banner' => $_POST['banner'],
            'provider' => $_POST['provider'],
            'type' => $_POST['type'],
            'game_type' => $_POST['game_type'],
            'api' => $_POST['api'],
            'status' => intval($_POST['status']),
            'popular' => intval($_POST['popular'])
        ];
        
        if (add_game($data)) {
            $toastType = 'success';
            $toastMessage = 'Jogo adicionado com sucesso!';
        } else {
            $toastType = 'error';
            $toastMessage = 'Erro ao adicionar o jogo.';
        }
    } elseif (isset($_POST['update_game'])) {
        $data = [
            'id' => intval($_POST['game_id']),
            'game_name' => $_POST['game_name'],
            'game_code' => $_POST['game_code'],
            'banner' => $_POST['banner'],
            'provider' => $_POST['provider'],
            'type' => $_POST['type'],
            'game_type' => $_POST['game_type'],
            'api' => $_POST['api'],
            'status' => intval($_POST['status']),
            'popular' => intval($_POST['popular'])
        ];
        
        if (update_game($data)) {
            $toastType = 'success';
            $toastMessage = 'Jogo atualizado com sucesso!';
        } else {
            $toastType = 'error';
            $toastMessage = 'Erro ao atualizar o jogo.';
        }
    }
}

// Paginação e filtros
$search = isset($_GET['search']) ? $_GET['search'] : '';
$filter_api = isset($_GET['filter_api']) ? $_GET['filter_api'] : '';
$filter_provider = isset($_GET['filter_provider']) ? $_GET['filter_provider'] : '';
$limit = 12;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$total_games = count_games($search, $filter_api, $filter_provider);
$total_pages = ceil($total_games / $limit);
$games = get_games($limit, $offset, $search, $filter_api, $filter_provider);
$providers = get_providers();
?>

<head>
    <?php $title = "Gerenciamento de Jogos API"; ?>
    <?php include 'partials/title-meta.php' ?>
    <?php include 'partials/head-css.php' ?>
    <style>
        .game-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 4px;
        }
    </style>
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
                                <h4 class="card-title mb-0">Gerenciamento de Jogos API</h4>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addGameModal">
                                    <i class="ti ti-plus me-1"></i>Adicionar Jogo
                                </button>
                            </div>
                            <div class="card-body">
                                
                                <!-- Filtros -->
                                <form method="GET" action="" class="row mb-3">
                                    <div class="col-md-4">
                                        <div class="input-group">
                                            <input type="text" name="search" class="form-control" placeholder="Buscar por nome do jogo..." value="<?= htmlspecialchars($search) ?>">
                                            <button class="btn btn-primary" type="submit">
                                                <i class="ti ti-search me-1"></i>Buscar
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <select name="filter_api" class="form-select" onchange="this.form.submit()">
                                            <option value="">Todas as APIs</option>
                                            <option value="PGClone" <?= $filter_api == 'PGClone' ? 'selected' : '' ?>>PGClone</option>
                                            <option value="iGameWin" <?= $filter_api == 'iGameWin' ? 'selected' : '' ?>>iGameWin</option>
                                            <option value="PPClone" <?= $filter_api == 'PPClone' ? 'selected' : '' ?>>PPClone</option>
                                            <option value="Drakon" <?= $filter_api == 'Drakon' ? 'selected' : '' ?>>Drakon</option>
                                            <option value="PlayFiver" <?= $filter_api == 'PlayFiver' ? 'selected' : '' ?>>PlayFiver</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <select name="filter_provider" class="form-select" onchange="this.form.submit()">
                                            <option value="">Todos os Providers</option>
                                            <?php foreach ($providers as $provider): ?>
                                                <option value="<?= htmlspecialchars($provider) ?>" <?= $filter_provider == $provider ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($provider) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </form>

                                <!-- Tabela de Jogos -->
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Banner</th>
                                                <th>Nome</th>
                                                <th>Código</th>
                                                <th>Provider</th>
                                                <th>API</th>
                                                <th>Status</th>
                                                <th>Popular</th>
                                                <th>Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($games)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center">Nenhum jogo encontrado</td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($games as $game): ?>
                                            <tr>
                                                <td>
                                                    <img src="<?= $game['banner'] ?>" alt="<?= htmlspecialchars($game['game_name']) ?>" class="game-image">
                                                </td>
                                                <td><?= htmlspecialchars($game['game_name']) ?></td>
                                                <td><code><?= htmlspecialchars($game['game_code']) ?></code></td>
                                                <td><?= htmlspecialchars($game['provider']) ?></td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $game['api'] == 'PGClone' ? 'primary' : 
                                                            ($game['api'] == 'iGameWin' ? 'success' : 
                                                            ($game['api'] == 'Drakon' ? 'warning' : 
                                                            ($game['api'] == 'PlayFiver' ? 'danger' : 'info'))); 
                                                    ?>">
                                                        <?= htmlspecialchars($game['api']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="form-check form-switch">
                                                        <input class="form-check-input" type="checkbox" 
                                                            <?= $game['status'] == 1 ? 'checked' : '' ?>
                                                            onchange="updateGameField(<?= $game['id'] ?>, 'status', this.checked ? 1 : 0)">
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="form-check form-switch">
                                                        <input class="form-check-input" type="checkbox" 
                                                            <?= $game['popular'] == 1 ? 'checked' : '' ?>
                                                            onchange="updateGameField(<?= $game['id'] ?>, 'popular', this.checked ? 1 : 0)">
                                                    </div>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editGameModal<?= $game['id'] ?>">
                                                        <i class="ti ti-pencil me-1"></i>Editar
                                                    </button>
                                                    <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $game['id'] ?>)">
                                                        <i class="ti ti-trash me-1"></i>Deletar
                                                    </button>
                                                </td>
                                            </tr>

                                            <!-- Modal Editar Jogo -->
                                            <div class="modal fade" id="editGameModal<?= $game['id'] ?>" tabindex="-1" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Editar Jogo</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <form method="POST">
                                                            <div class="modal-body">
                                                                <input type="hidden" name="update_game" value="1">
                                                                <input type="hidden" name="game_id" value="<?= $game['id'] ?>">
                                                                <input type="hidden" name="banner" value="<?= htmlspecialchars($game['banner']) ?>">
                                                                
                                                                <div class="mb-3">
                                                                    <label class="form-label">Nome do Jogo</label>
                                                                    <input type="text" name="game_name" class="form-control" value="<?= htmlspecialchars($game['game_name']) ?>" required>
                                                                </div>
                                                                
                                                                <div class="mb-3">
                                                                    <label class="form-label">Código do Jogo</label>
                                                                    <input type="text" name="game_code" class="form-control" value="<?= htmlspecialchars($game['game_code']) ?>" required>
                                                                </div>
                                                                
                                                                <div class="mb-3">
                                                                    <label class="form-label">Banner Atual</label>
                                                                    <div>
                                                                        <img src="<?= $game['banner'] ?>?t=<?= time() ?>" class="img-thumbnail" style="max-width: 200px;">
                                                                    </div>
                                                                </div>
                                                                
                                                                <div class="mb-3">
                                                                    <label class="form-label">Provider</label>
                                                                    <input type="text" name="provider" class="form-control" value="<?= htmlspecialchars($game['provider']) ?>" required>
                                                                </div>
                                                                
                                                                <div class="mb-3">
                                                                    <label class="form-label">API</label>
                                                                    <select name="api" class="form-select" required>
                                                                        <option value="PGClone" <?= $game['api'] == 'PGClone' ? 'selected' : '' ?>>PGClone</option>
                                                                        <option value="iGameWin" <?= $game['api'] == 'iGameWin' ? 'selected' : '' ?>>iGameWin</option>
                                                                        <option value="PPClone" <?= $game['api'] == 'PPClone' ? 'selected' : '' ?>>PPClone</option>
                                                                        <option value="Drakon" <?= $game['api'] == 'Drakon' ? 'selected' : '' ?>>Drakon</option>
                                                                        <option value="PlayFiver" <?= $game['api'] == 'PlayFiver' ? 'selected' : '' ?>>PlayFiver</option>
                                                                    </select>
                                                                </div>
                                                                
                                                                <div class="row">
                                                                    <div class="col-md-6">
                                                                        <div class="mb-3">
                                                                            <label class="form-label">Tipo</label>
                                                                            <input type="text" name="type" class="form-control" value="<?= htmlspecialchars($game['type']) ?>" required>
                                                                        </div>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <div class="mb-3">
                                                                            <label class="form-label">Game Type</label>
                                                                            <input type="text" name="game_type" class="form-control" value="<?= htmlspecialchars($game['game_type']) ?>" required>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                
                                                                <div class="row">
                                                                    <div class="col-md-6">
                                                                        <div class="mb-3">
                                                                            <label class="form-label">Status</label>
                                                                            <select name="status" class="form-select">
                                                                                <option value="1" <?= $game['status'] == 1 ? 'selected' : '' ?>>Ativo</option>
                                                                                <option value="0" <?= $game['status'] == 0 ? 'selected' : '' ?>>Inativo</option>
                                                                            </select>
                                                                        </div>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <div class="mb-3">
                                                                            <label class="form-label">Popular</label>
                                                                            <select name="popular" class="form-select">
                                                                                <option value="1" <?= $game['popular'] == 1 ? 'selected' : '' ?>>Sim</option>
                                                                                <option value="0" <?= $game['popular'] == 0 ? 'selected' : '' ?>>Não</option>
                                                                            </select>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                                                                <button type="submit" class="btn btn-primary">
                                                                    <i class="ti ti-device-floppy me-1"></i>Salvar Alterações
                                                                </button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>

                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Paginação -->
                                <?php if ($total_pages > 1): ?>
                                <nav aria-label="Page navigation">
                                    <ul class="pagination justify-content-center flex-wrap">
                                        <!-- Anterior -->
                                        <li class="page-item <?= $page == 1 ? 'disabled' : '' ?>">
                                            <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&filter_api=<?= urlencode($filter_api) ?>&filter_provider=<?= urlencode($filter_provider) ?>" aria-label="Anterior">
                                                <span aria-hidden="true">&laquo;</span>
                                            </a>
                                        </li>

                                        <?php
                                        // Lógica de paginação inteligente
                                        $range = 2; // Quantas páginas mostrar antes e depois da atual
                                        $start = max(1, $page - $range);
                                        $end = min($total_pages, $page + $range);

                                        // Primeira página
                                        if ($start > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=1&search=<?= urlencode($search) ?>&filter_api=<?= urlencode($filter_api) ?>&filter_provider=<?= urlencode($filter_provider) ?>">1</a>
                                            </li>
                                            <?php if ($start > 2): ?>
                                                <li class="page-item disabled"><span class="page-link">...</span></li>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <!-- Páginas do meio -->
                                        <?php for ($i = $start; $i <= $end; $i++): ?>
                                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                                <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&filter_api=<?= urlencode($filter_api) ?>&filter_provider=<?= urlencode($filter_provider) ?>"><?= $i ?></a>
                                            </li>
                                        <?php endfor; ?>

                                        <!-- Última página -->
                                        <?php if ($end < $total_pages): ?>
                                            <?php if ($end < $total_pages - 1): ?>
                                                <li class="page-item disabled"><span class="page-link">...</span></li>
                                            <?php endif; ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=<?= $total_pages ?>&search=<?= urlencode($search) ?>&filter_api=<?= urlencode($filter_api) ?>&filter_provider=<?= urlencode($filter_provider) ?>"><?= $total_pages ?></a>
                                            </li>
                                        <?php endif; ?>
                                        
                                        <!-- Próximo -->
                                        <li class="page-item <?= $page == $total_pages ? 'disabled' : '' ?>">
                                            <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&filter_api=<?= urlencode($filter_api) ?>&filter_provider=<?= urlencode($filter_provider) ?>" aria-label="Próximo">
                                                <span aria-hidden="true">&raquo;</span>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                                <?php endif; ?>

                            </div>
                        </div>
                    </div>
                </div>

            </div>
            
            <?php include 'partials/endbar.php' ?>
            <?php include 'partials/footer.php' ?>
        </div>
    </div>

    <!-- Modal Adicionar Jogo -->
    <div class="modal fade" id="addGameModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Adicionar Novo Jogo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="add_game" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label">Nome do Jogo</label>
                            <input type="text" name="game_name" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Código do Jogo</label>
                            <input type="text" name="game_code" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Banner (URL)</label>
                            <input type="text" name="banner" class="form-control" required>
                            <small class="text-muted">Ex: /game_pictures/g/EA/301/3/413/default.png</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Provider</label>
                            <input type="text" name="provider" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">API</label>
                            <select name="api" class="form-select" required>
                                <option value="">Selecione...</option>
                                <option value="PGClone">PGClone</option>
                                <option value="iGameWin">iGameWin</option>
                                <option value="PPClone">PPClone</option>
                                <option value="Drakon">Drakon</option>
                                <option value="PlayFiver">PlayFiver</option>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Tipo</label>
                                    <input type="text" name="type" class="form-control" value="slot" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Game Type</label>
                                    <input type="text" name="game_type" class="form-control" value="3" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="1" selected>Ativo</option>
                                        <option value="0">Inativo</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Popular</label>
                                    <select name="popular" class="form-select">
                                        <option value="0" selected>Não</option>
                                        <option value="1">Sim</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                        <button type="submit" class="btn btn-primary">Adicionar Jogo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Form oculto para delete -->
    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="delete_game" value="1">
        <input type="hidden" name="game_id" id="deleteGameId">
    </form>

    <div id="toastPlacement" class="toast-container position-fixed bottom-0 end-0 p-3"></div>

    <?php include 'partials/vendorjs.php' ?>
    <script src="assets/js/app.js"></script>

    <script>
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

            var bootstrapToast = new bootstrap.Toast(toast);
            bootstrapToast.show();

            setTimeout(function () {
                bootstrapToast.hide();
                setTimeout(() => toast.remove(), 500);
            }, 3000);
        }

        function updateGameField(gameId, field, value) {
            const formData = new FormData();
            formData.append('action', 'update_field');
            formData.append('game_id', gameId);
            formData.append('field', field);
            formData.append('value', value);

            fetch('ajax/att_jogos_all.php', {
                method: 'POST',
                body: formData,
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('success', data.message);
                } else {
                    showToast('error', data.message);
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                showToast('error', 'Erro ao atualizar.');
            });
        }

        function confirmDelete(gameId) {
            if (confirm('Deseja realmente excluir este jogo?')) {
                document.getElementById('deleteGameId').value = gameId;
                document.getElementById('deleteForm').submit();
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
