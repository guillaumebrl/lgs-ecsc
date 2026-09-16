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
document.querySelectorAll('.grade-table input[type=number]').forEach(input => {
  const update = () => { const cell=input.closest('tr').querySelector('.normalized'); const target=cell.dataset.target==='10'?10:20; cell.textContent=input.value ? `${(Number(input.value)/Number(cell.dataset.scale)*target).toFixed(2)} / ${target}` : '—'; };
  input.addEventListener('input', update); update();
});
document.querySelector('[data-projector]')?.addEventListener('click', async () => {
  document.body.classList.toggle('projector');
  if (document.body.classList.contains('projector')) await document.documentElement.requestFullscreen?.(); else await document.exitFullscreen?.();
});
document.querySelector('[data-privacy]')?.addEventListener('click', event => {
  document.body.classList.toggle('privacy'); event.target.textContent=document.body.classList.contains('privacy')?'Afficher les données':'Masquer les données';
});
document.querySelector('[data-addressee-mode]')?.addEventListener('change', event => {
  const custom = document.querySelector('[data-custom-addressee]');
  if (custom) custom.hidden = event.target.value !== 'custom';
});
const familyShortcut = document.querySelector('.families-shortcut');
const sidebarNav = document.querySelector('.sidebar nav');
if (familyShortcut && sidebarNav) {
  const link = document.createElement('a');
  link.href = '?page=families';
  link.className = new URLSearchParams(location.search).get('page') === 'families' ? 'active' : '';
  link.innerHTML = '⌂ <span>Familles</span>';
  sidebarNav.children[1]?.after(link);
}
const adminShortcut = document.querySelector('.admin-shortcut');
if (adminShortcut && sidebarNav) {
  const link = document.createElement('a');
  link.href = '?page=admin-management';
  link.className = new URLSearchParams(location.search).get('page') === 'admin-management' ? 'active' : '';
  link.innerHTML = '✎ <span>Modifier</span>';
  sidebarNav.append(link);
}
document.querySelector('[data-family-situation]')?.addEventListener('change', event => {
  const mode = document.querySelector('[data-addressee-mode]');
  if (!mode) return;
  mode.value = ['married', 'civil_union', 'cohabiting'].includes(event.target.value) ? 'shared_couple' : 'individual_names';
  mode.dispatchEvent(new Event('change'));
});
