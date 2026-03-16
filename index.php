<?php
require_once 'db.php';
$db = getDB();

// Totais via view
$saldoRow      = $db->query("SELECT * FROM saldo_geral")->fetch();
$totalReceitas = $saldoRow['total_receitas'];
$totalGastos   = $saldoRow['total_gastos'];
$saldo         = $saldoRow['saldo'];

// Todos os lançamentos com categoria
$dados = $db->query("
    SELECT l.*, c.nome AS categoria_nome, c.icone AS categoria_icone
    FROM lancamentos l
    LEFT JOIN categorias c ON c.id = l.categoria_id
    ORDER BY l.data DESC, l.criado_em DESC
")->fetchAll();

// Categorias para o select
$categorias = $db->query("SELECT * FROM categorias ORDER BY nome")->fetchAll();

// Item em edição
$editItem = null;
if (isset($_GET['editar'])) {
    $id = (int)$_GET['editar'];
    $stmt = $db->prepare("SELECT * FROM lancamentos WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if ($row) $editItem = $row;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FinanceOS — Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Space+Mono:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
<style>
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

  /* === GRID NOISE BACKGROUND === */
  body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image:
      radial-gradient(ellipse 80% 50% at 20% -10%, rgba(0,229,160,.08) 0%, transparent 60%),
      radial-gradient(ellipse 60% 40% at 80% 110%, rgba(123,97,255,.07) 0%, transparent 60%);
    pointer-events: none;
    z-index: 0;
  }

  .wrapper {
    position: relative;
    z-index: 1;
    max-width: 1200px;
    margin: 0 auto;
    padding: 40px 24px 80px;
  }

  /* === HEADER === */
  header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 48px;
    padding-bottom: 24px;
    border-bottom: 1px solid var(--border);
  }

  .logo {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .logo-icon {
    width: 40px; height: 40px;
    background: var(--accent);
    border-radius: 10px;
    display: grid;
    place-items: center;
    font-size: 20px;
  }

  .logo-text {
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -0.5px;
  }

  .logo-text span { color: var(--accent); }

  .header-date {
    font-family: var(--font-mono);
    font-size: 12px;
    color: var(--muted);
  }

  /* === KPI CARDS === */
  .kpi-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 40px;
  }

  @media (max-width: 700px) { .kpi-grid { grid-template-columns: 1fr; } }

  .kpi-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 28px 24px;
    position: relative;
    overflow: hidden;
    transition: transform .2s, border-color .2s;
  }

  .kpi-card:hover { transform: translateY(-3px); border-color: var(--accent3); }

  .kpi-card::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
  }

  .kpi-card.receita::after { background: var(--receita); }
  .kpi-card.gasto::after   { background: var(--gasto); }
  .kpi-card.saldo::after   { background: var(--accent3); }

  .kpi-label {
    font-family: var(--font-mono);
    font-size: 11px;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 10px;
  }

  .kpi-value {
    font-size: 32px;
    font-weight: 800;
    letter-spacing: -1px;
    line-height: 1;
  }

  .kpi-value.receita { color: var(--receita); }
  .kpi-value.gasto   { color: var(--gasto); }
  .kpi-value.saldo   { color: <?php echo $saldo >= 0 ? 'var(--receita)' : 'var(--gasto)'; ?>; }

  .kpi-sub {
    margin-top: 8px;
    font-family: var(--font-mono);
    font-size: 11px;
    color: var(--muted);
  }

  /* === LAYOUT 2 COLS === */
  .main-grid {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 24px;
    align-items: start;
  }

  @media (max-width: 900px) { .main-grid { grid-template-columns: 1fr; } }

  /* === PANEL === */
  .panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
  }

  .panel-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .panel-title {
    font-size: 14px;
    font-weight: 700;
    letter-spacing: .5px;
    text-transform: uppercase;
  }

  .badge {
    font-family: var(--font-mono);
    font-size: 11px;
    padding: 3px 10px;
    border-radius: 20px;
    background: var(--surface2);
    color: var(--muted);
  }

  /* === TABLE === */
  .table-wrap { overflow-x: auto; }

  table {
    width: 100%;
    border-collapse: collapse;
  }

  thead th {
    font-family: var(--font-mono);
    font-size: 10px;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--muted);
    padding: 14px 24px;
    text-align: left;
    border-bottom: 1px solid var(--border);
  }

  tbody tr {
    border-bottom: 1px solid var(--border);
    transition: background .15s;
  }

  tbody tr:last-child { border-bottom: none; }
  tbody tr:hover { background: var(--surface2); }

  tbody td {
    padding: 16px 24px;
    font-size: 14px;
  }

  .td-desc { font-weight: 600; max-width: 220px; }

  .pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-family: var(--font-mono);
    font-size: 11px;
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 700;
  }

  .pill.receita { background: rgba(0,229,160,.12); color: var(--receita); }
  .pill.gasto   { background: rgba(255,77,109,.12); color: var(--gasto); }

  .pill::before { content: '●'; font-size: 8px; }

  .td-valor {
    font-family: var(--font-mono);
    font-weight: 700;
    font-size: 15px;
  }

  .td-valor.receita { color: var(--receita); }
  .td-valor.gasto   { color: var(--gasto); }

  .actions { display: flex; gap: 8px; }

  .btn-icon {
    width: 32px; height: 32px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: transparent;
    color: var(--muted);
    cursor: pointer;
    display: grid;
    place-items: center;
    font-size: 14px;
    transition: all .15s;
    text-decoration: none;
  }

  .btn-icon:hover.edit   { border-color: var(--accent3); color: var(--accent3); background: rgba(123,97,255,.1); }
  .btn-icon:hover.delete { border-color: var(--gasto); color: var(--gasto); background: rgba(255,77,109,.1); }

  .empty-state {
    padding: 60px 24px;
    text-align: center;
    color: var(--muted);
    font-family: var(--font-mono);
    font-size: 13px;
  }

  .empty-state .icon { font-size: 40px; margin-bottom: 12px; display: block; }

  /* === FORM PANEL === */
  .form-panel { padding: 24px; }

  .form-title {
    font-size: 15px;
    font-weight: 700;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .form-title .dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--accent);
    animation: pulse 2s infinite;
  }

  @keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: .5; transform: scale(1.4); }
  }

  .form-group { margin-bottom: 16px; }

  label {
    display: block;
    font-family: var(--font-mono);
    font-size: 10px;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 8px;
  }

  input[type="text"],
  input[type="number"],
  select {
    width: 100%;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 12px 14px;
    color: var(--text);
    font-family: var(--font-head);
    font-size: 14px;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
    appearance: none;
  }

  input:focus, select:focus {
    border-color: var(--accent3);
    box-shadow: 0 0 0 3px rgba(123,97,255,.15);
  }

  select option { background: var(--surface2); }

  .tipo-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
  }

  .tipo-option input { display: none; }

  .tipo-label {
    display: block;
    padding: 12px;
    border-radius: 8px;
    border: 1px solid var(--border);
    text-align: center;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    transition: all .2s;
  }

  .tipo-option input:checked + .tipo-label.receita-label {
    background: rgba(0,229,160,.15);
    border-color: var(--receita);
    color: var(--receita);
  }

  .tipo-option input:checked + .tipo-label.gasto-label {
    background: rgba(255,77,109,.15);
    border-color: var(--gasto);
    color: var(--gasto);
  }

  .tipo-label:hover { border-color: var(--muted); }

  .btn-submit {
    width: 100%;
    padding: 14px;
    border-radius: 8px;
    border: none;
    font-family: var(--font-head);
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: all .2s;
    margin-top: 8px;
  }

  .btn-submit.add {
    background: var(--accent);
    color: #0a0a0f;
  }

  .btn-submit.add:hover { background: #00ffb3; transform: translateY(-1px); }

  .btn-submit.edit-mode {
    background: var(--accent3);
    color: #fff;
  }

  .btn-submit.edit-mode:hover { background: #9580ff; transform: translateY(-1px); }

  .btn-cancel {
    width: 100%;
    padding: 12px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: transparent;
    color: var(--muted);
    font-family: var(--font-head);
    font-size: 13px;
    cursor: pointer;
    margin-top: 8px;
    text-decoration: none;
    display: block;
    text-align: center;
    transition: all .2s;
  }

  .btn-cancel:hover { border-color: var(--muted); color: var(--text); }

  /* === TOAST === */
  .toast {
    position: fixed;
    bottom: 32px;
    right: 32px;
    background: var(--surface2);
    border: 1px solid var(--accent);
    border-radius: 10px;
    padding: 14px 20px;
    font-size: 13px;
    font-family: var(--font-mono);
    color: var(--accent);
    display: flex;
    align-items: center;
    gap: 10px;
    z-index: 999;
    animation: toastIn .3s ease, toastOut .3s ease 2.5s forwards;
  }

  @keyframes toastIn  { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: none; } }
  @keyframes toastOut { to   { opacity: 0; transform: translateY(16px); } }
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
    <div class="header-date" id="hdate"></div>
  </header>

  <!-- KPIs -->
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
  </div>

  <!-- MAIN GRID -->
  <div class="main-grid">

    <!-- TABLE -->
    <div class="panel">
      <div class="panel-header">
        <span class="panel-title">Lançamentos</span>
        <span class="badge"><?= count($dados) ?> registros</span>
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
              <td style="font-family:var(--font-mono);font-size:11px;color:var(--muted)"><?= str_pad($i+1, 2, '0', STR_PAD_LEFT) ?></td>
              <td class="td-desc"><?= htmlspecialchars($item['descricao']) ?></td>
              <td style="font-size:13px;color:var(--muted)">
                <?= $item['categoria_icone'] ?? '📦' ?> <?= htmlspecialchars($item['categoria_nome'] ?? '—') ?>
              </td>
              <td style="font-family:var(--font-mono);font-size:12px;color:var(--muted)">
                <?= date('d/m/Y', strtotime($item['data'])) ?>
              </td>
              <td><span class="pill <?= $item['tipo'] ?>"><?= ucfirst($item['tipo']) ?></span></td>
              <td class="td-valor <?= $item['tipo'] ?>">
                <?= $item['tipo']==='gasto' ? '−' : '+' ?>R$ <?= number_format($item['valor'], 2, ',', '.') ?>
              </td>
              <td>
                <div class="actions">
                  <a href="?editar=<?= $item['id'] ?>" class="btn-icon edit" title="Editar">✏️</a>
                  <a href="actions.php?deletar=<?= $item['id'] ?>" class="btn-icon delete" title="Excluir"
                     onclick="return confirm('Excluir este lançamento?')">🗑️</a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- FORM -->
    <div class="panel">
      <div class="panel-header">
        <span class="panel-title"><?= $editItem ? 'Editar Lançamento' : 'Novo Lançamento' ?></span>
      </div>
      <div class="form-panel">
        <form method="POST" action="actions.php">
          <?php if ($editItem): ?>
            <input type="hidden" name="id" value="<?= $editItem['id'] ?>">
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
    </div>

  </div>
</div>

<?php if (isset($_GET['ok'])): ?>
<div class="toast">✓ <?= htmlspecialchars($_GET['ok']) ?></div>
<?php endif; ?>

<script>
  document.getElementById('hdate').textContent = new Date().toLocaleDateString('pt-BR', {
    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
  });
</script>
</body>
</html>