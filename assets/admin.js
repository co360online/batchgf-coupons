jQuery(function ($) {
  function showNotice(message, type) {
    var notice = $('<div class="notice notice-' + type + ' is-dismissible"><p></p></div>');
    notice.find('p').text(message);
    $('.wrap h1').first().after(notice);
  }

  function renderResults(data) {
    var results = $('#gfbcu-results');
    var list = $('<table class="widefat striped"><thead><tr><th>Código</th><th>Copiar</th></tr></thead><tbody></tbody></table>');
    data.codes.forEach(function (code) {
      var row = $('<tr><td></td><td><button type="button" class="button gfbcu-copy">Copiar</button></td></tr>');
      row.find('td:first').text(code);
      row.find('.gfbcu-copy').attr('data-code', code);
      list.find('tbody').append(row);
    });

    results.html('');
    results.append('<h2>Resumen de generación</h2>');
    results.append('<p><a class="button" href="' + data.exportUrl + '">Exportar CSV</a></p>');
    results.append(list);
  }

  $('#gfbcu-generate-form').on('submit', function (e) {
    var count = parseInt($('#gfbcu-count').val(), 10);
    if (count <= 500 || !GFBCU_Admin) {
      return;
    }

    e.preventDefault();

    var form = $(this);
    var progress = $('#gfbcu-progress');
    var progressText = progress.find('.gfbcu-progress-text');
    var progressBar = progress.find('progress');
    progress.show();
    progressText.text(GFBCU_Admin.messages.progress);

    var offset = 0;
    var token = '';
    var allCodes = [];
    var payload = form.serializeArray();
    var basePayload = {};
    payload.forEach(function (item) {
      basePayload[item.name] = item.value;
    });
    basePayload.nonce = GFBCU_Admin.nonce;
    basePayload.action = 'gfbcu_generate_batch';

    function requestChunk() {
      var data = $.extend({}, basePayload, {
        offset: offset,
        chunk: GFBCU_Admin.chunk,
        token: token
      });

      $.post(GFBCU_Admin.ajaxUrl, data)
        .done(function (response) {
          if (!response.success) {
            showNotice(response.data.message, 'error');
            progress.hide();
            return;
          }

          if (response.data.notice) {
            showNotice(response.data.notice, 'warning');
          }
          token = response.data.token;
          offset = response.data.offset;
          allCodes = allCodes.concat(response.data.codes || []);
          progressBar.val(response.data.progress);

          if (offset >= count) {
            progressText.text(GFBCU_Admin.messages.complete);
            renderResults({
              codes: allCodes,
              exportUrl: response.data.exportUrl
            });
            progress.hide();
            return;
          }

          requestChunk();
        })
        .fail(function () {
          showNotice('Error al generar cupones.', 'error');
          progress.hide();
        });
    }

    requestChunk();
  });

  $(document).on('click', '.gfbcu-copy', function () {
    var code = $(this).data('code');
    if (!navigator.clipboard) {
      return;
    }
    navigator.clipboard.writeText(code);
  });
});
