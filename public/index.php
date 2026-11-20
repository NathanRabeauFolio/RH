<?php
require dirname(__DIR__) . '/app/lib/rest_work.php';
ensure_storage();

$user = current_user();
if ($user && ($user['role'] ?? '') === 'admin') {
    redirect('admin.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    try {
        $userId = (string) ($_POST['user_id'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        if (!login_user($userId, $password)) {
            throw new RuntimeException('Identifiants invalides.');
        }
        $logged = current_user();
        redirect(($logged['role'] ?? '') === 'admin' ? 'admin.php' : 'index.php');
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect('index.php');
    }
}

$flash = flash_get();
$employee = current_employee();
$month = team_calendar_month();
$calendar = month_calendar($month);
$pageTitle = app_config()['app_name'];
$active = 'dashboard';
include __DIR__ . '/partials/header.php';
?>

<?php if (!$employee): ?>
  <section class="login-shell">
    <?php if ($flash): ?><div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
    <form class="login-card" method="post">
      <input type="hidden" name="action" value="login">
      <div class="login-top"><h1>Connexion</h1><p>Choisissez votre profil pour accéder à votre espace.</p></div>
      <div class="user-picker" data-user-picker>
        <?php foreach (users() as $cardUser): ?>
          <button class="user-tile" type="button" aria-pressed="false" data-user-id="<?= h($cardUser['id']) ?>">
            <span class="user-tile__name"><?= h($cardUser['name']) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="user_id" value="" data-user-input required>
      <label class="field">
        <span>Mot de passe</span>
        <input type="password" name="password" autocomplete="current-password" required>
      </label>
      <button class="btn btn-primary btn-block" type="submit">Entrer</button>
    </form>
  </section>
<?php else:
  $expenses = records_for_employee('expenses', $employee['id']);
  $tickets = records_for_employee('tickets', $employee['id']);
  $navigo = records_for_employee('navigo', $employee['id']);
  $leaves = records_for_employee('leaves', $employee['id']);
  $defaultMonth = date('Y-m');
?>
  <?php if ($flash): ?><div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <section class="dash-head dash-head-simple">
    <div>
      <h1>Bonjour, <?= h($employee['name']) ?></h1>
      <p>Retrouvez vos déclarations, vos congés et le calendrier de l’équipe.</p>
    </div>
  </section>

  <nav class="mobile-shortcuts no-print" aria-label="Accès direct à vos démarches">
    <p>Accès direct à vos démarches</p>
    <div><a href="#notes-de-frais">Notes de frais</a><a href="#titres-restaurant">Titres-restaurant</a><a href="#navigo">Navigo</a><a href="#jours-repos-travailles">Jours de repos travaillés</a><a href="#conges">Congés</a><a class="wide" href="#calendrier">Calendrier d’équipe</a></div>
  </nav>
  <section id="notes-de-frais" class="section-block task-card">
    <div class="section-title"><div><h2>Notes de frais</h2><p>Déclarez vos dépenses et ajoutez vos justificatifs.</p></div></div>
    <form class="panel" method="post" action="save_expenses.php" enctype="multipart/form-data">
      <div class="repeatable-list" data-repeatable-container="expense">
        <div class="entry-grid expense-row" data-repeatable-row>
          <input type="hidden" name="row_id[]" value="0">
          <label class="field"><span>Date</span><input type="date" name="date[]" required></label>
          <label class="field span-2"><span>Descriptif</span><input type="text" name="description[]" required></label>
          <label class="field"><span>Montant TTC (€)</span><input type="text" name="amount_ttc[]" inputmode="decimal" required></label>
          <label class="field"><span>Montant HT (€)</span><input type="text" name="amount_ht[]" inputmode="decimal" required></label>
          <label class="field"><span>Paiement</span>
            <select name="payment_mode[]"><?php foreach (payment_modes() as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select>
          </label>
          <label class="field span-2"><span>Justificatif (facultatif)</span><input type="file" name="attachment_0[]" accept="<?= h(attachment_accept_attr()) ?>" capture="environment" multiple></label>
        </div>
      </div>
      <div class="inline-tools">
        <button class="btn btn-ghost" type="button" data-add-row="expense">+ Ajouter une ligne</button>
        <button class="btn btn-primary" type="submit">Enregistrer les frais</button>
      </div>
    </form>

    <div class="history"><h3>Historique des notes de frais</h3><div class="panel table-panel">
      <table class="table">
        <thead><tr><th>Date</th><th>Descriptif</th><th>TTC</th><th>HT</th><th>Paiement</th><th>Justif.</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if (!$expenses): ?><tr><td colspan="7" class="muted-cell">Aucune note.</td></tr><?php endif; ?>
          <?php foreach ($expenses as $row): ?>
            <tr>
              <td data-label="Date"><?= h((string) $row['date']) ?></td>
              <td data-label="Descriptif"><?= h((string) $row['description']) ?></td>
              <td data-label="TTC"><?= h(money((string) $row['amount_ttc'])) ?></td>
              <td data-label="HT"><?= h(money((string) $row['amount_ht'])) ?></td>
              <td data-label="Paiement"><?= h(payment_modes()[(string) $row['payment_mode']] ?? '') ?></td>
              <td data-label="Justif."><?= !empty($row['attachments']) ? 'Joint' : '—' ?></td>
              <td class="actions-cell"><a class="mini-link" href="edit.php?type=expenses&id=<?= urlencode((string) $row['id']) ?>">Modifier</a> <a class="mini-link danger" href="delete.php?type=expenses&id=<?= urlencode((string) $row['id']) ?>" onclick="return confirm('Supprimer ?')">Supprimer</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </section>

  <div class="monthly-heading"><h2>Déclarations mensuelles</h2><p>Titres-restaurant et prise en charge du transport.</p></div>
  <div class="monthly-grid">
  <section id="titres-restaurant" class="section-block task-card monthly-card">
    <div class="section-title"><div><h2>Titres-restaurant</h2><p>Déclarez vos jours et retraits.</p></div></div>
    <form class="panel compact-form" method="post" action="save_ticket.php">
      <label class="field"><span>Mois</span><input type="month" name="month" value="<?= h($defaultMonth) ?>" required></label>
      <label class="field"><span>Jours ouvrant droit</span><input type="number" name="eligible_days" min="0" value="<?= h((string) default_ticket_days_for_month($employee['id'], $defaultMonth)) ?>" required></label>
      <label class="field"><span>Retraits</span><input type="number" name="withdrawals" min="0" value="0" required></label>
      <button class="btn btn-primary" type="submit">Enregistrer</button>
    </form>
    <div class="history"><h3>Historique des déclarations</h3><div class="panel table-panel">
      <table class="table">
        <thead><tr><th>Mois</th><th>Jours</th><th>Retraits</th><th>Restants</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$tickets): ?><tr><td colspan="5" class="muted-cell">Aucune déclaration.</td></tr><?php endif; ?>
        <?php foreach ($tickets as $row): ?>
          <tr>
            <td data-label="Mois"><?= h((string) $row['month']) ?></td>
            <td data-label="Jours"><?= h((string) $row['eligible_days']) ?></td>
            <td data-label="Retraits"><?= h((string) $row['withdrawals']) ?></td>
            <td data-label="Restants"><?= h((string) transport_count($row)) ?></td>
            <td class="actions-cell"><a class="mini-link" href="edit.php?type=tickets&id=<?= urlencode((string) $row['id']) ?>">Modifier</a> <a class="mini-link danger" href="delete.php?type=tickets&id=<?= urlencode((string) $row['id']) ?>" onclick="return confirm('Supprimer ?')">Supprimer</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </section>

  <section id="navigo" class="section-block task-card monthly-card">
    <div class="section-title"><div><h2>Navigo</h2><p>Déclarez votre passe Navigo.</p></div></div>
    <form class="panel compact-form" method="post" action="save_navigo.php">
      <label class="field"><span>Mois</span><input type="month" name="month" value="<?= h($defaultMonth) ?>" required></label>
      <label class="field field-check"><input type="checkbox" name="has_navigo" value="1"><span>Passe Navigo</span></label>
      <button class="btn btn-primary" type="submit">Enregistrer</button>
    </form>
    <div class="history"><h3>Historique des déclarations</h3><div class="panel table-panel">
      <table class="table">
        <thead><tr><th>Mois</th><th>Navigo</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$navigo): ?><tr><td colspan="3" class="muted-cell">Aucune déclaration.</td></tr><?php endif; ?>
        <?php foreach ($navigo as $row): ?>
          <tr>
            <td data-label="Mois"><?= h((string) $row['month']) ?></td>
            <td data-label="Navigo"><?= !empty($row['has_navigo']) ? 'Oui' : 'Non' ?></td>
            <td class="actions-cell"><a class="mini-link" href="edit.php?type=navigo&id=<?= urlencode((string) $row['id']) ?>">Modifier</a> <a class="mini-link danger" href="delete.php?type=navigo&id=<?= urlencode((string) $row['id']) ?>" onclick="return confirm('Supprimer ?')">Supprimer</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </section>

  </div>

  <?php include __DIR__ . '/partials/rest_work_block.php'; ?>

  <section id="conges" class="section-block task-card">
    <div class="section-title"><div><h2>Demande de congés</h2><p>Indiquez le type de congés et la période souhaitée.</p></div></div>
    <form class="panel" method="post" action="save_leaves.php">
      <div class="repeatable-list" data-repeatable-container="leave">
        <div class="entry-grid leave-row" data-repeatable-row>
          <input type="hidden" name="row_id[]" value="0">
          <label class="field"><span>Type de congés</span>
            <select name="leave_type[]"><?php foreach (leave_types() as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select>
          </label>
          <label class="field"><span>Date de début</span><input type="date" name="start_date[]" required></label>
          <label class="field"><span>Date de fin</span><input type="date" name="end_date[]" required></label>
        </div>
      </div>
      <div class="inline-tools">
        <button class="btn btn-ghost" type="button" data-add-row="leave">+ Ajouter une ligne</button>
        <button class="btn btn-primary" type="submit">Enregistrer la demande</button>
      </div>
    </form>
    <div class="history"><h3>Historique des demandes</h3><div class="panel table-panel">
      <table class="table">
        <thead><tr><th>Type</th><th>Période</th><th>Statut</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$leaves): ?><tr><td colspan="4" class="muted-cell">Aucune demande.</td></tr><?php endif; ?>
        <?php foreach ($leaves as $row): ?>
          <tr>
            <td data-label="Type"><?= h(leave_type_label((string) $row['leave_type'])) ?></td>
            <td data-label="Période"><?= h((string) $row['start_date']) ?> → <?= h((string) $row['end_date']) ?></td>
            <td data-label="Statut"><span class="status <?= h(leave_status_class((string) $row['status'])) ?>"><?= h(leave_status_label((string) $row['status'])) ?></span></td>
            <td class="actions-cell"><a class="mini-link" href="edit.php?type=leaves&id=<?= urlencode((string) $row['id']) ?>">Modifier</a> <a class="mini-link danger" href="delete.php?type=leaves&id=<?= urlencode((string) $row['id']) ?>" onclick="return confirm('Supprimer ?')">Supprimer</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </section>

  <section id="calendrier" class="section-block calendar-section">
    <div class="section-title"><div><h2>Calendrier d’équipe</h2><p>Consultez les absences de vos collègues.</p></div></div>
    <div class="panel panel-tight">
      <?php include __DIR__ . '/partials/calendar_block.php'; ?>
    </div>
  </section>

<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
