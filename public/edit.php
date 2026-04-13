<?php
require dirname(__DIR__) . '/app/lib/helpers.php';
ensure_storage();

$user = require_login();
$type = (string) ($_GET['type'] ?? '');
$id = (string) ($_GET['id'] ?? '');
if (!in_array($type, ['expenses', 'tickets', 'navigo', 'leaves'], true)) {
    redirect(($user['role'] ?? '') === 'admin' ? 'admin.php' : 'index.php');
}
$record = find_record($type, $id);
if (!$record) {
    flash_set('error', 'Élément introuvable.');
    redirect(($user['role'] ?? '') === 'admin' ? 'admin.php' : 'index.php');
}
assert_can_access_record($user, $type, $record);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($type === 'expenses') {
            update_expense($user, $id);
        } elseif ($type === 'tickets') {
            update_ticket($user, $id);
        } elseif ($type === 'navigo') {
            update_navigo($user, $id);
        } elseif ($type === 'leaves') {
            update_leave($user, $id);
        }
        flash_set('success', 'Modification enregistrée.');
        redirect(($user['role'] ?? '') === 'admin' ? 'admin.php' : 'index.php');
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect('edit.php?type=' . urlencode($type) . '&id=' . urlencode($id));
    }
}

$flash = flash_get();
$pageTitle = 'Modifier';
$active = ($user['role'] ?? '') === 'admin' ? 'admin' : 'dashboard';
include __DIR__ . '/partials/header.php';
?>
<?php if ($flash): ?><div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<section class="section-block">
  <div class="section-title"><h1>Modifier</h1></div>
  <form class="panel form-stack" method="post" enctype="multipart/form-data">
    <?php if ($type === 'expenses'): ?>
      <div class="entry-grid">
        <label class="field"><span>Date</span><input type="date" name="date" value="<?= h((string) $record['date']) ?>" required></label>
        <label class="field span-2"><span>Descriptif</span><input type="text" name="description" value="<?= h((string) $record['description']) ?>" required></label>
        <label class="field"><span>TTC</span><input type="text" name="amount_ttc" value="<?= h((string) $record['amount_ttc']) ?>" required></label>
        <label class="field"><span>HT</span><input type="text" name="amount_ht" value="<?= h((string) $record['amount_ht']) ?>" required></label>
        <label class="field"><span>Paiement</span>
          <select name="payment_mode"><?php foreach (payment_modes() as $key => $label): ?><option value="<?= h($key) ?>" <?= $key === (string) $record['payment_mode'] ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select>
        </label>
        <label class="field span-2"><span>Justificatif</span><input type="file" name="attachment_edit[]" accept="<?= h(attachment_accept_attr()) ?>" capture="environment" multiple></label>
      </div>
      <?php if (!empty($record['attachments'])): ?>
        <div class="attachment-list">
          <?php foreach ($record['attachments'] as $attachment): ?>
            <label class="attachment-item"><input type="checkbox" name="remove_attachments[]" value="<?= h((string) $attachment['path']) ?>"> Retirer <?= h((string) $attachment['original_name']) ?></label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php elseif ($type === 'tickets'): ?>
      <div class="compact-form">
        <label class="field"><span>Mois</span><input type="month" name="month" value="<?= h((string) $record['month']) ?>" required></label>
        <label class="field"><span>Jours ouvrant droit</span><input type="number" name="eligible_days" min="0" value="<?= h((string) $record['eligible_days']) ?>" required></label>
        <label class="field"><span>Retraits</span><input type="number" name="withdrawals" min="0" value="<?= h((string) $record['withdrawals']) ?>" required></label>
      </div>
    <?php elseif ($type === 'navigo'): ?>
      <div class="compact-form">
        <label class="field"><span>Mois</span><input type="month" name="month" value="<?= h((string) $record['month']) ?>" required></label>
        <label class="field field-check"><input type="checkbox" name="has_navigo" value="1" <?= !empty($record['has_navigo']) ? 'checked' : '' ?>><span>Passe Navigo</span></label>
      </div>
    <?php elseif ($type === 'leaves'): ?>
      <div class="entry-grid">
        <?php if (is_admin()): ?>
          <label class="field"><span>Employé</span>
            <select name="employee_id"><?php foreach (employees() as $employee): ?><option value="<?= h($employee['id']) ?>" <?= $employee['id'] === (string) $record['employee_id'] ? 'selected' : '' ?>><?= h($employee['name']) ?></option><?php endforeach; ?></select>
          </label>
        <?php endif; ?>
        <label class="field"><span>Type</span><select name="leave_type"><?php foreach (leave_types() as $key => $label): ?><option value="<?= h($key) ?>" <?= $key === (string) $record['leave_type'] ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Début</span><input type="date" name="start_date" value="<?= h((string) $record['start_date']) ?>" required></label>
        <label class="field"><span>Fin</span><input type="date" name="end_date" value="<?= h((string) $record['end_date']) ?>" required></label>
        <?php if (is_admin()): ?>
          <label class="field"><span>Statut</span><select name="status"><option value="pending" <?= (string) $record['status'] === 'pending' ? 'selected' : '' ?>>Demandé</option><option value="approved" <?= (string) $record['status'] === 'approved' ? 'selected' : '' ?>>Accordé</option><option value="rejected" <?= (string) $record['status'] === 'rejected' ? 'selected' : '' ?>>Refusé</option></select></label>
          <label class="field span-2"><span>Commentaire admin</span><input type="text" name="admin_comment" value="<?= h((string) ($record['admin_comment'] ?? '')) ?>"></label>
        <?php else: ?>
          <div class="field"><span>Statut</span><div class="field-static"><?= h(leave_status_label((string) $record['status'])) ?></div></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="inline-tools">
      <button class="btn btn-primary" type="submit">Enregistrer</button>
      <a class="btn btn-ghost" href="<?= ($user['role'] ?? '') === 'admin' ? 'admin.php' : 'index.php' ?>">Retour</a>
    </div>
  </form>
</section>
<?php include __DIR__ . '/partials/footer.php'; ?>
