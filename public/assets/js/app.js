(() => {
  const picker = document.querySelector('[data-user-picker]');
  if (picker) {
    const hidden = document.querySelector('[data-user-input]');
    picker.querySelectorAll('[data-user-id]').forEach((btn) => {
      btn.addEventListener('click', () => {
        picker.querySelectorAll('[data-user-id]').forEach((el) => { el.classList.remove('is-selected'); el.setAttribute('aria-pressed', 'false'); });
        btn.classList.add('is-selected');
        btn.setAttribute('aria-pressed', 'true');
        hidden.value = btn.dataset.userId || '';
      });
    });
  }

  const attachmentAccept = 'image/*,.pdf,.doc,.docx,.xls,.xlsx,.heic,.heif,.webp,.png,.jpg,.jpeg,.txt,.rtf,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain';

  const templates = {
    expense: (i) => `
      <div class="entry-grid expense-row" data-repeatable-row>
        <input type="hidden" name="row_id[]" value="${i}">
        <label class="field"><span>Date</span><input type="date" name="date[]" required></label>
        <label class="field span-2"><span>Descriptif</span><input type="text" name="description[]" required></label>
        <label class="field"><span>Montant TTC (€)</span><input type="text" name="amount_ttc[]" inputmode="decimal" required></label>
        <label class="field"><span>Montant HT (€)</span><input type="text" name="amount_ht[]" inputmode="decimal" required></label>
        <label class="field"><span>Paiement</span>
          <select name="payment_mode[]">
            <option value="cb">CB</option>
            <option value="cash">Espèce</option>
            <option value="transfer">Virement</option>
          </select>
        </label>
        <label class="field span-2"><span>Justificatif (facultatif)</span><input type="file" name="attachment_${i}[]" accept="${attachmentAccept}" capture="environment" multiple></label>
      </div>`,
    rest_work: () => `
      <div class="entry-grid rest-work-row" data-repeatable-row>
        <label class="field"><span>Date travaillée</span><input type="date" name="date[]" required></label>
        <label class="field"><span>Motif (facultatif)</span><input type="text" name="reason[]" maxlength="200" placeholder="Événement, déplacement…"></label>
        <button type="button" class="btn btn-ghost rest-work-remove" data-remove-rest-row>Retirer</button>
      </div>`,
    leave: (i) => `
      <div class="entry-grid leave-row" data-repeatable-row>
        <input type="hidden" name="row_id[]" value="${i}">
        <label class="field"><span>Type de congés</span>
          <select name="leave_type[]">
            <option value="paid">Congés payés</option>
            <option value="sick">Maladie</option>
            <option value="other">Autres</option>
          </select>
        </label>
        <label class="field"><span>Date de début</span><input type="date" name="start_date[]" required></label>
        <label class="field"><span>Date de fin</span><input type="date" name="end_date[]" required></label>
      </div>`
  };

  document.querySelectorAll('[data-add-row]').forEach((button) => {
    button.addEventListener('click', () => {
      const kind = button.getAttribute('data-add-row');
      const container = document.querySelector(`[data-repeatable-container="${kind}"]`);
      if (!container || !templates[kind]) return;
      const index = container.querySelectorAll('[data-repeatable-row]').length;
      if (kind === 'rest_work' && index >= 100) return;
      container.insertAdjacentHTML('beforeend', templates[kind](index));
      if (kind === 'rest_work') {
        const row = container.lastElementChild;
        const date = row.querySelector('input[type="date"]');
        date.max = container.dataset.maxDate;
        row.querySelector('[data-remove-rest-row]').addEventListener('click', () => row.remove());
        date.focus();
      }
    });
  });
})();
