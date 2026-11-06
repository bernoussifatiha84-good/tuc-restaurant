(() => {
  'use strict';
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const menuRoot = $('#tuc-menu-list');
  if (!menuRoot || !window.TUCPro) return;

  const editor = $('#tuc-menu-editor');
  const notice = $('#tuc-pro-notice');
  const groupsRoot = $('#tuc-option-groups');
  const items = new Map();
  let photoUrl = '';
  let busy = false;

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[char]);
  const slug = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 26);
  const money = (value) => `${Number(value || 0).toLocaleString('fr-MA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} MAD`;
  const request = async (path, options = {}) => {
    const response = await fetch(TUCPro.rest + path, {
      credentials: 'same-origin', ...options,
      headers: { 'X-WP-Nonce': TUCPro.nonce, ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }), ...(options.headers || {}) }
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.message || 'Une erreur est survenue. Réessayez.');
    return body;
  };
  const showNotice = (message, error = false) => {
    notice.textContent = message;
    notice.hidden = false;
    notice.classList.toggle('is-error', error);
    notice.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  };
  const hideNotice = () => { notice.hidden = true; notice.classList.remove('is-error'); };
  const setBusy = (state) => {
    busy = state;
    $$('.tuc-editor-actions button', editor).forEach((button) => { button.disabled = state; button.textContent = state ? 'Enregistrement…' : button.dataset.label; });
  };

  function renderItems(list) {
    items.clear();
    list.forEach((item) => items.set(Number(item.id), item));
    $('#tuc-menu-loading').hidden = true;
    if (!list.length) {
      menuRoot.innerHTML = '<div class="tuc-empty"><strong>Votre menu est prêt à commencer</strong><span>Ajoutez un premier plat. Vous pourrez le garder en brouillon ou le publier.</span></div>';
      return;
    }
    menuRoot.innerHTML = list.map((item) => {
      const photo = item.photo ? `<img class="tuc-menu-thumb" src="${escapeHtml(item.photo)}" alt="" loading="lazy">` : '<div class="tuc-menu-thumb tuc-menu-thumb-placeholder" aria-hidden="true">🍽️</div>';
      const optionCount = (item.options?.groups || []).length;
      const state = item.status === 'draft' ? '<span class="tuc-draft-badge">BROUILLON</span>' : '';
      const price = Number(item.promo_pct) > 0 ? `<del>${money(item.price)}</del> ${money(item.price * (100 - item.promo_pct) / 100)}` : money(item.price);
      const description = item.description || (optionCount ? `${optionCount} groupe(s) d’options` : 'Aucune description');
      return `<article class="tuc-menu-row" data-id="${Number(item.id)}">${photo}<div class="tuc-menu-row-copy"><h3>${escapeHtml(item.title)}${state}</h3><p>${escapeHtml(description)}</p><span class="tuc-row-price">${price}${item.available ? '' : ' · Indisponible'}</span></div><div class="tuc-row-actions"><button class="tuc-small-button" type="button" data-edit="${Number(item.id)}">Modifier</button><button class="tuc-small-button archive" type="button" data-archive="${Number(item.id)}">Archiver</button></div></article>`;
    }).join('');
  }

  function newGroupId(index, name) {
    return slug(name) || `groupe-${index + 1}`;
  }
  function groupNodes() { return $$('.tuc-option-group', groupsRoot); }
  function collectGroupSnapshot() {
    return groupNodes().map((node, index) => {
      const name = $('[data-group-name]', node).value.trim();
      const id = node.dataset.groupId || newGroupId(index, name);
      const choices = $$('.tuc-choice-row', node).map((row, choiceIndex) => {
        const choiceName = $('[data-choice-name]', row).value.trim();
        return {
          id: row.dataset.choiceId || `${slug(choiceName) || 'choix'}-${choiceIndex + 1}`,
          name: choiceName,
          price_delta: Number($('[data-choice-price]', row).value || 0),
          available: $('[data-choice-available]', row).checked
        };
      });
      const group = {
        id, name,
        required: $('[data-required]', node).checked,
        min: Number($('[data-min]', node).value || 0),
        max: Number($('[data-max]', node).value || 1),
        choices
      };
      const dependency = $('[data-condition-group]', node).value;
      const conditionChoice = $('[data-condition-choice]', node).value;
      if (dependency && conditionChoice) group.visible_if = { group_id: dependency, choice_ids: [conditionChoice] };
      return group;
    });
  }

  function refreshConditions() {
    const snapshot = collectGroupSnapshot();
    groupNodes().forEach((node, index) => {
      const groupSelect = $('[data-condition-group]', node);
      const choiceSelect = $('[data-condition-choice]', node);
      const ownId = node.dataset.groupId;
      const oldGroup = groupSelect.value;
      const oldChoice = choiceSelect.value;
      const earlier = snapshot.slice(0, index);
      groupSelect.innerHTML = '<option value="">Toujours afficher</option>' + earlier.map((group) => `<option value="${escapeHtml(group.id)}">${escapeHtml(group.name || `Groupe ${index + 1}`)}</option>`).join('');
      groupSelect.value = earlier.some((group) => group.id === oldGroup) ? oldGroup : '';
      const selectedGroup = earlier.find((group) => group.id === groupSelect.value);
      const choices = selectedGroup?.choices || [];
      choiceSelect.innerHTML = '<option value="">Choix déclencheur…</option>' + choices.map((choice) => `<option value="${escapeHtml(choice.id)}">${escapeHtml(choice.name || 'Choix')}</option>`).join('');
      choiceSelect.value = choices.some((choice) => choice.id === oldChoice) ? oldChoice : '';
      groupSelect.disabled = index === 0;
      choiceSelect.disabled = !groupSelect.value;
      node.dataset.groupId = ownId || newGroupId(index, $('[data-group-name]', node).value);
    });
  }

  function addChoice(groupNode, choice = {}) {
    const list = $('.tuc-choice-list', groupNode);
    const choiceId = choice.id || '';
    const row = document.createElement('div');
    row.className = 'tuc-choice-row';
    row.dataset.choiceId = choiceId;
    row.innerHTML = `<input data-choice-name type="text" maxlength="60" placeholder="Nom du choix" value="${escapeHtml(choice.name || choice.nom || '')}" aria-label="Nom du choix"><input data-choice-price class="tuc-choice-price" type="number" min="0" max="10000" step="0.01" inputmode="decimal" placeholder="Supplément" value="${Number(choice.price_delta ?? choice.prix ?? 0)}" aria-label="Supplément en MAD"><label class="tuc-choice-enabled" title="Choix disponible"><input data-choice-available type="checkbox" ${choice.available === false ? '' : 'checked'}><span>Actif</span></label><button class="tuc-choice-remove" type="button" aria-label="Supprimer ce choix">×</button>`;
    list.append(row);
    row.addEventListener('input', refreshConditions);
    $('.tuc-choice-remove', row).addEventListener('click', () => { row.remove(); refreshConditions(); updatePreview(); });
  }

  function addGroup(group = {}, index = groupNodes().length) {
    const node = document.createElement('section');
    node.className = 'tuc-option-group';
    node.dataset.groupId = group.id || '';
    const choices = group.choices || [];
    node.innerHTML = `<div class="tuc-group-head"><input data-group-name type="text" maxlength="60" placeholder="Nom du groupe, ex. Taille" value="${escapeHtml(group.name || group.nom || '')}" aria-label="Nom du groupe"><input data-min type="number" min="0" max="20" value="${Number(group.min ?? (group.required ? 1 : 0))}" aria-label="Minimum de choix" title="Minimum"><input data-max type="number" min="1" max="20" value="${Number(group.max ?? Math.max(1, choices.length))}" aria-label="Maximum de choix" title="Maximum"><button data-remove-group type="button" aria-label="Supprimer le groupe">×</button></div><div class="tuc-group-settings"><label><input data-required type="checkbox" ${group.required ? 'checked' : ''}> Obligatoire</label><label>Afficher si <select data-condition-group aria-label="Groupe conditionnel"><option value="">Toujours afficher</option></select></label><label><select data-condition-choice aria-label="Choix déclencheur"><option value="">Choix déclencheur…</option></select></label></div><div class="tuc-choice-list"></div><button class="tuc-add-choice" type="button">＋ Ajouter un choix</button>`;
    groupsRoot.append(node);
    choices.forEach((choice) => addChoice(node, choice));
    $('[data-remove-group]', node).addEventListener('click', () => { node.remove(); refreshConditions(); updatePreview(); });
    $('.tuc-add-choice', node).addEventListener('click', () => { addChoice(node); refreshConditions(); });
    node.addEventListener('input', () => { refreshConditions(); updatePreview(); });
    $('[data-condition-group]', node).addEventListener('change', () => { refreshConditions(); updatePreview(); });
    refreshConditions();
  }

  function updatePreview() {
    const name = $('#tuc-item-name').value.trim() || 'Votre prochain plat';
    const description = $('#tuc-item-description').value.trim() || 'Votre description apparaîtra ici.';
    const price = Number($('#tuc-item-price').value || 0);
    const promo = Math.min(100, Math.max(0, Number($('#tuc-item-promo').value || 0)));
    const discounted = price * (100 - promo) / 100;
    const image = photoUrl || ($('#tuc-photo-preview').src || '');
    $('#tuc-preview-card').innerHTML = `${image && !$('#tuc-photo-preview').hidden ? `<img src="${escapeHtml(image)}" alt="">` : '<div class="tuc-preview-placeholder">🍽️</div>'}<div class="tuc-preview-copy"><h3>${escapeHtml(name)}</h3><p>${escapeHtml(description)}</p><strong>${money(discounted)}</strong>${promo ? `<del>${money(price)}</del>` : ''}</div>`;
  }

  function resetEditor() {
    editor.reset();
    $('#tuc-item-id').value = '';
    $('#tuc-item-promo').value = '0';
    $('#tuc-item-available').checked = true;
    $('#tuc-item-photo').value = '';
    $('#tuc-photo-preview').hidden = true;
    $('#tuc-photo-preview').removeAttribute('src');
    photoUrl = '';
    groupsRoot.innerHTML = '';
    $('#tuc-editor-mode').textContent = 'NOUVEAU PLAT';
    $('#tuc-editor-title').textContent = 'Ajouter au menu';
    updatePreview();
  }
  function openEditor(item = null) {
    hideNotice();
    resetEditor();
    if (item) {
      $('#tuc-item-id').value = item.id;
      $('#tuc-item-name').value = item.title;
      $('#tuc-item-description').value = item.description || '';
      $('#tuc-item-price').value = Number(item.price).toFixed(2);
      $('#tuc-item-promo').value = Number(item.promo_pct || 0);
      $('#tuc-item-available').checked = Boolean(item.available);
      $('#tuc-editor-mode').textContent = item.status === 'draft' ? 'MODIFIER LE BROUILLON' : 'MODIFIER LE PLAT';
      $('#tuc-editor-title').textContent = item.title;
      const image = item.photo || '';
      if (image) { $('#tuc-photo-preview').src = image; $('#tuc-photo-preview').hidden = false; photoUrl = image; }
      (item.options?.groups || []).forEach((group) => addGroup(group));
    }
    editor.hidden = false;
    editor.scrollIntoView({ behavior: 'smooth', block: 'start' });
    updatePreview();
  }

  function collectOptions() {
    const groups = collectGroupSnapshot();
    if (groups.length > 12) throw new Error('Un plat ne peut pas contenir plus de 12 groupes d’options.');
    const ids = new Set();
    groups.forEach((group) => {
      if (!group.name || !group.choices.length || group.choices.some((choice) => !choice.name)) throw new Error('Complétez le nom de chaque groupe et de chaque choix.');
      if (!group.id || ids.has(group.id)) throw new Error('Les noms de groupes doivent produire des identifiants distincts.');
      ids.add(group.id);
      if (group.choices.length > 20) throw new Error('Un groupe ne peut pas contenir plus de 20 choix.');
      if (group.min < 0 || group.max < 1 || group.min > group.max || group.max > group.choices.length) throw new Error(`Minimum ou maximum invalide pour « ${group.name} ».`);
      const choiceIds = new Set();
      group.choices.forEach((choice) => {
        if (choiceIds.has(choice.id)) throw new Error(`Les choix de « ${group.name} » doivent avoir des noms distincts.`);
        choiceIds.add(choice.id);
        if (!Number.isFinite(choice.price_delta) || choice.price_delta < 0) throw new Error('Un supplément doit être un montant positif ou nul.');
      });
      if (group.visible_if) {
        const dependency = groups.find((candidate) => candidate.id === group.visible_if.group_id);
        if (!dependency || groups.indexOf(dependency) >= groups.indexOf(group) || !dependency.choices.some((choice) => choice.id === group.visible_if.choice_ids[0])) throw new Error('Une condition doit cibler un choix d’un groupe précédent.');
      }
    });
    return { version: 2, groups };
  }

  async function loadMenu() {
    try {
      const [restaurant, list] = await Promise.all([request('restaurant'), request('menu')]);
      $('#tuc-restaurant-name').textContent = restaurant.name;
      renderItems(list);
    } catch (error) {
      $('#tuc-menu-loading').hidden = true;
      showNotice(error.message, true);
      menuRoot.innerHTML = '<div class="tuc-empty"><strong>Menu indisponible</strong><span>Vérifiez l’affectation de votre compte ou réessayez.</span></div>';
    }
  }

  async function saveItem(status) {
    if (busy || !editor.reportValidity()) return;
    hideNotice();
    let options;
    try { options = collectOptions(); } catch (error) { showNotice(error.message, true); return; }
    const id = $('#tuc-item-id').value;
    const payload = {
      title: $('#tuc-item-name').value.trim(),
      description: $('#tuc-item-description').value.trim(),
      price: Number($('#tuc-item-price').value),
      promo_pct: Number($('#tuc-item-promo').value || 0),
      available: $('#tuc-item-available').checked,
      options,
      status
    };
    setBusy(true);
    try {
      const saved = await request(id ? `menu/${id}` : 'menu', { method: id ? 'PUT' : 'POST', body: JSON.stringify(payload) });
      const photo = $('#tuc-item-photo').files[0];
      if (photo) {
        const form = new FormData(); form.append('file', photo);
        await request(`menu/${saved.id}/photo`, { method: 'POST', body: form });
      }
      editor.hidden = true;
      showNotice(status === 'publish' ? 'Plat publié sur votre menu.' : 'Brouillon enregistré.');
      await loadMenu();
    } catch (error) {
      showNotice(error.message, true);
    } finally {
      setBusy(false);
    }
  }

  $('#tuc-new-item').addEventListener('click', () => openEditor());
  $('#tuc-editor-close').addEventListener('click', () => { editor.hidden = true; });
  $('#tuc-add-group').addEventListener('click', () => addGroup());
  $$('.tuc-editor-actions button').forEach((button) => { button.dataset.label = button.textContent; button.addEventListener('click', () => saveItem(button.dataset.saveStatus)); });
  ['input', 'change'].forEach((eventName) => editor.addEventListener(eventName, updatePreview));
  menuRoot.addEventListener('click', async (event) => {
    const editButton = event.target.closest('[data-edit]');
    if (editButton) openEditor(items.get(Number(editButton.dataset.edit)));
    const archiveButton = event.target.closest('[data-archive]');
    if (archiveButton) {
      const id = Number(archiveButton.dataset.archive);
      if (!window.confirm('Archiver ce plat ? Il ne sera plus visible aux clients et restera récupérable en brouillon.')) return;
      archiveButton.disabled = true;
      try { await request(`menu/${id}`, { method: 'DELETE' }); showNotice('Plat archivé en brouillon.'); await loadMenu(); }
      catch (error) { showNotice(error.message, true); archiveButton.disabled = false; }
    }
  });
  $('#tuc-item-photo').addEventListener('change', () => {
    const file = $('#tuc-item-photo').files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
      $('#tuc-item-photo').value = '';
      showNotice('Formats acceptés : JPEG, PNG ou WebP, 5 Mo maximum.', true);
      return;
    }
    photoUrl = URL.createObjectURL(file);
    $('#tuc-photo-preview').src = photoUrl;
    $('#tuc-photo-preview').hidden = false;
    updatePreview();
  });
  loadMenu();
})();
