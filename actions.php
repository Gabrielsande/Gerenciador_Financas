<?php
require_once 'db.php';

$db = getDB();

$acao = $_POST['acao'] ?? (isset($_GET['deletar']) ? 'deletar' : '');

// ── CRIAR ─────────────────────────────────────────────
if ($acao === 'criar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $descricao    = trim($_POST['descricao'] ?? '');
    $valor        = floatval($_POST['valor'] ?? 0);
    $tipo         = $_POST['tipo'] ?? '';
    $categoria_id = !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null;
    $data         = $_POST['data'] ?? date('Y-m-d');
    $observacao   = trim($_POST['observacao'] ?? '') ?: null;

    if (empty($descricao) || $valor <= 0 || !in_array($tipo, ['receita', 'gasto'])) {
        header("Location: index.php?ok=" . urlencode("Dados inválidos!"));
        exit;
    }

    $stmt = $db->prepare("
        INSERT INTO lancamentos (descricao, valor, tipo, categoria_id, data, observacao)
        VALUES (:descricao, :valor, :tipo, :categoria_id, :data, :observacao)
    ");
    $stmt->execute(compact('descricao', 'valor', 'tipo', 'categoria_id', 'data', 'observacao'));

    header("Location: index.php?ok=" . urlencode("Lançamento adicionado!"));
    exit;
}

// ── EDITAR ────────────────────────────────────────────
if ($acao === 'editar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id           = (int)($_POST['id'] ?? 0);
    $descricao    = trim($_POST['descricao'] ?? '');
    $valor        = floatval($_POST['valor'] ?? 0);
    $tipo         = $_POST['tipo'] ?? '';
    $categoria_id = !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null;
    $data         = $_POST['data'] ?? date('Y-m-d');
    $observacao   = trim($_POST['observacao'] ?? '') ?: null;

    if ($id <= 0 || empty($descricao) || $valor <= 0 || !in_array($tipo, ['receita', 'gasto'])) {
        header("Location: index.php?ok=" . urlencode("Erro ao editar."));
        exit;
    }

    $stmt = $db->prepare("
        UPDATE lancamentos
        SET descricao    = :descricao,
            valor        = :valor,
            tipo         = :tipo,
            categoria_id = :categoria_id,
            data         = :data,
            observacao   = :observacao
        WHERE id = :id
    ");
    $stmt->execute(compact('descricao', 'valor', 'tipo', 'categoria_id', 'data', 'observacao', 'id'));

    header("Location: index.php?ok=" . urlencode("Lançamento atualizado!"));
    exit;
}

// ── DELETAR ───────────────────────────────────────────
if ($acao === 'deletar' && isset($_GET['deletar'])) {
    $id = (int)$_GET['deletar'];
    if ($id > 0) {
        $stmt = $db->prepare("DELETE FROM lancamentos WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }
    header("Location: index.php?ok=" . urlencode("Lançamento excluído!"));
    exit;
}

// Fallback
header("Location: index.php");
exit;