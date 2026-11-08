// Page logic: welcome page, sign in / password reset, and the dashboard.
const welcomePage = document.getElementById('welcome-page');
const loginPage = document.getElementById('login-page');
const dashboardPage = document.getElementById('dashboard-page');
const form = document.getElementById('login-form');
const message = document.getElementById('message');

async function showDashboard(user) {
  document.getElementById('me-name').textContent = user.name;
  document.getElementById('me-email').textContent = user.email;
  document.getElementById('me-role').textContent = user.role === 'StatDept' ? 'Stat-Dept' : user.role;
  document.getElementById('me-id').textContent = `ID ${user.id}`;
  document.getElementById('portal-name').textContent = `${user.role === 'StatDept' ? 'Stat-Dept' : user.role} portal`;
  welcomePage.hidden = true;
  loginPage.hidden = true;
  dashboardPage.hidden = false;
  const { views } = await (await fetch('/api/views')).json();
  const nav = document.getElementById('nav');
  nav.replaceChildren(...views.map((v) => {
    const a = document.createElement('a');
    a.href = `#${v.id}`;
    a.textContent = v.label;
    a.dataset.view = v.id;
    return a;
  }));
  return openDashboardLocation();
}

let currentView = null;
let currentParams = {};
let viewRequestId = 0;
// Hash parameters are data for the view; the role-specific navigation decides which view may open.
function openDashboardLocation() {
  const hash = location.hash.slice(1);
  const separator = hash.indexOf('?');
  const id = separator < 0 ? hash : hash.slice(0, separator);
  const params = Object.fromEntries(new URLSearchParams(separator < 0 ? '' : hash.slice(separator + 1)));
  const links = [...document.querySelectorAll('#nav a[data-view]')];
  const wanted = links.find((a) => a.dataset.view === id);
  const target = wanted || links[0];
  if (target) return openView(target.dataset.view, wanted ? params : {}, null, 'replace');
}

async function openView(id, params = {}, flash = null, historyMode = 'push') {
  const requestId = ++viewRequestId;
  currentView = id;
  currentParams = params;
  document.querySelectorAll('#nav a').forEach((a) => a.classList.toggle('active', a.dataset.view === id));
  const qs = new URLSearchParams(params).toString();
  const hash = `#${id}${qs ? `?${qs}` : ''}`;
  if (location.hash !== hash) {
    history[historyMode === 'replace' ? 'replaceState' : 'pushState'](null, '', hash);
  }
  const blocks = document.getElementById('page-blocks');
  const title = document.getElementById('page-title');
  const subtitle = document.getElementById('page-subtitle');
  title.parentElement.dataset.view = id;
  if (id === 'overview') {
    title.textContent = 'Loading your dashboard…';
    subtitle.textContent = '';
  }
  const loading = el('p', 'view-loading', 'Loading…');
  loading.setAttribute('role', 'status');
  blocks.replaceChildren(loading);
  const isCurrent = () => requestId === viewRequestId && !blocks.closest('[hidden]');
  let res, page;
  try {
    res = await fetch(`/api/views/${id}${qs ? `?${qs}` : ''}`);
    if (!isCurrent()) return;
    if (res.status === 401) return showLogin();
    page = await res.json();
  } catch {
    if (!isCurrent()) return;
    page = { error: 'The page could not load. Try again.' };
  }
  // Ignore older responses when another lookup or filter request has started.
  if (!isCurrent()) return;
  if (!res || res.status >= 400 || !page || page.error) {
    title.textContent = 'Page unavailable';
    subtitle.textContent = '';
    const error = renderFlash({ ok: false, heading: 'Page unavailable', message: page?.error || 'Try again.' });
    error.setAttribute('role', 'alert');
    const retry = el('button', 'view-retry', 'Retry');
    retry.type = 'button';
    retry.addEventListener('click', () => openView(id, params, flash, 'replace'));
    blocks.replaceChildren(error, retry);
    return;
  }
  // Use the server-validated Master Schedule choices for refreshes and future filter changes.
  if (id === 'master-schedule' && res.ok) {
    const filters = (page.blocks || []).find((b) => b.type === 'filters')?.filters || [];
    const choices = filters.filter((f) => f.name === 'semester' || f.name === 'dept');
    if (choices.length === 2) {
      currentParams = Object.fromEntries(choices.map((f) => [f.name, f.value]));
      history.replaceState(null, '', `#${id}?${new URLSearchParams(currentParams)}`);
    }
  }
  title.textContent = page.title;
  subtitle.textContent = page.subtitle || '';
  blocks.replaceChildren(...(flash ? [renderFlash(flash)] : []), ...(page.blocks || []).map((b) => renderBlock(b, id)));
}

function renderStudentOverview(overview, viewId) {
  const root = el('div', 'student-overview');
  const section = (id, title, note) => {
    const panel = el('section', 'overview-panel');
    const heading = el('h3', null, title);
    heading.id = id;
    panel.setAttribute('aria-labelledby', id);
    panel.append(heading);
    if (note) panel.append(el('p', 'overview-note', note));
    return panel;
  };

  const summary = section('overview-summary', 'Academic summary');
  const summaryHead = el('div', 'overview-summary-head');
  summaryHead.append(summary.firstChild);
  if (overview.filter) {
    const filter = renderBlock(overview.filter, viewId, async (params) => {
      const request = openView(viewId, params);
      const requestId = viewRequestId;
      await request;
      if (requestId === viewRequestId && document.activeElement === document.body) {
        document.querySelector('.overview-summary-head select')?.focus();
      }
    });
    summaryHead.append(filter);
  }
  summary.append(summaryHead);

  const metrics = el('dl', 'overview-metrics');
  const metric = (label, value, note, details) => {
    const card = el('div', 'overview-metric');
    const body = el('dd');
    body.append(el('div', 'overview-value', value));
    if (note) body.append(el('p', 'overview-note', note));
    if (details?.length) {
      const list = el('ul', 'overview-details');
      for (const detail of details) list.append(el('li', null, detail));
      body.append(list);
    }
    card.append(el('dt', null, label), body);
    metrics.append(card);
  };

  for (const [kind, empty] of [['Major', 'Not on file'], ['Minor', 'None declared']]) {
    const programs = overview.programs.filter(p => p.kind === kind);
    const names = programs.map(p => p.name + (p.is_primary && programs.length > 1 ? ' (primary)' : ''));
    const missingDeclaration = programs.some(p => !p.declared);
    metric(kind, names.length > 1 ? `${names.length} on file` : names[0] || empty,
      missingDeclaration ? 'The primary major is on file without a declaration link.' : null,
      names.length > 1 ? names : null);
  }

  const count = overview.sections.length;
  metric('Cumulative GPA', overview.gpa ?? 'Unavailable', overview.gpa === null ? 'No GPA is available from course history.' : 'Based on course history.');
  metric('Credits earned', overview.provisional_credits, 'Provisional passing-course credits from Degree Audit.',
    overview.stored_credits === null ? null : [`Student record total: ${overview.stored_credits}`]);
  metric(overview.semester ? `${overview.semester} classes` : 'Semester classes',
    overview.semester ? `${count} registered ${count === 1 ? 'section' : 'sections'}` : 'Unavailable',
    overview.semester ? `${overview.enrolled_credits} enrolled credits` : 'No semesters are on file.');
  metric('Hold records on file', overview.hold_count, 'Current status is unavailable.',
    overview.holds.map(hold => `${hold.type}: ${hold.status}`));
  summary.append(metrics);
  root.append(summary);

  const shortcuts = el('nav', 'overview-panel overview-shortcuts');
  shortcuts.setAttribute('aria-labelledby', 'overview-actions');
  const shortcutHeading = el('h3', null, 'Quick actions');
  shortcutHeading.id = 'overview-actions';
  const links = el('div', 'overview-links');
  for (const id of ['schedule', 'register', 'degree-audit', 'grades', 'transcript']) {
    const navLink = document.querySelector(`#nav [data-view="${id}"]`);
    if (!navLink) continue;
    const link = el('a', null, navLink.textContent);
    link.href = navLink.getAttribute('href');
    link.addEventListener('click', async event => {
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      const request = openView(id);
      const requestId = viewRequestId;
      await request;
      if (requestId === viewRequestId && !dashboardPage.hidden && document.activeElement === document.body) {
        document.getElementById('page-title').focus();
      }
    });
    links.append(link);
  }
  shortcuts.append(shortcutHeading, links);
  root.append(shortcuts);

  const weekly = section('overview-weekly', 'Weekly timetable', overview.semester);
  weekly.classList.add('overview-timetable');
  const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
  const byDay = new Map(weekdays.map(day => [day, []]));
  let meetingCount = 0;
  for (const course of overview.sections) {
    for (const meeting of course.meetings) {
      if (!meeting.day || !meeting.time) continue;
      if (!byDay.has(meeting.day)) byDay.set(meeting.day, []);
      byDay.get(meeting.day).push({ ...meeting, course });
      meetingCount++;
    }
  }

  if (meetingCount) {
    const week = el('div', 'overview-week');
    for (const day of [...weekdays, 'Saturday', 'Sunday']) {
      const meetings = byDay.get(day);
      if (!meetings) continue;
      const column = el('div', 'overview-day');
      column.append(el('h4', null, day));
      if (!meetings.length) column.append(el('p', 'overview-note overview-no-meetings', 'No classes'));
      else {
        const list = el('ul', 'overview-meetings');
        meetings.sort((a, b) => a.starts_at.localeCompare(b.starts_at));
        for (const meeting of meetings) {
          const item = el('li', 'overview-meeting');
          item.append(el('span', 'overview-meeting-time', meeting.time),
            el('strong', null, meeting.course.code),
            el('span', null, meeting.course.title),
            el('span', 'overview-note', meeting.room ?? 'Room unavailable'),
            el('span', 'overview-meeting-kind', `${meeting.kind} · CRN ${meeting.course.crn}`));
          list.append(item);
        }
        column.append(list);
      }
      week.append(column);
    }
    weekly.append(week);
  } else {
    weekly.append(el('p', 'overview-empty', count ? 'No meeting days and times are on file for these sections.' :
      overview.semester ? 'You have no registered sections for this semester.' : 'No semester is available.'));
  }
  root.append(weekly);

  // Group each meeting type by time and room for the table, keeping one row per CRN.
  const meetingColumns = (course, kind) => {
    const groups = new Map();
    for (const meeting of course.meetings.filter(m => m.kind === kind)) {
      const key = JSON.stringify([meeting.time, meeting.room]);
      if (!groups.has(key)) groups.set(key, { time: meeting.time, room: meeting.room, days: new Set() });
      if (meeting.day) groups.get(key).days.add(meeting.day.slice(0, 3));
    }
    if (!groups.size) return [null, null, null];
    const entries = [...groups.values()];
    return [entries.map(m => [...m.days].join(', ') || '—').join('\n'),
      entries.map(m => m.time ?? '—').join('\n'), entries.map(m => m.room ?? '—').join('\n')];
  };
  const table = renderBlock({
    type: 'table',
    title: overview.semester ? `${overview.semester} schedule` : 'Semester schedule',
    columns: ['CRN', 'Course', 'Title', 'Section', 'Credits', 'Level', 'Days', 'Instructor', 'Time', 'Room', 'Lab days', 'Lab time', 'Lab room'],
    rows: overview.sections.map(course => {
      const [days, time, room] = meetingColumns(course, 'Lecture');
      return [course.crn, course.code, course.title, course.section, course.credits, course.level,
        days, course.instructor, time, room, ...meetingColumns(course, 'Lab')];
    }),
  }, viewId);
  table.classList.add('overview-schedule');
  const tableHeading = table.querySelector('h3');
  tableHeading.id = 'overview-schedule';
  table.querySelector('.table-head span').textContent = `${count} ${count === 1 ? 'section' : 'sections'}`;
  if (count) {
    const wrap = table.querySelector('.table-wrap');
    wrap.tabIndex = 0;
    wrap.setAttribute('role', 'region');
    wrap.setAttribute('aria-labelledby', tableHeading.id);
    wrap.before(el('p', 'overview-note overview-scroll-hint', 'Scroll the table horizontally to see all columns.'));
    wrap.querySelectorAll('th').forEach(th => { th.scope = 'col'; });
  } else {
    table.querySelector('.empty-row').textContent = overview.semester ?
      'Registered sections for this semester will appear here.' : 'No semester is available.';
  }
  root.append(table);
  return root;
}

// Student audit: recorded progress is separated from policies the database cannot establish.
function renderDegreeAudit(audit) {
  const root = el('div', 'degree-audit');
  const section = (title, note = null) => {
    const box = el('section', 'audit-section');
    box.append(el('h3', 'audit-section-title', title));
    if (note) box.append(el('p', 'muted audit-note', note));
    return box;
  };
  const auditTable = (title, columns, rows, empty) => {
    const block = renderBlock({ type: 'table', title, columns, rows, note: null }, null);
    if (!rows.length) block.querySelector('.empty-row').textContent = empty;
    return block;
  };
  const profile = section('Student academic profile');
  profile.append(el('p', 'audit-student-name', audit.profile.name));
  const fields = el('dl', 'audit-profile-grid');
  const majors = audit.programs.filter(p => p.kind === 'Major');
  const minors = audit.programs.filter(p => p.kind === 'Minor');
  for (const [label, value] of [
    ['Student ID', audit.profile.id], ['Student type', audit.profile.type],
    ['Academic year', audit.profile.year], ['Enrollment status', audit.profile.enrollment_status],
    ['Degree program', majors.map(p => `${p.name}${Number(p.is_primary) ? ' (primary)' : ''}`).join('; ') || 'Not on file'],
    ['Minor', minors.map(p => p.name).join('; ') || 'None declared'], ['Records checked', audit.profile.as_of],
  ]) {
    const field = el('div'); field.append(el('dt', null, label), el('dd', null, String(value))); fields.append(field);
  }
  profile.append(fields); root.append(profile);

  const credit = section('Overall credit progress', 'Program-specific graduation credit targets are not stored. Overall degree completion cannot be calculated.');
  credit.append(renderBlock({ type: 'stats', items: [
    { label: 'Passing-course credits · provisional', value: audit.credits.earned_provisional },
    { label: 'In-progress credits · not earned', value: audit.credits.in_progress },
    { label: 'Future registered credits · not earned', value: audit.credits.future_registered },
    { label: 'Overall degree completion', value: 'Unavailable' },
  ] }, null));
  credit.append(el('p', 'muted audit-note', `${audit.credits.passing_courses} distinct courses with recognized passing transcript grades. Credits count once per course using current course credits. In-progress totals also count distinct courses and are not added to earned credits; repeats and graduation credit policies need confirmation.`));
  credit.append(el('p', 'muted audit-note', `Stored student credit total: ${audit.profile.stored_credits ?? 'Unavailable'}. This separate record is not used as a graduation target.`));
  root.append(credit);

  for (const [kind, programs] of [['Major', majors], ['Minor', minors]]) {
    const group = section(`${kind} requirements`, 'Percentages describe only each stored required-course list. Programs are checked independently; cross-program credit sharing and final degree eligibility are not established.');
    if (!programs.length) group.append(el('p', 'muted', kind === 'Minor' ? 'No minor declared.' : 'No major recorded. Requirements are unavailable.'));
    for (const program of programs) {
      const card = el('details', 'audit-program'); card.open = true;
      const summary = el('summary', 'audit-program-heading');
      const title = el('div'); title.append(el('strong', null, program.name), el('span', 'muted small', program.id));
      const state = el('span', `audit-state${program.total && program.met === program.total ? ' audit-state-met' : ''}`,
        program.percent === null ? 'Requirements unavailable' : `${program.met} of ${program.total} listed courses met · ${program.percent}%`);
      summary.append(title, state); card.append(summary);
      if (program.percent !== null) {
        const meter = el('progress', 'audit-progress'); meter.max = program.total; meter.value = program.met;
        meter.setAttribute('aria-label', `${program.name}: ${program.met} of ${program.total} listed course requirements met`); card.append(meter);
        card.append(el('p', 'muted audit-program-note', `${program.met_credits} of ${program.listed_credits} credits represented by this course list are met in records. This is not the full degree credit requirement.`));
      }
      card.append(auditTable('Required courses', ['Course', 'Title', 'Credits', 'Minimum grade', 'Best recorded grade', 'Transcript attempts', 'Status'],
        program.requirements.map(r => [r.code, r.title, r.credits, r.minimum_grade, r.best_grade ?? '—',
          r.attempts.map(a => `${a.grade} · ${a.semester}`).join('; ') || 'None recorded', r.status]), 'No requirement mappings are stored for this program. Completion is unavailable.'));
      group.append(card);
    }
    root.append(group);
  }

  const unavailable = el('div', 'audit-unavailable-grid');
  for (const item of audit.unavailable) {
    const card = section(item.title); card.append(el('span', 'audit-state', 'Unavailable'), el('p', 'muted audit-note', item.reason)); unavailable.append(card);
  }
  root.append(unavailable);

  const current = section('Courses currently in progress', 'Only ungraded enrollments in semesters that have started and have not ended appear here. These courses do not satisfy completed requirements.');
  const enrollmentRows = list => list.map(c => [c.code, c.title, c.crn, c.credits, c.semester]);
  const enrollmentColumns = ['Course', 'Title', 'CRN', 'Credits', 'Semester'];
  current.append(auditTable('Current courses', enrollmentColumns, enrollmentRows(audit.in_progress), 'No current ungraded enrollments are recorded.'));
  current.append(auditTable('Future registrations', enrollmentColumns, enrollmentRows(audit.future_registered), 'No future registrations are recorded.'));
  root.append(current);

  const outstanding = section('Outstanding graduation requirements', 'Graduation eligibility is unavailable. The list below identifies unmet stored major/minor course requirements only; other graduation rules still require confirmation.');
  outstanding.append(auditTable('Unmet stored course requirements', ['Program', 'Type', 'Course', 'Minimum grade', 'Status'],
    audit.outstanding.map(r => [r.program, r.kind, r.code, r.minimum_grade, r.status]),
    'No unmet entries in the stored course lists. This does not establish graduation eligibility.'));
  outstanding.append(el('p', 'muted audit-note', 'Still unverified: program credit targets, General Education, Liberal Arts, elective allocation, catalog year, residency, GPA, substitutions, repeat policies and any graduate thesis requirements.'));
  root.append(outstanding);

  if (audit.pending_records.length || audit.review.length) {
    const review = section('Records needing review', 'These records are shown for clarification, without changing student data.');
    review.append(auditTable('Record checks', ['Course / program', 'CRN', 'Semester', 'Reason'], [
      ...audit.review.map(r => [r.code ?? '—', r.crn ?? '—', r.semester ?? '—', r.reason]),
      ...audit.pending_records.map(r => [r.code, r.crn, r.semester, r.status]),
    ], 'No record checks are pending.')); root.append(review);
  }
  const history = el('details', 'audit-section audit-history');
  history.append(el('summary', 'audit-section-title', `Academic history · ${audit.credits.transcript_attempts} attempts · ${audit.credits.repeated_courses} repeated courses`));
  history.append(el('p', 'muted audit-note', 'All transcript attempts are preserved. A repeated passing course contributes credits once; the highest recognized consistent grade is compared with each stored requirement minimum. Unknown grades need review.'));
  const historyRows = list => list.map(h => [h.code, h.title, h.crn, h.credits, h.grade, h.semester, h.result]);
  const historyColumns = ['Course', 'Title', 'CRN', 'Credits', 'Grade', 'Semester', 'Record result'];
  history.append(auditTable('Transcript coursework', historyColumns, historyRows(audit.history), 'No academic history is recorded. A zero provisional total does not establish that no credits were earned elsewhere.'));
  history.append(auditTable('Other Academic Coursework', historyColumns, historyRows(audit.other_coursework), 'No transcript coursework outside the stored major/minor course lists is recorded.'));
  history.append(el('p', 'muted audit-note', 'Other Academic Coursework is not automatically assigned to General Education, Liberal Arts, or electives.'));
  root.append(history);
  return root;
}

function renderFlash({ ok, message, heading }) {
  const box = el('div', ok ? 'flash ok' : 'flash err');
  box.append(el('strong', null, heading || (ok ? 'Done' : 'Not allowed')), el('span', null, message));
  return box;
}

// Runs a database action, then reloads the page with the result on top.
async function runAction(endpoint, body) {
  const res = await fetch(`/api/actions/${endpoint}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  if (res.status === 401) return showLogin();
  const data = await res.json();
  if (res.ok && endpoint === 'complete-password-change') {
    showLogin();
    message.replaceChildren(el('div', 'notice', data.message));
    return;
  }
  await openView(currentView, currentParams, res.ok ? { ok: true, message: data.message } : { ok: false, message: data.error }, 'replace');
  document.querySelector('.content').scrollIntoView({ behavior: 'smooth' });
}

function el(tag, className, text) {
  const e = document.createElement(tag);
  if (className) e.className = className;
  if (text !== undefined) e.textContent = text;
  return e;
}

// onFilter: what a filter change does. By default it reloads the dashboard page with the new values.
function renderBlock(block, viewId, onFilter = (params) => openView(viewId, params)) {
  if (block.type === 'student-overview') return renderStudentOverview(block, viewId);
  if (block.type === 'degree-audit') return renderDegreeAudit(block.audit);
  if (block.type === 'stats') {
    const grid = el('div', 'stats');
    for (const item of block.items) {
      const card = el('div', 'stat');
      card.append(el('span', 'stat-label', item.label), el('strong', 'stat-value', String(item.value)));
      grid.append(card);
    }
    return grid;
  }
  if (block.type === 'message') return renderFlash(block);

if (block.type === 'empty-major') {
  const card = el('div', 'major-empty');

  const icon = el('div', 'major-empty-icon', '🎓');

  const title = el(
    'h3',
    null,
    'Find courses in your area of interest'
  );

  const description = el(
    'p',
    'muted',
    block.message
  );

  card.append(icon, title, description);

  return card;
}

  if (block.type === 'mail') return renderMail(block.messages);
  if (block.type === 'filters') {
    const bar = el('div', 'filters');
    for (const f of block.filters) {
      const label = el('label', null, f.label);
      const select = el('select');
      select.name = f.name;
      for (const [value, text] of f.options) {
        const o = el('option', null, text);
        o.value = value;
        o.selected = value === f.value;
        select.append(o);
      }
      select.addEventListener('change', () => {
        const params = Object.fromEntries([...bar.querySelectorAll('select')].map((s) => [s.name, s.value]));
        onFilter(params);
      });
      label.append(select);
      bar.append(label);
    }
    return bar;
  }
  if (block.type === 'form') {
    const card = el('form', 'table-card form-card');
    card.append(el('h3', null, block.title));
    const grid = el('div', 'form-grid');
    for (const f of block.fields) {
      const label = el('label', null, f.label);
      let input;
      if (f.options) {
        input = el('select');
        for (const [value, text] of f.options) {
          const o = el('option', null, text);
          o.value = value;
          o.selected = value === f.value;
          input.append(o);
        }
        // Some choices reload the form, e.g. the semester (teaching loads depend on it) or the type of user.
        if (f.reload) input.addEventListener('change', () => openView(viewId, { [f.reload]: input.value }));
      } else {
        input = el('input');
        input.type = f.type || 'text';
        if (f.min !== undefined) input.min = f.min;
        if (f.max !== undefined) input.max = f.max;
        if (f.step !== undefined) input.step = f.step;
        if (f.required) input.required = true;
        input.value = f.value;
        if (f.placeholder) input.placeholder = f.placeholder;
        if (f.type === 'password') input.autocomplete = 'new-password';
      }
      input.name = f.name;
      label.append(input);
      grid.append(label);
    }
    const button = el('button', 'primary', block.submit);
    button.type = 'submit';
    card.append(grid);
    if (block.note) card.append(el('p', 'muted small', block.note));
    card.append(button);
    card.addEventListener('submit', async (e) => {
      e.preventDefault();
      button.disabled = true;
      const body = Object.fromEntries(new FormData(card));
      // A search form only reloads the page with its values; other forms change the database.
      if (block.method === 'view') return openView(viewId, body);
      // Only forms that ask for it keep their values after saving (never passwords).
      if (block.keep) currentParams = { semester: body.semester_ID, ...body };
      await runAction(block.endpoint, body);
    });
    return card;
  }

  // Public course browser: expandable course cards
  if (block.type === 'course-cards') {
    const container = el('div', 'course-card-list');

    const heading = el('h3', null, block.title);
    container.append(heading);

    for (const course of block.courses) {
      const card = el('details', 'course-card');

      const summary = el('summary', 'course-card-summary');

      const info = el('div', 'course-card-info');

      info.append(
        el('strong', null,
          `${course.code} — ${course.title}`),
        el('span', 'muted small',
          `${course.level} · ${course.credits} credits · ${course.sections.length} section(s)`)
      );

      summary.append(info);
      card.append(summary);

      const sections = el('div', 'course-card-sections');

      for (const section of course.sections) {
        const item = el('div', 'course-section');

        item.append(
          el('strong', null, `Section ${section.section}`),
          el('p', null, `CRN: ${section.crn}`),
          el('p', null, `Schedule: ${section.days} · ${section.time}`),
          el('p', null, `Instructor: ${section.instructor}`),
          el('p', null, `Room: ${section.room}`)
        );

  const openSeats = Number(section.seats);

  const availability = el(
  'span',
  openSeats > 0 ? 'seat-available' : 'seat-full',
  openSeats > 0
    ? `${openSeats} seats available`
    : 'Full'
);

item.append(availability);
        if (section.labDays && section.labDays !== '—') {
          item.append(
            el('p', null,
              `Lab: ${section.labDays} · ${section.labTime} · ${section.labRoom}`)
          );
        }

        sections.append(item);
      }

      card.append(sections);
      container.append(card);
    }

    return container;
  }

  // table
  const card = el('div', 'table-card');
  const head = el('div', 'table-head');
  head.append(el('h3', null, block.title), el('span', 'muted small', `${block.rows.length} row${block.rows.length === 1 ? '' : 's'}`));
  card.append(head);
  if (block.note) card.append(el('p', 'muted small note', block.note));
  if (!block.rows.length) {
    card.append(el('p', 'muted empty-row', 'Nothing to show.'));
    return card;
  }
  const wrap = el('div', 'table-wrap');
  const table = el('table');
  const tr = el('tr');
  for (const c of block.columns) tr.append(el('th', null, c));
  if (block.action) tr.append(el('th'));
  const keyIndex = block.action ? block.columns.indexOf(block.action.column) : -1;
  table.append(el('thead'));
  table.tHead.append(tr);
  const body = el('tbody');
  for (const row of block.rows) {
    const r = el('tr');
    for (const v of row) r.append(el('td', null, v === null ? '—' : String(v)));
    if (block.action) {
      const td = el('td');
      // A per-row choice (e.g. Present/Absent, or a letter grade) sent alongside the action's
      // key column. Defaults to this row's own value in `defaultColumn`, when that's one of the
      // choices, else the first option.
      let picker = null;
      if (block.action.select) {
        picker = el('select');
        picker.name = block.action.select.name;
        const defaultIndex = block.action.select.defaultColumn ? block.columns.indexOf(block.action.select.defaultColumn) : -1;
        const rowDefault = defaultIndex >= 0 ? row[defaultIndex] : null;
        for (const [value, text] of block.action.select.options) {
          const o = el('option', null, text);
          o.value = value;
          o.selected = value === rowDefault;
          picker.append(o);
        }
        if (!block.action.select.options.some(([value]) => value === rowDefault)) picker.selectedIndex = 0;
        td.append(picker);
      }
      const btn = el('button', `row-action ${block.action.style || ''}`, block.action.label);
      btn.type = 'button';
      btn.disabled = Boolean(block.action.disabled);
      btn.addEventListener('click', () => {
        if (block.action.disabled) return;
        btn.disabled = true;
        const body = { [block.action.column]: row[keyIndex], ...(block.action.extra || {}) };
        if (picker) body[picker.name] = picker.value;
        runAction(block.action.endpoint, body);
      });
      td.append(btn);
      r.append(td);
    }
    body.append(r);
  }
  table.append(body);
  wrap.append(table);
  card.append(wrap);
  return card;
}

document.getElementById('nav').addEventListener('click', (e) => {
  const a = e.target.closest('a[data-view]');
  if (!a) return;
  e.preventDefault();
  openView(a.dataset.view);
});

function showLogin() {
  dashboardPage.hidden = true;
  welcomePage.hidden = true;
  loginPage.hidden = false;
  showAuthView('login-view');
  form.reset();
  setLoginRole(form.role.dataset.chosen);
  message.innerHTML = '';
  history.replaceState(null, '', location.pathname);
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const button = form.querySelector('button[type=submit]');
  button.disabled = true;
  message.innerHTML = '';
  try {
    const res = await fetch('/api/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: form.email.value, password: form.password.value, role: form.role.value }),
    });
    const data = await res.json();
    if (!res.ok) {
      const box = document.createElement('div');
      box.className = 'error';
      box.textContent = data.error;
      // After 3 failed attempts (or a locked account) offer a password reset.
      if (data.canReset) {
        const link = el('a', 'reset-link', 'Forgot your password? Reset it');
        link.href = '#';
        link.style.display = 'block';
        link.addEventListener('click', (ev) => { ev.preventDefault(); openReset(form.email.value); });
        box.append(link);
      }
      message.appendChild(box);
      form.password.value = '';
    } else {
      showDashboard(data.user);
    }
  } finally {
    button.disabled = false;
  }
});

// Test-account buttons pick the account type, fill in the form and sign in
document.querySelectorAll('[data-email]').forEach((b) => b.addEventListener('click', () => {
  setLoginRole(b.dataset.role);
  form.email.value = b.dataset.email;
  form.password.value = 'Campus123!';
  form.requestSubmit();
}));

// ---------- Account type ("Sign in as"), remembered in this browser ----------
function setLoginRole(role) {
  form.role.value = ['Student', 'Faculty', 'Admin', 'StatDept'].includes(role) ? role : 'Student';
  form.role.dataset.chosen = form.role.value;
  try { localStorage.setItem('campus-connect-role', form.role.value); } catch { /* the page works without saving the choice */ }
}
document.getElementById('role-select').addEventListener('change', (e) => {
  setLoginRole(e.target.value);
  message.replaceChildren();
});
let savedRole = 'Student';
try { savedRole = localStorage.getItem('campus-connect-role') || 'Student'; } catch { /* no saved choice */ }
setLoginRole(savedRole);

document.getElementById('logout').addEventListener('click', async () => {
  await fetch('/api/logout', { method: 'POST' });
  showWelcome();
});

// ---------- Switching between sign in and password reset ----------
const authViews = ['login-view', 'reset-view', 'code-view', 'newpass-view', 'mail-view'];
function showAuthView(id) {
  for (const v of authViews) document.getElementById(v).hidden = v !== id;
}
document.querySelectorAll('[data-show]').forEach((a) => a.addEventListener('click', (e) => {
  e.preventDefault();
  showAuthView(a.dataset.show);
}));

function showBox(target, kind, text) {
  target.replaceChildren(el('div', kind, text));
}

// ---------- Password reset: email a code, enter it (or open the emailed link), choose a new password ----------
async function postJson(url, body) {
  const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  return [res.ok, await res.json()];
}
// Runs a form's request with its button disabled; shows an error in box, or calls done(data).
function onSubmit(formEl, box, send, done) {
  formEl.addEventListener('submit', async (e) => {
    e.preventDefault();
    const button = formEl.querySelector('button[type=submit]');
    button.disabled = true;
    box.replaceChildren();
    try {
      const [ok, data] = await send(Object.fromEntries(new FormData(formEl)));
      if (!ok) return showBox(box, 'error', data.error);
      await done(data);
    } finally {
      button.disabled = false;
    }
  });
}

const resetForm = document.getElementById('reset-form');
const codeForm = document.getElementById('code-form');
const newpassForm = document.getElementById('newpass-form');
let resetLogin = '';
function openReset(login) {
  resetForm.reset();
  document.getElementById('reset-message').replaceChildren();
  resetForm.login.value = login;
  showAuthView('reset-view');
  resetForm.login.focus();
}
function showCodeStep(sentMessage) {
  codeForm.reset();
  codeForm.login.value = resetLogin;
  document.getElementById('code-sent').textContent = `${sentMessage} It works for 15 minutes.`;
  document.getElementById('code-message').replaceChildren();
  showAuthView('code-view');
  (resetLogin ? codeForm.code : codeForm.login).focus();
}
document.getElementById('have-code').addEventListener('click', (e) => {
  e.preventDefault();
  resetLogin = form.email.value;
  showCodeStep('Enter the 6-digit code from your password-reset email.');
});
function showNewPasswordStep(note) {
  newpassForm.reset();
  document.getElementById('newpass-for').textContent = note;
  document.getElementById('newpass-message').replaceChildren();
  showAuthView('newpass-view');
  newpassForm.password.focus();
}

onSubmit(resetForm, document.getElementById('reset-message'), (body) => postJson('/api/reset/request', body), (data) => {
  resetLogin = resetForm.login.value;
  showCodeStep(data.message);
});
document.getElementById('resend-code').addEventListener('click', async (e) => {
  e.preventDefault();
  const [ok, data] = await postJson('/api/reset/request', { login: resetLogin });
  if (!ok) return showBox(document.getElementById('code-message'), 'error', data.error);
  showCodeStep(`A new code was sent. ${data.message}`);
});
onSubmit(codeForm, document.getElementById('code-message'), (body) => postJson('/api/reset/verify', body), () => {
  resetLogin = codeForm.login.value;
  showNewPasswordStep('Code accepted. At least 8 characters, with a letter and a number.');
});
onSubmit(newpassForm, document.getElementById('newpass-message'), (body) => postJson('/api/reset/complete', body), (data) => {
  showAuthView('login-view');
  form.reset();
  setLoginRole(form.role.dataset.chosen);
  form.email.value = resetLogin;
  message.replaceChildren(el('div', 'notice', data.message));
  history.replaceState(null, '', '#login');
  form.password.focus();
});

// Recovery information never asks for ID/DOB or opens an unauthenticated inbox.
document.getElementById('open-mail').addEventListener('click', (e) => {
  e.preventDefault();
  showAuthView('mail-view');
});

// The emailed link (#reset?token=...) skips the code and goes straight to choosing a new password.
async function openResetLink(token) {
  dashboardPage.hidden = true;
  welcomePage.hidden = true;
  loginPage.hidden = false;
  const [ok, data] = await postJson('/api/reset/verify', { token });
  if (!ok) {
    showAuthView('reset-view');
    showBox(document.getElementById('reset-message'), 'error', data.error);
    return;
  }
  resetLogin = data.email || '';
  showNewPasswordStep('Verification accepted. At least 8 characters, with a letter and a number.');
}
window.addEventListener('hashchange', () => {
  const token = new URLSearchParams(location.hash.split('?')[1] || '').get('token');
  if (location.hash.startsWith('#reset?') && token) return openResetLink(token);
  if (!dashboardPage.hidden) openDashboardLocation();
});

// Emails, newest first. A reset link in the text becomes clickable.
function renderMail(messages) {
  const list = el('div', 'mail-list');
  if (!messages.length) list.append(el('p', 'muted', 'No email yet.'));
  for (const m of messages) {
    const card = el('article', m.unread ? 'mail-item unread' : 'mail-item');
    const head = el('div', 'mail-head');
    head.append(el('strong', null, m.subject), el('span', 'muted small', m.sent));
    const body = el('div', 'mail-body');
    for (const line of m.body.split('\n')) {
      if (line.startsWith('/#reset?token=')) {
        const a = el('a', null, `${location.origin}${line}`);
        a.href = line;
        body.append(a, document.createElement('br'));
      } else {
        body.append(line, document.createElement('br'));
      }
    }
    card.append(head, el('span', 'muted small', `From: Campus Connect · To: ${m.to}`), body);
    list.append(card);
  }
  return list;
}

// ---------- Welcome page ----------
function showWelcome() {
  dashboardPage.hidden = true;
  loginPage.hidden = true;
  welcomePage.hidden = false;
  history.replaceState(null, '', location.pathname);
  window.scrollTo(0, 0);
  loadWelcome();
}

// Buttons and links with data-go open the welcome page or the sign-in form.
document.querySelectorAll('[data-go]').forEach((b) => b.addEventListener('click', (e) => {
  e.preventDefault();
  const go = b.dataset.go;
  if (go === 'welcome') return showWelcome();
  showLogin();
  history.replaceState(null, '', `#${go}`);
  window.scrollTo(0, 0);
}));

async function loadWelcome() {
  try {
    const res = await fetch('/api/welcome');
    if (!res.ok) return;
    const info = await res.json();
    if (info.current) document.getElementById('w-semester').textContent = `${info.current} semester`;
    const reg = document.getElementById('w-registration');
    const r = info.registration;
    if (r && info.next) {
      reg.textContent = Number(r.is_open)
        ? `Registration for ${info.next} is open until ${r.closes}`
        : `Registration for ${info.next} runs ${r.opens} – ${r.closes}`;
      reg.hidden = false;
    }
  } catch {
    // The welcome page still works without the semester and registration dates.
  }
  loadPublicSchedule();
}

// The public course explorer on the welcome page, with its semester and major filters.
let publicScheduleRequestId = 0;
async function loadPublicSchedule(params = {}) {
  const requestId = ++publicScheduleRequestId;
  const box = document.getElementById('w-schedule');
  // Keep the current filters usable, but clear courses from the previous selection immediately.
  const filters = box.querySelector('.filters');
  box.replaceChildren(...(filters ? [filters] : []), el('p', 'muted', 'Loading course options...'));
  box.setAttribute('aria-busy', 'true');
  try {
    const res = await fetch(`/api/major-browser?${new URLSearchParams(params)}`);
    if (requestId !== publicScheduleRequestId) return;
    if (!res.ok) throw new Error();
    const { blocks } = await res.json();
    if (requestId !== publicScheduleRequestId) return;
    box.replaceChildren(...blocks.map((b) => renderBlock(b, null, loadPublicSchedule)));
  } catch {
    if (requestId !== publicScheduleRequestId) return;
    box.replaceChildren(...(filters ? [filters] : []),
      el('p', 'muted', 'The schedule could not be loaded. Please try again later.'));
  } finally {
    if (requestId === publicScheduleRequestId) box.setAttribute('aria-busy', 'false');
  }
}

// On first load: signed in → dashboard; #login → the sign-in form; otherwise the welcome page.
fetch('/api/me').then((r) => (r.ok ? r.json() : null)).then((d) => {
  const token = new URLSearchParams(location.hash.split('?')[1] || '').get('token');
  if (location.hash.startsWith('#reset?') && token) return openResetLink(token);
  if (d) return showDashboard(d.user);
  const hash = location.hash.slice(1);
  const link = document.querySelector(`.w-cta [data-go="${hash}"]`);
  if (link) link.click();
  else showWelcome();
});
