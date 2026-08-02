<?php

namespace App\Services\Carousel;

/**
 * Rend un gabarit de brique stocké en base, SANS AUCUNE EXÉCUTION.
 *
 * On ne compile jamais de Blade venant de la base : ce serait exécuter du PHP
 * arbitraire depuis des données. Le moteur ne sait faire que trois choses :
 *
 *   1. substitution échappée   {{ title }}   {{ theme.accent }}
 *   2. conditionnels           {{#if subtitle}} … {{/if}}   {{#unless x}} … {{/unless}}
 *   3. boucles sur les listes  {{#each items}} {{ left }} {{ right }} {{ index }} {{/each}}
 *
 * Aucune expression, aucun calcul, aucun accès disque ou réseau.
 *
 * Les TAILLES s'expriment en CSS et non en PHP : le slide est un conteneur de
 * requête (`container-type: size`), donc `font-size: 6.2cqh` vaut « 6,2 % de la
 * hauteur du slide » — l'équivalent des `round($h * 0.062)` des briques en code.
 *
 * Variables CSS injectées, prêtes à l'emploi dans le gabarit :
 *   --text --bg --accent --overlay --title-font --body-font   (thème)
 *   --justify --align --text-align --shift                    (slots position/offset)
 *   --scrim                                                   (voile de lisibilité)
 */
class TemplateRenderer
{
    /**
     * Balises et attributs interdits : rejetés à l'enregistrement ET neutralisés
     * ici (défense en profondeur — un gabarit peut avoir été inséré autrement).
     */
    private const FORBIDDEN_TAGS = ['script', 'iframe', 'object', 'embed', 'link', 'meta', 'base', 'form', 'svg'];

    /**
     * @param  array<string, mixed>  $data  slots déjà normalisés (BrickRegistry)
     * @param  array<string, mixed>  $theme
     */
    public function render(string $template, array $data, array $theme, int $w, int $h): string
    {
        $context = $this->context($data, $theme);

        $html = $this->renderSections($template, $context, $data);
        $html = $this->sanitize($html);

        return $this->wrap($html, $data, $theme, $h);
    }

    /**
     * Signale les motifs interdits d'un gabarit (validation à l'enregistrement).
     * Retourne la liste des problèmes trouvés, vide si le gabarit est acceptable.
     *
     * @return array<int, string>
     */
    public function violations(string $template): array
    {
        $problems = [];

        foreach (self::FORBIDDEN_TAGS as $tag) {
            if (preg_match('/<\s*'.$tag.'\b/i', $template)) {
                $problems[] = "La balise <{$tag}> n’est pas autorisée dans un gabarit.";
            }
        }

        if (preg_match('/\son[a-z]+\s*=/i', $template)) {
            $problems[] = 'Les attributs d’événement (onclick, onload, …) ne sont pas autorisés.';
        }

        $external = '/(?:(?:src|href|srcset|poster)\s*=\s*["\']?|url\(\s*["\']?)\s*(?:https?:)?\/\//i';
        if (preg_match($external, $template)) {
            $problems[] = 'Les ressources externes (http, //) ne sont pas autorisées : tout doit être local.';
        }

        if (preg_match('/javascript\s*:/i', $template)) {
            $problems[] = 'Les URL « javascript: » ne sont pas autorisées.';
        }

        if (str_contains($template, '<?')) {
            $problems[] = 'Le code PHP n’est pas autorisé dans un gabarit.';
        }

        return $problems;
    }

    /**
     * Contexte plat de substitution : slots + theme.* + quelques valeurs utiles.
     *
     * @return array<string, string>
     */
    private function context(array $data, array $theme): array
    {
        $context = [];

        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $context[$key] = (string) $value;
            }
        }

        foreach ($theme as $key => $value) {
            if (is_scalar($value)) {
                $context['theme.'.$key] = (string) $value;
            }
        }

        return $context;
    }

    /**
     * Traite conditionnels et boucles, puis substitue les marqueurs restants.
     */
    private function renderSections(string $template, array $context, array $data): string
    {
        // {{#each slot}} … {{/each}} — une ligne = un item (Lines::parse).
        $template = preg_replace_callback(
            '/\{\{#each\s+([\w.]+)\s*\}\}(.*?)\{\{\/each\}\}/s',
            function (array $m) use ($context, $data) {
                $items = Lines::parse($data[$m[1]] ?? null, 12);
                $out = '';
                foreach ($items as $i => [$left, $right]) {
                    $out .= $this->substitute($m[2], $context + [
                        'left' => $left,
                        'right' => $right,
                        'index' => (string) ($i + 1),
                    ]);
                }

                return $out;
            },
            $template
        ) ?? $template;

        // {{#if slot}} … {{/if}} et {{#unless slot}} … {{/unless}}
        $template = preg_replace_callback(
            '/\{\{#(if|unless)\s+([\w.]+)\s*\}\}(.*?)\{\{\/\1\}\}/s',
            function (array $m) use ($context) {
                $filled = isset($context[$m[2]]) && trim($context[$m[2]]) !== '';
                $keep = $m[1] === 'if' ? $filled : ! $filled;

                return $keep ? $m[3] : '';
            },
            $template
        ) ?? $template;

        return $this->substitute($template, $context);
    }

    /**
     * Substitution ÉCHAPPÉE de tous les marqueurs restants. Un marqueur inconnu
     * disparaît (plutôt que de laisser du texte parasite dans le rendu).
     */
    private function substitute(string $template, array $context): string
    {
        return preg_replace_callback(
            '/\{\{\s*([\w.]+)\s*\}\}/',
            fn (array $m) => htmlspecialchars($context[$m[1]] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $template
        ) ?? '';
    }

    /**
     * Neutralise ce qui aurait échappé à la validation (gabarit inséré hors UI).
     */
    private function sanitize(string $html): string
    {
        $html = preg_replace(
            '/<\s*\/?\s*('.implode('|', self::FORBIDDEN_TAGS).')\b[^>]*>/i',
            '',
            $html
        ) ?? '';

        // Attributs d'événement inline.
        $html = preg_replace('/\son[a-z]+\s*=\s*(["\']).*?\1/i', '', $html) ?? $html;

        return preg_replace('/javascript\s*:/i', '', $html) ?? $html;
    }

    /**
     * Enveloppe le gabarit dans un conteneur plein cadre qui porte les variables
     * CSS et fait du slide un conteneur de requête (unités cqw/cqh).
     */
    private function wrap(string $html, array $data, array $theme, int $h): string
    {
        $anchor = Anchor::resolve($data['position'] ?? null, $theme['overlay'] ?? '#000000');
        $shift = Anchor::offsetTransform($data['offset'] ?? 0, $h);

        $vars = [
            '--text' => $theme['text'] ?? '#ffffff',
            '--bg' => $theme['background'] ?? '#0f0f1a',
            '--accent' => $theme['accent'] ?? '#0083ff',
            '--overlay' => $theme['overlay'] ?? '#000000',
            '--title-font' => "'".($theme['title_font'] ?? 'Montserrat')."', sans-serif",
            '--body-font' => "'".($theme['body_font'] ?? 'Poppins')."', sans-serif",
            '--justify' => $anchor['justify'],
            '--align' => $anchor['align'],
            '--text-align' => $anchor['text_align'],
            '--shift' => $shift === '' ? 'none' : trim(str_replace(['transform:', ';'], '', $shift)),
        ];

        $style = '';
        foreach ($vars as $name => $value) {
            $style .= $name.':'.$this->cssValue($value).';';
        }

        // --scrim n'est pas une valeur mais un bloc de déclarations : on l'expose
        // comme une classe utilitaire prête à poser dans le gabarit.
        $scrim = $anchor['scrim'];

        return '<div class="brick-root" style="position:absolute; inset:0; container-type:size; '.$style.'">'
            .'<style>.brick-scrim{'.$this->cssValue($scrim).'}</style>'
            .$html
            .'</div>';
    }

    /**
     * Coupe toute tentative de sortir d'une déclaration CSS via les valeurs de thème.
     */
    private function cssValue(string $value): string
    {
        return str_replace(['<', '>', '"'], '', $value);
    }
}
