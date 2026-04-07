<?php

session_start();
include_once "../config.php";
include_once('../'.DASH.'/services/database.php');
include_once('../'.DASH.'/services/funcao.php');
include_once('../'.DASH.'/services/crud.php');
include_once('../'.DASH.'/services/afiliacao.php');
global $mysqli;

function busca_valor_ipn($transacao_id){
    global $mysqli;
    $qry = "SELECT usuario, valor FROM transacoes WHERE transacao_id='" . $transacao_id . "'";
    $res = mysqli_query($mysqli, $qry);
    if (mysqli_num_rows($res) > 0) {
        $data = mysqli_fetch_assoc($res);
        $retorna_insert_saldo = adicionarSaldoUsuario($data['usuario'], $data['valor']);
        
        // Processar comissões de afiliação após creditar o saldo
        if ($retorna_insert_saldo) {
            processarTodasComissoes($data['usuario'], $data['valor']);
        }
        
        return $retorna_insert_saldo;
    }
    return false;
}

function att_paymentpix($transacao_id){
    global $mysqli;
    $sql = $mysqli->prepare("UPDATE transacoes SET status='1' WHERE transacao_id=?");
    $sql->bind_param("s", $transacao_id);
    if ($sql->execute()) {
        $buscar = busca_valor_ipn($transacao_id);
        if ($buscar) {
            $rf = 1;
        } else {
            $rf = 0;
        }
    } else {
        $rf = 0;
    }
    return $rf;
}

function webhook() {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo 'erro404';
        //show_404();
    }
    $json_data = file_get_contents('php://input');

    $data = json_decode($json_data, true);

    if (!isset($data['idTransaction']) || !isset($data['typeTransaction']) || !isset($data['statusTransaction'])) {
        echo json_encode(['status' => 'error', 'message' => 'Dados incompletos']);
        return;
    }

    // Processa os dados conforme necessário
        $idTransaction = PHP_SEGURO($data['idTransaction']);     		 // id da transação
        $typeTransaction = PHP_SEGURO($data['typeTransaction']); 		// tipo de transação
        $statusTransaction = PHP_SEGURO($data['statusTransaction']);


    if ($statusTransaction === 'PAID_OUT') {

        $att_transacao = att_paymentpix($idTransaction);

        //$retorna_insert_saldo_suit_pay = enviarSaldo($retornaUSER['email'], $data['valor']);

    }

    echo json_encode(['status' => 'success']);
}

// Executar webhook se chamado diretamente
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    webhook();
}

?>