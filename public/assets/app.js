const passwordEyeIcon = visible => visible
  ? '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M13.359 11.238 15 12.879l-.707.707-13-13L2 .879l2.138 2.138A8.4 8.4 0 0 1 8 2c3.5 0 6.2 2.1 7.5 5a10.2 10.2 0 0 1-2.141 3.03L10.7 7.37A2.8 2.8 0 0 0 6.63 3.3L9.42 6.09A1.8 1.8 0 0 1 9.91 7.37l3.449 3.868zM8 12c-3.5 0-6.2-2.1-7.5-5a10.1 10.1 0 0 1 2.03-2.93l1.42 1.42A4.5 4.5 0 0 0 3.2 7c1.04 1.85 2.72 3 4.8 3 .5 0 .98-.07 1.42-.2L11 11.38A8.4 8.4 0 0 1 8 12z"/></svg>'
  : '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 2c3.5 0 6.2 2.1 7.5 5-1.3 2.9-4 5-7.5 5S1.8 9.9.5 7C1.8 4.1 4.5 2 8 2zm0 2C5.92 4 4.24 5.15 3.2 7 4.24 8.85 5.92 10 8 10s3.76-1.15 4.8-3C11.76 5.15 10.08 4 8 4zm0 1.2A1.8 1.8 0 1 1 8 8.8a1.8 1.8 0 0 1 0-3.6z"/></svg>';

document.querySelectorAll('input[type="password"]').forEach(input => {
  if (input.closest('.password-field')) return;
  const wrapper = document.createElement('span');
  wrapper.className = 'password-field';
  input.parentNode.insertBefore(wrapper, input);
  wrapper.appendChild(input);
  const toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'password-toggle';
  toggle.setAttribute('aria-label', 'Afficher le mot de passe');
  toggle.setAttribute('aria-pressed', 'false');
  toggle.title = 'Afficher le mot de passe';
  toggle.innerHTML = passwordEyeIcon(false);
  toggle.addEventListener('click', () => {
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    const label = visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe';
    toggle.setAttribute('aria-label', label);
    toggle.setAttribute('aria-pressed', visible ? 'true' : 'false');
    toggle.title = label;
    toggle.innerHTML = passwordEyeIcon(visible);
    input.focus({ preventScroll: true });
  });
  wrapper.appendChild(toggle);
});

document.querySelectorAll('[data-tab]').forEach((button, index) => {
  button.addEventListener('click', () => {
    document.querySelectorAll('[data-tab]').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.hidden = true);
    button.classList.add('active'); document.getElementById(button.dataset.tab).hidden = false;
  });
  if (index === 0) button.click();
});
document.querySelector('[data-filter-table]')?.addEventListener('input', event => {
  const q = event.target.value.toLowerCase();
  document.querySelectorAll('[data-table] tbody tr').forEach(row => row.hidden = !row.textContent.toLowerCase().includes(q));
});
document.querySelectorAll('[data-sortable-table]').forEach(table => {
  table.querySelectorAll('[data-sort-column]').forEach(button => button.addEventListener('click', () => {
    const column = Number(button.dataset.sortColumn);
    const numeric = button.dataset.sortType === 'number';
    const direction = button.dataset.sortDirection === 'asc' ? 'desc' : 'asc';
    const body = table.tBodies[0];
    const rows = [...body.rows];
    rows.sort((left, right) => {
      const leftValue = left.cells[column]?.dataset.sortValue ?? left.cells[column]?.textContent.trim() ?? '';
      const rightValue = right.cells[column]?.dataset.sortValue ?? right.cells[column]?.textContent.trim() ?? '';
      const comparison = numeric
        ? Number(leftValue) - Number(rightValue)
        : leftValue.localeCompare(rightValue, 'fr', { sensitivity: 'base', numeric: true });
      return direction === 'asc' ? comparison : -comparison;
    });
    table.querySelectorAll('[data-sort-column]').forEach(item => {
      delete item.dataset.sortDirection;
      item.setAttribute('aria-sort', 'none');
      const indicator = item.querySelector('span');
      if (indicator) indicator.textContent = '↕';
    });
    button.dataset.sortDirection = direction;
    button.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : 'descending');
    const indicator = button.querySelector('span');
    if (indicator) indicator.textContent = direction === 'asc' ? '↑' : '↓';
    rows.forEach(row => body.appendChild(row));
  }));
});
document.querySelectorAll('.grade-table input[type=number]').forEach(input => {
  const update = () => { const cell=input.closest('tr').querySelector('.normalized'); const target=cell.dataset.target==='10'?10:20; const normalized=Number(input.value)/Number(cell.dataset.scale)*target; cell.textContent=input.value ? `${normalized.toLocaleString('fr-FR',{maximumFractionDigits:2})} / ${target}` : '—'; };
  input.addEventListener('input', update); update();
});
const updateProjectorState = active => {
  document.body.classList.toggle('projector', active);
  const projectorButton = document.querySelector('[data-projector]');
  const projectorState = document.querySelector('[data-projector-state]');
  const projectorLabel = document.querySelector('[data-projector-label]');
  if (projectorState) projectorState.disabled = !active;
  if (projectorButton) projectorButton.setAttribute('aria-pressed', active ? 'true' : 'false');
  if (projectorLabel) projectorLabel.textContent = active ? 'Activé' : 'Désactivé';
  const url = new URL(location.href);
  if (active) url.searchParams.set('projector','1'); else url.searchParams.delete('projector');
  history.replaceState({},'',url);
};
const loadCouncilSelection = async (changedSelect, resetDependents = false) => {
  const form = changedSelect.form;
  if (!form) return;
  const projectorActive = document.body.classList.contains('projector');
  const params = new URLSearchParams(new FormData(form));
  if (resetDependents) { params.delete('period_id'); params.delete('student_id'); }
  if (projectorActive) params.set('projector','1');
  const url = `${location.pathname}?${params.toString()}`;
  try {
    const response = await fetch(url, {headers:{'X-Requested-With':'XMLHttpRequest'}});
    if (!response.ok) throw new Error('Chargement impossible');
    const nextDocument = new DOMParser().parseFromString(await response.text(),'text/html');
    const nextMain = nextDocument.querySelector('.main');
    if (!nextMain) throw new Error('Contenu introuvable');
    document.querySelector('.main').innerHTML = nextMain.innerHTML;
    history.replaceState({},'',url);
    updateProjectorState(projectorActive);
    bindCouncilControls();
  } catch (_) { location.assign(url); }
};
const bindCouncilControls = () => {
  const projectorButton = document.querySelector('[data-projector]');
  projectorButton?.addEventListener('click', async () => {
    const active = !document.body.classList.contains('projector');
    updateProjectorState(active);
    try { if (active) await document.documentElement.requestFullscreen?.(); else if (document.fullscreenElement) await document.exitFullscreen?.(); } catch (_) {}
  });
  document.querySelector('[data-council-class]')?.addEventListener('change', event => loadCouncilSelection(event.target,true));
  document.querySelector('[data-council-period]')?.addEventListener('change', event => loadCouncilSelection(event.target));
  document.querySelector('[data-council-student]')?.addEventListener('change', event => loadCouncilSelection(event.target));
  document.querySelector('[data-council-decision]')?.addEventListener('submit', async event => {
    if (!document.body.classList.contains('projector')) return;
    event.preventDefault();
    const form = event.currentTarget;
    const submitter = event.submitter;
    const data = new FormData(form);
    if (submitter?.name) data.set(submitter.name, submitter.value);
    if (submitter) submitter.disabled = true;
    try {
      const response = await fetch(form.action || location.href, {method:'POST',body:data,headers:{'X-Requested-With':'XMLHttpRequest'}});
      if (!response.ok) throw new Error('Enregistrement impossible');
      const nextDocument = new DOMParser().parseFromString(await response.text(),'text/html');
      const nextMain = nextDocument.querySelector('.main');
      if (!nextMain) throw new Error('Contenu introuvable');
      document.querySelector('.main').innerHTML = nextMain.innerHTML;
      updateProjectorState(true);
      bindCouncilControls();
    } catch (_) {
      if (submitter) submitter.disabled = false;
      alert('L’enregistrement n’a pas abouti. Vérifiez votre connexion puis réessayez.');
    }
  });
  document.querySelector('[data-privacy]')?.addEventListener('click', event => {
    document.body.classList.toggle('privacy'); event.target.textContent=document.body.classList.contains('privacy')?'Afficher les données':'Masquer les données';
  });
};
updateProjectorState(new URLSearchParams(location.search).get('projector') === '1');
bindCouncilControls();
document.addEventListener('fullscreenchange', () => {
  if (!document.fullscreenElement && document.body.classList.contains('projector')) updateProjectorState(false);
});
document.querySelector('[data-addressee-mode]')?.addEventListener('change', event => {
  const custom = document.querySelector('[data-custom-addressee]');
  if (custom) custom.hidden = event.target.value !== 'custom';
});
document.querySelector('[data-family-situation]')?.addEventListener('change', event => {
  const mode = document.querySelector('[data-addressee-mode]');
  if (!mode) return;
  mode.value = ['married', 'civil_union', 'cohabiting'].includes(event.target.value) ? 'shared_couple' : 'individual_names';
  mode.dispatchEvent(new Event('change'));
});
const courseClass = document.querySelector('[data-course-class]');
if (courseClass) {
  const groupSelect = document.querySelector('select[name="class_group_id"]');
  const filterGroups = () => groupSelect?.querySelectorAll('option[data-class-id]').forEach(option => {
    option.hidden = option.dataset.classId !== courseClass.value;
    option.disabled = option.hidden;
    if (option.hidden && option.selected) groupSelect.value = '';
  });
  courseClass.addEventListener('change', filterGroups); filterGroups();
}
document.querySelectorAll('[data-assignment-class]').forEach(classSelect => {
  const form = classSelect.closest('form');
  const groupSelect = form?.querySelector('select[name="class_group_id"]');
  const filterGroups = () => groupSelect?.querySelectorAll('option[data-class-id]').forEach(option => {
    option.hidden = option.dataset.classId !== classSelect.value;
    option.disabled = option.hidden;
    if (option.hidden && option.selected) groupSelect.value = '';
  });
  classSelect.addEventListener('change', filterGroups);
  filterGroups();
});
document.querySelectorAll('[data-account-qualifications]').forEach(group => {
  const admin = group.querySelector('[data-admin-qualification]');
  const staff = [...group.querySelectorAll('[data-staff-qualification]')];
  const updateQualifications = () => {
    staff.forEach(input => {
      if (admin.checked) input.checked = false;
      input.disabled = admin.checked;
      input.closest('label')?.classList.toggle('qualification-disabled', admin.checked);
    });
  };
  admin?.addEventListener('change', updateQualifications);
  updateQualifications();
});
document.querySelectorAll('.sidebar a[href="?page=direction"]').forEach(link => {
  link.href = '?page=documents';
  const label = link.querySelector('span');
  if (label) label.textContent = 'Documents';
  if (new URLSearchParams(location.search).get('page') === 'documents') link.classList.add('active');
});
document.querySelectorAll('select[name="class_id"] option').forEach(option => {
  option.textContent = option.textContent
    .replace(/^\s*\d{4}-\d{4}\s*·\s*/, '')
    .replace(/\s*·\s*\d{4}-\d{4}\s*$/, '');
});
document.querySelectorAll('small').forEach(label => {
  const cleaned = label.textContent.replace(/^\s*\d{4}-\d{4}\s*·\s*/, '').replace(/\s*·\s*\d{4}-\d{4}\s*$/, '').trim();
  if (/^\d{4}-\d{4}$/.test(cleaned)) label.hidden = true;
  else label.textContent = cleaned;
});
document.querySelectorAll('input[name="coefficient"]').forEach(input => {
  const value = Number(input.value);
  if (input.value !== '' && Number.isFinite(value)) input.value = String(value);
});
if (new URLSearchParams(location.search).get('page') === 'structure') {
  document.querySelectorAll('table').forEach(table => {
    const coefficientColumn = [...table.querySelectorAll('thead th')]
      .findIndex(cell => cell.textContent.trim().toLowerCase().startsWith('coefficient'));
    if (coefficientColumn < 0) return;
    table.querySelectorAll('tbody tr').forEach(row => {
      const cell = row.children[coefficientColumn];
      if (!cell) return;
      const value = Number(cell.textContent.trim().replace(',', '.'));
      if (Number.isFinite(value)) cell.textContent = String(value).replace('.', ',');
    });
  });
  document.querySelectorAll('.tag').forEach(tag => {
    tag.textContent = tag.textContent.replace(/(Coefficient par défaut\s+)(\d+)[.,]00\b/i, '$1$2');
  });
}
const selectedClassId = new URLSearchParams(location.search).get('class_id');
if (selectedClassId) document.querySelectorAll('[data-assignment-class]').forEach(select => {
  if ([...select.options].some(option => option.value === selectedClassId)) {
    select.value = selectedClassId;
    select.dispatchEvent(new Event('change'));
  }
});
document.querySelectorAll('.sidebar a[href="?page=grades"] span').forEach(label => { label.textContent = 'Évaluations'; });
const studentsNavigationLink = document.querySelector('.sidebar a[href="?page=students"]');
const schoolLifeNavigationLink = document.querySelector('.sidebar a[href="?page=school-life"]');
if (studentsNavigationLink && schoolLifeNavigationLink) schoolLifeNavigationLink.after(studentsNavigationLink);
if (new URLSearchParams(location.search).get('page') === 'school-life') {
  const scrollKey = 'lgs-school-life-scroll-position';
  const rememberScrollPosition = () => sessionStorage.setItem(scrollKey, String(window.scrollY));

  document.querySelectorAll('a[href*="page=school-life"][href*="student_id"]').forEach(link => {
    link.addEventListener('click', rememberScrollPosition);
  });
  document.querySelectorAll('a[href*="page=school-life"][href*="edit_event"]').forEach(link => {
    link.addEventListener('click', rememberScrollPosition);
  });
  document.querySelectorAll('form').forEach(form => {
    const action = form.querySelector('input[name="action"]')?.value || '';
    if (['add_attendance', 'update_attendance', 'delete_attendance'].includes(action)) {
      form.addEventListener('submit', rememberScrollPosition);
    }
  });

  const savedScrollPosition = sessionStorage.getItem(scrollKey);
  if (savedScrollPosition !== null) {
    sessionStorage.removeItem(scrollKey);
    requestAnimationFrame(() => requestAnimationFrame(() => {
      window.scrollTo({ top: Number(savedScrollPosition), behavior: 'auto' });
    }));
  }
}
const roleMarker = document.querySelector('.user-role-marker');
if (!document.querySelector('.admin-marker')) document.querySelector('input[name="action"][value="prepare_school_year"]')?.closest('form')?.remove();
const commentLimit = Number(document.querySelector('.settings-marker')?.dataset.commentLimit || 500);
document.querySelectorAll('textarea[name^="comments["]').forEach(textarea => textarea.maxLength = commentLimit);
if (new URLSearchParams(location.search).get('page') === 'settings') {
  const settingsForm = document.querySelector('input[name="action"][value="save_settings"]')?.closest('form');
  const submit = settingsForm?.querySelector('button');
  if (settingsForm && submit && !settingsForm.querySelector('[name="comment_max_length"]')) {
    const label = document.createElement('label');
    label.innerHTML = `Longueur maximale des appréciations<input required type="number" min="50" max="2000" name="comment_max_length" value="${commentLimit}"><small>Entre 50 et 2 000 caractères</small>`;
    settingsForm.insertBefore(label, submit);
  }
}
if (roleMarker?.dataset.role === 'principal' && !document.querySelector('.sidebar a[href="?page=grades"]')) {
  const myStudentsLink = document.querySelector('.sidebar a[href="?page=my-students"]');
  const gradesLink = document.createElement('a');
  gradesLink.href = '?page=grades';
  gradesLink.innerHTML = '▦ <span>Évaluations et notes</span>';
  myStudentsLink?.after(gradesLink);
}
if (new URLSearchParams(location.search).get('page') === 'grade-notebook') {
  document.querySelector('.sidebar a[href="?page=grades"]')?.classList.add('active');

  const notebookParams = new URLSearchParams(location.search);
  const notebookMode = notebookParams.get('notebook_mode');
  if (notebookMode === 'teacher') {
    document.querySelectorAll('.notebook-filters').forEach(form => {
      if (form.querySelector('input[name="notebook_mode"]')) return;
      const modeInput = document.createElement('input');
      modeInput.type = 'hidden';
      modeInput.name = 'notebook_mode';
      modeInput.value = 'teacher';
      form.append(modeInput);
    });
    document.querySelectorAll('a[href*="page=grade-notebook"]').forEach(link => {
      const url = new URL(link.href, location.href);
      if (!url.searchParams.has('notebook_mode')) url.searchParams.set('notebook_mode', 'teacher');
      link.href = url.pathname + url.search;
    });
  }

  const periodDates = JSON.parse(document.querySelector('.notebook-period-data')?.dataset.periods || '{}');
  document.querySelectorAll('.notebook-filters select[name="period"]').forEach(periodSelect => {
    periodSelect.addEventListener('change', () => {
      const dates = periodDates[periodSelect.value];
      if (!dates) return;
      const form = periodSelect.closest('form');
      const from = form?.querySelector('input[name="from"]');
      const to = form?.querySelector('input[name="to"]');
      if (from) from.value = dates.from;
      if (to) to.value = dates.to;
    });
  });

  document.querySelectorAll('.notebook-filters select').forEach(select => {
    if (select.name === 'class_id') return;
    select.addEventListener('change', () => {
      if (select.name === 'period' && select.value === 'other') return;
      select.closest('form')?.submit();
    });
  });

  document.querySelectorAll('.notebook-grade').forEach(grade => {
    const link = grade.querySelector('a[href*="page=gradebook"]');
    if (!link) return;
    const linkUrl = new URL(link.href, location.href);
    linkUrl.searchParams.set('return_to', location.search);
    link.href = linkUrl.pathname + linkUrl.search;
    grade.classList.add('interactive-grade');
    grade.tabIndex = 0;
    grade.setAttribute('role', 'link');
    grade.addEventListener('click', event => {
      if (!event.target.closest('a')) location.href = link.href;
    });
    grade.addEventListener('keydown', event => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        location.href = link.href;
      }
    });
  });

  const responsibleAssessmentIds = JSON.parse(
    document.querySelector('.responsible-assessment-links')?.dataset.assessmentIds || '[]'
  );
  document.querySelectorAll('.responsible-notebook-table .report-note').forEach((grade, index) => {
    const assessmentId = responsibleAssessmentIds[index];
    if (!assessmentId) return;
    const openAssessment = () => {
      const params = new URLSearchParams({
        page: 'gradebook',
        id: String(assessmentId),
        return_to: location.search,
      });
      location.href = `?${params.toString()}`;
    };
    grade.classList.add('interactive-grade');
    grade.tabIndex = 0;
    grade.setAttribute('role', 'link');
    grade.addEventListener('click', openAssessment);
    grade.addEventListener('keydown', event => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openAssessment();
      }
    });
  });

  const responsibleNotebookForm = document.querySelector('.notebook-filters select[name="class_id"]')?.closest('form');
  const classSelect = responsibleNotebookForm?.querySelector('select[name="class_id"]');
  classSelect?.addEventListener('change', () => {
    const studentSelect = responsibleNotebookForm.querySelector('select[name="student_id"]');
    if (studentSelect) studentSelect.disabled = true;
    responsibleNotebookForm.submit();
  });
}

const sidebar = document.querySelector('.sidebar');
if (sidebar) {
  const menuButton = document.createElement('button');
  menuButton.type = 'button';
  menuButton.className = 'mobile-menu-toggle';
  menuButton.setAttribute('aria-label', 'Ouvrir le menu');
  menuButton.setAttribute('aria-expanded', 'false');
  menuButton.innerHTML = '<i class="bi bi-list" aria-hidden="true"></i><span>Menu</span>';
  sidebar.querySelector('.brand')?.after(menuButton);

  const closeMenu = () => {
    sidebar.classList.remove('mobile-menu-open');
    document.body.classList.remove('mobile-navigation-open');
    menuButton.setAttribute('aria-expanded', 'false');
    menuButton.setAttribute('aria-label', 'Ouvrir le menu');
    menuButton.innerHTML = '<i class="bi bi-list" aria-hidden="true"></i><span>Menu</span>';
  };
  menuButton.addEventListener('click', () => {
    const open = !sidebar.classList.contains('mobile-menu-open');
    if (!open) return closeMenu();
    sidebar.classList.add('mobile-menu-open');
    document.body.classList.add('mobile-navigation-open');
    menuButton.setAttribute('aria-expanded', 'true');
    menuButton.setAttribute('aria-label', 'Fermer le menu');
    menuButton.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i><span>Fermer</span>';
  });
  sidebar.querySelectorAll('nav a,.sidebar-user a').forEach(link => link.addEventListener('click', closeMenu));
  window.addEventListener('resize', () => { if (window.innerWidth > 620) closeMenu(); });
}
