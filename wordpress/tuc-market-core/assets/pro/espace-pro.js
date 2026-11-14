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
  /* Montant saisi par un humain : refuser plutot que deviner. Miroir exact de
     TUC_Espace_Pro::normaliser_montant — sinon Number("45,50") = NaN et l'aperçu
     afficherait 0,00 MAD en silence, ce qui est le meme degre de gravite que le bug corrige. */
  const montant = (v) => {
    if (typeof v === 'number') return Number.isFinite(v) ? v : NaN;
    let s = String(v == null ? '' : v).replace(/[\u00A0\u202F\u2009\u200A\u2007\u2019\u2018\s]/g, '');
    if (!s) return NaN;
    const nbVirg = (s.match(/,/g) || []).length, nbPoint = (s.match(/\./g) || []).length;
    if (nbVirg > 1 || nbPoint > 1) return NaN;
    let dec = null;
    if (nbVirg && nbPoint) dec = s.lastIndexOf(',') > s.lastIndexOf('.') ? ',' : '.';
    else if (nbVirg) dec = ',';
    else if (nbPoint) dec = '.';
    if (!dec) return /^[0-9]{1,7}$/.test(s) ? parseFloat(s) : NaN;
    const pos = s.lastIndexOf(dec);
    let entier = s.slice(0, pos) || '0';
    let fraction = s.slice(pos + 1) || '0';
    const mil = dec === ',' ? '.' : ',';
    if (entier.includes(mil)) {
      const blocs = entier.split(mil);
      const premier = blocs.shift();
      if (!/^[0-9]{1,3}$/.test(premier)) return NaN;
      if (!blocs.every((b) => /^[0-9]{3}$/.test(b))) return NaN;
      entier = premier + blocs.join('');
    }
    if (!/^[0-9]{1,2}$/.test(fraction) || !/^[0-9]{1,7}$/.test(entier)) return NaN;
    return parseFloat(entier + '.' + fraction);
  };
  const texteMontant = (v) => `Montant « ${String(v == null ? '' : v).trim()} » illisible. Écrivez par exemple 45,50 ou 1234 (2 chiffres au plus après la virgule).`;
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
          price_delta: montant($('[data-choice-price]', row).value),
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
    row.innerHTML = `<input data-choice-name type="text" maxlength="60" placeholder="Nom du choix" value="${escapeHtml(choice.name || choice.nom || '')}" aria-label="Nom du choix"><input data-choice-price class="tuc-choice-price" type="text" inputmode="decimal" maxlength="16" autocomplete="off" placeholder="Supplément" value="${Number(choice.price_delta ?? choice.prix ?? 0)}" aria-label="Supplément en MAD"><label class="tuc-choice-enabled" title="Choix disponible"><input data-choice-available type="checkbox" ${choice.available === false ? '' : 'checked'}><span>Actif</span></label><button class="tuc-choice-remove" type="button" aria-label="Supprimer ce choix">×</button>`;
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
    const price = montant($('#tuc-item-price').value);
    const promo = Math.min(100, Math.max(0, Math.round(montant($('#tuc-item-promo').value) || 0)));
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

  const statusLabels = { nouvelle: 'À traiter', acceptee: 'Acceptée', preparation: 'En préparation', prete: 'Prête', servie: 'Terminée', refusee: 'Refusée', annulee: 'Annulée' };
  const modeLabels = { livraison: 'Livraison', emporter: 'À emporter', table: 'À table' };

  function renderOrders(result) {
    const root = $('#tuc-orders-list');
    if (!root) return;
    const orders = Array.isArray(result?.orders) ? result.orders : [];
    const pending = orders.filter((order) => ['nouvelle', 'acceptee', 'preparation'].includes(order.status)).length;
    const badge = $('#tuc-orders-count');
    if (badge) badge.textContent = pending ? String(pending) : '';
    if (!orders.length) {
      root.innerHTML = '<div class="tuc-orders-empty"><strong>Aucune commande pour ce filtre</strong><span>Les nouvelles commandes apparaîtront ici.</span></div>';
      return;
    }
    root.innerHTML = orders.map((order) => {
      const status = statusLabels[order.status] || 'Reçue';
      const date = order.created_at ? new Date(order.created_at).toLocaleString('fr-MA', { dateStyle: 'medium', timeStyle: 'short' }) : '';
      const mode = modeLabels[order.mode] || 'Commande';
      const table = order.mode === 'table' && Number(order.table) > 0 ? ` · Table ${Number(order.table)}` : '';
      const lines = (order.items || []).map((line) => {
        const options = Array.isArray(line.options) && line.options.length ? `<small>${line.options.map(escapeHtml).join(' · ')}</small>` : '';
        return `<div class="tuc-order-line"><span><strong>${Number(line.quantity)} × ${escapeHtml(line.title)}</strong>${options}</span><span>${money(Number(line.unit_price) * Number(line.quantity))}</span></div>`;
      }).join('');
      let actions = '';
      if (TUCPro.canUpdateOrders) {
        const next = { nouvelle: [['acceptee', 'Accepter'], ['refusee', 'Refuser']], acceptee: [['preparation', 'En préparation'], ['refusee', 'Refuser']], preparation: [['prete', 'Marquer prête']], prete: [['servie', 'Terminer']] }[order.status] || [];
        actions = next.length ? `<div class="tuc-order-actions">${next.map(([target, label]) => `<button type="button" data-order-status="${escapeHtml(target)}" data-order-id="${Number(order.id)}" data-next="${escapeHtml(target)}">${escapeHtml(label)}</button>`).join('')}</div>` : '';
      }
      return `<article class="tuc-order-card"><div class="tuc-order-card-head"><div><div class="tuc-order-ref">Commande #${Number(order.id)}</div><div class="tuc-order-meta">${escapeHtml(date)} · ${escapeHtml(mode)}${escapeHtml(table)}</div></div><span class="tuc-order-status" data-status="${escapeHtml(order.status)}">${escapeHtml(status)}</span></div><div class="tuc-order-lines">${lines || '<span class="tuc-order-meta">Détail indisponible</span>'}</div><div class="tuc-order-total"><span>Total</span><span>${money(order.total)}</span></div>${actions}</article>`;
    }).join('');
  }

  async function loadOrders() {
    const root = $('#tuc-orders-list');
    if (!root || !TUCPro.canViewOrders) return;
    root.innerHTML = '<div class="tuc-loading">Chargement des commandes…</div>';
    try {
      const filter = $('#tuc-order-filter')?.value || 'toutes';
      renderOrders(await request(`commandes?statut=${encodeURIComponent(filter)}`));
    } catch (error) {
      root.innerHTML = `<div class="tuc-orders-empty"><strong>Commandes indisponibles</strong><span>${escapeHtml(error.message)}</span></div>`;
    }
  }

  let pendingLocation = null;

  function setLocationNotice(message, kind = '') {
    const node = $('#tuc-store-location-notice');
    if (!node) return;
    node.textContent = message;
    node.classList.toggle('is-error', kind === 'error');
    node.classList.toggle('is-success', kind === 'success');
  }

  function renderSavedLocation(location) {
    const current = $('#tuc-store-location-current');
    if (!current) return;
    if (!location || !Number.isFinite(Number(location.lat)) || !Number.isFinite(Number(location.lng))) {
      current.textContent = 'Aucune localisation enregistrée.';
      return;
    }
    const source = location.source === 'gps' ? 'GPS sur place' : location.source === 'google_maps_link' ? 'lien Google Maps' : location.source === 'manual_coordinates' ? 'coordonnées saisies' : 'coordonnées existantes';
    const accuracy = location.source === 'gps' && Number(location.accuracy) > 0 ? ` · précision déclarée ±${Math.round(Number(location.accuracy))} m` : '';
    current.textContent = `Localisation enregistrée : ${Number(location.lat).toFixed(6)}, ${Number(location.lng).toFixed(6)} · ${source}${accuracy}.`;
  }

  function updateLocationSaveState() {
    const save = $('#tuc-save-location');
    if (!save) return;
    const confirmed = pendingLocation?.source === 'gps' || $('#tuc-location-confirm')?.checked;
    save.disabled = !pendingLocation || !confirmed;
  }

  function previewLocation(location, label) {
    pendingLocation = location;
    $('#tuc-store-location-preview').textContent = `${label} : ${Number(location.lat).toFixed(6)}, ${Number(location.lng).toFixed(6)}. Vérifiez que le point correspond à votre établissement, puis enregistrez.`;
    $('#tuc-location-confirm-wrap').hidden = location.source === 'gps';
    $('#tuc-location-confirm-text').textContent = location.source === 'manual_coordinates'
      ? 'J’ai vérifié que ces coordonnées placent le repère sur mon établissement.'
      : 'J’ai vérifié que le repère du lien correspond bien à mon établissement.';
    $('#tuc-location-confirm').checked = false;
    updateLocationSaveState();
    setLocationNotice('');
  }

  function invalidatePendingLocation(source, message) {
    if (pendingLocation?.source !== source) return;
    pendingLocation = null;
    $('#tuc-store-location-preview').textContent = '';
    $('#tuc-location-confirm-wrap').hidden = true;
    $('#tuc-location-confirm').checked = false;
    updateLocationSaveState();
    setLocationNotice(message);
  }

  function fillStoreSettings(restaurant) {
    const open = $('#tuc-store-open');
    if (!open) return;
    $('#tuc-restaurant-name').textContent = restaurant.name;
    open.checked = Boolean(restaurant.open);
    $('#tuc-open-dot')?.classList.toggle('is-closed', !restaurant.open);
    $('#tuc-store-minimum').value = Number(restaurant.minimum || 0);
    $('#tuc-store-fee').value = Number(restaurant.fee || 0);
    $('#tuc-store-tables').value = Number(restaurant.tables || 0);
    $$('input[name="mode"]', $('#tuc-store-settings')).forEach((input) => { input.checked = (restaurant.modes || []).includes(input.value); });
    $('#tuc-store-tables-wrap').hidden = !$('input[name="mode"][value="table"]', $('#tuc-store-settings')).checked;
    if (!pendingLocation) renderSavedLocation(restaurant.location);
  }

  function mapsCoordinates(rawLink) {
    let url;
    try { url = new URL(rawLink); } catch { return null; }
    const host = url.hostname.toLowerCase();
    const isGoogle = host === 'maps.app.goo.gl' || host === 'goo.gl' || host === 'g.page'
      || host === 'google.com' || host.endsWith('.google.com')
      || host === 'google.co.ma' || host.endsWith('.google.co.ma');
    if (!isGoogle || !['https:', 'http:'].includes(url.protocol)) return null;
    let decoded;
    try { decoded = decodeURIComponent(url.href.replace(/\+/g, '%20')); } catch { decoded = url.href; }
    const patterns = [
      // !3d/!4d are the selected place pin; @lat,lng can be only the map viewport center.
      /!3d(-?\d{1,2}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/,
      /@(-?\d{1,2}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)/
    ];
    let match = patterns.map((pattern) => decoded.match(pattern)).find(Boolean);
    if (!match) {
      const candidates = ['query', 'q', 'll', 'center'].map((key) => url.searchParams.get(key) || '').filter(Boolean);
      for (const candidate of candidates) {
        match = candidate.match(/(-?\d{1,2}(?:\.\d+)?)\s*[,;]\s*(-?\d{1,3}(?:\.\d+)?)/);
        if (match) break;
      }
    }
    if (!match) return null;
    const lat = Number(match[1]);
    const lng = Number(match[2]);
    return Number.isFinite(lat) && Number.isFinite(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180 ? { lat, lng } : null;
  }

  function previewManualRestaurantCoordinates() {
    const rawLat = $('#tuc-store-lat').value.trim();
    const rawLng = $('#tuc-store-lng').value.trim();
    const lat = Number(rawLat);
    const lng = Number(rawLng);
    if (!rawLat || !rawLng || !Number.isFinite(lat) || !Number.isFinite(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
      pendingLocation = null;
      $('#tuc-store-location-preview').textContent = '';
      $('#tuc-location-confirm-wrap').hidden = true;
      $('#tuc-location-confirm').checked = false;
      updateLocationSaveState();
      setLocationNotice('Saisissez une latitude entre −90 et 90 et une longitude entre −180 et 180.', 'error');
      return;
    }
    previewLocation({ lat, lng, source: 'manual_coordinates' }, 'Coordonnées saisies');
  }

  function captureRestaurantGps() {
    const button = $('#tuc-capture-location');
    if (!navigator.geolocation) {
      setLocationNotice('La géolocalisation n’est pas disponible sur cet appareil. Vous pouvez coller un lien Google Maps complet.', 'error');
      return;
    }
    button.disabled = true;
    button.textContent = 'Recherche de votre position…';
    setLocationNotice('Autorisez la localisation dans la fenêtre du navigateur. Placez-vous près de l’entrée du restaurant.');
    navigator.geolocation.getCurrentPosition((position) => {
      button.disabled = false;
      button.textContent = 'Capturer ma position actuelle';
      const accuracy = Number(position.coords.accuracy);
      if (!Number.isFinite(accuracy) || accuracy <= 0 || accuracy > 100) {
        setLocationNotice(`La précision GPS est de ±${Math.round(accuracy || 0)} m, insuffisante (100 m maximum). Réessayez dehors ou utilisez un lien Google Maps.`, 'error');
        return;
      }
      const capturedAt = Math.floor(Number(position.timestamp || Date.now()) / 1000);
      previewLocation({ lat: Number(position.coords.latitude), lng: Number(position.coords.longitude), accuracy, capturedAt, source: 'gps' }, 'Position GPS capturée');
      setLocationNotice(`Position capturée avec une précision de ±${Math.round(accuracy)} m.`, 'success');
    }, (error) => {
      button.disabled = false;
      button.textContent = 'Capturer ma position actuelle';
      const message = error.code === 1 ? 'Autorisation refusée. Vous pouvez réessayer ou coller un lien Google Maps complet.' : error.code === 3 ? 'La recherche GPS a expiré. Réessayez sur place ou utilisez un lien Google Maps.' : 'Position indisponible. Vérifiez les réglages de localisation ou collez un lien Google Maps.';
      setLocationNotice(message, 'error');
    }, { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 });
  }

  async function saveRestaurantLocation() {
    if (!pendingLocation || $('#tuc-save-location').disabled) return;
    const button = $('#tuc-save-location');
    button.disabled = true;
    button.textContent = 'Enregistrement…';
    try {
      const saved = await request('restaurant/location', {
        method: 'POST',
        body: JSON.stringify({ ...pendingLocation, confirmed: pendingLocation.source !== 'gps' ? Boolean($('#tuc-location-confirm').checked) : undefined })
      });
      pendingLocation = null;
      $('#tuc-store-location-preview').textContent = '';
      $('#tuc-location-confirm-wrap').hidden = true;
      $('#tuc-location-confirm').checked = false;
      renderSavedLocation(saved.location);
      setLocationNotice('La localisation du restaurant a été enregistrée.', 'success');
    } catch (error) {
      setLocationNotice(error.message, 'error');
    } finally {
      button.textContent = 'Enregistrer cette localisation';
      updateLocationSaveState();
    }
  }

  async function saveStoreSettings(event) {
    event.preventDefault();
    const button = $('button[type="submit"]', event.currentTarget);
    const modes = $$('input[name="mode"]:checked', event.currentTarget).map((input) => input.value);
    if (!modes.length) { showNotice('Choisissez au moins un mode de service.', true); return; }
    button.disabled = true;
    try {
      const restaurant = await request('restaurant', { method: 'PATCH', body: JSON.stringify({
        open: $('#tuc-store-open').checked,
        minimum: montant($('#tuc-store-minimum').value),
        fee: montant($('#tuc-store-fee').value),
        modes,
        tables: Number($('#tuc-store-tables').value)
      }) });
      fillStoreSettings(restaurant);
      showNotice('Paramètres de la boutique enregistrés.');
      if (!restaurant.open) $('#tuc-open-dot')?.classList.add('is-closed');
    } catch (error) { showNotice(error.message, true); }
    finally { button.disabled = false; }
  }

  async function loadMenu() {
    try {
      const [restaurant, list] = await Promise.all([request('restaurant'), request('menu')]);
      fillStoreSettings(restaurant);
      renderItems(list);
      if (TUCPro.canViewOrders) loadOrders();
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
    const brutPrix = $('#tuc-item-price').value;
    if (!Number.isFinite(montant(brutPrix))) { showNotice(texteMontant(brutPrix), true); return; }
    const brutPromo = $('#tuc-item-promo').value;
    if (String(brutPromo).trim() !== '' && !Number.isFinite(montant(brutPromo))) { showNotice(texteMontant(brutPromo), true); return; }
    const id = $('#tuc-item-id').value;
    const payload = {
      title: $('#tuc-item-name').value.trim(),
      description: $('#tuc-item-description').value.trim(),
      price: montant($('#tuc-item-price').value),
      promo_pct: Math.round(montant($('#tuc-item-promo').value) || 0),
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
  $('#tuc-pro-tabs')?.addEventListener('click', (event) => {
    const button = event.target.closest('[role="tab"][data-tab]');
    if (!button) return;
    const tab = button.dataset.tab;
    $$('[role="tab"]', $('#tuc-pro-tabs')).forEach((item) => {
      const selected = item === button;
      item.setAttribute('aria-selected', String(selected));
      item.tabIndex = selected ? 0 : -1;
    });
    $$('[data-panel]').forEach((panel) => { panel.hidden = panel.dataset.panel !== tab; });
    $('#tuc-pro-page-title').textContent = { menu: 'Votre menu', orders: 'Commandes', settings: 'Votre boutique' }[tab] || 'Votre espace';
    $('#tuc-new-item').hidden = tab !== 'menu';
    if (tab !== 'menu') editor.hidden = true;
    if (tab === 'orders') loadOrders();
  });
  $('#tuc-pro-tabs')?.addEventListener('keydown', (event) => {
    const tabs = $$('[role="tab"]', $('#tuc-pro-tabs'));
    const currentIndex = tabs.indexOf(event.target.closest('[role="tab"]'));
    if (currentIndex < 0) return;
    const rtl = getComputedStyle($('#tuc-pro-tabs')).direction === 'rtl';
    let nextIndex = currentIndex;
    if (event.key === 'Home') nextIndex = 0;
    else if (event.key === 'End') nextIndex = tabs.length - 1;
    else if (event.key === 'ArrowRight') nextIndex = (currentIndex + (rtl ? -1 : 1) + tabs.length) % tabs.length;
    else if (event.key === 'ArrowLeft') nextIndex = (currentIndex + (rtl ? 1 : -1) + tabs.length) % tabs.length;
    else return;
    event.preventDefault();
    tabs[nextIndex].focus();
    tabs[nextIndex].click();
  });
  $('#tuc-refresh-orders')?.addEventListener('click', loadOrders);
  $('#tuc-order-filter')?.addEventListener('change', loadOrders);
  $('#tuc-orders-list')?.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-order-status]');
    if (!button || button.disabled) return;
    const next = button.dataset.orderStatus;
    if (next === 'refusee' && !window.confirm('Refuser cette commande ?')) return;
    button.disabled = true;
    try {
      await request(`commandes/${Number(button.dataset.orderId)}/statut`, { method: 'POST', body: JSON.stringify({ statut: next }) });
      showNotice(`Commande mise à jour : ${statusLabels[next] || next}.`);
      await loadOrders();
    } catch (error) { showNotice(error.message, true); button.disabled = false; }
  });
  $('#tuc-store-settings')?.addEventListener('submit', saveStoreSettings);
  $('#tuc-capture-location')?.addEventListener('click', captureRestaurantGps);
  $('#tuc-parse-google-link')?.addEventListener('click', () => {
    const input = $('#tuc-store-google-link');
    const coordinates = mapsCoordinates(input.value.trim());
    if (!coordinates) {
      setLocationNotice('Ce lien ne contient pas de coordonnées lisibles. Les liens courts ne sont pas résolus par une API externe. Ouvrez le lieu dans Google Maps et copiez son lien complet avec les coordonnées, ou utilisez le GPS sur place.', 'error');
      $('#tuc-store-location-preview').textContent = '';
      pendingLocation = null;
      $('#tuc-location-confirm-wrap').hidden = true;
      updateLocationSaveState();
      return;
    }
    previewLocation({ ...coordinates, source: 'google_maps_link' }, 'Coordonnées extraites du lien Google Maps');
  });
  $('#tuc-location-confirm')?.addEventListener('change', updateLocationSaveState);
  $('#tuc-store-google-link')?.addEventListener('input', () => invalidatePendingLocation('google_maps_link', 'Lien modifié : extrayez à nouveau ses coordonnées avant de les enregistrer.'));
  ['#tuc-store-lat', '#tuc-store-lng'].forEach((selector) => $(selector)?.addEventListener('input', () => invalidatePendingLocation('manual_coordinates', 'Coordonnées modifiées : prévisualisez-les à nouveau avant de les enregistrer.')));
  $('#tuc-use-manual-coordinates')?.addEventListener('click', previewManualRestaurantCoordinates);
  $('#tuc-save-location')?.addEventListener('click', saveRestaurantLocation);
  $('#tuc-store-settings')?.addEventListener('change', (event) => {
    if (event.target.matches('input[name="mode"]')) $('#tuc-store-tables-wrap').hidden = !$('input[name="mode"][value="table"]', $('#tuc-store-settings')).checked;
  });
  loadMenu();
})();
