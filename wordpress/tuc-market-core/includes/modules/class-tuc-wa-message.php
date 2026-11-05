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
    /**
     * @param array $lignes  [['nom'=>..,'q'=>..,'pu'=>..,'opts'=>[...]]]
     */
    public static function panier(string $resto, array $lignes, float $frais, float $total, string $mode, int $table = 0, string $ref = ''): string
    {
        $ref = $ref !== '' ? $ref : strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

        $entete = sprintf('TUC · %s', $ref);

        if ($mode === 'table' && $table > 0) {
            $lieu = sprintf('Table %d', $table);
        } elseif ($mode === 'emporter') {
            $lieu = 'À emporter';
        } else {
            $lieu = 'Livraison';
        }

        $lignes_txt = [];
        foreach ($lignes as $l) {
            $nom  = (string) ($l['nom'] ?? '');
            $q    = max(1, (int) ($l['q'] ?? 1));
            $pu   = (float) ($l['pu'] ?? 0);
            $opts = (array) ($l['opts'] ?? []);

            $txt = sprintf(
                '%d× %s — %s MAD',
                $q,
                $nom,
                number_format($pu * $q, 0, ',', ' ')
            );
            if ($opts !== []) {
                $txt .= "\n   + " . implode(', ', array_map('strval', $opts));
            }
            $lignes_txt[] = $txt;
        }

        $corps = implode("\n", $lignes_txt);

        $pied = sprintf(
            "Sous-total : %s MAD\nFrais : %s\nTOTAL : %s MAD",
            number_format($total - $frais, 0, ',', ' '),
            $frais > 0 ? number_format($frais, 0, ',', ' ') . ' MAD' : 'Gratuit',
            number_format($total, 0, ',', ' ')
        );

        return "{$entete}\n{$resto} · {$lieu}\n\n{$corps}\n\n{$pied}\n\nJe confirme ma commande.";
    }

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
