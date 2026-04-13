<?php /** @var array $calendar */ ?>
<section class="calendar-panel">
  <div class="calendar-head">
    <div>
      <strong><?= h($calendar['label']) ?></strong>
    </div>
    <div class="calendar-actions no-print">
      <a class="btn btn-ghost" href="?month=<?= urlencode($calendar['prev']) ?>">←</a>
      <a class="btn btn-ghost" href="?month=<?= urlencode($calendar['next']) ?>">→</a>
      <button class="btn btn-ghost" type="button" onclick="window.print()">Imprimer</button>
    </div>
  </div>
  <div class="calendar-legend">
    <span class="legend-item"><i class="legend-swatch swatch-pending"></i>Demandé</span>
    <span class="legend-item"><i class="legend-swatch swatch-approved"></i>Accordé</span>
  </div>
  <div class="calendar-grid">
    <?php foreach (['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $label): ?>
      <div class="calendar-weekday"><?= h($label) ?></div>
    <?php endforeach; ?>
    <?php foreach ($calendar['weeks'] as $week): ?>
      <?php foreach ($week as $day): ?>
        <article class="calendar-day <?= $day['in_month'] ? '' : 'is-out' ?> <?= $day['holiday'] ? 'is-holiday' : '' ?>">
          <header><span><?= h((string) $day['day']) ?></span></header>
          <div class="calendar-chips">
            <?php foreach ($day['entries'] as $entry): ?>
              <?php $accent = employee_accent((string) ($entry['employee_id'] ?? '')); $fill = ((string) ($entry['status'] ?? 'pending') === 'approved') ? $accent : tint_hex($accent, 0.58); $textColor = ((string) ($entry['status'] ?? 'pending') === 'approved') ? '#ffffff' : '#1f3561'; ?>
              <span class="leave-chip <?= h(leave_status_class((string) ($entry['status'] ?? 'pending'))) ?>" style="background: <?= h($fill) ?>; color: <?= h($textColor) ?>; border-color: <?= h($accent) ?>">
                <?= h(leave_chip_label($entry)) ?>
              </span>
            <?php endforeach; ?>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
</section>
