<?php
$dados = [];

if(file_exists("dados.json")){
    $dados = json_decode(file_get_contents("dados.json"), true);
}

// Cálculos rápidos
$totalReceitas = 0;
$totalGastos = 0;

foreach($dados as $d){
    if($d['tipo'] === 'receita'){
        $totalReceitas += $d['valor'];
    } else {
        $totalGastos += $d['valor'];
    }
}

$saldo = $totalReceitas - $totalGastos;
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Dashboard Financeiro</title>
<link rel="stylesheet" href="css/style.css">
</head>
<body>

<header>
    <h1>💰 Meu Dashboard Financeiro</h1>
    <p>Controle suas receitas e despesas em tempo real</p>
</header>

<section class="dashboard">

    <div class="cards">

        <div class="card receita-card">
            <h3>Receitas</h3>
            <p>R$ <?= number_format($totalReceitas,2,",","."); ?></p>
        </div>

        <div class="card gasto-card">
            <h3>Gastos</h3>
            <p>R$ <?= number_format($totalGastos,2,",","."); ?></p>
        </div>

        <div class="card saldo-card">
            <h3>Saldo Atual</h3>
            <p class="<?= $saldo>=0 ? 'positivo' : 'negativo' ?>">
                R$ <?= number_format($saldo,2,",","."); ?>
            </p>
        </div>

    </div>

    <form action="salvar.php" method="POST" class="form-movimentacao">
        <input type="text" name="descricao" placeholder="Descrição" required>
        <input type="number" step="0.01" name="valor" placeholder="Valor" required>
        <select name="tipo">
            <option value="receita">Receita</option>
            <option value="gasto">Gasto</option>
        </select>
        <button type="submit">Adicionar</button>
    </form>

    <h2>Movimentações</h2>

    <table>
        <tr>
            <th>Descrição</th>
            <th>Valor</th>
            <th>Tipo</th>
        </tr>

        <?php foreach($dados as $d): ?>
        <tr class="<?= $d['tipo']; ?>">
            <td><?= htmlspecialchars($d['descricao']); ?></td>
            <td>R$ <?= number_format($d['valor'],2,",","."); ?></td>
            <td><?= $d['tipo']; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

</section>

<script src="js/script.js"></script>
</body>
</html>