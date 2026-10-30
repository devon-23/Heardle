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
  const order = ['artists', 'setlists'];
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
