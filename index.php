<?php
require_once 'db.php';
$db = getDB();

// ── Totais via view ───────────────────────────────────
$saldoRow      = $db->query("SELECT * FROM saldo_geral")->fetch();
$totalReceitas = (float)$saldoRow['total_receitas'];
$totalGastos   = (float)$saldoRow['total_gastos'];
$saldo         = (float)$saldoRow['saldo'];

// ── Lançamentos com categoria ─────────────────────────
$dados = $db->query("
    SELECT l.*, c.nome AS categoria_nome, c.icone AS categoria_icone
    FROM lancamentos l
    LEFT JOIN categorias c ON c.id = l.categoria_id
    ORDER BY l.data DESC, l.criado_em DESC
")->fetchAll();

// ── Categorias para o select ──────────────────────────
$categorias = $db->query("SELECT * FROM categorias ORDER BY nome")->fetchAll();

// ── Item em edição ────────────────────────────────────
$editItem = null;
if (isset($_GET['editar'])) {
    $id   = (int)$_GET['editar'];
    $stmt = $db->prepare("SELECT * FROM lancamentos WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row  = $stmt->fetch();
    if ($row) $editItem = $row;
}

// ── Dados para gráficos ───────────────────────────────
$evolucao = $db->query("
    SELECT DATE_FORMAT(data, '%Y-%m') AS mes,
           DATE_FORMAT(data, '%b/%Y') AS mes_label,
           SUM(CASE WHEN tipo='receita' THEN valor ELSE 0 END) AS receitas,
           SUM(CASE WHEN tipo='gasto'   THEN valor ELSE 0 END) AS gastos
    FROM lancamentos
    WHERE data >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY mes, mes_label
    ORDER BY mes ASC
")->fetchAll();

$porCategoria = $db->query("
    SELECT COALESCE(c.nome,'Sem categoria') AS nome,
           COALESCE(c.icone,'📦') AS icone,
           SUM(l.valor) AS total
    FROM lancamentos l
    LEFT JOIN categorias c ON c.id = l.categoria_id
    WHERE l.tipo = 'gasto'
    GROUP BY c.id, c.nome, c.icone
    ORDER BY total DESC
    LIMIT 6
")->fetchAll();

// ── Melhorias: maiores gastos do mês ─────────────────
$maioresGastos = $db->query("
    SELECT descricao, valor, c.icone AS icone
    FROM lancamentos l
    LEFT JOIN categorias c ON c.id = l.categoria_id
    WHERE l.tipo = 'gasto'
      AND MONTH(l.data) = MONTH(CURDATE())
      AND YEAR(l.data)  = YEAR(CURDATE())
    ORDER BY valor DESC
    LIMIT 5
")->fetchAll();

// ── Melhorias: % dos gastos em relação à receita ──────
$percentGasto = $totalReceitas > 0 ? round(($totalGastos / $totalReceitas) * 100) : 0;

// ── Melhorias: último lançamento ─────────────────────
$ultimoLanc = $db->query("
    SELECT descricao, valor, tipo, data FROM lancamentos
    ORDER BY criado_em DESC LIMIT 1
")->fetch();

// ── Metas ─────────────────────────────────────────────
// CRUD de metas via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_meta') {
    $titulo      = trim($_POST['titulo'] ?? '');
    $descricao   = trim($_POST['meta_descricao'] ?? '') ?: null;
    $valor_meta  = floatval($_POST['valor_meta'] ?? 0);
    $valor_atual = floatval($_POST['valor_atual'] ?? 0);
    $icone       = trim($_POST['icone'] ?? '🎯');
    $prazo       = !empty($_POST['prazo']) ? $_POST['prazo'] : null;
    if (!empty($titulo) && $valor_meta > 0) {
        $stmt = $db->prepare("INSERT INTO metas (titulo,descricao,valor_meta,valor_atual,icone,prazo) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$titulo,$descricao,$valor_meta,$valor_atual,$icone,$prazo]);
    }
    header("Location: index.php?secao=metas&ok=" . urlencode("Meta criada!"));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_meta') {
    $id          = (int)($_POST['meta_id'] ?? 0);
    $valor_atual = floatval($_POST['valor_atual_upd'] ?? 0);
    if ($id > 0) {
        $stmt = $db->prepare("UPDATE metas SET valor_atual = ? WHERE id = ?");
        $stmt->execute([$valor_atual, $id]);
    }
    header("Location: index.php?secao=metas&ok=" . urlencode("Meta atualizada!"));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_meta') {
    $id          = (int)($_POST['meta_id'] ?? 0);
    $titulo      = trim($_POST['titulo'] ?? '');
    $descricao   = trim($_POST['meta_descricao'] ?? '') ?: null;
    $valor_meta  = floatval($_POST['valor_meta'] ?? 0);
    $valor_atual = floatval($_POST['valor_atual'] ?? 0);
    $icone       = trim($_POST['icone'] ?? '🎯');
    $prazo       = !empty($_POST['prazo']) ? $_POST['prazo'] : null;
    if ($id > 0 && !empty($titulo) && $valor_meta > 0) {
        $stmt = $db->prepare("UPDATE metas SET titulo=?,descricao=?,valor_meta=?,valor_atual=?,icone=?,prazo=? WHERE id=?");
        $stmt->execute([$titulo,$descricao,$valor_meta,$valor_atual,$icone,$prazo,$id]);
    }
    header("Location: index.php?secao=metas&ok=" . urlencode("Meta atualizada!"));
    exit;
}
if (isset($_GET['deletar_meta'])) {
    $id = (int)$_GET['deletar_meta'];
    $db->prepare("DELETE FROM metas WHERE id = ?")->execute([$id]);
    header("Location: index.php?secao=metas&ok=" . urlencode("Meta excluída!"));
    exit;
}

$metas    = $db->query("SELECT * FROM metas ORDER BY criado_em DESC")->fetchAll();
$secaoAtiva = $_GET['secao'] ?? 'dashboard';

// Meta em edição
$editMeta = null;
if (isset($_GET['editar_meta'])) {
    $id = (int)$_GET['editar_meta'];
    $stmt = $db->prepare("SELECT * FROM metas WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) $editMeta = $row;
    $secaoAtiva = 'metas';
}

// ── Contas a Pagar ────────────────────────────────────
// Atualiza status de contas vencidas automaticamente
$db->exec("UPDATE contas SET status='vencido' WHERE vencimento < CURDATE() AND status='pendente'");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_conta') {
    $descricao  = trim($_POST['conta_descricao'] ?? '');
    $valor      = floatval($_POST['conta_valor'] ?? 0);
    $vencimento = $_POST['conta_vencimento'] ?? '';
    $categoria  = trim($_POST['conta_categoria'] ?? '') ?: null;
    $recorrente = isset($_POST['recorrente']) ? 1 : 0;
    $observacao = trim($_POST['conta_obs'] ?? '') ?: null;
    if (!empty($descricao) && $valor > 0 && !empty($vencimento)) {
        $stmt = $db->prepare("INSERT INTO contas (descricao,valor,vencimento,categoria,recorrente,observacao) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$descricao,$valor,$vencimento,$categoria,$recorrente,$observacao]);
    }
    header("Location: index.php?secao=contas&ok=" . urlencode("Conta adicionada!"));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_conta') {
    $id         = (int)($_POST['conta_id'] ?? 0);
    $descricao  = trim($_POST['conta_descricao'] ?? '');
    $valor      = floatval($_POST['conta_valor'] ?? 0);
    $vencimento = $_POST['conta_vencimento'] ?? '';
    $categoria  = trim($_POST['conta_categoria'] ?? '') ?: null;
    $status     = $_POST['conta_status'] ?? 'pendente';
    $recorrente = isset($_POST['recorrente']) ? 1 : 0;
    $observacao = trim($_POST['conta_obs'] ?? '') ?: null;
    if ($id > 0 && !empty($descricao) && $valor > 0) {
        $stmt = $db->prepare("UPDATE contas SET descricao=?,valor=?,vencimento=?,categoria=?,status=?,recorrente=?,observacao=? WHERE id=?");
        $stmt->execute([$descricao,$valor,$vencimento,$categoria,$status,$recorrente,$observacao,$id]);
    }
    header("Location: index.php?secao=contas&ok=" . urlencode("Conta atualizada!"));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'pagar_conta') {
    $id = (int)($_POST['conta_id'] ?? 0);
    if ($id > 0) {
        $db->prepare("UPDATE contas SET status='pago' WHERE id=?")->execute([$id]);
        // Se recorrente, cria próximo mês
        $conta = $db->prepare("SELECT * FROM contas WHERE id=?");
        $conta->execute([$id]);
        $c = $conta->fetch();
        if ($c && $c['recorrente']) {
            $proxVenc = date('Y-m-d', strtotime($c['vencimento'] . ' +1 month'));
            $stmt = $db->prepare("INSERT INTO contas (descricao,valor,vencimento,categoria,recorrente,observacao) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$c['descricao'],$c['valor'],$proxVenc,$c['categoria'],1,$c['observacao']]);
        }
    }
    header("Location: index.php?secao=contas&ok=" . urlencode("Conta marcada como paga!"));
    exit;
}
if (isset($_GET['deletar_conta'])) {
    $id = (int)$_GET['deletar_conta'];
    $db->prepare("DELETE FROM contas WHERE id=?")->execute([$id]);
    header("Location: index.php?secao=contas&ok=" . urlencode("Conta excluída!"));
    exit;
}

$contas = $db->query("SELECT * FROM contas ORDER BY vencimento ASC")->fetchAll();
$contasPendentes = array_filter($contas, fn($c) => $c['status'] === 'pendente');
$contasVencidas  = array_filter($contas, fn($c) => $c['status'] === 'vencido');
$contasPagas     = array_filter($contas, fn($c) => $c['status'] === 'pago');
$totalPendente   = array_sum(array_column(array_merge($contasPendentes,$contasVencidas), 'valor'));

$editConta = null;
if (isset($_GET['editar_conta'])) {
    $id = (int)$_GET['editar_conta'];
    $stmt = $db->prepare("SELECT * FROM contas WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) { $editConta = $row; $secaoAtiva = 'contas'; }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FinanceOS — Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Space+Mono:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
/* =====================================================
   VARIÁVEIS & RESET
   ===================================================== */
:root {
  --bg:        #0a0a0f;
  --surface:   #13131a;
  --surface2:  #1c1c27;
  --border:    #2a2a3d;
  --accent:    #00e5a0;
  --accent2:   #ff4d6d;
  --accent3:   #7b61ff;
  --text:      #e8e8f0;
  --muted:     #6b6b85;
  --receita:   #00e5a0;
  --gasto:     #ff4d6d;
  --warn:      #f59e0b;
  --radius:    12px;
  --font-head: 'Syne', sans-serif;
  --font-mono: 'Space Mono', monospace;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--font-head);
  min-height: 100vh;
  overflow-x: hidden;
}
body::before {
  content: '';
  position: fixed; inset: 0;
  background-image:
    radial-gradient(ellipse 80% 50% at 20% -10%, rgba(0,229,160,.07) 0%, transparent 60%),
    radial-gradient(ellipse 60% 40% at 80% 110%, rgba(123,97,255,.06) 0%, transparent 60%);
  pointer-events: none; z-index: 0;
}
.wrapper {
  position: relative; z-index: 1;
  max-width: 1280px;
  margin: 0 auto;
  padding: 40px 24px 80px;
}

/* =====================================================
   HEADER
   ===================================================== */
header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 40px; padding-bottom: 24px;
  border-bottom: 1px solid var(--border);
}
.logo { display: flex; align-items: center; gap: 12px; }
.logo-icon {
  width: 40px; height: 40px; background: var(--accent);
  border-radius: 10px; display: grid; place-items: center; font-size: 20px;
}
.logo-text { font-size: 22px; font-weight: 800; letter-spacing: -.5px; }
.logo-text span { color: var(--accent); }
.header-right { display: flex; align-items: center; gap: 16px; }
.header-date { font-family: var(--font-mono); font-size: 12px; color: var(--muted); }

/* ── Alerta de saldo negativo ── */
.alert-bar {
  background: rgba(255,77,109,.08);
  border: 1px solid rgba(255,77,109,.3);
  border-radius: var(--radius);
  padding: 12px 20px;
  margin-bottom: 24px;
  display: flex; align-items: center; gap: 10px;
  font-size: 13px; color: var(--gasto);
  font-family: var(--font-mono);
}

/* =====================================================
   KPI CARDS
   ===================================================== */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px; margin-bottom: 28px;
}
@media (max-width: 900px) { .kpi-grid { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 500px) { .kpi-grid { grid-template-columns: 1fr; } }

.kpi-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 24px 20px;
  position: relative; overflow: hidden;
  transition: transform .2s, border-color .2s;
}
.kpi-card:hover { transform: translateY(-3px); border-color: var(--accent3); }
.kpi-card::after {
  content: ''; position: absolute;
  top: 0; left: 0; right: 0; height: 3px;
}
.kpi-card.receita::after { background: var(--receita); }
.kpi-card.gasto::after   { background: var(--gasto); }
.kpi-card.saldo::after   { background: var(--accent3); }
.kpi-card.percent::after { background: var(--warn); }

.kpi-label {
  font-family: var(--font-mono); font-size: 10px;
  letter-spacing: 2px; text-transform: uppercase;
  color: var(--muted); margin-bottom: 10px;
}
.kpi-value {
  font-size: 28px; font-weight: 800;
  letter-spacing: -1px; line-height: 1;
}
.kpi-value.receita  { color: var(--receita); }
.kpi-value.gasto    { color: var(--gasto); }
.kpi-value.saldo    { color: <?php echo $saldo >= 0 ? 'var(--receita)' : 'var(--gasto)'; ?>; }
.kpi-value.percent  { color: var(--warn); }
.kpi-sub { margin-top: 8px; font-family: var(--font-mono); font-size: 11px; color: var(--muted); }

/* barra de progresso do % gasto */
.progress-bar {
  margin-top: 10px; height: 4px;
  background: var(--surface2); border-radius: 4px; overflow: hidden;
}
.progress-fill {
  height: 100%; border-radius: 4px;
  background: <?php echo $percentGasto > 80 ? 'var(--gasto)' : ($percentGasto > 60 ? 'var(--warn)' : 'var(--receita)'); ?>;
  width: <?= min($percentGasto, 100) ?>%;
  transition: width 1s ease;
}

/* =====================================================
   LAYOUT PRINCIPAL (2 cols)
   ===================================================== */
.main-grid {
  display: grid;
  grid-template-columns: 1fr 360px;
  gap: 24px; align-items: start;
}
@media (max-width: 900px) { .main-grid { grid-template-columns: 1fr; } }

/* =====================================================
   PANEL
   ===================================================== */
.panel {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius); overflow: hidden;
}
.panel-header {
  padding: 18px 24px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
}
.panel-title { font-size: 13px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; }
.badge {
  font-family: var(--font-mono); font-size: 11px;
  padding: 3px 10px; border-radius: 20px;
  background: var(--surface2); color: var(--muted);
}

/* =====================================================
   SEARCH
   ===================================================== */
.search-wrap { padding: 14px 20px; border-bottom: 1px solid var(--border); }
.search-box { position: relative; display: flex; align-items: center; }
.search-icon { position: absolute; left: 12px; font-size: 13px; color: var(--muted); pointer-events: none; }
.search-input {
  width: 100%; background: var(--surface2);
  border: 1px solid var(--border); border-radius: 8px;
  padding: 9px 36px; color: var(--text);
  font-family: var(--font-head); font-size: 13px;
  outline: none; transition: border-color .2s, box-shadow .2s;
}
.search-input::placeholder { color: var(--muted); }
.search-input:focus { border-color: var(--accent3); box-shadow: 0 0 0 3px rgba(123,97,255,.12); }
.search-clear {
  position: absolute; right: 10px; background: none; border: none;
  color: var(--muted); cursor: pointer; font-size: 14px;
  padding: 2px 6px; border-radius: 4px; display: none; transition: color .15s;
}
.search-clear:hover { color: var(--text); }
.search-filter { display: flex; gap: 8px; margin-top: 10px; }
.filter-btn {
  font-family: var(--font-mono); font-size: 10px; letter-spacing: 1px;
  padding: 4px 12px; border-radius: 20px; border: 1px solid var(--border);
  background: transparent; color: var(--muted); cursor: pointer;
  transition: all .15s; text-transform: uppercase;
}
.filter-btn.active     { border-color: var(--text);    color: var(--text);    background: var(--surface2); }
.filter-btn.active-rec { border-color: var(--receita); color: var(--receita); background: rgba(0,229,160,.08); }
.filter-btn.active-gas { border-color: var(--gasto);   color: var(--gasto);   background: rgba(255,77,109,.08); }
.no-results { padding: 40px 24px; text-align: center; color: var(--muted); font-family: var(--font-mono); font-size: 12px; display: none; }

/* =====================================================
   TABLE
   ===================================================== */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead th {
  font-family: var(--font-mono); font-size: 10px;
  letter-spacing: 2px; text-transform: uppercase; color: var(--muted);
  padding: 12px 20px; text-align: left; border-bottom: 1px solid var(--border);
}
tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: var(--surface2); }
tbody td { padding: 14px 20px; font-size: 13px; }
.td-desc { font-weight: 600; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pill {
  display: inline-flex; align-items: center; gap: 6px;
  font-family: var(--font-mono); font-size: 10px;
  padding: 3px 10px; border-radius: 20px; font-weight: 700;
}
.pill.receita { background: rgba(0,229,160,.12); color: var(--receita); }
.pill.gasto   { background: rgba(255,77,109,.12); color: var(--gasto); }
.pill::before { content: '●'; font-size: 7px; }
.td-valor { font-family: var(--font-mono); font-weight: 700; font-size: 14px; }
.td-valor.receita { color: var(--receita); }
.td-valor.gasto   { color: var(--gasto); }
.actions { display: flex; gap: 8px; }
.btn-icon {
  width: 30px; height: 30px; border-radius: 7px;
  border: 1px solid var(--border); background: transparent;
  color: var(--muted); cursor: pointer; display: grid;
  place-items: center; font-size: 13px; transition: all .15s; text-decoration: none;
}
.btn-icon.edit:hover   { border-color: var(--accent3); color: var(--accent3); background: rgba(123,97,255,.1); }
.btn-icon.delete:hover { border-color: var(--gasto); color: var(--gasto); background: rgba(255,77,109,.1); }
.empty-state { padding: 60px 24px; text-align: center; color: var(--muted); font-family: var(--font-mono); font-size: 13px; }
.empty-state .icon { font-size: 40px; margin-bottom: 12px; display: block; }

/* =====================================================
   FORM
   ===================================================== */
.form-panel { padding: 20px; }
.form-group { margin-bottom: 14px; }
label {
  display: block; font-family: var(--font-mono); font-size: 10px;
  letter-spacing: 2px; text-transform: uppercase; color: var(--muted); margin-bottom: 7px;
}
input[type="text"],
input[type="number"],
input[type="date"],
select {
  width: 100%; background: var(--surface2); border: 1px solid var(--border);
  border-radius: 8px; padding: 11px 13px; color: var(--text);
  font-family: var(--font-head); font-size: 13px; outline: none;
  transition: border-color .2s, box-shadow .2s; appearance: none;
}
input:focus, select:focus { border-color: var(--accent3); box-shadow: 0 0 0 3px rgba(123,97,255,.12); }
select option { background: var(--surface2); }
.tipo-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.tipo-option input { display: none; }
.tipo-label {
  display: block; padding: 11px; border-radius: 8px;
  border: 1px solid var(--border); text-align: center;
  cursor: pointer; font-size: 13px; font-weight: 600; transition: all .2s;
}
.tipo-option input:checked + .tipo-label.receita-label { background: rgba(0,229,160,.15); border-color: var(--receita); color: var(--receita); }
.tipo-option input:checked + .tipo-label.gasto-label   { background: rgba(255,77,109,.15); border-color: var(--gasto);   color: var(--gasto); }
.tipo-label:hover { border-color: var(--muted); }
.btn-submit {
  width: 100%; padding: 13px; border-radius: 8px; border: none;
  font-family: var(--font-head); font-size: 14px; font-weight: 700;
  cursor: pointer; transition: all .2s; margin-top: 8px;
}
.btn-submit.add        { background: var(--accent); color: #0a0a0f; }
.btn-submit.add:hover  { background: #00ffb3; transform: translateY(-1px); }
.btn-submit.edit-mode  { background: var(--accent3); color: #fff; }
.btn-submit.edit-mode:hover { background: #9580ff; transform: translateY(-1px); }
.btn-cancel {
  width: 100%; padding: 11px; border-radius: 8px; border: 1px solid var(--border);
  background: transparent; color: var(--muted); font-family: var(--font-head);
  font-size: 13px; cursor: pointer; margin-top: 8px; text-decoration: none;
  display: block; text-align: center; transition: all .2s;
}
.btn-cancel:hover { border-color: var(--muted); color: var(--text); }

/* ── Maiores gastos do mês (sidebar) ── */
.top-gastos { padding: 16px 20px; border-top: 1px solid var(--border); }
.top-gastos-title { font-family: var(--font-mono); font-size: 10px; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); margin-bottom: 12px; }
.gasto-item { display: flex; align-items: center; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid var(--border); }
.gasto-item:last-child { border-bottom: none; }
.gasto-item-name { font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 6px; max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.gasto-item-val  { font-family: var(--font-mono); font-size: 11px; color: var(--gasto); }

/* ── Observação ── */
textarea {
  width: 100%; background: var(--surface2); border: 1px solid var(--border);
  border-radius: 8px; padding: 11px 13px; color: var(--text);
  font-family: var(--font-head); font-size: 13px; outline: none;
  resize: vertical; min-height: 70px;
  transition: border-color .2s, box-shadow .2s;
}
textarea:focus { border-color: var(--accent3); box-shadow: 0 0 0 3px rgba(123,97,255,.12); }

/* =====================================================
   CHARTS SECTION (abaixo do grid)
   ===================================================== */
.charts-section { margin-top: 24px; }
.charts-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}
@media (max-width: 800px) { .charts-grid { grid-template-columns: 1fr; } }

.chart-panel {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); overflow: hidden;
}
.chart-full { grid-column: 1 / -1; }
.chart-header {
  padding: 14px 20px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
}
.chart-title { font-size: 11px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; color: var(--muted); }
.chart-body { padding: 20px; }
.chart-body canvas { display: block; }

/* =====================================================
   TOAST
   ===================================================== */
.toast {
  position: fixed; bottom: 32px; right: 32px;
  background: var(--surface2); border: 1px solid var(--accent);
  border-radius: 10px; padding: 14px 20px; font-size: 13px;
  font-family: var(--font-mono); color: var(--accent);
  display: flex; align-items: center; gap: 10px; z-index: 999;
  animation: toastIn .3s ease, toastOut .3s ease 2.8s forwards;
}
@keyframes toastIn  { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:none; } }
@keyframes toastOut { to   { opacity:0; transform:translateY(16px); } }
/* =====================================================
   NAV TABS
   ===================================================== */
.nav-tabs {
  display: flex; gap: 8px; margin-bottom: 32px;
  border-bottom: 1px solid var(--border);
  padding-bottom: 0;
}
.nav-tab {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 20px; border-radius: 8px 8px 0 0;
  border: 1px solid transparent; border-bottom: none;
  background: transparent; color: var(--muted);
  font-family: var(--font-head); font-size: 13px; font-weight: 600;
  cursor: pointer; transition: all .2s; text-decoration: none;
  position: relative; bottom: -1px;
}
.nav-tab:hover { color: var(--text); background: var(--surface2); }
.nav-tab.active {
  background: var(--surface); color: var(--text);
  border-color: var(--border); border-bottom-color: var(--surface);
}
.nav-tab.active.graficos { color: var(--accent3); }
.nav-tab.active.metas    { color: var(--accent); }
.nav-tab .tab-dot {
  width: 6px; height: 6px; border-radius: 50%;
}
.nav-tab.graficos .tab-dot { background: var(--accent3); }
.nav-tab.metas    .tab-dot { background: var(--accent); }
.nav-tab.dashboard .tab-dot { background: var(--muted); }

/* =====================================================
   METAS DASHBOARD
   ===================================================== */
.metas-section { display: none; }
.metas-section.visible { display: block; }

.metas-kpi-grid {
  display: grid; grid-template-columns: repeat(3,1fr);
  gap: 16px; margin-bottom: 28px;
}
@media(max-width:700px){ .metas-kpi-grid { grid-template-columns:1fr; } }

.metas-main-grid {
  display: grid; grid-template-columns: 1fr 360px;
  gap: 24px; align-items: start;
}
@media(max-width:900px){ .metas-main-grid { grid-template-columns:1fr; } }

.metas-list { display: flex; flex-direction: column; gap: 12px; }

.meta-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 20px 22px;
  transition: border-color .2s, transform .2s;
  position: relative; overflow: hidden;
}
.meta-card:hover { border-color: var(--accent3); transform: translateY(-2px); }
.meta-card.concluida { border-color: rgba(0,229,160,.4); }
.meta-card.concluida::before {
  content: '✓ CONCLUÍDA';
  position: absolute; top: 10px; right: 12px;
  font-family: var(--font-mono); font-size: 9px; letter-spacing: 1px;
  color: var(--receita); background: rgba(0,229,160,.1);
  padding: 2px 8px; border-radius: 20px;
}

.meta-top { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 14px; }
.meta-icon-wrap {
  width: 42px; height: 42px; border-radius: 10px;
  background: var(--surface2); border: 1px solid var(--border);
  display: grid; place-items: center; font-size: 20px; flex-shrink: 0;
}
.meta-info { flex: 1; min-width: 0; }
.meta-titulo { font-size: 14px; font-weight: 700; margin-bottom: 3px; }
.meta-desc { font-size: 12px; color: var(--muted); }
.meta-prazo { font-family: var(--font-mono); font-size: 10px; color: var(--warn); margin-top: 3px; }
.meta-actions-top { display: flex; gap: 6px; flex-shrink: 0; }

.meta-progress-row {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px;
}
.meta-valores { display: flex; align-items: baseline; gap: 6px; }
.meta-atual { font-family: var(--font-mono); font-size: 18px; font-weight: 700; color: var(--accent); }
.meta-total { font-family: var(--font-mono); font-size: 12px; color: var(--muted); }
.meta-pct   { font-family: var(--font-mono); font-size: 12px; color: var(--muted); }

.meta-bar {
  height: 8px; background: var(--surface2);
  border-radius: 8px; overflow: hidden; margin-bottom: 12px;
}
.meta-fill {
  height: 100%; border-radius: 8px;
  transition: width 1s cubic-bezier(.4,0,.2,1);
}

.meta-update-form {
  display: flex; gap: 8px; align-items: center;
  border-top: 1px solid var(--border); padding-top: 12px;
}
.meta-update-input {
  flex: 1; background: var(--surface2); border: 1px solid var(--border);
  border-radius: 7px; padding: 8px 12px; color: var(--text);
  font-family: var(--font-mono); font-size: 12px; outline: none;
  transition: border-color .2s;
}
.meta-update-input:focus { border-color: var(--accent3); }
.meta-update-btn {
  padding: 8px 14px; border-radius: 7px; border: none;
  background: var(--accent3); color: #fff; font-family: var(--font-head);
  font-size: 12px; font-weight: 700; cursor: pointer; transition: background .2s;
  white-space: nowrap;
}
.meta-update-btn:hover { background: #9580ff; }

/* form nova meta */
.nova-meta-form { padding: 20px; }
.nova-meta-form .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

/* icone picker */
.icone-picker { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.icone-opt input { display: none; }
.icone-opt span {
  display: block; width: 36px; height: 36px;
  border-radius: 8px; border: 1px solid var(--border);
  display: grid; place-items: center; font-size: 18px;
  cursor: pointer; transition: all .15s;
}
.icone-opt input:checked + span { border-color: var(--accent3); background: rgba(123,97,255,.15); }
.icone-opt span:hover { border-color: var(--muted); }

/* empty metas */
.metas-empty {
  text-align: center; padding: 60px 20px;
  color: var(--muted); font-family: var(--font-mono); font-size: 13px;
}
.metas-empty .icon { font-size: 48px; display: block; margin-bottom: 12px; }

/* =====================================================
   FOOTER
   ===================================================== */
footer {
  margin-top: 60px; padding: 28px 24px;
  border-top: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 16px;
}
.footer-brand { display: flex; align-items: center; gap: 10px; }
.footer-logo-icon {
  width: 28px; height: 28px; background: var(--accent);
  border-radius: 7px; display: grid; place-items: center; font-size: 14px;
}
.footer-brand-name { font-size: 14px; font-weight: 700; }
.footer-brand-name span { color: var(--accent); }
.footer-center {
  font-family: var(--font-mono); font-size: 11px; color: var(--muted);
  text-align: center;
}
.footer-center strong { color: var(--text); }
.footer-right {
  font-family: var(--font-mono); font-size: 11px; color: var(--muted);
  display: flex; align-items: center; gap: 8px;
}
.footer-dot { color: var(--accent); }

/* =====================================================
   CONTAS A PAGAR
   ===================================================== */
.contas-kpi-grid {
  display: grid; grid-template-columns: repeat(4,1fr);
  gap: 16px; margin-bottom: 28px;
}
@media(max-width:900px){ .contas-kpi-grid { grid-template-columns: repeat(2,1fr); } }
@media(max-width:500px){ .contas-kpi-grid { grid-template-columns: 1fr; } }

.status-pill {
  display: inline-flex; align-items: center; gap: 5px;
  font-family: var(--font-mono); font-size: 10px; font-weight: 700;
  padding: 3px 10px; border-radius: 20px;
}
.status-pill.pendente { background: rgba(245,158,11,.12); color: var(--warn); }
.status-pill.vencido  { background: rgba(255,77,109,.12);  color: var(--gasto); }
.status-pill.pago     { background: rgba(0,229,160,.12);   color: var(--receita); }
.status-pill::before  { content: '●'; font-size: 7px; }

.btn-pagar {
  padding: 5px 12px; border-radius: 7px; border: 1px solid var(--receita);
  background: rgba(0,229,160,.08); color: var(--receita);
  font-family: var(--font-mono); font-size: 10px; font-weight: 700;
  cursor: pointer; transition: all .15s; white-space: nowrap;
}
.btn-pagar:hover { background: rgba(0,229,160,.2); }

/* =====================================================
   CÂMERA / NOTA FISCAL
   ===================================================== */
.camera-btn {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 18px; border-radius: 8px;
  border: 1px solid var(--accent3); background: rgba(123,97,255,.08);
  color: var(--accent3); font-family: var(--font-head);
  font-size: 13px; font-weight: 600; cursor: pointer;
  transition: all .2s; text-decoration: none;
}
.camera-btn:hover { background: rgba(123,97,255,.18); transform: translateY(-1px); }

.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.75); z-index: 1000;
  align-items: center; justify-content: center;
  backdrop-filter: blur(4px);
}
.modal-overlay.open { display: flex; }

.modal {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 16px; width: 100%; max-width: 520px;
  margin: 16px; overflow: hidden;
  animation: modalIn .25s cubic-bezier(.34,1.56,.64,1);
}
@keyframes modalIn { from { opacity:0; transform:scale(.92) translateY(16px); } to { opacity:1; transform:none; } }

.modal-header {
  padding: 18px 22px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
}
.modal-title { font-size: 15px; font-weight: 700; }
.modal-close {
  width: 30px; height: 30px; border-radius: 7px; border: 1px solid var(--border);
  background: transparent; color: var(--muted); cursor: pointer;
  display: grid; place-items: center; font-size: 16px; transition: all .15s;
}
.modal-close:hover { border-color: var(--gasto); color: var(--gasto); }

.modal-body { padding: 22px; }

.camera-area {
  position: relative; width: 100%; aspect-ratio: 4/3;
  background: #000; border-radius: 10px; overflow: hidden;
  margin-bottom: 16px; display: flex; align-items: center; justify-content: center;
}
.camera-area video, .camera-area canvas, .camera-area img {
  width: 100%; height: 100%; object-fit: cover; border-radius: 10px;
}
.camera-area .camera-placeholder {
  color: var(--muted); font-family: var(--font-mono); font-size: 12px;
  text-align: center; padding: 20px;
}
.camera-placeholder .cam-icon { font-size: 48px; display: block; margin-bottom: 10px; }

.camera-controls { display: flex; gap: 10px; flex-wrap: wrap; }
.camera-controls button, .camera-controls label {
  flex: 1; padding: 11px; border-radius: 8px; border: 1px solid var(--border);
  background: var(--surface2); color: var(--text); font-family: var(--font-head);
  font-size: 13px; font-weight: 600; cursor: pointer; transition: all .2s;
  text-align: center; display: flex; align-items: center; justify-content: center; gap: 6px;
}
.camera-controls button:hover, .camera-controls label:hover { border-color: var(--accent3); color: var(--accent3); }
.camera-controls button.primary { background: var(--accent3); border-color: var(--accent3); color: #fff; }
.camera-controls button.primary:hover { background: #9580ff; }
.camera-controls input[type="file"] { display: none; }

.ai-result {
  margin-top: 16px; padding: 16px; border-radius: 10px;
  border: 1px solid var(--border); background: var(--surface2);
  display: none;
}
.ai-result.visible { display: block; }
.ai-result-title { font-family: var(--font-mono); font-size: 10px; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); margin-bottom: 10px; }
.ai-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 10px; }
.ai-field label { font-family: var(--font-mono); font-size: 10px; letter-spacing: 1px; text-transform: uppercase; color: var(--muted); }
.ai-field input {
  background: var(--surface); border: 1px solid var(--border); border-radius: 7px;
  padding: 9px 12px; color: var(--text); font-family: var(--font-head); font-size: 13px; outline: none;
  transition: border-color .2s;
}
.ai-field input:focus { border-color: var(--accent3); }
.ai-loading {
  display: none; text-align: center; padding: 20px;
  font-family: var(--font-mono); font-size: 12px; color: var(--muted);
}
.ai-loading.visible { display: block; }
.ai-spinner {
  width: 28px; height: 28px; border: 2px solid var(--border);
  border-top-color: var(--accent3); border-radius: 50%;
  animation: spin .7s linear infinite; margin: 0 auto 10px;
}
@keyframes spin { to { transform: rotate(360deg); } }

.btn-add-nf {
  width: 100%; padding: 12px; border-radius: 8px; border: none;
  background: var(--accent); color: #0a0a0f;
  font-family: var(--font-head); font-size: 14px; font-weight: 700;
  cursor: pointer; transition: all .2s; margin-top: 8px;
}
.btn-add-nf:hover { background: #00ffb3; }

</style>
</head>
<body>
<div class="wrapper">

  <!-- HEADER -->
  <header>
    <div class="logo">
      <div class="logo-icon">💹</div>
      <div class="logo-text">Finance<span>OS</span></div>
    </div>
    <div class="header-right">
      <?php if ($ultimoLanc): ?>
      <span style="font-family:var(--font-mono);font-size:11px;color:var(--muted)">
        Último: <span style="color:var(--text)"><?= htmlspecialchars($ultimoLanc['descricao']) ?></span>
        — <?= date('d/m', strtotime($ultimoLanc['data'])) ?>
      </span>
      <?php endif; ?>
      <div class="header-date" id="hdate"></div>
    </div>
  </header>

  <?php if ($saldo < 0): ?>
  <div class="alert-bar">⚠️ Atenção: seu saldo está negativo! Os gastos superam as receitas em R$ <?= number_format(abs($saldo), 2, ',', '.') ?>.</div>
  <?php endif; ?>

  <!-- NAV TABS -->
  <nav class="nav-tabs">
    <a href="?secao=dashboard" class="nav-tab dashboard <?= $secaoAtiva==='dashboard' ? 'active' : '' ?>">
      <span class="tab-dot"></span> Dashboard
    </a>
    <a href="?secao=graficos" class="nav-tab graficos <?= $secaoAtiva==='graficos' ? 'active' : '' ?>">
      <span class="tab-dot"></span> 📊 Gráficos
    </a>
    <a href="?secao=metas" class="nav-tab metas <?= $secaoAtiva==='metas' ? 'active' : '' ?>">
      <span class="tab-dot"></span> 🎯 Metas
    </a>
    <a href="?secao=contas" class="nav-tab contas <?= $secaoAtiva==='contas' ? 'active' : '' ?>"
       style="--dot-color:#f59e0b">
      <span class="tab-dot" style="background:var(--warn)"></span> 📅 Contas a Pagar
    </a>
    <button class="camera-btn" id="btnCamera" style="margin-left:auto">
      📷 Nota Fiscal
    </button>
  </nav>

  <!-- ================================================
       SEÇÃO: DASHBOARD
       ================================================ -->
  <div id="secao-dashboard" <?= $secaoAtiva !== 'dashboard' ? 'style="display:none"' : '' ?>>

  <!-- KPIs (4 cards) -->
  <div class="kpi-grid">
    <div class="kpi-card receita">
      <div class="kpi-label">Total Receitas</div>
      <div class="kpi-value receita">R$ <?= number_format($totalReceitas, 2, ',', '.') ?></div>
      <div class="kpi-sub"><?= count(array_filter($dados, fn($d) => $d['tipo']==='receita')) ?> lançamento(s)</div>
    </div>
    <div class="kpi-card gasto">
      <div class="kpi-label">Total Gastos</div>
      <div class="kpi-value gasto">R$ <?= number_format($totalGastos, 2, ',', '.') ?></div>
      <div class="kpi-sub"><?= count(array_filter($dados, fn($d) => $d['tipo']==='gasto')) ?> lançamento(s)</div>
    </div>
    <div class="kpi-card saldo">
      <div class="kpi-label">Saldo Final</div>
      <div class="kpi-value saldo"><?= $saldo < 0 ? '−' : '' ?>R$ <?= number_format(abs($saldo), 2, ',', '.') ?></div>
      <div class="kpi-sub"><?= $saldo >= 0 ? '✓ Positivo' : '⚠ Negativo' ?></div>
    </div>
    <div class="kpi-card percent">
      <div class="kpi-label">Gastos / Receita</div>
      <div class="kpi-value percent"><?= $percentGasto ?>%</div>
      <div class="progress-bar"><div class="progress-fill"></div></div>
      <div class="kpi-sub"><?= $percentGasto > 80 ? '⚠ Alto comprometimento' : ($percentGasto > 60 ? '⚡ Atenção' : '✓ Saudável') ?></div>
    </div>
  </div>

  <!-- MAIN GRID -->
  <div class="main-grid">

    <!-- TABELA DE LANÇAMENTOS -->
    <div class="panel">
      <div class="panel-header">
        <span class="panel-title">Lançamentos</span>
        <span class="badge" id="count-badge"><?= count($dados) ?> registros</span>
      </div>
      <div class="search-wrap">
        <div class="search-box">
          <span class="search-icon">🔍</span>
          <input type="text" class="search-input" id="searchInput" placeholder="Buscar por descrição, categoria...">
          <button class="search-clear" id="searchClear" title="Limpar">✕</button>
        </div>
        <div class="search-filter">
          <button class="filter-btn active" data-filter="todos">Todos</button>
          <button class="filter-btn" data-filter="receita">Receitas</button>
          <button class="filter-btn" data-filter="gasto">Gastos</button>
        </div>
      </div>
      <div class="table-wrap">
        <?php if (empty($dados)): ?>
          <div class="empty-state">
            <span class="icon">📭</span>
            Nenhum lançamento ainda.<br>Adicione sua primeira transação ao lado.
          </div>
        <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Descrição</th>
              <th>Categoria</th>
              <th>Data</th>
              <th>Tipo</th>
              <th>Valor</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dados as $i => $item): ?>
            <tr>
              <td style="font-family:var(--font-mono);font-size:11px;color:var(--muted)"><?= str_pad($i+1,2,'0',STR_PAD_LEFT) ?></td>
              <td class="td-desc"><?= htmlspecialchars($item['descricao']) ?></td>
              <td style="font-size:12px;color:var(--muted)"><?= $item['categoria_icone'] ?? '📦' ?> <?= htmlspecialchars($item['categoria_nome'] ?? '—') ?></td>
              <td style="font-family:var(--font-mono);font-size:11px;color:var(--muted)"><?= date('d/m/Y', strtotime($item['data'])) ?></td>
              <td><span class="pill <?= $item['tipo'] ?>"><?= ucfirst($item['tipo']) ?></span></td>
              <td class="td-valor <?= $item['tipo'] ?>"><?= $item['tipo']==='gasto' ? '−' : '+' ?>R$ <?= number_format($item['valor'],2,',','.') ?></td>
              <td>
                <div class="actions">
                  <a href="?editar=<?= $item['id'] ?>" class="btn-icon edit" title="Editar"
                     onclick="setTimeout(()=>document.getElementById('formPanel').scrollIntoView({behavior:'smooth'}),100)">✏️</a>
                  <a href="actions.php?deletar=<?= $item['id'] ?>" class="btn-icon delete" title="Excluir"
                     onclick="return confirm('Excluir: <?= addslashes(htmlspecialchars($item['descricao'])) ?>?')">🗑️</a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
        <div class="no-results" id="noResults">🔎 Nenhum resultado encontrado.</div>
      </div>
    </div>

    <!-- FORMULÁRIO + MAIORES GASTOS -->
    <div class="panel" id="formPanel">
      <div class="panel-header">
        <span class="panel-title"><?= $editItem ? 'Editar Lançamento' : 'Novo Lançamento' ?></span>
        <?php if ($editItem): ?>
          <span class="badge" style="color:var(--accent3);border-color:var(--accent3)">Editando #<?= $editItem['id'] ?></span>
        <?php endif; ?>
      </div>
      <div class="form-panel">
        <form method="POST" action="actions.php">
          <?php if ($editItem): ?>
            <input type="hidden" name="id"   value="<?= $editItem['id'] ?>">
            <input type="hidden" name="acao" value="editar">
          <?php else: ?>
            <input type="hidden" name="acao" value="criar">
          <?php endif; ?>

          <div class="form-group">
            <label>Descrição</label>
            <input type="text" name="descricao" placeholder="Ex: Salário, Aluguel..."
              value="<?= $editItem ? htmlspecialchars($editItem['descricao']) : '' ?>" required>
          </div>

          <div class="form-group">
            <label>Valor (R$)</label>
            <input type="number" name="valor" step="0.01" min="0.01" placeholder="0,00"
              value="<?= $editItem ? $editItem['valor'] : '' ?>" required>
          </div>

          <div class="form-group">
            <label>Data</label>
            <input type="date" name="data"
              value="<?= $editItem ? $editItem['data'] : date('Y-m-d') ?>" required>
          </div>

          <div class="form-group">
            <label>Categoria</label>
            <select name="categoria_id">
              <option value="">— Sem categoria —</option>
              <?php foreach ($categorias as $cat): ?>
                <option value="<?= $cat['id'] ?>"
                  <?= ($editItem && $editItem['categoria_id'] == $cat['id']) ? 'selected' : '' ?>>
                  <?= $cat['icone'] ?> <?= htmlspecialchars($cat['nome']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label>Observação (opcional)</label>
            <textarea name="observacao" placeholder="Alguma nota sobre este lançamento..."><?= $editItem ? htmlspecialchars($editItem['observacao'] ?? '') : '' ?></textarea>
          </div>

          <div class="form-group">
            <label>Tipo</label>
            <div class="tipo-grid">
              <label class="tipo-option">
                <input type="radio" name="tipo" value="receita"
                  <?= (!$editItem || $editItem['tipo']==='receita') ? 'checked' : '' ?>>
                <span class="tipo-label receita-label">📈 Receita</span>
              </label>
              <label class="tipo-option">
                <input type="radio" name="tipo" value="gasto"
                  <?= ($editItem && $editItem['tipo']==='gasto') ? 'checked' : '' ?>>
                <span class="tipo-label gasto-label">📉 Gasto</span>
              </label>
            </div>
          </div>

          <button type="submit" class="btn-submit <?= $editItem ? 'edit-mode' : 'add' ?>">
            <?= $editItem ? '💾 Salvar Alterações' : '+ Adicionar Lançamento' ?>
          </button>

          <?php if ($editItem): ?>
            <a href="index.php" class="btn-cancel">✕ Cancelar edição</a>
          <?php endif; ?>
        </form>
      </div>

      <!-- Maiores gastos do mês -->
      <?php if (!empty($maioresGastos)): ?>
      <div class="top-gastos">
        <div class="top-gastos-title">🔥 Maiores gastos do mês</div>
        <?php foreach ($maioresGastos as $g): ?>
        <div class="gasto-item">
          <span class="gasto-item-name"><?= $g['icone'] ?? '📦' ?> <?= htmlspecialchars($g['descricao']) ?></span>
          <span class="gasto-item-val">−R$ <?= number_format($g['valor'],2,',','.') ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /main-grid -->
  </div><!-- /secao-dashboard -->

  <!-- ================================================
       SEÇÃO: GRÁFICOS
       ================================================ -->
  <div id="secao-graficos" <?= $secaoAtiva !== 'graficos' ? 'style="display:none"' : '' ?>>
    <div class="charts-grid" style="margin-top:8px;">

      <div class="chart-panel chart-full">
        <div class="chart-header">
          <span class="chart-title">📈 Evolução Mensal — Receitas vs Gastos</span>
          <span class="badge">últimos 6 meses</span>
        </div>
        <div class="chart-body"><canvas id="chartEvolucao" height="80"></canvas></div>
      </div>

      <div class="chart-panel">
        <div class="chart-header"><span class="chart-title">📊 Comparativo por Mês</span></div>
        <div class="chart-body"><canvas id="chartComparativo" height="180"></canvas></div>
      </div>

      <div class="chart-panel">
        <div class="chart-header"><span class="chart-title">🍩 Gastos por Categoria</span></div>
        <div class="chart-body"><canvas id="chartCategoria" height="180"></canvas></div>
      </div>

    </div>
  </div><!-- /secao-graficos -->

  <!-- ================================================
       SEÇÃO: METAS
       ================================================ -->
  <div id="secao-metas" <?= $secaoAtiva !== 'metas' ? 'style="display:none"' : '' ?>>

    <?php
      $metasConcluidas = count(array_filter($metas, fn($m) => $m['valor_atual'] >= $m['valor_meta']));
      $metasAtivas     = count($metas) - $metasConcluidas;
      $totalMeta       = array_sum(array_column($metas, 'valor_meta'));
      $totalAtual      = array_sum(array_column($metas, 'valor_atual'));
      $pctGeral        = $totalMeta > 0 ? round(($totalAtual / $totalMeta) * 100) : 0;
    ?>

    <!-- KPIs de Metas -->
    <div class="metas-kpi-grid">
      <div class="kpi-card" style="--after-bg:#7b61ff;">
        <div style="position:absolute;top:0;left:0;right:0;height:3px;background:var(--accent3)"></div>
        <div class="kpi-label">Total de Metas</div>
        <div class="kpi-value" style="color:var(--accent3)"><?= count($metas) ?></div>
        <div class="kpi-sub"><?= $metasAtivas ?> ativa(s) · <?= $metasConcluidas ?> concluída(s)</div>
      </div>
      <div class="kpi-card">
        <div style="position:absolute;top:0;left:0;right:0;height:3px;background:var(--accent)"></div>
        <div class="kpi-label">Progresso Geral</div>
        <div class="kpi-value" style="color:var(--accent)"><?= $pctGeral ?>%</div>
        <div class="progress-bar"><div class="progress-fill" style="width:<?= min($pctGeral,100) ?>%;background:var(--accent)"></div></div>
        <div class="kpi-sub">R$ <?= number_format($totalAtual,2,',','.') ?> de R$ <?= number_format($totalMeta,2,',','.') ?></div>
      </div>
      <div class="kpi-card">
        <div style="position:absolute;top:0;left:0;right:0;height:3px;background:var(--warn)"></div>
        <div class="kpi-label">Falta Acumular</div>
        <div class="kpi-value" style="color:var(--warn)">R$ <?= number_format(max($totalMeta-$totalAtual,0),2,',','.') ?></div>
        <div class="kpi-sub">para atingir todas as metas</div>
      </div>
    </div>

    <!-- Grid metas -->
    <div class="metas-main-grid">

      <!-- Lista de metas -->
      <div>
        <?php if (empty($metas)): ?>
        <div class="metas-empty panel">
          <span class="icon">🎯</span>
          Nenhuma meta criada ainda.<br>Adicione sua primeira meta ao lado!
        </div>
        <?php else: ?>
        <div class="metas-list">
          <?php foreach ($metas as $m):
            $pct      = $m['valor_meta'] > 0 ? min(round(($m['valor_atual'] / $m['valor_meta']) * 100), 100) : 0;
            $concluida = $m['valor_atual'] >= $m['valor_meta'];
            $cor       = $concluida ? '#00e5a0' : ($pct >= 60 ? '#7b61ff' : ($pct >= 30 ? '#f59e0b' : '#ff4d6d'));
            $diasRestantes = null;
            if ($m['prazo']) {
              $diff = (new DateTime($m['prazo']))->diff(new DateTime())->days;
              $diasRestantes = (new DateTime()) < (new DateTime($m['prazo'])) ? $diff : -$diff;
            }
          ?>
          <div class="meta-card <?= $concluida ? 'concluida' : '' ?>">
            <div class="meta-top">
              <div class="meta-icon-wrap"><?= $m['icone'] ?></div>
              <div class="meta-info">
                <div class="meta-titulo"><?= htmlspecialchars($m['titulo']) ?></div>
                <?php if ($m['descricao']): ?>
                  <div class="meta-desc"><?= htmlspecialchars($m['descricao']) ?></div>
                <?php endif; ?>
                <?php if ($m['prazo']): ?>
                  <div class="meta-prazo">
                    <?php if ($diasRestantes >= 0): ?>
                      ⏱ <?= $diasRestantes ?> dia(s) restante(s) — <?= date('d/m/Y', strtotime($m['prazo'])) ?>
                    <?php else: ?>
                      ⚠️ Prazo vencido há <?= abs($diasRestantes) ?> dia(s)
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
              <div class="meta-actions-top">
                <a href="?secao=metas&editar_meta=<?= $m['id'] ?>" class="btn-icon edit" title="Editar meta">✏️</a>
                <a href="?secao=metas&deletar_meta=<?= $m['id'] ?>" class="btn-icon delete"
                   onclick="return confirm('Excluir a meta \'<?= addslashes(htmlspecialchars($m['titulo'])) ?>\'?')" title="Excluir">🗑️</a>
              </div>
            </div>

            <div class="meta-progress-row">
              <div class="meta-valores">
                <span class="meta-atual">R$ <?= number_format($m['valor_atual'],2,',','.') ?></span>
                <span class="meta-total">/ R$ <?= number_format($m['valor_meta'],2,',','.') ?></span>
              </div>
              <span class="meta-pct"><?= $pct ?>%</span>
            </div>
            <div class="meta-bar">
              <div class="meta-fill" style="width:<?= $pct ?>%;background:<?= $cor ?>"></div>
            </div>

            <?php if (!$concluida): ?>
            <form method="POST" class="meta-update-form">
              <input type="hidden" name="acao"    value="atualizar_meta">
              <input type="hidden" name="meta_id" value="<?= $m['id'] ?>">
              <input type="number" name="valor_atual_upd" class="meta-update-input"
                     placeholder="Novo valor acumulado" step="0.01" min="0"
                     value="<?= $m['valor_atual'] ?>">
              <button type="submit" class="meta-update-btn">💾 Atualizar</button>
            </form>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Formulário nova/editar meta -->
      <div class="panel" id="metaFormPanel">
        <div class="panel-header">
          <span class="panel-title"><?= $editMeta ? 'Editar Meta' : 'Nova Meta' ?></span>
          <?php if ($editMeta): ?>
            <span class="badge" style="color:var(--accent3);border-color:var(--accent3)">Editando #<?= $editMeta['id'] ?></span>
          <?php endif; ?>
        </div>
        <div class="nova-meta-form">
          <form method="POST">
            <input type="hidden" name="acao" value="<?= $editMeta ? 'editar_meta' : 'criar_meta' ?>">
            <?php if ($editMeta): ?>
              <input type="hidden" name="meta_id" value="<?= $editMeta['id'] ?>">
            <?php endif; ?>

            <div class="form-group">
              <label>Título da Meta</label>
              <input type="text" name="titulo" placeholder="Ex: Viagem, Reserva emergência..."
                value="<?= $editMeta ? htmlspecialchars($editMeta['titulo']) : '' ?>" required>
            </div>

            <div class="form-group">
              <label>Descrição (opcional)</label>
              <textarea name="meta_descricao" placeholder="Detalhes sobre a meta..." style="min-height:60px"><?= $editMeta ? htmlspecialchars($editMeta['descricao'] ?? '') : '' ?></textarea>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label>Valor da Meta (R$)</label>
                <input type="number" name="valor_meta" step="0.01" min="0.01" placeholder="0,00"
                  value="<?= $editMeta ? $editMeta['valor_meta'] : '' ?>" required>
              </div>
              <div class="form-group">
                <label>Já Acumulado (R$)</label>
                <input type="number" name="valor_atual" step="0.01" min="0" placeholder="0,00"
                  value="<?= $editMeta ? $editMeta['valor_atual'] : '0' ?>">
              </div>
            </div>

            <div class="form-group">
              <label>Prazo (opcional)</label>
              <input type="date" name="prazo" value="<?= $editMeta ? ($editMeta['prazo'] ?? '') : '' ?>">
            </div>

            <div class="form-group">
              <label>Ícone</label>
              <div class="icone-picker">
                <?php foreach(['🎯','🏠','✈️','🚗','📚','💊','💍','🖥️','🎮','🐾','💰','🏋️'] as $ic): ?>
                <label class="icone-opt">
                  <input type="radio" name="icone" value="<?= $ic ?>"
                    <?= ($editMeta ? $editMeta['icone'] === $ic : $ic === '🎯') ? 'checked' : '' ?>>
                  <span><?= $ic ?></span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>

            <button type="submit" class="btn-submit <?= $editMeta ? 'edit-mode' : 'add' ?>" style="margin-top:12px">
              <?= $editMeta ? '💾 Salvar Alterações' : '+ Criar Meta' ?>
            </button>

            <?php if ($editMeta): ?>
              <a href="?secao=metas" class="btn-cancel">✕ Cancelar edição</a>
            <?php endif; ?>
          </form>
        </div>
      </div>

    </div>
  </div><!-- /secao-metas -->

  <!-- ================================================
       SEÇÃO: CONTAS A PAGAR
       ================================================ -->
  <div id="secao-contas" <?= $secaoAtiva !== 'contas' ? 'style="display:none"' : '' ?>>

    <!-- KPIs Contas -->
    <div class="contas-kpi-grid">
      <div class="kpi-card" style="position:relative;overflow:hidden">
        <div style="position:absolute;top:0;left:0;right:0;height:3px;background:var(--warn)"></div>
        <div class="kpi-label">A Pagar</div>
        <div class="kpi-value" style="color:var(--warn)">R$ <?= number_format($totalPendente,2,',','.') ?></div>
        <div class="kpi-sub"><?= count($contasPendentes)+count($contasVencidas) ?> conta(s)</div>
      </div>
      <div class="kpi-card" style="position:relative;overflow:hidden">
        <div style="position:absolute;top:0;left:0;right:0;height:3px;background:var(--gasto)"></div>
        <div class="kpi-label">Vencidas</div>
        <div class="kpi-value" style="color:var(--gasto)"><?= count($contasVencidas) ?></div>
        <div class="kpi-sub">R$ <?= number_format(array_sum(array_column(array_values($contasVencidas),'valor')),2,',','.') ?></div>
      </div>
      <div class="kpi-card" style="position:relative;overflow:hidden">
        <div style="position:absolute;top:0;left:0;right:0;height:3px;background:var(--receita)"></div>
        <div class="kpi-label">Pagas</div>
        <div class="kpi-value" style="color:var(--receita)"><?= count($contasPagas) ?></div>
        <div class="kpi-sub">este mês</div>
      </div>
      <div class="kpi-card" style="position:relative;overflow:hidden">
        <div style="position:absolute;top:0;left:0;right:0;height:3px;background:var(--accent3)"></div>
        <div class="kpi-label">Total Contas</div>
        <div class="kpi-value" style="color:var(--accent3)"><?= count($contas) ?></div>
        <div class="kpi-sub"><?= count(array_filter($contas, fn($c)=>$c['recorrente'])) ?> recorrente(s)</div>
      </div>
    </div>

    <div class="main-grid">

      <!-- Tabela de contas -->
      <div class="panel">
        <div class="panel-header">
          <span class="panel-title">Contas</span>
          <span class="badge"><?= count($contas) ?> registros</span>
        </div>
        <div class="table-wrap">
          <?php if (empty($contas)): ?>
            <div class="empty-state">
              <span class="icon">📅</span>
              Nenhuma conta cadastrada ainda.
            </div>
          <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Descrição</th>
                <th>Categoria</th>
                <th>Vencimento</th>
                <th>Valor</th>
                <th>Status</th>
                <th>Ações</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($contas as $c):
                $hoje = new DateTime();
                $venc = new DateTime($c['vencimento']);
                $diff = (int)$hoje->diff($venc)->days * ($hoje > $venc ? -1 : 1);
              ?>
              <tr>
                <td>
                  <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($c['descricao']) ?></div>
                  <?php if ($c['recorrente']): ?><div style="font-family:var(--font-mono);font-size:10px;color:var(--accent3)">🔄 Recorrente</div><?php endif; ?>
                </td>
                <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($c['categoria'] ?? '—') ?></td>
                <td style="font-family:var(--font-mono);font-size:11px">
                  <?= date('d/m/Y', strtotime($c['vencimento'])) ?>
                  <?php if ($c['status']==='pendente' && $diff >= 0 && $diff <= 5): ?>
                    <div style="color:var(--warn);font-size:10px">⚡ <?= $diff ?> dia(s)</div>
                  <?php elseif ($c['status']==='vencido'): ?>
                    <div style="color:var(--gasto);font-size:10px">⚠ <?= abs($diff) ?>d atraso</div>
                  <?php endif; ?>
                </td>
                <td class="td-valor gasto">−R$ <?= number_format($c['valor'],2,',','.') ?></td>
                <td><span class="status-pill <?= $c['status'] ?>"><?= ucfirst($c['status']) ?></span></td>
                <td>
                  <div class="actions" style="gap:6px">
                    <?php if ($c['status'] !== 'pago'): ?>
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="acao" value="pagar_conta">
                      <input type="hidden" name="conta_id" value="<?= $c['id'] ?>">
                      <button type="submit" class="btn-pagar" title="Marcar como pago">✓ Pagar</button>
                    </form>
                    <?php endif; ?>
                    <a href="?secao=contas&editar_conta=<?= $c['id'] ?>" class="btn-icon edit" title="Editar">✏️</a>
                    <a href="?secao=contas&deletar_conta=<?= $c['id'] ?>" class="btn-icon delete" title="Excluir"
                       onclick="return confirm('Excluir conta \'<?= addslashes(htmlspecialchars($c['descricao'])) ?>\'?')">🗑️</a>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>

      <!-- Formulário conta -->
      <div class="panel" id="contaFormPanel">
        <div class="panel-header">
          <span class="panel-title"><?= $editConta ? 'Editar Conta' : 'Nova Conta' ?></span>
          <?php if ($editConta): ?>
            <span class="badge" style="color:var(--warn)">Editando #<?= $editConta['id'] ?></span>
          <?php endif; ?>
        </div>
        <div class="form-panel">
          <form method="POST">
            <input type="hidden" name="acao" value="<?= $editConta ? 'editar_conta' : 'criar_conta' ?>">
            <?php if ($editConta): ?>
              <input type="hidden" name="conta_id" value="<?= $editConta['id'] ?>">
            <?php endif; ?>

            <div class="form-group">
              <label>Descrição</label>
              <input type="text" name="conta_descricao" placeholder="Ex: Aluguel, Luz, Internet..."
                value="<?= $editConta ? htmlspecialchars($editConta['descricao']) : '' ?>" required>
            </div>
            <div class="form-group">
              <label>Valor (R$)</label>
              <input type="number" name="conta_valor" step="0.01" min="0.01" placeholder="0,00"
                value="<?= $editConta ? $editConta['valor'] : '' ?>" required>
            </div>
            <div class="form-group">
              <label>Vencimento</label>
              <input type="date" name="conta_vencimento"
                value="<?= $editConta ? $editConta['vencimento'] : date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
              <label>Categoria</label>
              <select name="conta_categoria">
                <option value="">— Selecione —</option>
                <?php foreach(['Moradia','Alimentação','Transporte','Saúde','Educação','Lazer','Serviços','Impostos','Outros'] as $cat): ?>
                  <option value="<?= $cat ?>" <?= ($editConta && $editConta['categoria']===$cat) ? 'selected' : '' ?>><?= $cat ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php if ($editConta): ?>
            <div class="form-group">
              <label>Status</label>
              <select name="conta_status">
                <option value="pendente" <?= $editConta['status']==='pendente'?'selected':'' ?>>Pendente</option>
                <option value="pago"     <?= $editConta['status']==='pago'?'selected':'' ?>>Pago</option>
                <option value="vencido"  <?= $editConta['status']==='vencido'?'selected':'' ?>>Vencido</option>
              </select>
            </div>
            <?php endif; ?>
            <div class="form-group">
              <label>Observação</label>
              <textarea name="conta_obs" placeholder="Detalhes..."><?= $editConta ? htmlspecialchars($editConta['observacao'] ?? '') : '' ?></textarea>
            </div>
            <div class="form-group" style="display:flex;align-items:center;gap:10px;margin-top:4px">
              <input type="checkbox" name="recorrente" id="recorrente" style="width:auto"
                <?= ($editConta && $editConta['recorrente']) ? 'checked' : '' ?>>
              <label for="recorrente" style="margin:0;text-transform:none;letter-spacing:0;font-size:13px;color:var(--text)">
                🔄 Conta recorrente (repete todo mês)
              </label>
            </div>

            <button type="submit" class="btn-submit <?= $editConta ? 'edit-mode' : 'add' ?>">
              <?= $editConta ? '💾 Salvar' : '+ Adicionar Conta' ?>
            </button>
            <?php if ($editConta): ?>
              <a href="?secao=contas" class="btn-cancel">✕ Cancelar</a>
            <?php endif; ?>
          </form>
        </div>
      </div>

    </div>
  </div><!-- /secao-contas -->

</div><!-- /wrapper -->

<!-- ================================================
     MODAL: CÂMERA / NOTA FISCAL
     ================================================ -->
<div class="modal-overlay" id="modalCamera">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">📷 Escanear Nota Fiscal</span>
      <button class="modal-close" id="btnFecharCamera">✕</button>
    </div>
    <div class="modal-body">

      <div class="camera-area" id="cameraArea">
        <div class="camera-placeholder">
          <span class="cam-icon">📷</span>
          Clique em "Abrir Câmera" ou<br>envie uma foto da nota fiscal
        </div>
      </div>

      <div class="camera-controls">
        <button type="button" id="btnAbrirCamera">📷 Abrir Câmera</button>
        <button type="button" id="btnCapturar" style="display:none" class="primary">⚡ Capturar</button>
        <label id="btnUpload">📁 Enviar Foto <input type="file" id="inputFoto" accept="image/*"></label>
      </div>

      <div class="ai-loading" id="aiLoading">
        <div class="ai-spinner"></div>
        Analisando nota fiscal com IA...
      </div>

      <div class="ai-result" id="aiResult">
        <div class="ai-result-title">✦ Dados identificados pela IA</div>
        <div class="ai-field">
          <label>Descrição</label>
          <input type="text" id="nfDescricao" placeholder="Descrição do gasto">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="ai-field">
            <label>Valor (R$)</label>
            <input type="number" id="nfValor" step="0.01" min="0.01">
          </div>
          <div class="ai-field">
            <label>Data</label>
            <input type="date" id="nfData">
          </div>
        </div>
        <button class="btn-add-nf" id="btnAdicionarNF">+ Adicionar como Gasto</button>
      </div>

    </div>
  </div>
</div>

<!-- FOOTER -->
<footer>
  <div class="footer-brand">
    <div class="footer-logo-icon">💹</div>
    <div class="footer-brand-name">Finance<span>OS</span></div>
  </div>
  <div class="footer-center">
    Desenvolvido por <strong>Gabriel Sandes</strong> <span class="footer-dot">✦</span> <?= date('Y') ?>
  </div>
  <div class="footer-right">
    <span class="footer-dot">●</span> v1.0.0 <span class="footer-dot">·</span> PHP + MySQL
  </div>
</footer>

<?php if (isset($_GET['ok'])): ?>
<div class="toast">✓ <?= htmlspecialchars($_GET['ok']) ?></div>
<?php endif; ?>

<script>
  // Data no header
  document.getElementById('hdate').textContent = new Date().toLocaleDateString('pt-BR', {
    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
  });

  // Auto-scroll para formulário de meta em edição
  <?php if ($editMeta): ?>
  document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('metaFormPanel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  <?php endif; ?>

  // Auto-scroll para formulário de conta em edição
  <?php if ($editConta): ?>
  document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('contaFormPanel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  <?php endif; ?>

  // =====================================================
  // CÂMERA / NOTA FISCAL
  // =====================================================
  const modalCamera    = document.getElementById('modalCamera');
  const btnCamera      = document.getElementById('btnCamera');
  const btnFechar      = document.getElementById('btnFecharCamera');
  const btnAbrirCam    = document.getElementById('btnAbrirCamera');
  const btnCapturar    = document.getElementById('btnCapturar');
  const inputFoto      = document.getElementById('inputFoto');
  const cameraArea     = document.getElementById('cameraArea');
  const aiLoading      = document.getElementById('aiLoading');
  const aiResult       = document.getElementById('aiResult');
  const nfDescricao    = document.getElementById('nfDescricao');
  const nfValor        = document.getElementById('nfValor');
  const nfData         = document.getElementById('nfData');
  const btnAdicionarNF = document.getElementById('btnAdicionarNF');

  let stream = null;
  let capturedImage = null;

  btnCamera.addEventListener('click', () => modalCamera.classList.add('open'));

  function fecharModal() {
    modalCamera.classList.remove('open');
    if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
    cameraArea.innerHTML = `<div class="camera-placeholder"><span class="cam-icon">📷</span>Clique em "Abrir Câmera" ou<br>envie uma foto da nota fiscal</div>`;
    btnCapturar.style.display = 'none';
    btnAbrirCam.style.display = '';
    aiResult.classList.remove('visible');
    aiLoading.classList.remove('visible');
    capturedImage = null;
  }

  btnFechar.addEventListener('click', fecharModal);
  modalCamera.addEventListener('click', e => { if (e.target === modalCamera) fecharModal(); });

  btnAbrirCam.addEventListener('click', async () => {
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      const video = document.createElement('video');
      video.srcObject = stream; video.autoplay = true; video.playsInline = true;
      cameraArea.innerHTML = '';
      cameraArea.appendChild(video);
      btnCapturar.style.display = '';
      btnAbrirCam.style.display = 'none';
    } catch(e) {
      cameraArea.innerHTML = `<div class="camera-placeholder"><span class="cam-icon">🚫</span>Câmera não disponível.<br>Use "Enviar Foto" abaixo.</div>`;
    }
  });

  btnCapturar.addEventListener('click', () => {
    const video = cameraArea.querySelector('video');
    if (!video) return;
    const canvas = document.createElement('canvas');
    canvas.width  = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    capturedImage = canvas.toDataURL('image/jpeg', 0.9);
    const img = document.createElement('img');
    img.src = capturedImage;
    cameraArea.innerHTML = '';
    cameraArea.appendChild(img);
    if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
    btnCapturar.style.display = 'none';
    analisarImagem(capturedImage);
  });

  inputFoto.addEventListener('change', e => {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = ev => {
      capturedImage = ev.target.result;
      const img = document.createElement('img');
      img.src = capturedImage;
      cameraArea.innerHTML = '';
      cameraArea.appendChild(img);
      analisarImagem(capturedImage);
    };
    reader.readAsDataURL(file);
  });

  async function analisarImagem(base64) {
    aiResult.classList.remove('visible');
    aiLoading.classList.add('visible');

    try {
      const base64Data = base64.split(',')[1];
      const mediaType  = base64.split(';')[0].split(':')[1];

      const response = await fetch('https://api.anthropic.com/v1/messages', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          model: 'claude-sonnet-4-20250514',
          max_tokens: 300,
          messages: [{
            role: 'user',
            content: [
              {
                type: 'image',
                source: { type: 'base64', media_type: mediaType, data: base64Data }
              },
              {
                type: 'text',
                text: 'Analise esta nota fiscal ou cupom fiscal. Extraia: 1) descrição resumida do estabelecimento/tipo de compra, 2) valor total pago, 3) data da compra. Responda SOMENTE em JSON sem markdown, no formato: {"descricao":"...","valor":0.00,"data":"YYYY-MM-DD"}. Se não conseguir identificar algum campo, use null.'
              }
            ]
          }]
        })
      });

      const data  = await response.json();
      const texto = data.content?.find(b => b.type === 'text')?.text ?? '{}';

      let parsed = {};
      try { parsed = JSON.parse(texto.replace(/```json|```/g, '').trim()); } catch(e) {}

      nfDescricao.value = parsed.descricao ?? 'Nota Fiscal';
      nfValor.value     = parsed.valor     ?? '';
      nfData.value      = parsed.data      ?? new Date().toISOString().split('T')[0];

      aiLoading.classList.remove('visible');
      aiResult.classList.add('visible');

    } catch(err) {
      aiLoading.classList.remove('visible');
      // Fallback manual
      nfDescricao.value = 'Nota Fiscal';
      nfData.value      = new Date().toISOString().split('T')[0];
      aiResult.classList.add('visible');
    }
  }

  btnAdicionarNF.addEventListener('click', async () => {
    const desc  = nfDescricao.value.trim();
    const valor = parseFloat(nfValor.value);
    const data  = nfData.value;

    if (!desc || !valor || valor <= 0 || !data) {
      alert('Preencha descrição, valor e data antes de adicionar.');
      return;
    }

    const form = document.createElement('form');
    form.method = 'POST'; form.action = 'actions.php';
    const fields = { acao:'criar', descricao:desc, valor:valor, tipo:'gasto', categoria_id:'', data:data, observacao:'Importado via Nota Fiscal 📷' };
    for (const [k,v] of Object.entries(fields)) {
      const i = document.createElement('input');
      i.type='hidden'; i.name=k; i.value=v;
      form.appendChild(i);
    }
    document.body.appendChild(form);
    form.submit();
  });

  // =====================================================
  // SEARCH & FILTER
  // =====================================================
  const searchInput = document.getElementById('searchInput');
  const searchClear = document.getElementById('searchClear');
  const countBadge  = document.getElementById('count-badge');
  const noResults   = document.getElementById('noResults');
  const filterBtns  = document.querySelectorAll('.filter-btn');
  const rows        = document.querySelectorAll('tbody tr');
  const totalRows   = rows.length;
  let currentFilter = 'todos';
  let currentSearch = '';

  function applyFilters() {
    let visible = 0;
    rows.forEach(row => {
      const desc      = row.querySelector('.td-desc')?.textContent.toLowerCase() ?? '';
      const catCell   = row.cells[2]?.textContent.toLowerCase() ?? '';
      const tipoCell  = row.querySelector('.pill')?.textContent.toLowerCase() ?? '';
      const matchSearch = currentSearch === '' || desc.includes(currentSearch) || catCell.includes(currentSearch);
      const matchFilter = currentFilter === 'todos' || tipoCell.includes(currentFilter);
      if (matchSearch && matchFilter) { row.style.display = ''; visible++; }
      else { row.style.display = 'none'; }
    });
    countBadge.textContent  = visible + ' registro' + (visible !== 1 ? 's' : '');
    noResults.style.display = visible === 0 && totalRows > 0 ? 'block' : 'none';
    searchClear.style.display = currentSearch !== '' ? 'inline' : 'none';
  }

  searchInput.addEventListener('input', e => { currentSearch = e.target.value.toLowerCase().trim(); applyFilters(); });
  searchClear.addEventListener('click', () => { searchInput.value = ''; currentSearch = ''; applyFilters(); searchInput.focus(); });
  filterBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      currentFilter = btn.dataset.filter;
      filterBtns.forEach(b => b.className = 'filter-btn');
      if (currentFilter === 'todos')   btn.classList.add('active');
      if (currentFilter === 'receita') btn.classList.add('active-rec');
      if (currentFilter === 'gasto')   btn.classList.add('active-gas');
      applyFilters();
    });
  });

  // =====================================================
  // CHARTS
  // =====================================================
  Chart.defaults.font.family = "'Space Mono', monospace";
  Chart.defaults.color       = '#6b6b85';

  const gridColor  = 'rgba(42,42,61,0.8)';
  const tooltipBg  = '#1c1c27';
  const tooltipBase = {
    backgroundColor: tooltipBg, borderColor: '#2a2a3d', borderWidth: 1,
    titleColor: '#e8e8f0', bodyColor: '#6b6b85',
    padding: 12, cornerRadius: 8,
    titleFont: { size: 12, weight: '700' }, bodyFont: { size: 11 },
  };

  const evolucaoData = <?= json_encode(array_values($evolucao)) ?>;
  const catData      = <?= json_encode(array_values($porCategoria)) ?>;

  // ── 1. Linha: Evolução Mensal ──
  new Chart(document.getElementById('chartEvolucao'), {
    type: 'line',
    data: {
      labels: evolucaoData.map(r => r.mes_label),
      datasets: [
        {
          label: 'Receitas', data: evolucaoData.map(r => parseFloat(r.receitas)),
          borderColor: '#00e5a0', backgroundColor: 'rgba(0,229,160,0.07)',
          borderWidth: 2, pointBackgroundColor: '#00e5a0', pointRadius: 4, pointHoverRadius: 6,
          fill: true, tension: 0.4,
        },
        {
          label: 'Gastos', data: evolucaoData.map(r => parseFloat(r.gastos)),
          borderColor: '#ff4d6d', backgroundColor: 'rgba(255,77,109,0.07)',
          borderWidth: 2, pointBackgroundColor: '#ff4d6d', pointRadius: 4, pointHoverRadius: 6,
          fill: true, tension: 0.4,
        }
      ]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { usePointStyle: true, pointStyle: 'circle', padding: 20, font: { size: 11 } } },
        tooltip: { ...tooltipBase, callbacks: { label: ctx => '  R$ ' + ctx.parsed.y.toLocaleString('pt-BR', {minimumFractionDigits:2}) } }
      },
      scales: {
        x: { grid: { color: gridColor }, ticks: { font: { size: 10 } } },
        y: { grid: { color: gridColor }, ticks: { font: { size: 10 }, callback: v => 'R$ ' + v.toLocaleString('pt-BR') } }
      }
    }
  });

  // ── 2. Barras: Comparativo ──
  new Chart(document.getElementById('chartComparativo'), {
    type: 'bar',
    data: {
      labels: evolucaoData.map(r => r.mes_label),
      datasets: [
        { label: 'Receitas', data: evolucaoData.map(r => parseFloat(r.receitas)), backgroundColor: 'rgba(0,229,160,0.7)', borderColor: '#00e5a0', borderWidth: 1, borderRadius: 5 },
        { label: 'Gastos',   data: evolucaoData.map(r => parseFloat(r.gastos)),   backgroundColor: 'rgba(255,77,109,0.7)', borderColor: '#ff4d6d', borderWidth: 1, borderRadius: 5 }
      ]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { labels: { usePointStyle: true, pointStyle: 'circle', padding: 16, font: { size: 11 } } },
        tooltip: { ...tooltipBase, callbacks: { label: ctx => '  R$ ' + ctx.parsed.y.toLocaleString('pt-BR', {minimumFractionDigits:2}) } }
      },
      scales: {
        x: { grid: { color: gridColor }, ticks: { font: { size: 10 } } },
        y: { grid: { color: gridColor }, ticks: { font: { size: 10 }, callback: v => 'R$ ' + v.toLocaleString('pt-BR') } }
      }
    }
  });

  // ── 3. Rosca: Por Categoria ──
  const catColors = ['#7b61ff','#ff4d6d','#00e5a0','#f59e0b','#3b82f6','#ec4899'];
  new Chart(document.getElementById('chartCategoria'), {
    type: 'doughnut',
    data: {
      labels: catData.map(r => r.icone + ' ' + r.nome),
      datasets: [{
        data: catData.map(r => parseFloat(r.total)),
        backgroundColor: catColors.map(c => c + 'cc'),
        borderColor: catColors, borderWidth: 2, hoverOffset: 8,
      }]
    },
    options: {
      responsive: true, cutout: '65%',
      plugins: {
        legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', padding: 12, font: { size: 11 } } },
        tooltip: { ...tooltipBase, callbacks: { label: ctx => '  R$ ' + ctx.parsed.toLocaleString('pt-BR', {minimumFractionDigits:2}) } }
      }
    }
  });
</script>
</body>
</html>