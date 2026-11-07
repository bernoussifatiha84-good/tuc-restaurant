(() => {
  'use strict';
  const form = document.querySelector('#tuc-restaurant-onboarding-form');
  if (!form) return;
  const status = document.querySelector('#tuc-onboarding-status');
  const result = document.querySelector('#tuc-onboarding-whatsapp');
  const link = document.querySelector('#tuc-onboarding-wa-link');
  const confirmed = document.querySelector('#tuc-onboarding-wa-confirmed');
  const button = form.querySelector('button[type="submit"]');
  const restBase = form.dataset.rest;
  let pollTimer = 0;
  let pollCount = 0;
  let credentials = null;

  async function pollWhatsApp() {
    if (!credentials || pollCount >= 60) return;
    pollCount += 1;
    try {
      const query = new URLSearchParams({ code: credentials.code, poll: credentials.poll });
      const response = await fetch(`${restBase}wa-statut?${query}`, {
        credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' },
      });
      const data = await response.json();
      if (response.ok && data.pret && data.type === 'restaurant_application') {
        window.clearInterval(pollTimer);
        confirmed.hidden = false;
        status.textContent = data.reponse || 'WhatsApp confirmé. Notre équipe vous accompagnera pour créer votre espace.';
      }
    } catch { /* le formulaire reste enregistré même si le navigateur est hors ligne */ }
  }

  link.addEventListener('click', () => {
    if (credentials && !pollTimer) {
      pollTimer = window.setInterval(pollWhatsApp, 5000);
      window.setTimeout(() => window.clearInterval(pollTimer), 5 * 60 * 1000);
    }
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!form.reportValidity()) return;
    status.textContent = 'Envoi de votre demande…';
    result.hidden = true;
    confirmed.hidden = true;
    button.disabled = true;
    try {
      const response = await fetch(`${restBase}restaurant-applications`, {
        method: 'POST', body: new FormData(form), credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json' },
      });
      const data = await response.json();
      if (!response.ok || !data.wa_url) throw new Error(data.message || data.code || 'La demande n’a pas pu être envoyée.');
      credentials = { code: data.code, poll: data.poll };
      link.href = data.wa_url;
      link.textContent = 'Continuer dans WhatsApp';
      result.hidden = false;
      status.textContent = `${data.message} Référence : ${data.reference}.`;
      form.hidden = true;
    } catch (error) {
      status.textContent = error.message || 'Une erreur est survenue. Réessayez.';
      button.disabled = false;
    }
  });
})();
