(() => {
  const picker = document.querySelector('[data-user-picker]');
  if (picker) {
    const hidden = document.querySelector('[data-user-input]');
    picker.querySelectorAll('[data-user-id]').forEach((btn) => {
      btn.addEventListener('click', () => {
        picker.querySelectorAll('[data-user-id]').forEach((el) => el.classList.remove('is-selected'));
        btn.classList.add('is-selected');
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
        <label class="field"><span>TTC</span><input type="text" name="amount_ttc[]" inputmode="decimal" required></label>
        <label class="field"><span>HT</span><input type="text" name="amount_ht[]" inputmode="decimal" required></label>
        <label class="field"><span>Paiement</span>
          <select name="payment_mode[]">
            <option value="cb">CB</option>
            <option value="cash">Espèce</option>
            <option value="transfer">Virement</option>
          </select>
        </label>
        <label class="field span-2"><span>Justificatif</span><input type="file" name="attachment_${i}[]" accept="${attachmentAccept}" capture="environment" multiple></label>
      </div>`,
    leave: (i) => `
      <div class="entry-grid leave-row" data-repeatable-row>
        <input type="hidden" name="row_id[]" value="${i}">
        <label class="field"><span>Type</span>
          <select name="leave_type[]">
            <option value="paid">Congés payés</option>
            <option value="sick">Maladie</option>
            <option value="other">Autres</option>
          </select>
        </label>
        <label class="field"><span>Début</span><input type="date" name="start_date[]" required></label>
        <label class="field"><span>Fin</span><input type="date" name="end_date[]" required></label>
      </div>`
  };

  document.querySelectorAll('[data-add-row]').forEach((button) => {
    button.addEventListener('click', () => {
      const kind = button.getAttribute('data-add-row');
      const container = document.querySelector(`[data-repeatable-container="${kind}"]`);
      if (!container || !templates[kind]) return;
      const index = container.querySelectorAll('[data-repeatable-row]').length;
      container.insertAdjacentHTML('beforeend', templates[kind](index));
    });
  });
})();
