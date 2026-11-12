<?php
/**
 * Module 2c — Construit le message WhatsApp pré-rempli.
 *
 * Décision : le PANIER EST EN TEXTE ET CHIFFRÉ dans le message.
 * Le client voit exactement ce qu'il s'apprête à envoyer, puis appuie sur
 * « Envoyer » pour valider. C'est sa confirmation écrite, et n8n reçoit
 * une trace complète qu'il peut parser sans état serveur.
 *
 * Le message porte AUSSI une référence courte en en-tête : elle permet à n8n
 * de router sans lire tout le panier, et de rattacher le message à la commande.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class TUC_WA_Message
{
    /** Message d'inscription : rien à écrire, rien à composer. */
    public static function inscription(string $ref): string
    {
        return sprintf("TUC · %s\nJe veux créer mon compte.", $ref);
    }

    /** URL wa.me complète. `?text=` encode le message. */
    public static function wa_url(string $numero, string $message): string
    {
        $numero = preg_replace('/[^0-9]/', '', $numero);
        return 'https://wa.me/' . $numero . '?text=' . rawurlencode($message);
    }
}
