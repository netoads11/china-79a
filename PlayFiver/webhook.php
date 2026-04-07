<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once('../dash/services/funcao.php');
include_once('../dash/services/crud.php');

function webhook($data)
{
    global $mysqli;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['msg' => 'error404']);
        return;
    }

    error_log("Requisição POST recebida.");

    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);

    if ($data['type'] === 'BALANCE' && !empty($data['user_code'])) {
        $stmtUsr = mysqli_prepare($mysqli, "SELECT * FROM usuarios WHERE mobile = ?");
        mysqli_stmt_bind_param($stmtUsr, 's', $data['user_code']);
        mysqli_stmt_execute($stmtUsr);
        $result = mysqli_stmt_get_result($stmtUsr);
        $usuario = mysqli_fetch_assoc($result);

        if (!$usuario) {
            echo json_encode(['msg' => 'INVALID_USER', 'balance' => 0]);
            return;
        }

        echo json_encode(['msg' => '', 'balance' => $usuario['saldo']]);
    } elseif ($data['type'] === 'WinBet' && !empty($data['user_code'])) {
        $stmtUsr = mysqli_prepare($mysqli, "SELECT * FROM usuarios WHERE mobile = ?");
        mysqli_stmt_bind_param($stmtUsr, 's', $data['user_code']);
        mysqli_stmt_execute($stmtUsr);
        $result = mysqli_stmt_get_result($stmtUsr);
        $usuario = mysqli_fetch_assoc($result);

        if ($usuario) {
            $dataPost = [
                'id_user' => $usuario['id'],
                'nome_game' => $data[$data['game_type']]['game_code'] ?? null,
                'bet_money' => $data[$data['game_type']]['bet'] ?? null,
                'win_money' => $data[$data['game_type']]['win'] ?? null,
                'txn_id' => $data[$data['game_type']]['txn_id'] ?? null,
                'created_at' => $data[$data['game_type']]['created_at'] ?? null,
                'status_play' => 1,
            ];

            $stmtInsert = mysqli_prepare($mysqli, "INSERT INTO historico_play (id_user, nome_game, bet_money, win_money, txn_id, created_at, status_play) VALUES (?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmtInsert, 'issdssi', $dataPost['id_user'], $dataPost['nome_game'], $dataPost['bet_money'], $dataPost['win_money'], $dataPost['txn_id'], $dataPost['created_at'], $dataPost['status_play']);

            if (mysqli_stmt_execute($stmtInsert)) {
                $ganho = $data[$data['game_type']]['win'] - $data[$data['game_type']]['bet'];
                $novosaldo = $usuario['saldo'] + $ganho;

                $stmtGanho = mysqli_prepare($mysqli, "UPDATE usuarios SET saldo = ? WHERE id = ?");
                mysqli_stmt_bind_param($stmtGanho, 'di', $novosaldo, $usuario['id']);
                mysqli_stmt_execute($stmtGanho);

                echo json_encode(['msg' => 'SUCCESS', 'balance' => $novosaldo]);
                return;
            }
        }

        echo json_encode(['msg' => 'INVALID_USER', 'balance' => 0]);
    } else {
        echo json_encode(['msg' => 'INVALID_TYPE']);
    }
}

webhook($_POST);
