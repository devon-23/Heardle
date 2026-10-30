// Progressive enhancement. Every form here also works without JavaScript.
document.documentElement.classList.add('js');

// ---------------------------------------------------------------------------
// Import: show the chosen file name and highlight the drop zone while dragging.
// ---------------------------------------------------------------------------
for (const input of document.querySelectorAll('[data-file-input]')) {
  const zone = input.closest('[data-dropzone]');
  const label = zone.querySelector('[data-file-name]');

  input.addEventListener('change', () => {
    const file = input.files[0];
    label.hidden = !file;
    label.textContent = file ? file.name : '';
  });
  for (const type of ['dragenter', 'dragover']) {
    input.addEventListener(type, () => zone.classList.add('is-dragging'));
  }
  for (const type of ['dragleave', 'drop']) {
    input.addEventListener(type, () => zone.classList.remove('is-dragging'));
  }
}

// ---------------------------------------------------------------------------
// Concert review: save attendance as boxes are ticked, and "Looks right".
// ---------------------------------------------------------------------------
const latestRequest = new WeakMap();

async function postForm(form) {
  const response = await fetch(form.action, {
    method: 'POST',
    body: new FormData(form),
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(data.error || 'Couldn’t save. Try again.');
  }
  return data;
}

function applyEvent(data) {
  const card = document.getElementById(`event-${data.id}`);
  if (!card) return;

  card.querySelector('[data-attended]').textContent = data.attended;
  card.classList.toggle('event--attention', data.needs_attention);
  if (!data.flags_pending) {
    card.querySelector('.js-review')?.remove();
  }
  for (const p of data.performances) {
    const note = card.querySelector(`[data-note-for="${p.id}"]`);
    if (note) note.textContent = p.source === 'user' ? 'edited' : '';
  }
  for (const [key, value] of Object.entries(data.stats)) {
    const el = document.querySelector(`[data-stat="${key}"]`);
    if (el) el.textContent = value;
  }
}

async function save(form, status, onError) {
  const id = (latestRequest.get(form) || 0) + 1;
  latestRequest.set(form, id);
  if (status) {
    status.classList.remove('is-error');
    status.textContent = 'Saving…';
  }
  try {
    const data = await postForm(form);
    // Each request sends the whole form, so only the newest response matters.
    if (latestRequest.get(form) !== id) return;
    applyEvent(data);
    if (status) status.textContent = 'Saved';
  } catch (error) {
    onError?.();
    if (status) {
      status.classList.add('is-error');
      status.textContent = error.message;
    }
  }
}

document.addEventListener('change', (event) => {
  const box = event.target;
  if (!box.matches('.js-attendance input[type="checkbox"]')) return;
  const form = box.form;
  save(form, form.querySelector('.lineup-status'), () => { box.checked = !box.checked; });
});

// ---------------------------------------------------------------------------
// Scan: poll the job while it runs, then reload to show the results.
// ---------------------------------------------------------------------------
const scan = document.querySelector('[data-job][data-job-id]');
if (scan && ['queued', 'running'].includes(scan.dataset.jobStatus)) {
  const order = ['artists', 'setlists', 'songs', 'matches'];
  const setMeter = (key, pct, text, active) => {
    const meter = scan.querySelector(`[data-meter="${key}"]`);
    meter.querySelector('progress').value = pct;
    meter.querySelector('[data-meter-count]').textContent = text;
    meter.classList.toggle('is-active', active);
  };

  const poll = async () => {
    let job;
    try {
      const response = await fetch(`/api/jobs/${scan.dataset.jobId}`, { headers: { Accept: 'application/json' } });
      job = await response.json();
    } catch {
      setTimeout(poll, 4000);
      return;
    }
    if (!['queued', 'running'].includes(job.status)) {
      window.location.reload();
      return;
    }
    const current = order.indexOf(job.stage);
    for (const [i, key] of order.entries()) {
      if (i < current) setMeter(key, 100, 'done', false);
      else if (i === current) {
        const pct = job.total > 0 ? Math.floor((job.done / job.total) * 100) : 0;
        setMeter(key, pct, `${job.done} / ${job.total}`, true);
      }
    }
    scan.querySelector('[data-job-label]').textContent = job.label || '';
    scan.querySelector('[data-job-state]').textContent = job.status === 'queued' ? 'Waiting for the worker…' : 'Scanning…';
    setTimeout(poll, 2000);
  };
  setTimeout(poll, 1000);
}

document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!form.matches('.js-attendance, .js-review')) return;
  event.preventDefault();
  const card = form.closest('.event');
  save(form, card?.querySelector('.lineup-status'));
});

// ---------------------------------------------------------------------------
// Apple Music review: save each decision in place and drive the queue from
// the keyboard (j/k move, 1–5 pick, enter confirm, x leave off).
// ---------------------------------------------------------------------------
const queue = document.querySelector('[data-match-queue]');
const tiles = document.querySelector('.tiles');
let currentRow = null;

const openRows = () => [...queue.querySelectorAll('[data-match]:not(.is-done)')];

function selectRow(row, focus = true) {
  if (!row) return;
  currentRow?.classList.remove('is-current');
  currentRow = row;
  row.classList.add('is-current');
  if (focus) {
    row.focus({ preventScroll: true });
    row.scrollIntoView({ block: 'nearest', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
  }
}

function moveRow(step) {
  const rows = [...queue.querySelectorAll('[data-match]')];
  const index = rows.indexOf(currentRow);
  selectRow(rows[Math.min(rows.length - 1, Math.max(0, index + step))]);
}

if (queue) {
  const fromHash = location.hash.startsWith('#song-') ? document.getElementById(location.hash.slice(1)) : null;
  selectRow(fromHash && queue.contains(fromHash) ? fromHash : openRows()[0], Boolean(fromHash));
  queue.addEventListener('click', (event) => {
    const row = event.target.closest('[data-match]');
    if (row && row !== currentRow) selectRow(row, false);
  });
}

document.addEventListener('submit', async (event) => {
  const form = event.target;
  if (!form.matches('.js-match') || !(queue?.contains(form) || tiles?.contains(form))) return;
  event.preventDefault();
  const item = form.closest('[data-match], .tile');
  if (item.dataset.busy) return;
  item.dataset.busy = '1';
  const status = item.querySelector('[data-match-status]');
  try {
    const data = await postForm(form);
    item.classList.add('is-done');
    if (status) status.textContent = data.message;
    else item.querySelector('.tile__action')?.replaceChildren(Object.assign(document.createElement('p'), { className: 'small muted', textContent: data.message }));

    const picked = form.querySelector('[data-pick]');
    if (picked) {
      item.querySelectorAll('.option').forEach((option) => option.classList.remove('is-pick'));
      form.closest('.option').classList.add('is-pick');
    }
    if (queue?.contains(item) && item === currentRow) {
      const next = openRows().find((row) => row.compareDocumentPosition(item) & Node.DOCUMENT_POSITION_PRECEDING) || openRows()[0];
      selectRow(next);
    }
  } catch (error) {
    if (status) status.textContent = error.message;
  } finally {
    delete item.dataset.busy;
  }
});

document.addEventListener('keydown', (event) => {
  if (!queue || !currentRow || event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) return;
  if (event.target.closest('input, textarea, select, [contenteditable]')) return;

  if (event.key === 'j') moveRow(1);
  else if (event.key === 'k') moveRow(-1);
  else if (/^[1-5]$/.test(event.key)) currentRow.querySelector(`[data-pick="${event.key}"]`)?.click();
  else if (event.key === 'x') currentRow.querySelector('[data-action="exclude"]')?.click();
  else if (event.key === 'Enter' && (event.target === currentRow || event.target === document.body)) {
    const accept = currentRow.querySelector('[data-action="accept"]');
    if (accept) accept.click();
    else moveRow(1);
  } else return;
  event.preventDefault();
});
