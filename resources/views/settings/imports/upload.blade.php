@extends('layouts.skeleton')

@section('content')

<div class="settings upload">

  {{-- Breadcrumb --}}
  <div class="breadcrumb">
    <div class="{{ Auth::user()->getFluidLayout() }}">
      <div class="row">
        <div class="col-12">
          <ul class="horizontal">
            <li>
                <a href="{{ route('dashboard.index') }}">{{ trans('app.breadcrumb_dashboard') }}</a>
              </li>
              <li>
                <a href="{{ route('settings.index') }}">{{ trans('app.breadcrumb_settings') }}</a>
              </li>
              <li>
                <a href="{{ route('settings.import') }}">{{ trans('app.breadcrumb_settings_import') }}</a>
              </li>
              <li>
                {{ trans('app.breadcrumb_settings_import_upload') }}
              </li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <div class="main-content central-form">
    <div class="{{ auth()->user()->getFluidLayout() }}">
      <div class="row">
        <div class="col-12 col-sm-6 offset-sm-3 offset-sm-3-right">

          {{-- ════════ Background import: CSV or vCard (uses /api/import) ════════ --}}
          <div class="br3 ba b--gray-monica bg-white mb4">
            <div class="pa3 bb b--gray-monica">
              <h2>Import contacts</h2>

              <div class="warning-zone">
                <p>Upload a <strong>CSV</strong> (columns <code>name</code>, <code>email</code>, <code>phone</code> —
                  <code>name</code> required) or a <strong>vCard</strong> (<code>.vcf</code>) file. The file is
                  processed in the background in batches — progress updates live below, and rows that fail
                  validation are skipped and listed in a downloadable error report.</p>
              </div>

              <div class="form-group">
                <label for="import-file">CSV or vCard file</label>
                <input type="file" class="form-control-file" id="import-file" accept=".csv,.txt,.vcf,.vcard,text/csv,text/vcard,text/x-vcard,text/plain">
                <small class="form-text text-muted">{{ trans('people.information_edit_max_size', ['size' => config('monica.max_upload_size')]) }}</small>
              </div>

              <div class="form-group actions">
                <button id="import-upload-btn" type="button" class="btn btn-primary">Upload &amp; import</button>
                <button id="import-cancel-btn" type="button" class="btn btn-secondary" style="display:none">Cancel import</button>
                <a href="{{ route('settings.import') }}" class="btn btn-secondary">{{ trans('app.cancel') }}</a>
              </div>

              <div id="import-alert" class="mt2" style="display:none"></div>

              <div id="import-progress-wrap" style="display:none" class="mt3">
                <div style="background:#eef0f2;border-radius:4px;height:18px;overflow:hidden">
                  <div id="import-bar" style="background:#5c92e8;height:100%;width:0%;transition:width .3s ease"></div>
                </div>
                <p id="import-status" class="mt2 mb0 f6"></p>
                <p id="import-errors-link" class="mt1 mb0 f6" style="display:none"></p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

@endsection

@push('scripts')
<script>
(function () {
  // The /api routes authenticate via Passport's laravel_token cookie
  // (CreateFreshApiToken middleware) validated against this CSRF token.
  var CSRF = '{{ csrf_token() }}';
  var TERMINAL = ['completed', 'failed', 'cancelled'];
  var currentId = null;

  // NOTE: this view and app.js both add to the 'scripts' stack, and app.js
  // mounts Vue on #app — which re-creates the nodes inside it. So we bind via
  // event delegation on `document` (a stable node) rather than on the buttons
  // directly, which makes this immune to that re-mount and to script ordering.
  function el(id) { return document.getElementById(id); }

  function alertMsg(msg, ok) {
    var a = el('import-alert');
    a.style.display = 'block';
    a.textContent = msg;
    a.style.color = ok ? '#2f7a33' : '#c0392b';
  }

  function api(url, opts) {
    opts = opts || {};
    opts.credentials = 'same-origin';
    opts.headers = Object.assign({ 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, opts.headers || {});
    return fetch(url, opts);
  }

  function render(job) {
    el('import-bar').style.width = (job.progress_pct || 0) + '%';
    var line = 'Status: ' + job.status + ' — ' + job.processed_rows + ' / ' + job.total_rows + ' rows';
    if (job.failed_rows) { line += ' · ' + job.failed_rows + ' failed'; }
    if (job.estimated_remaining_sec != null) { line += ' · ~' + job.estimated_remaining_sec + 's remaining'; }
    el('import-status').textContent = line;
  }

  function finish(job) {
    el('import-upload-btn').disabled = false;
    el('import-cancel-btn').style.display = 'none';
    if (job.failed_rows > 0) {
      var l = el('import-errors-link');
      l.style.display = 'block';
      l.innerHTML = '<a href="#" id="import-dl">Download error report (' + job.failed_rows + ' failed rows)</a>';
    }
  }

  function poll(id) {
    api('/api/import/' + id)
      .then(function (r) { return r.json(); })
      .then(function (body) {
        var job = body.data;
        render(job);
        if (TERMINAL.indexOf(job.status) !== -1) { finish(job); return; }
        setTimeout(function () { poll(id); }, 1000);
      })
      .catch(function (e) { alertMsg('Lost connection while tracking progress: ' + e, false); el('import-upload-btn').disabled = false; });
  }

  function downloadErrors() {
    api('/api/import/' + currentId + '/errors.csv')
      .then(function (r) { return r.blob(); })
      .then(function (blob) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url; a.download = 'errors-' + currentId + '.csv';
        document.body.appendChild(a); a.click(); a.remove();
        URL.revokeObjectURL(url);
      });
  }

  function upload() {
    var fi = el('import-file');
    if (!fi.files.length) { alertMsg('Please choose a CSV or vCard file first.', false); return; }
    el('import-alert').style.display = 'none';
    el('import-errors-link').style.display = 'none';
    el('import-upload-btn').disabled = true;

    var fd = new FormData();
    fd.append('file', fi.files[0]);

    api('/api/import', { method: 'POST', body: fd })
      .then(function (r) { return r.json().then(function (b) { return { status: r.status, body: b }; }); })
      .then(function (res) {
        if (res.status !== 201 && res.status !== 200) {
          alertMsg('Upload rejected: ' + (res.body.message || JSON.stringify(res.body)), false);
          el('import-upload-btn').disabled = false;
          return;
        }
        var job = res.body.data;
        currentId = job.id;
        alertMsg(res.status === 200 ? 'This file was already imported — showing that import.' : 'Import started in the background.', true);
        el('import-progress-wrap').style.display = 'block';
        el('import-cancel-btn').style.display = 'inline-block';
        render(job);
        if (TERMINAL.indexOf(job.status) !== -1) { finish(job); } else { poll(job.id); }
      })
      .catch(function (e) { alertMsg('Upload failed: ' + e, false); el('import-upload-btn').disabled = false; });
  }

  function cancel() {
    if (!currentId) { return; }
    el('import-cancel-btn').disabled = true;
    api('/api/import/' + currentId + '/cancel', { method: 'POST' })
      .then(function (r) { return r.json(); })
      .then(function (body) { render(body.data); })
      .finally(function () { el('import-cancel-btn').disabled = false; });
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.id) { return; }
    if (t.id === 'import-upload-btn') { e.preventDefault(); upload(); }
    else if (t.id === 'import-cancel-btn') { e.preventDefault(); cancel(); }
    else if (t.id === 'import-dl') { e.preventDefault(); downloadErrors(); }
  });
})();
</script>
@endpush
