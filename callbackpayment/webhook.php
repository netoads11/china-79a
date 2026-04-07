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
    $stmt = $mysqli->prepare("SELECT usuario, valor FROM transacoes WHERE transacao_id = ?");
    $stmt->bind_param("s", $transacao_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows > 0) {
        $data = $res->fetch_assoc();
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
    // Só atualiza se ainda estiver em processamento — evita duplo crédito em reenvios do webhook
    $sql = $mysqli->prepare("UPDATE transacoes SET status='pago' WHERE transacao_id=? AND status='processamento'");
    $sql->bind_param("s", $transacao_id);
    $sql->execute();
    if ($sql->affected_rows === 1) {
        $buscar = busca_valor_ipn($transacao_id);
        return $buscar ? 1 : 0;
    }
    return 0;
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