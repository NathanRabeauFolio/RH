<?php
require dirname(__DIR__) . '/app/lib/helpers.php';
ensure_storage();

$user = require_login();
if (($user['role'] ?? '') !== 'admin') {
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'set_leave_status') {
            set_leave_status((string) ($_POST['id'] ?? ''), (string) ($_POST['status'] ?? ''), trim((string) ($_POST['admin_comment'] ?? '')));
            flash_set('success', 'Statut mis à jour.');
        } else {
            throw new RuntimeException('Action inconnue.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect('admin.php?month=' . urlencode(team_calendar_month()));
}

$flash = flash_get();
$month = team_calendar_month();
$calendar = month_calendar($month);
$pageTitle = app_config()['app_name'] . ' — Admin';
$active = 'admin';
$pending = pending_leaves();

include __DIR__ . '/partials/header.php';
?>
<?php if ($flash): ?><div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

<section class="dash-head">
  <div>
    <h1>Valerie K</h1>
    <p><?= h($calendar['label']) ?></p>
  </div>
  <div class="metric-row">
    <div class="metric"><span>Demandes</span><strong><?= h((string) count($pending)) ?></strong></div>
    <div class="metric"><span>Équipe</span><strong><?= h((string) count(employees())) ?></strong></div>
  </div>
</section>

<section class="section-block">
  <div class="section-title"><h2>Validation des congés</h2></div>
  <div class="record-stack">
    <?php if (!$pending): ?><div class="panel">Aucune demande en attente.</div><?php endif; ?>
    <?php foreach ($pending as $row): ?>
      <form class="panel approval-row" method="post">
        <input type="hidden" name="action" value="set_leave_status">
        <input type="hidden" name="id" value="<?= h((string) $row['id']) ?>">
        <div class="approval-copy">
          <strong><?= h(employee_name((string) $row['employee_id'])) ?></strong>
          <span><?= h(leave_type_label((string) $row['leave_type'])) ?> · <?= h((string) $row['start_date']) ?> → <?= h((string) $row['end_date']) ?> · <?= h((string) $row['estimated_days']) ?> j</span>
        </div>
        <label class="field">
          <span>Statut</span>
          <select name="status">
            <option value="pending" <?= (string) $row['status'] === 'pending' ? 'selected' : '' ?>>Demandé</option>
            <option value="approved" <?= (string) $row['status'] === 'approved' ? 'selected' : '' ?>>Accordé</option>
            <option value="rejected" <?= (string) $row['status'] === 'rejected' ? 'selected' : '' ?>>Refusé</option>
          </select>
        </label>
        <label class="field span-2">
          <span>Commentaire</span>
          <input type="text" name="admin_comment" value="<?= h((string) ($row['admin_comment'] ?? '')) ?>">
        </label>
        <div class="inline-tools">
          <button class="btn btn-primary" type="submit">Enregistrer</button>
          <a class="btn btn-ghost" href="edit.php?type=leaves&id=<?= urlencode((string) $row['id']) ?>">Ouvrir</a>
        </div>
      </form>
    <?php endforeach; ?>
  </div>
</section>

<section class="section-block">
  <div class="section-title"><h2>Calendrier</h2></div>
  <div class="panel panel-tight">
    <?php include __DIR__ . '/partials/calendar_block.php'; ?>
  </div>
</section>

<section class="section-block">
  <div class="section-title"><h2>Exports</h2></div>
  <div class="panel export-grid">
    <?php foreach (employees() as $employee): ?>
      <article class="export-card">
        <strong><?= h($employee['name']) ?></strong>
        <div class="inline-tools">
          <a class="btn btn-ghost" href="export_notes_docx.php?employee=<?= urlencode($employee['id']) ?>">Notes de frais</a>
        </div>
      </article>
    <?php endforeach; ?>
    <article class="export-card">
      <strong>Calendrier</strong>
      <div class="inline-tools">
        <a class="btn btn-ghost" href="export_calendar_pdf.php?month=<?= urlencode($month) ?>">PDF</a>
        <a class="btn btn-ghost" href="team_calendar.php?month=<?= urlencode($month) ?>">Vue</a>
      </div>
    </article>
    <article class="export-card">
      <strong>Titres-resto + Navigo</strong>
      <form class="export-form-stacked" method="get" action="export_transport_docx.php">
        <label class="field"><span>Mois</span><input type="month" name="month" value="<?= h($month) ?>" required></label>
        <button class="btn btn-primary" type="submit">Exporter</button>
      </form>
    </article>
  </div>
</section>

<section class="section-block">
  <div class="section-title"><h2>Historique</h2></div>
  <div class="panel table-panel">
    <table class="table">
      <thead><tr><th>Employé</th><th>Type</th><th>Période</th><th>Jours</th><th>Statut</th><th></th></tr></thead>
      <tbody>
      <?php foreach (all_leaves() as $row): ?>
        <tr>
          <td><?= h(employee_name((string) $row['employee_id'])) ?></td>
          <td><?= h(leave_type_label((string) $row['leave_type'])) ?></td>
          <td><?= h((string) $row['start_date']) ?> → <?= h((string) $row['end_date']) ?></td>
          <td><?= h((string) $row['estimated_days']) ?></td>
          <td><span class="status <?= h(leave_status_class((string) $row['status'])) ?>"><?= h(leave_status_label((string) $row['status'])) ?></span></td>
          <td class="actions-cell"><a class="mini-link" href="edit.php?type=leaves&id=<?= urlencode((string) $row['id']) ?>">Modifier</a> <a class="mini-link danger" href="delete.php?type=leaves&id=<?= urlencode((string) $row['id']) ?>" onclick="return confirm('Supprimer ?')">Supprimer</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?>
