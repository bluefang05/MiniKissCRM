// File: /assets/js/spreadsheet-importer.js
;(function(window, XLSX) {
  if (!XLSX) {
    console.error('SheetJS (XLSX) not found – make sure you load it first');
    return;
  }

  /**
   * Initialize the importer on a given form + file input.
   * @param {Object} cfg
   * @param {string} cfg.formId      – the ID of your <form>
   * @param {string} cfg.inputId     – the ID of your <input type="file">
   * @param {string} [cfg.textareaId='csv_data'] – optional: id for hidden CSV holder
   */
  function init(cfg) {
    const formEl  = document.getElementById(cfg.formId);
    const fileEl  = document.getElementById(cfg.inputId);
    const taId    = cfg.textareaId || 'csv_data';

    if (!formEl || !fileEl) {
      console.error(`SpreadsheetImporter: cannot find #${cfg.formId} or #${cfg.inputId}`);
      return;
    }

    formEl.addEventListener('submit', e => {
      const file = fileEl.files[0];
      if (!file) return; // no file selected

      const ext = file.name.split('.').pop().toLowerCase();
      if (ext === 'csv') {
        e.preventDefault();
        readText(file);
      } else if (['xls','xlsx','ods'].includes(ext)) {
        e.preventDefault();
        readBinary(file);
      }
      // else: allow normal submit (e.g. you might handle other types)
    });

    function readText(file) {
      const r = new FileReader();
      r.onload = ev => submitCsv(ev.target.result);
      r.readAsText(file);
    }

    function readBinary(file) {
      const r = new FileReader();
      r.onload = ev => {
        try {
          // parse as array buffer for binary formats
          const wb    = XLSX.read(ev.target.result, { type: 'array' });
          const sheet = wb.Sheets[wb.SheetNames[0]];
          const csv   = XLSX.utils.sheet_to_csv(sheet);
          submitCsv(csv);
        } catch (err) {
          console.error('SpreadsheetImporter parse error:', err);
          alert('Could not parse spreadsheet: ' + err.message);
        }
      };
      r.readAsArrayBuffer(file);
    }

    function submitCsv(csvText) {
      let ta = document.getElementById(taId);
      if (!ta) {
        ta = document.createElement('textarea');
        ta.id    = taId;
        ta.name  = 'csv_data';
        ta.style.display = 'none';
        formEl.appendChild(ta);
      }
      ta.value = csvText;
      formEl.submit();
    }
  }

  // expose
  window.SpreadsheetImporter = { init };

})(window, window.XLSX);
