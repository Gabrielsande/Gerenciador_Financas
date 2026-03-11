<?php
if($_SERVER["REQUEST_METHOD"] === "POST"){

    $descricao = htmlspecialchars(trim($_POST["descricao"]));
    $valor = floatval($_POST["valor"]);
    $tipo = $_POST["tipo"];

    if(empty($descricao) || !in_array($tipo,['receita','gasto']) || $valor <= 0){
        die("Dados inválidos!");
    }

    $dados = [];
    if(file_exists("dados.json")){
        $dados = json_decode(file_get_contents("dados.json"), true);
    }

    $dados[] = [
        "descricao" => $descricao,
        "valor" => $valor,
        "tipo" => $tipo
    ];

    file_put_contents("dados.json", json_encode($dados, JSON_PRETTY_PRINT));

    header("Location: index.php");
    exit;
}
?>