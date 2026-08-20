<?php

namespace humhub\modules\slackBridge\services;

/**
 * Traduit le *mrkdwn* de Slack vers le texte enrichi de HumHub.
 *
 * Les deux dialectes se ressemblent assez pour qu'on croie pouvoir recopier tel
 * quel, et diffèrent assez pour que ça ne marche pas :
 *
 * - Slack encode ses liens et mentions entre chevrons — `<@U1>`, `<#C1|général>`,
 *   `<https://x|texte>` — que Markdown afficherait bruts ;
 * - le gras de Slack est `*x*`, celui de Markdown `**x**` ; `*x*` en Markdown
 *   donne de l'italique, donc ne rien faire ne « dégrade » pas, ça ment ;
 * - un retour à la ligne simple disparaît dans le Markdown de HumHub, et sans
 *   même laisser d'espace : « ligne un\nligne deux » se rend « ligne unligne
 *   deux ». Vérifié sur cebe/markdown, `enableNewlines` étant à false.
 *
 * Et une préoccupation qui n'est pas cosmétique : le texte vient de Slack, donc
 * de l'extérieur. HumHub a ses propres schémas de lien (`mention:`, `oembed:`)
 * qu'un message pourrait écrire à la main pour se faire passer pour une mention
 * système. On les désamorce.
 */
class MessageFormatter
{
    public function __construct(private SlackApi $api)
    {
    }

    public function toMarkdown(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = $this->expandTokens($text);
        // Après les chevrons, jamais avant : Slack encode `&lt;` pour un
        // chevron littéral, et décoder d'abord le rendrait indistinguable d'un
        // vrai délimiteur de lien.
        $text = $this->decodeEntities($text);
        $text = $this->outsideCode($text, fn(string $chunk): string => $this->convertEmphasis($chunk));
        $text = $this->neutralizeInternalSchemes($text);

        return trim($this->addHardBreaks($text));
    }

    /**
     * Les jetons entre chevrons. L'ordre compte : les formes les plus
     * spécifiques d'abord, sinon la règle des liens avalerait les mentions.
     */
    private function expandTokens(string $text): string
    {
        $rules = [
            // <@U123> ou <@U123|nom> → @Nom. En texte simple, délibérément : une
            // vraie mention HumHub notifierait ici quelqu'un qui a déjà été
            // notifié dans Slack, pour une conversation qu'il suit déjà.
            '/<@([UW][A-Z0-9]+)(?:\|([^>]*))?>/' => function (array $m): string {
                $fallback = trim($m[2] ?? '');
                $name = $fallback !== '' ? $fallback : ($this->api->getUserName($m[1]) ?? $m[1]);

                return '@' . $name;
            },
            // <#C123|général> → #général
            '/<#([CG][A-Z0-9]+)(?:\|([^>]*))?>/' => function (array $m): string {
                $name = trim($m[2] ?? '');

                return '#' . ($name !== '' ? $name : $m[1]);
            },
            // <!subteam^S123|@équipe> → @équipe
            '/<!subteam\^([A-Z0-9]+)(?:\|([^>]*))?>/' => function (array $m): string {
                $name = trim($m[2] ?? '');

                return $name !== '' ? $name : '@équipe';
            },
            // <!here>, <!channel>, <!everyone> — rendus en texte : ils ne doivent
            // pas se transformer en interpellation du Space entier.
            '/<!(here|channel|everyone)(?:\|[^>]*)?>/' => fn(array $m): string => '@' . $m[1],
            // <!date^...^{date_short}|repli> → le repli, que Slack fournit toujours.
            '/<!date\^[^>]*\|([^>]*)>/' => fn(array $m): string => $m[1],
            // <mailto:x@y.z|texte> → texte (ou l'adresse si pas de libellé)
            '/<mailto:([^|>]+)(?:\|([^>]*))?>/' => function (array $m): string {
                $label = trim($m[2] ?? '');

                return $label !== '' ? $label : $m[1];
            },
            // <https://url|texte> → [texte](url) ; <https://url> → url nu
            '/<(https?:\/\/[^|>\s]+)(?:\|([^>]*))?>/' => function (array $m): string {
                $url = $this->sanitizeUrl($m[1]);
                if ($url === '') {
                    return '';
                }
                $label = $this->sanitizeLinkLabel($m[2] ?? '');

                // Sans libellé, l'URL nue suffit : HumHub l'autolie, et un
                // [url](url) ferait doublon dans les aperçus.
                return $label === '' ? $url : '[' . $label . '](' . $url . ')';
            },
        ];

        foreach ($rules as $pattern => $replace) {
            $text = preg_replace_callback($pattern, $replace, $text) ?? $text;
        }

        return $text;
    }

    /**
     * Slack n'échappe que ces trois-là (« Escaping text »). Utiliser
     * html_entity_decode complet décoderait aussi `&copy;` ou `&#x2F;` que
     * l'auteur avait tapés littéralement.
     */
    private function decodeEntities(string $text): string
    {
        return strtr($text, ['&lt;' => '<', '&gt;' => '>', '&amp;' => '&']);
    }

    /**
     * Applique une transformation au texte **hors** blocs et fragments de code.
     * Sans ça, un extrait de code contenant `*ptr` verrait son astérisque
     * réinterprétée en gras.
     */
    private function outsideCode(string $text, callable $fn): string
    {
        // Les segments capturés (indices impairs) sont le code ; on les repose
        // intacts.
        $parts = preg_split('/(```.*?```|`[^`\n]*`)/s', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $fn($text);
        }

        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $parts[$i] = $fn($part);
            }
        }

        return implode('', $parts);
    }

    /**
     * `*gras*` → `**gras**` et `~barré~` → `~~barré~~`.
     *
     * Les gardes autour des délimiteurs évitent les faux positifs qui font mal :
     * une puce « * item » en début de ligne, une multiplication « 3 * 4 », un
     * nom_de_variable. D'où l'exigence d'un caractère non-espace collé au
     * délimiteur des deux côtés, et pas de mot juste à l'extérieur.
     *
     * `_italique_` est laissé tel quel : Markdown lui donne déjà le même sens.
     */
    private function convertEmphasis(string $text): string
    {
        $text = preg_replace('/(?<![\w*])\*(?=\S)([^*\n]+?)(?<=\S)\*(?![\w*])/u', '**$1**', $text) ?? $text;

        return preg_replace('/(?<![\w~])~(?=\S)([^~\n]+?)(?<=\S)~(?![\w~])/u', '~~$1~~', $text) ?? $text;
    }

    /**
     * Un retour simple devient un vrai saut (deux espaces en fin de ligne).
     *
     * On ne touche ni aux lignes déjà suivies d'une ligne vide — c'est un
     * changement de paragraphe, Markdown le gère —, ni à l'intérieur des blocs
     * de code, où deux espaces ajoutés seraient du contenu falsifié.
     */
    private function addHardBreaks(string $text): string
    {
        $lines = explode("\n", $text);
        $inFence = false;

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*```/', $line)) {
                $inFence = !$inFence;
                continue;
            }
            if ($inFence) {
                continue;
            }

            $next = $lines[$i + 1] ?? null;
            if ($next === null || trim($line) === '' || trim($next) === '') {
                continue;
            }
            if (preg_match('/\s$/', $line)) {
                continue;
            }

            $lines[$i] = $line . '  ';
        }

        return implode("\n", $lines);
    }

    /**
     * Neutralise les liens Markdown pointant vers un schéma interne à HumHub.
     *
     * Un message Slack contenant littéralement `[Direction](mention:<guid>)` se
     * rendrait ici en mention cliquable d'un membre qui n'a rien dit. On
     * échappe le crochet ouvrant : le lien s'affiche alors tel qu'il a été
     * écrit, ce qui est exactement ce qu'il était côté Slack.
     */
    private function neutralizeInternalSchemes(string $text): string
    {
        return preg_replace('/\[([^\]]*)\]\(\s*(mention|oembed|file-guid):/i', '\\\\[$1]($2:', $text) ?? $text;
    }

    /** Le libellé part dans des crochets : ceux qu'il contient les fermeraient. */
    private function sanitizeLinkLabel(string $label): string
    {
        return trim(str_replace(['[', ']'], '', $label));
    }

    /**
     * L'URL part dans une parenthèse Markdown, où un `)` la fermerait trop tôt.
     * Slack ne produit que du http(s) dans ces jetons, mais la garde reste :
     * c'est du texte venu de l'extérieur.
     */
    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);

        return preg_match('#^https?://[^\s<>()"\']+$#i', $url) ? $url : '';
    }
}
