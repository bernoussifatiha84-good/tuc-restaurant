(() => {
  'use strict';
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const root = $('#tuc-client-menu');
  if (!root || !window.TUCClient) return;
  const slug = root.dataset.resto || '';
  const table = Math.max(0, Number(root.dataset.table || 0));
  const status = $('#tuc-client-status');
  const products = $('#tuc-client-items');
  const customDialog = $('#tuc-customize-dialog');
  const cartDialog = $('#tuc-cart-dialog');
  const storeKey = `tuc-cart-v2:${slug}`;
  const trackingKey = `tuc-order-track-v1:${slug}`;
  const pendingOrderKey = `tuc-pending-order-v1:${slug}:${TUCClient.phone || 'guest'}`;
  const trackingDialog = $('#tuc-tracking-dialog');
  const accountDialog = $('#tuc-account-dialog');
  let trackingTimer = 0;
  const labels = { livraison: 'Livraison', emporter: 'À emporter', table: 'À table' };
  let restaurant = null;
  let menu = [];
  let cart = [];
  let currentItem = null;
  let pendingKey = '';
  try {
    const savedPendingKey = localStorage.getItem(pendingOrderKey) || '';
    if (/^[A-Za-z0-9._-]{24,64}$/.test(savedPendingKey)) pendingKey = savedPendingKey;
    else if (savedPendingKey) localStorage.removeItem(pendingOrderKey);
  } catch (_) { /* clé non persistante si stockage désactivé */ }
  const locationKey = `tuc-delivery-location-v1:${slug}:${TUCClient.phone || 'guest'}`;
  const modeKey = `tuc-order-mode-v1:${slug}`;
  let deliveryLocation = null;
  let waSession = null;
  let waPollTimer = 0;
  try {
    const saved = JSON.parse(localStorage.getItem(locationKey) || 'null');
    const candidate = TUCClient.location || saved;
    if (candidate && Number.isFinite(Number(candidate.lat)) && Number.isFinite(Number(candidate.lng))) {
      deliveryLocation = { lat: Number(candidate.lat), lng: Number(candidate.lng), label: String(candidate.label || 'Position enregistrée') };
    }
  } catch (_) { /* géolocalisation facultative */ }

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[char]);
  const money = (value) => `${Number(value || 0).toLocaleString('fr-MA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} MAD`;
  const setStatus = (message, error = false) => { status.textContent = message; status.classList.toggle('is-error', error); status.hidden = !message; };
  const cacheCart = () => {
    try { localStorage.setItem(storeKey, JSON.stringify(cart.map(({ id, q, options }) => ({ id, q, options })))); } catch (_) { /* stockage local facultatif */ }
  };
  const resetIdempotency = () => {
    pendingKey = '';
    try { localStorage.removeItem(pendingOrderKey); } catch (_) { /* clé non persistante si stockage désactivé */ }
  };
  const newKey = () => {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID();
    if (window.crypto?.getRandomValues) return Array.from(window.crypto.getRandomValues(new Uint8Array(24)), (byte) => byte.toString(16).padStart(2, '0')).join('');
    return `tuc-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`;
  };
  function readTracking() {
    try {
      const data = JSON.parse(localStorage.getItem(trackingKey) || 'null');
      return data && Number.isInteger(Number(data.reference)) && /^[A-Za-z0-9_-]{43}$/.test(data.token) ? data : null;
    } catch (_) { return null; }
  }
  function saveTracking(data) {
    try { localStorage.setItem(trackingKey, JSON.stringify(data)); } catch (_) { /* suivi local facultatif */ }
    const button = $('#tuc-open-saved-tracking');
    if (button) { button.hidden = false; button.textContent = `Suivre ma commande #${Number(data.reference)}`; }
  }
  async function refreshTracking() {
    const tracked = readTracking();
    const content = $('#tuc-tracking-content');
    if (!tracked || !content) { if (content) content.innerHTML = '<p>Aucun lien de suivi enregistré sur cet appareil.</p>'; return; }
    content.innerHTML = '<p class="tuc-tracking-loading">Actualisation du statut…</p>';
    try {
      const response = await fetch(`${TUCClient.rest}commande/suivi`, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': TUCClient.nonce },
        body: JSON.stringify({ jeton: tracked.token })
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.message || 'Suivi momentanément indisponible.');
      const history = Array.isArray(data.history) ? data.history : [];
      const historyHtml = history.map((entry) => {
        const date = entry.at ? new Date(entry.at).toLocaleString('fr-MA', { dateStyle: 'medium', timeStyle: 'short' }) : '';
        return `<li><span class="tuc-tracking-dot"></span><span><strong>${escapeHtml(entry.label)}</strong><small>${escapeHtml(date)}</small></span></li>`;
      }).join('');
      content.innerHTML = `<div class="tuc-tracking-current"><span class="tuc-tracking-state">${escapeHtml(data.label)}</span><h3>Commande #${Number(data.reference)}</h3><p>${escapeHtml(data.restaurant)}</p></div><ol class="tuc-tracking-history">${historyHtml || '<li><span>Statut reçu.</span></li>'}</ol><p class="tuc-tracking-note">Ce lien privé fonctionne pendant 30 jours sur cet appareil.</p>`;
    } catch (error) {
      content.innerHTML = `<p class="tuc-tracking-error">${escapeHtml(error.message)}</p>`;
    }
  }
  function openTracking() {
    if (!trackingDialog) return;
    if (!trackingDialog.open) trackingDialog.showModal();
    refreshTracking();
    window.clearInterval(trackingTimer);
    trackingTimer = window.setInterval(() => { if (trackingDialog.open) refreshTracking(); }, 30000);
  }
  const getItem = (id) => menu.find((item) => Number(item.id) === Number(id));
  const groupsFor = (item) => Array.isArray(item?.options?.groups) ? item.options.groups : [];

  function selectionDetails(item, inputSelections) {
    const groups = groupsFor(item);
    const selected = new Map();
    (inputSelections || []).forEach((entry) => {
      if (entry && typeof entry.group_id === 'string' && Array.isArray(entry.choices)) selected.set(entry.group_id, entry.choices.map(String));
    });
    const canonical = [];
    const optionLabels = [];
    let surcharge = 0;
    for (const group of groups) {
      const choices = selected.get(group.id) || [];
      const visible = !group.visible_if || (selected.get(group.visible_if.group_id) || []).some((id) => group.visible_if.choice_ids.includes(id));
      if (!visible && choices.length) throw new Error('Un choix dépend d’une option non sélectionnée.');
      if (!visible) continue;
      if (choices.length < Number(group.min || 0) || choices.length > Number(group.max || 0)) throw new Error(`Choisissez entre ${group.min} et ${group.max} option(s) pour « ${group.name} ».`);
      const picked = [];
      choices.forEach((id) => {
        const choice = (group.choices || []).find((candidate) => candidate.id === id);
        if (!choice || !choice.available) throw new Error('Un choix de votre panier n’est plus disponible.');
        const price = Number(choice.price_delta || 0);
        surcharge += price;
        const suffix = price > 0 ? ` +${price.toLocaleString('fr-MA', { minimumFractionDigits: 0, maximumFractionDigits: 2 })}` : '';
        optionLabels.push(`${group.id === 'options' ? choice.name : `${group.name}: ${choice.name}`}${suffix}`);
        picked.push(id);
      });
      if (picked.length) canonical.push({ group_id: group.id, choices: picked });
    }
    for (const groupId of selected.keys()) if (!groups.some((group) => group.id === groupId)) throw new Error('Groupe d’options inconnu.');
    const base = Number(item.price || 0) * (100 - Math.max(0, Math.min(100, Number(item.promo_pct || 0)))) / 100;
    return { options: canonical, optionLabels, unit: Math.round((base + surcharge) * 100) / 100 };
  }

  function renderMenu() {
    if (!restaurant) return;
    if (!menu.length) {
      products.innerHTML = '<div class="tuc-client-empty"><span aria-hidden="true">🍽️</span><strong>Menu bientôt disponible</strong><p>Ce restaurant n’a pas encore publié de plats.</p></div>';
      return;
    }
    products.innerHTML = menu.map((item) => {
      const soldOut = !item.available || !restaurant.open;
      const discounted = Number(item.promo_pct) > 0;
      const photo = item.photo ? `<img src="${escapeHtml(item.photo)}" alt="" loading="lazy">` : '<div class="tuc-client-photo-placeholder" aria-hidden="true">🍽️</div>';
      return `<article class="tuc-client-card ${soldOut ? 'is-unavailable' : ''}"><div class="tuc-client-card-photo">${photo}${discounted ? `<span class="tuc-client-promo">−${Number(item.promo_pct)}%</span>` : ''}</div><div class="tuc-client-card-body"><div><h3>${escapeHtml(item.title)}</h3><p>${escapeHtml(item.description || '')}</p></div><div class="tuc-client-card-footer"><div class="tuc-client-price">${discounted ? `<del>${money(item.price)}</del>` : ''}<strong>${money(Number(item.price) * (100 - Number(item.promo_pct || 0)) / 100)}</strong></div><button type="button" class="tuc-add-to-cart" data-add="${Number(item.id)}" ${soldOut ? 'disabled' : ''}>${soldOut ? (restaurant.open ? 'Indisponible' : 'Fermé') : 'Choisir'}</button></div></div></article>`;
    }).join('');
  }

  function readOptionDialog() {
    const selections = [];
    for (const fieldset of $$('.tuc-option-fieldset', $('#tuc-customize-groups'))) {
      if (fieldset.hidden) continue;
      const group = groupsFor(currentItem).find((candidate) => candidate.id === fieldset.dataset.groupId);
      if (!group) continue;
      const chosen = $$('input[type="checkbox"]:checked', fieldset).map((input) => input.value);
      if (chosen.length < Number(group.min || 0) || chosen.length > Number(group.max || 0)) {
        throw new Error(`Choisissez entre ${group.min} et ${group.max} option(s) pour « ${group.name} ».`);
      }
      if (chosen.length) selections.push({ group_id: group.id, choices: chosen });
    }
    return selectionDetails(currentItem, selections);
  }

  function updateCustomizeVisibility() {
    if (!currentItem) return;
    for (const group of groupsFor(currentItem)) {
      const fieldset = $(`[data-group-id="${CSS.escape(group.id)}"]`, $('#tuc-customize-groups'));
      if (!fieldset) continue;
      const visible = !group.visible_if || $$(`input[type="checkbox"]:checked`, $(`[data-group-id="${CSS.escape(group.visible_if.group_id)}"]`, $('#tuc-customize-groups')) || document).some((input) => group.visible_if.choice_ids.includes(input.value));
      fieldset.hidden = !visible;
      if (!visible) $$('input[type="checkbox"]', fieldset).forEach((input) => { input.checked = false; });
    }
    updateCustomizePrice();
  }

  function updateCustomizePrice() {
    if (!currentItem) return;
    try { $('#tuc-customize-price').textContent = `· ${money(readOptionDialog().unit)}`; }
    catch (_) {
      const base = Number(currentItem.price) * (100 - Number(currentItem.promo_pct || 0)) / 100;
      const surcharge = $$('.tuc-option-fieldset input[type="checkbox"]:checked', $('#tuc-customize-groups')).reduce((sum, input) => sum + Number(input.dataset.price || 0), 0);
      $('#tuc-customize-price').textContent = `· ${money(base + surcharge)}`;
    }
  }

  function openCustomize(item) {
    currentItem = item;
    $('#tuc-customize-title').textContent = item.title;
    $('#tuc-customize-error').hidden = true;
    const groups = groupsFor(item);
    if (!groups.length) { addLine(item, []); return; }
    $('#tuc-customize-groups').innerHTML = groups.map((group) => `<fieldset class="tuc-option-fieldset" data-group-id="${escapeHtml(group.id)}"><legend>${escapeHtml(group.name)}${group.required ? ' <em>*</em>' : ''}</legend><p>${group.min === group.max ? `Choisissez ${group.min}` : `Choisissez ${group.min} à ${group.max}`}</p><div class="tuc-option-choices">${(group.choices || []).map((choice) => `<label class="tuc-option-choice ${choice.available ? '' : 'is-disabled'}"><input type="checkbox" value="${escapeHtml(choice.id)}" data-price="${Number(choice.price_delta || 0)}" ${choice.available ? '' : 'disabled'}><span>${escapeHtml(choice.name)}</span><strong>${Number(choice.price_delta) > 0 ? `+${money(choice.price_delta)}` : 'Inclus'}</strong></label>`).join('')}</div></fieldset>`).join('');
    for (const group of groups) {
      const fieldset = $(`[data-group-id="${CSS.escape(group.id)}"]`, $('#tuc-customize-groups'));
      $$('input[type="checkbox"]', fieldset).forEach((input) => input.addEventListener('change', () => {
        const checked = $$('input[type="checkbox"]:checked', fieldset);
        if (checked.length > Number(group.max)) input.checked = false;
        updateCustomizeVisibility();
      }));
    }
    updateCustomizeVisibility();
    customDialog.showModal();
  }

  function onCustomizeChange() { updateCustomizeVisibility(); }
  function addLine(item, selection) {
    let details;
    try { details = selectionDetails(item, selection); }
    catch (error) { const err = $('#tuc-customize-error'); err.textContent = error.message; err.hidden = false; return; }
    const key = `${item.id}:${JSON.stringify(details.options)}`;
    const line = cart.find((entry) => entry.key === key);
    if (line) line.q = Math.min(50, line.q + 1);
    else cart.push({ key, id: Number(item.id), q: 1, options: details.options });
    resetIdempotency();
    cacheCart();
    updateCart();
    if (customDialog.open) customDialog.close();
    setStatus(`${item.title} ajouté au panier.`);
    window.setTimeout(() => setStatus(''), 2400);
  }

  function resolvedLines() {
    const resolved = [];
    cart.forEach((line) => {
      const item = getItem(line.id);
      if (!item || !item.available) return;
      try {
        const details = selectionDetails(item, line.options || []);
        resolved.push({ ...line, item, ...details });
      } catch (_) { /* options modifiées depuis l’ajout : ligne invalidée */ }
    });
    return resolved;
  }
  function serviceModes() {
    if (table > 0) return ['table'];
    const configured = Array.isArray(restaurant?.modes) ? restaurant.modes : [];
    const modes = configured.filter((mode) => ['livraison', 'emporter'].includes(mode));
    if (configured.length) return modes;
    return Number(restaurant?.fee || 0) > 0 ? ['livraison', 'emporter'] : ['emporter'];
  }
  function selectedMode() { return table > 0 ? 'table' : ($('#tuc-order-mode').value || serviceModes()[0] || ''); }
  function getTotals() {
    const lines = resolvedLines();
    const subtotal = lines.reduce((sum, line) => sum + line.unit * line.q, 0);
    const fee = selectedMode() === 'livraison' ? Number(restaurant?.fee || 0) : 0;
    return { lines, subtotal: Math.round(subtotal * 100) / 100, fee, total: Math.round((subtotal + fee) * 100) / 100 };
  }
  function renderLocationPanel() {
    const panel = $('#tuc-location-panel');
    const button = $('#tuc-get-location');
    const message = $('#tuc-location-status');
    const delivery = selectedMode() === 'livraison';
    panel.hidden = !delivery;
    if (!delivery) return;
    if (!TUCClient.loggedIn) {
      button.hidden = true;
      message.textContent = 'Après la connexion sécurisée par WhatsApp, votre position sera demandée.';
      return;
    }
    button.hidden = false;
    button.textContent = deliveryLocation ? 'Mettre à jour ma position' : 'Utiliser ma position';
    message.textContent = deliveryLocation
      ? `${deliveryLocation.label} · ${deliveryLocation.lat.toFixed(5)}, ${deliveryLocation.lng.toFixed(5)}`
      : 'La localisation est nécessaire pour confirmer une livraison.';
  }
  function demanderPosition() {
    const message = $('#tuc-location-status');
    const button = $('#tuc-get-location');
    if (!navigator.geolocation) {
      message.textContent = 'La localisation n’est pas disponible dans ce navigateur.';
      return;
    }
    button.disabled = true;
    message.textContent = 'Recherche de votre position…';
    navigator.geolocation.getCurrentPosition((position) => {
      deliveryLocation = {
        lat: Number(position.coords.latitude.toFixed(6)),
        lng: Number(position.coords.longitude.toFixed(6)),
        label: 'Position partagée pour cette livraison',
      };
      try { localStorage.setItem(locationKey, JSON.stringify(deliveryLocation)); } catch (_) { /* stockage facultatif */ }
      button.disabled = false;
      renderLocationPanel();
      updateCart();
    }, (error) => {
      button.disabled = false;
      message.textContent = error.code === 1
        ? 'Autorisez la localisation dans votre navigateur, ou réessayez.'
        : 'Position introuvable. Vérifiez le GPS et réessayez.';
    }, { enableHighAccuracy: false, timeout: 15000, maximumAge: 60000 });
  }
  function updateCart() {
    const totals = getTotals();
    const count = totals.lines.reduce((sum, line) => sum + line.q, 0);
    $('#tuc-cart-count').textContent = `${count} ${count > 1 ? 'articles' : 'article'}`;
    $('#tuc-cart-total').textContent = money(totals.total);
    $('#tuc-cart-bar').hidden = count === 0;
    const lines = totals.lines;
    $('#tuc-cart-lines').innerHTML = lines.length ? lines.map((line) => `<article class="tuc-cart-line" data-key="${escapeHtml(line.key)}"><div class="tuc-cart-line-copy"><strong>${escapeHtml(line.item.title)}</strong><span>${line.optionLabels.length ? escapeHtml(line.optionLabels.join(' · ')) : 'Sans option'}</span><b>${money(line.unit * line.q)}</b></div><div class="tuc-quantity"><button type="button" data-quantity="-1" aria-label="Diminuer la quantité">−</button><span>${line.q}</span><button type="button" data-quantity="1" aria-label="Augmenter la quantité">＋</button><button type="button" data-remove-line aria-label="Retirer du panier">×</button></div></article>`).join('') : '<div class="tuc-client-empty tuc-cart-empty"><span aria-hidden="true">🛍️</span><strong>Votre panier est vide</strong><p>Choisissez un plat pour commencer.</p></div>';
    $('#tuc-checkout-subtotal').textContent = money(totals.subtotal);
    $('#tuc-checkout-fee').textContent = money(totals.fee);
    $('#tuc-fee-row').hidden = totals.fee <= 0;
    $('#tuc-checkout-total').textContent = money(totals.total);
    const minimum = Number(restaurant?.minimum || 0);
    const minimumNotice = $('#tuc-minimum-notice');
    if (minimum > 0 && totals.subtotal < minimum) {
      minimumNotice.textContent = `Minimum de commande : ${money(minimum)} (il manque ${money(minimum - totals.subtotal)}).`;
      minimumNotice.hidden = false;
    } else minimumNotice.hidden = true;
    const button = $('#tuc-submit-order');
    const validTable = table === 0 || (Number(restaurant?.tables || 0) >= table && table > 0);
    const locationRequired = TUCClient.loggedIn && selectedMode() === 'livraison' && !deliveryLocation;
    button.disabled = count === 0 || !restaurant?.open || !validTable || (table === 0 && serviceModes().length === 0) || (minimum > 0 && totals.subtotal < minimum) || locationRequired;
    if (!restaurant?.open) button.textContent = 'Restaurant fermé';
    else button.textContent = 'Confirmer la commande';
    renderLocationPanel();
    try { if (table === 0) localStorage.setItem(modeKey, selectedMode()); } catch (_) { /* mode non persistant si stockage désactivé */ }
    cacheCart();
  }

  function prepareModes() {
    const select = $('#tuc-order-mode');
    if (table > 0) {
      $('#tuc-mode-label').hidden = true; select.hidden = true;
      const note = document.createElement('p'); note.className = 'tuc-table-checkout-note'; note.textContent = `Commande à la table ${table} · règlement à la fin du repas.`;
      select.insertAdjacentElement('afterend', note);
    } else {
      $('#tuc-mode-label').hidden = false; select.hidden = false;
      const modes = serviceModes();
      select.innerHTML = modes.length ? modes.map((mode) => `<option value="${mode}">${labels[mode]}</option>`).join('') : '<option value="">Mode de service non configuré</option>';
      select.disabled = modes.length === 0;
      try {
        const savedMode = localStorage.getItem(modeKey);
        if (modes.includes(savedMode)) select.value = savedMode;
      } catch (_) { /* mode par défaut */ }
      if (!modes.length) setStatus('Ce restaurant n’a pas encore configuré ses modes de commande.', true);
    }
  }

  function restoreCart() {
    let stored = [];
    try { stored = JSON.parse(localStorage.getItem(storeKey) || '[]'); } catch (_) { stored = []; }
    if (!Array.isArray(stored)) return;
    cart = stored.filter((line) => line && Number.isInteger(Number(line.id)) && Number(line.q) > 0 && Array.isArray(line.options))
      .map((line) => ({ id: Number(line.id), q: Math.max(1, Math.min(50, Number(line.q))), options: line.options, key: `${Number(line.id)}:${JSON.stringify(line.options)}` }))
      .filter((line) => {
        const item = getItem(line.id);
        if (!item || !item.available) return false;
        try { selectionDetails(item, line.options); return true; } catch (_) { return false; }
      });
  }

  async function load() {
    try {
      const response = await fetch(`${TUCClient.rest}menu/${encodeURIComponent(slug)}`, { credentials: 'same-origin' });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.message || 'Menu indisponible.');
      restaurant = data.restaurant;
      menu = Array.isArray(data.items) ? data.items : [];
      const phoneInput = $('#tuc-customer-phone');
      const phoneLabel = $('label[for="tuc-customer-phone"]');
      const phoneHelp = $('#tuc-phone-help');
      phoneInput.required = Boolean(TUCClient.loggedIn);
      if (TUCClient.loggedIn) {
        if (TUCClient.phone) {
          phoneInput.value = `+${String(TUCClient.phone).replace(/^\+/, '')}`;
          phoneInput.readOnly = true;
        }
      } else {
        phoneLabel.hidden = true;
        phoneInput.hidden = true;
        phoneHelp.textContent = 'Votre numéro sera vérifié automatiquement par le message que vous enverrez depuis WhatsApp.';
      }
      $('#tuc-menu-heading').textContent = `Le menu de ${restaurant.name}`;
      if (!restaurant.open) setStatus('Ce restaurant est fermé pour le moment.', true);
      else if (table > 0 && (!restaurant.tables || table > restaurant.tables)) setStatus('Le numéro de table indiqué est invalide. Demandez de l’aide au personnel.', true);
      else setStatus('');
      prepareModes();
      renderMenu();
      restoreCart();
      updateCart();
      const currentUrl = new URL(window.location.href);
      if (currentUrl.searchParams.get('tuc_reprendre') === '1') {
        currentUrl.searchParams.delete('tuc_reprendre');
        window.history.replaceState({}, '', currentUrl.pathname + currentUrl.search + currentUrl.hash);
        if (cart.length) {
          $('#tuc-checkout-area').hidden = false;
          $('#tuc-cart-lines').hidden = false;
          window.setTimeout(() => { if (!cartDialog.open) cartDialog.showModal(); }, 0);
        }
      }
      const savedTracking = readTracking();
      if (savedTracking) saveTracking(savedTracking);
      if (table > 0 && (!restaurant.tables || table > restaurant.tables)) $$('.tuc-add-to-cart').forEach((button) => { button.disabled = true; });
    } catch (error) {
      setStatus(error.message || 'Impossible de charger le menu. Réessayez.', true);
      products.innerHTML = '<div class="tuc-client-empty"><strong>Menu momentanément indisponible</strong><p>Actualisez la page ou réessayez un peu plus tard.</p></div>';
    }
  }

  async function pollWhatsAppStatus() {
    if (!waSession) return;
    try {
      const query = new URLSearchParams({ code: waSession.code, poll: waSession.poll });
      const response = await fetch(`${TUCClient.rest}wa-statut?${query}`, { credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json().catch(() => ({}));
      if (response.ok && data.pret && data.magie) {
        window.clearTimeout(waPollTimer);
        $('#tuc-account-status').textContent = 'Votre compte est prêt. Le lien de connexion a aussi été envoyé dans WhatsApp.';
        $('#tuc-wa-resume').href = data.magie;
        $('#tuc-wa-resume').hidden = false;
        return;
      }
    } catch (_) { /* le polling reprendra tant que le code est valide */ }
    waPollTimer = window.setTimeout(pollWhatsAppStatus, 2000);
  }
  async function preparerWhatsApp() {
    const button = $('#tuc-start-whatsapp');
    const statusMessage = $('#tuc-account-status');
    button.disabled = true;
    statusMessage.textContent = 'Préparation du message sécurisé…';
    try {
      const retour = new URL(window.location.href);
      retour.searchParams.set('tuc_reprendre', '1');
      const response = await fetch(`${TUCClient.rest}wa-lien`, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': TUCClient.nonce },
        body: JSON.stringify({ but: 'compte', retour: retour.toString() }),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.url || !data.code || !data.poll) throw new Error(data.message || 'Impossible de préparer WhatsApp. Réessayez.');
      waSession = { code: data.code, poll: data.poll };
      const openLink = $('#tuc-open-whatsapp');
      openLink.href = data.url;
      openLink.hidden = false;
      button.hidden = true;
      statusMessage.textContent = 'Ouvrez WhatsApp, envoyez le message prérempli, puis revenez ici. Cette page attend la confirmation.';
      pollWhatsAppStatus();
    } catch (error) {
      statusMessage.textContent = error.message;
      button.disabled = false;
    }
  }

  async function submitOrder(event) {
    event.preventDefault();
    const checkoutError = $('#tuc-checkout-error');
    checkoutError.hidden = true;
    const totals = getTotals();
    if (!totals.lines.length) { checkoutError.textContent = 'Votre panier est vide.'; checkoutError.hidden = false; return; }
    if (!$('#tuc-checkout-form').reportValidity()) return;
    if (!TUCClient.loggedIn) {
      if (!accountDialog.open) accountDialog.showModal();
      return;
    }
    if (selectedMode() === 'livraison' && !deliveryLocation) {
      checkoutError.textContent = 'Partagez votre position pour confirmer une livraison.';
      checkoutError.hidden = false;
      return;
    }
    const phone = $('#tuc-customer-phone').value.trim();
    if (!pendingKey) {
      pendingKey = newKey();
      try { localStorage.setItem(pendingOrderKey, pendingKey); } catch (_) { /* reprise idempotente non disponible si stockage désactivé */ }
    }
    const button = $('#tuc-submit-order');
    button.disabled = true;
    button.textContent = 'Envoi en cours…';
    try {
      const response = await fetch(`${TUCClient.rest}commande`, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': TUCClient.nonce },
        body: JSON.stringify({
          resto: slug, table: table > 0 ? table : 0, mode: selectedMode(), tel: phone,
          total: totals.total,
          items: totals.lines.map((line) => ({ id: line.id, q: line.q, options: line.options })),
          ...(selectedMode() === 'livraison' && deliveryLocation ? { lat: deliveryLocation.lat, lng: deliveryLocation.lng } : {}),
          cle: pendingKey
        })
      });
      const body = await response.json().catch(() => ({}));
      if (!response.ok) {
        if (response.status === 409 && Number(body.data?.total_serveur) >= 0) {
          const serverTotal = Number(body.data.total_serveur);
          resetIdempotency();
          await load();
          checkoutError.textContent = `Le prix ou les frais ont changé. Le nouveau total est ${money(serverTotal)}. Vérifiez le panier actualisé puis confirmez à nouveau.`;
          checkoutError.hidden = false;
          return;
        }
        if (response.status === 422 && body.code === 'tuc_item_indisponible') {
          resetIdempotency();
          await load();
          checkoutError.textContent = 'Un plat vient d’être épuisé. Le panier a été actualisé; vérifiez les articles restants avant de confirmer.';
          checkoutError.hidden = false;
          return;
        }
        throw new Error(body.message || 'La commande n’a pas pu être envoyée. Réessayez.');
      }
      cart = [];
      cacheCart();
      resetIdempotency();
      $('#tuc-checkout-area').hidden = true;
      $('#tuc-cart-lines').hidden = true;
      const trackingToken = typeof body.suivi === 'string' && /^[A-Za-z0-9_-]{43}$/.test(body.suivi) ? body.suivi : '';
      if (trackingToken) saveTracking({ reference: Number(body.commande), token: trackingToken });
      $('#tuc-order-success').innerHTML = `<div class="tuc-success-icon" aria-hidden="true">✓</div><h3>Commande reçue</h3><p>Votre commande <strong>#${Number(body.commande)}</strong> a bien été transmise à ${escapeHtml(restaurant.name)}.</p><p>Le règlement se fera sur place. Le restaurant pourra vous contacter au numéro indiqué.</p>${trackingToken ? '<button type="button" class="tuc-client-primary" data-open-tracking>Suivre ma commande</button>' : ''}<button type="button" class="tuc-client-secondary" data-success-close>Continuer</button>`;
      $('#tuc-order-success').hidden = false;
      updateCart();
    } catch (error) {
      checkoutError.textContent = error.message;
      checkoutError.hidden = false;
    } finally {
      button.disabled = false;
      button.textContent = 'Confirmer la commande';
      if (restaurant?.open && getTotals().lines.length) button.disabled = false;
      else button.disabled = true;
    }
  }

  products.addEventListener('click', (event) => {
    const button = event.target.closest('[data-add]');
    if (!button || button.disabled) return;
    const item = getItem(Number(button.dataset.add));
    if (item && restaurant?.open) openCustomize(item);
  });
  $('#tuc-customize-groups').addEventListener('change', onCustomizeChange);
  $('#tuc-customize-form').addEventListener('submit', (event) => {
    event.preventDefault();
    if (!currentItem) return;
    try { addLine(currentItem, readOptionDialog().options); }
    catch (error) { const err = $('#tuc-customize-error'); err.textContent = error.message; err.hidden = false; }
  });
  $('[data-close-customize]').addEventListener('click', () => customDialog.close());
  $('[data-close-cart]').addEventListener('click', () => cartDialog.close());
  $('#tuc-open-cart').addEventListener('click', () => { updateCart(); $('#tuc-checkout-area').hidden = false; $('#tuc-cart-lines').hidden = false; $('#tuc-order-success').hidden = true; cartDialog.showModal(); });
  $('#tuc-cart-lines').addEventListener('click', (event) => {
    const row = event.target.closest('[data-key]');
    if (!row) return;
    const line = cart.find((entry) => entry.key === row.dataset.key);
    if (!line) return;
    if (event.target.closest('[data-remove-line]')) cart = cart.filter((entry) => entry !== line);
    const quantity = event.target.closest('[data-quantity]');
    if (quantity) {
      line.q = Math.max(0, Math.min(50, line.q + Number(quantity.dataset.quantity)));
      if (line.q === 0) cart = cart.filter((entry) => entry !== line);
    }
    resetIdempotency(); cacheCart(); updateCart();
  });
  $('#tuc-checkout-form').addEventListener('submit', submitOrder);
  $('#tuc-order-mode').addEventListener('change', () => { resetIdempotency(); updateCart(); });
  $('#tuc-get-location').addEventListener('click', demanderPosition);
  $('#tuc-start-whatsapp').addEventListener('click', preparerWhatsApp);
  $('[data-close-account]', accountDialog)?.addEventListener('click', () => accountDialog.close());
  accountDialog?.addEventListener('click', (event) => { if (event.target === accountDialog) accountDialog.close(); });
  $('#tuc-customer-phone').addEventListener('input', () => { resetIdempotency(); updateCart(); });
  $('#tuc-order-success').addEventListener('click', (event) => {
    if (event.target.closest('[data-success-close]')) cartDialog.close();
    if (event.target.closest('[data-open-tracking]')) { cartDialog.close(); openTracking(); }
  });
  $('#tuc-open-saved-tracking')?.addEventListener('click', openTracking);
  $('[data-close-tracking]', trackingDialog)?.addEventListener('click', () => trackingDialog.close());
  $('[data-refresh-tracking]', trackingDialog)?.addEventListener('click', refreshTracking);
  $('[data-forget-tracking]', trackingDialog)?.addEventListener('click', () => {
    try { localStorage.removeItem(trackingKey); } catch (_) { /* stockage local facultatif */ }
    const button = $('#tuc-open-saved-tracking');
    if (button) button.hidden = true;
    window.clearInterval(trackingTimer);
    trackingDialog.close();
  });
  trackingDialog?.addEventListener('close', () => window.clearInterval(trackingTimer));
  for (const dialog of [customDialog, cartDialog, trackingDialog].filter(Boolean)) dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
  load();
})();
