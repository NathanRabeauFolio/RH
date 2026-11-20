<?php
require dirname(__DIR__) . '/app/lib/rest_work.php';
$user = require_login('employee');
$id = (string) ($_GET['id'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        rest_work_verify_token((string) ($_POST['csrf'] ?? ''));
        if ($action === 'save') {
            $count = rest_work_save($user['id'], (array) ($_POST['date'] ?? []), (array) ($_POST['reason'] ?? []), isset($_POST['report_month']) ? (string) $_POST['report_month'] : null);
            rest_work_flash_set('success', $count . ' jour(s) de repos travaillé(s) enregistré(s).');
        } elseif ($action === 'update') {
            rest_work_update($user['id'], (string) ($_POST['id'] ?? ''), (string) ($_POST['date'] ?? ''), (string) ($_POST['reason'] ?? ''), isset($_POST['report_month']) ? (string) $_POST['report_month'] : null);
            rest_work_flash_set('success', 'Déclaration modifiée.');
        } elseif ($action === 'delete') {
            rest_work_delete($user['id'], (string) ($_POST['id'] ?? ''));
            rest_work_flash_set('success', 'Déclaration supprimée.');
        } else {
            throw new InvalidArgumentException('Action inconnue.');
        }
    } catch (Throwable $e) {
        rest_work_flash_set('error', $e->getMessage());
        if ($action === 'update' && $id !== '') redirect('rest_work.php?id=' . urlencode($id));
    }
    redirect('index.php#jours-repos-travailles');
}
if ($id === '') redirect('index.php#jours-repos-travailles');
try {
    $record = rest_work_find($user['id'], $id);
} catch (Throwable $e) {
    rest_work_flash_set('error', 'Déclaration introuvable.');
    redirect('index.php#jours-repos-travailles');
}
$flash = rest_work_flash_get();
$pageTitle = 'Modifier · Jour de repos travaillé';
$active = 'dashboard';
include __DIR__ . '/partials/header.php';
?>
<?php if ($flash): ?><div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<section class="section-block edit-section">
  <div class="section-title"><div><h1>Modifier</h1><p>Jour de repos travaillé</p></div></div>
  <form class="panel form-stack" method="post">
    <input type="hidden" name="csrf" value="<?= h(rest_work_token()) ?>">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" value="<?= h($id) ?>">
    <div class="entry-grid rest-work-row">
      <label class="field"><span>Date travaillée</span><input type="date" name="date" max="<?= h(date('Y-m-d')) ?>" value="<?= h($record['date']) ?>" required></label>
      <label class="field"><span>Motif (facultatif)</span><input type="text" name="reason" maxlength="200" value="<?= h($record['reason']) ?>"></label>
    </div>
    <label class="field rest-work-month"><span>Mois RH de rattachement</span><input type="month" name="report_month" value="<?= h(rest_work_report_month($record)) ?>" max="<?= h(date('Y-m')) ?>" required><small>Le changement de mois déplace cette déclaration dans le document RH correspondant.</small></label>
    <div class="inline-tools"><a class="btn btn-ghost" href="index.php#jours-repos-travailles">Retour</a><button class="btn btn-primary" type="submit">Enregistrer</button></div>
  </form>
</section>
<?php include __DIR__ . '/partials/footer.php'; ?>
