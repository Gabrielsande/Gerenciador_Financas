<?php
$arquivo = "dados.json";

function carregarDados(string $arquivo): array {
    if (!file_exists($arquivo)) return [];
    return json_decode(file_get_contents($arquivo), true) ?? [];
}

function salvarDados(string $arquivo, array $dados): void {
    file_put_contents($arquivo, json_encode(array_values($dados), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function validar(string $descricao, float $valor, string $tipo): bool {
    return !empty($descricao) && $valor > 0 && in_array($tipo, ['receita', 'gasto']);
}

$acao = $_POST['acao'] ?? ($_GET['deletar'] !== null ? 'deletar' : '');

// ── CRIAR ─────────────────────────────────────────────
if ($acao === 'criar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $descricao = htmlspecialchars(trim($_POST['descricao'] ?? ''));
    $valor     = floatval($_POST['valor'] ?? 0);
    $tipo      = $_POST['tipo'] ?? '';

    if (!validar($descricao, $valor, $tipo)) {
        header("Location: index.php?ok=" . urlencode("Dados inválidos!"));
        exit;
    }

    $dados   = carregarDados($arquivo);
    $dados[] = compact('descricao', 'valor', 'tipo');
    salvarDados($arquivo, $dados);

    header("Location: index.php?ok=" . urlencode("Lançamento adicionado!"));
    exit;
}

// ── EDITAR ────────────────────────────────────────────
if ($acao === 'editar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $idx       = (int)($_POST['index'] ?? -1);
    $descricao = htmlspecialchars(trim($_POST['descricao'] ?? ''));
    $valor     = floatval($_POST['valor'] ?? 0);
    $tipo      = $_POST['tipo'] ?? '';

    $dados = carregarDados($arquivo);

    if (!isset($dados[$idx]) || !validar($descricao, $valor, $tipo)) {
        header("Location: index.php?ok=" . urlencode("Erro ao editar."));
        exit;
    }

    $dados[$idx] = compact('descricao', 'valor', 'tipo');
    salvarDados($arquivo, $dados);

    header("Location: index.php?ok=" . urlencode("Lançamento atualizado!"));
    exit;
}

// ── DELETAR ───────────────────────────────────────────
if (isset($_GET['deletar'])) {
    $idx   = (int)$_GET['deletar'];
    $dados = carregarDados($arquivo);

    if (isset($dados[$idx])) {
        array_splice($dados, $idx, 1);
        salvarDados($arquivo, $dados);
    }

    header("Location: index.php?ok=" . urlencode("Lançamento excluído!"));
    exit;
}

// Fallback
header("Location: index.php");
exit;