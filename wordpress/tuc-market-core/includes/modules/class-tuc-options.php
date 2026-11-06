<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Validation serveur du schéma v2 de groupes d'options et de ses sélections. */
final class TUC_Options
{
    public const VERSION = 2;
    private const MAX_GROUPS = 12;
    private const MAX_CHOICES = 20;

    private static function key($value): string
    {
        return is_scalar($value) ? sanitize_key((string) $value) : '';
    }

    private static function text($value): string
    {
        return is_scalar($value) ? sanitize_text_field((string) $value) : '';
    }

    private static function boolean($value): bool
    {
        return is_scalar($value) && rest_sanitize_boolean($value);
    }

    /** Retourne une définition canonique; les anciennes listes plates restent lisibles. */
    public static function normaliser_definition($raw): array|WP_Error
    {
        if (!is_array($raw) || $raw === []) {
            return ['version' => self::VERSION, 'groups' => []];
        }

        if (isset($raw['groups']) && is_array($raw['groups'])) {
            $groups = $raw['groups'];
        } else {
            // Compatibilité avec l'ancien format [{id, nom, prix}].
            $legacy = array_values(array_filter($raw, static fn($option): bool => is_array($option) && !empty($option['id'])));
            if ($legacy === []) {
                return new WP_Error('tuc_options_definition', 'La définition des options est invalide.', ['status' => 422]);
            }
            $groups = [[
                'id' => 'options',
                'name' => 'Options',
                'required' => false,
                'min' => 0,
                'max' => min(self::MAX_CHOICES, count($legacy)),
                'choices' => array_map(static fn(array $option): array => [
                    'id' => $option['id'] ?? '',
                    'name' => $option['nom'] ?? $option['name'] ?? '',
                    'price_delta' => $option['prix'] ?? $option['price_delta'] ?? 0,
                    'available' => true,
                ], $legacy),
            ]];
        }

        if (count($groups) > self::MAX_GROUPS) {
            return new WP_Error('tuc_options_definition', 'Un plat ne peut pas contenir plus de 12 groupes.', ['status' => 422]);
        }

        $normalised = [];
        $groupIds = [];
        foreach (array_values($groups) as $index => $group) {
            if (!is_array($group)) {
                return new WP_Error('tuc_options_definition', 'Un groupe d’options est invalide.', ['status' => 422]);
            }
            $id = self::key($group['id'] ?? '');
            $name = self::text($group['name'] ?? $group['nom'] ?? '');
            $choices = $group['choices'] ?? [];
            if ($id === '' || strlen($id) > 40 || in_array($id, $groupIds, true) || $name === '' || !is_array($choices) || $choices === [] || count($choices) > self::MAX_CHOICES) {
                return new WP_Error('tuc_options_definition', 'Chaque groupe doit avoir un identifiant, un nom et de 1 à 20 choix uniques.', ['status' => 422]);
            }

            $choiceIds = [];
            $normalisedChoices = [];
            foreach (array_values($choices) as $choice) {
                if (!is_array($choice)) {
                    return new WP_Error('tuc_options_definition', 'Un choix d’option est invalide.', ['status' => 422]);
                }
                $choiceId = self::key($choice['id'] ?? '');
                $choiceName = self::text($choice['name'] ?? $choice['nom'] ?? '');
                $price = $choice['price_delta'] ?? $choice['prix'] ?? 0;
                if ($choiceId === '' || strlen($choiceId) > 40 || in_array($choiceId, $choiceIds, true) || $choiceName === '' || !is_numeric($price) || (float) $price < 0 || (float) $price > 10000) {
                    return new WP_Error('tuc_options_definition', 'Un choix doit avoir un nom, un identifiant unique et un supplément valide.', ['status' => 422]);
                }
                $choiceIds[] = $choiceId;
                $normalisedChoices[] = [
                    'id' => $choiceId,
                    'name' => $choiceName,
                    'price_delta' => round((float) $price, 2),
                    'available' => !array_key_exists('available', $choice) || self::boolean($choice['available']),
                ];
            }

            $required = self::boolean($group['required'] ?? false);
            $min = isset($group['min']) ? (int) $group['min'] : ($required ? 1 : 0);
            $max = isset($group['max']) ? (int) $group['max'] : count($normalisedChoices);
            if ($required && $min < 1) {
                $min = 1;
            }
            $availableCount = count(array_filter($normalisedChoices, static fn(array $choice): bool => $choice['available']));
            if ($min < 0 || $max < 1 || $min > $max || $max > count($normalisedChoices) || $min > $availableCount) {
                return new WP_Error('tuc_options_definition', 'Les minimums, maximums et disponibilités d’un groupe sont incohérents.', ['status' => 422]);
            }

            $visibleIf = null;
            if (isset($group['visible_if']) && $group['visible_if'] !== null && $group['visible_if'] !== []) {
                $condition = $group['visible_if'];
                if (!is_array($condition)) {
                    return new WP_Error('tuc_options_definition', 'La condition d’affichage est invalide.', ['status' => 422]);
                }
                $conditionGroup = self::key($condition['group_id'] ?? '');
                $conditionChoices = array_values(array_unique(array_filter(array_map(static fn($value): string => self::key($value), (array) ($condition['choice_ids'] ?? [])))));
                if ($conditionGroup === '' || $conditionGroup === $id || !in_array($conditionGroup, $groupIds, true) || $conditionChoices === []) {
                    return new WP_Error('tuc_options_definition', 'Une condition doit cibler un choix d’un groupe précédent.', ['status' => 422]);
                }
                $dependency = $normalised[$conditionGroup] ?? null;
                if (!is_array($dependency) || array_diff($conditionChoices, array_column($dependency['choices'], 'id')) !== []) {
                    return new WP_Error('tuc_options_definition', 'La condition référence un choix inexistant.', ['status' => 422]);
                }
                $visibleIf = ['group_id' => $conditionGroup, 'choice_ids' => $conditionChoices];
            }

            $groupIds[] = $id;
            $normalised[$id] = [
                'id' => $id,
                'name' => $name,
                'required' => $required || $min > 0,
                'min' => $min,
                'max' => $max,
                'visible_if' => $visibleIf,
                'choices' => $normalisedChoices,
            ];
        }

        return ['version' => self::VERSION, 'groups' => array_values($normalised)];
    }

    /** Valide des IDs de choix non fiables et recalcule tous les suppléments. */
    public static function valider_selection($definition, array $selection): array|WP_Error
    {
        $definition = self::normaliser_definition($definition);
        if (is_wp_error($definition)) {
            return $definition;
        }
        $groups = $definition['groups'];
        if ($groups === []) {
            if ($selection !== []) {
                return new WP_Error('tuc_options', 'Ce plat ne propose pas d’options.', ['status' => 422]);
            }
            return ['selections' => [], 'labels' => [], 'surcharge' => 0.0];
        }

        $selectedByGroup = [];
        $legacyGroup = count($groups) === 1 && $groups[0]['id'] === 'options';
        foreach ($selection as $entry) {
            if (is_string($entry) || (is_array($entry) && isset($entry['id']) && !isset($entry['group_id']))) {
                if (!$legacyGroup) {
                    return new WP_Error('tuc_options', 'La sélection doit indiquer son groupe.', ['status' => 422]);
                }
                $groupId = 'options';
                $choiceIds = [is_string($entry) ? $entry : self::key($entry['id'])];
            } elseif (is_array($entry)) {
                $groupId = self::key($entry['group_id'] ?? '');
                $choiceIds = $entry['choices'] ?? (isset($entry['choice_id']) ? [$entry['choice_id']] : []);
                if (!is_array($choiceIds)) {
                    return new WP_Error('tuc_options', 'La sélection d’un groupe est invalide.', ['status' => 422]);
                }
                $choiceIds = array_values(array_map(static fn($value): string => self::key($value), $choiceIds));
            } else {
                return new WP_Error('tuc_options', 'La sélection d’une option est invalide.', ['status' => 422]);
            }
            if ($groupId === '' || !isset($selectedByGroup[$groupId]) && !in_array($groupId, array_column($groups, 'id'), true)) {
                return new WP_Error('tuc_options', 'Groupe d’options inconnu.', ['status' => 422]);
            }
            if (isset($selectedByGroup[$groupId])) {
                return new WP_Error('tuc_options', 'Un groupe ne peut être envoyé qu’une fois.', ['status' => 422]);
            }
            if (count($choiceIds) !== count(array_unique($choiceIds)) || in_array('', $choiceIds, true)) {
                return new WP_Error('tuc_options', 'Un choix ne peut pas être répété.', ['status' => 422]);
            }
            $selectedByGroup[$groupId] = $choiceIds;
        }

        $canonical = [];
        $labels = [];
        $surcharge = 0.0;
        foreach ($groups as $group) {
            $groupId = $group['id'];
            $choiceIds = $selectedByGroup[$groupId] ?? [];
            $visible = true;
            if ($group['visible_if'] !== null) {
                $condition = $group['visible_if'];
                $visible = array_intersect($selectedByGroup[$condition['group_id']] ?? [], $condition['choice_ids']) !== [];
            }
            if (!$visible && $choiceIds !== []) {
                return new WP_Error('tuc_options', 'Un choix dépend d’une option non sélectionnée.', ['status' => 422]);
            }
            if (!$visible) {
                continue;
            }
            if (count($choiceIds) < $group['min'] || count($choiceIds) > $group['max']) {
                return new WP_Error('tuc_options', sprintf('Choisissez entre %d et %d option(s) pour « %s ».', $group['min'], $group['max'], $group['name']), ['status' => 422]);
            }

            $resolved = [];
            foreach ($choiceIds as $choiceId) {
                $choice = null;
                foreach ($group['choices'] as $candidate) {
                    if ($candidate['id'] === $choiceId) {
                        $choice = $candidate;
                        break;
                    }
                }
                if ($choice === null || !$choice['available']) {
                    return new WP_Error('tuc_options', 'Choix invalide ou indisponible.', ['status' => 422]);
                }
                $price = (float) $choice['price_delta'];
                $surcharge += $price;
                $suffix = $price > 0 ? ' +' . rtrim(rtrim(number_format($price, 2, ',', ''), '0'), ',') : '';
                $labels[] = ($groupId === 'options' ? $choice['name'] : $group['name'] . ': ' . $choice['name']) . $suffix;
                $resolved[] = ['id' => $choice['id'], 'name' => $choice['name'], 'price_delta' => $price];
            }
            if ($resolved !== []) {
                $canonical[] = ['group_id' => $groupId, 'group_name' => $group['name'], 'choices' => $resolved];
            }
        }

        return ['selections' => $canonical, 'labels' => $labels, 'surcharge' => round($surcharge, 2)];
    }
}
