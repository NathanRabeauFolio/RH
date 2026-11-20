<?php
$restRows = rest_work_for_employee($employee['id']);
$restMonth = team_calendar_month();
$restMonthRows = array_values(array_filter($restRows, static fn(array $row): bool => substr($row['date'], 0, 7) === $restMonth));
$restDays = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
$usualRest = array_map(static fn($day): string => $restDays[(int) $day] ?? '', employee_off_weekdays($employee['id']));
?>
<section id="jours-repos-travailles" class="section-block task-card">
  <div class="section-title"><div><h2>Jours de repos travaillés</h2><p>Déclarez les dates exceptionnellement travaillées pendant vos jours de repos.</p></div></div>
  <div class="rest-work-info"><p>Vos jours habituels de repos : <strong><?= h(implode(' et ', $usualRest)) ?></strong>.</p><p>Vos déclarations sont directement reprises dans le document RH du mois concerné, sans validation administrative.</p></div>
  <form class="panel" method="post" action="rest_work.php">
    <input type="hidden" name="csrf" value="<?= h(rest_work_token()) ?>">
    <input type="hidden" name="action" value="save">
    <div class="repeatable-list" data-repeatable-container="rest_work" data-max-date="<?= h(date('Y-m-d')) ?>">
      <div class="entry-grid rest-work-row" data-repeatable-row>
        <label class="field"><span>Date travaillée</span><input type="date" name="date[]" max="<?= h(date('Y-m-d')) ?>" required></label>
        <label class="field"><span>Motif (facultatif)</span><input type="text" name="reason[]" maxlength="200" placeholder="Événement, déplacement…"></label>
      </div>
    </div>
    <div class="inline-tools"><button class="btn btn-ghost" type="button" data-add-row="rest_work">+ Ajouter une ligne</button><button class="btn btn-primary" type="submit">Enregistrer la déclaration</button></div>
  </form>
  <div class="history">
    <div class="rest-work-history-head"><h3>Historique des déclarations</h3><span><strong><?= count($restMonthRows) ?> j</strong> · <?= h(month_label_fr($restMonth)) ?></span></div>
    <div class="panel table-panel">
      <table class="table rest-work-table"><thead><tr><th>Date</th><th>Jour</th><th>Motif</th><th>Actions</th></tr></thead><tbody>
        <?php if (!$restRows): ?><tr><td colspan="4" class="muted-cell">Aucun jour de repos travaillé déclaré.</td></tr><?php endif; ?>
        <?php foreach ($restRows as $row): ?>
          <tr>
            <td data-label="Date"><?= h((new DateTimeImmutable($row['date']))->format('d/m/Y')) ?></td>
            <td data-label="Jour"><?= h(rest_work_day($row['date'])) ?></td>
            <td data-label="Motif"><?= h($row['reason'] ?: '—') ?></td>
            <td class="actions-cell rest-work-actions"><a class="mini-link" href="rest_work.php?id=<?= urlencode($row['id']) ?>">Modifier</a><form method="post" action="rest_work.php" onsubmit="return confirm('Supprimer cette déclaration ?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= h($row['id']) ?>"><input type="hidden" name="csrf" value="<?= h(rest_work_token()) ?>"><button class="mini-link danger" type="submit">Supprimer</button></form></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table>
    </div>
  </div>
</section>
